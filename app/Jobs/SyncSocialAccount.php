<?php

namespace App\Jobs;

use App\Models\ContentItem;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\PlatformDriver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Pull the latest content, metrics and comments for one connected account.
 */
class SyncSocialAccount implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    /** How many recent posts/videos to import per sync. */
    private const CONTENT_LIMIT = 50;

    /** Most items to re-download comments for in one sync (newest first). */
    private const COMMENT_ITEMS = 50;

    /** Even without new comments, refresh a video's comments this often so like counts stay current. */
    private const REFRESH_HOURS = 6;

    /** For platforms that don't report comment counts, re-check recent posts this often. */
    private const UNKNOWN_COUNT_MINUTES = 30;

    public function __construct(public SocialAccount $account) {}

    public function uniqueId(): string
    {
        return (string) $this->account->id;
    }

    public function handle(): void
    {
        $account = $this->account;
        $driver = $account->driver();

        try {
            if ($driver->supports(PlatformDriver::CONTENT)) {
                foreach ($this->withRetry(fn () => $driver->fetchContent($account, self::CONTENT_LIMIT)) as $data) {
                    $item = $account->contentItems()->updateOrCreate(
                        ['platform_content_id' => $data->platformContentId],
                        $data->toAttributes(),
                    );

                    $this->linkToPost($item);
                }
            }

            if ($driver->supports(PlatformDriver::COMMENTS)) {
                $this->itemsNeedingComments()->each(fn (ContentItem $item) => $this->syncComments($driver, $item));
            }

            $account->update(['last_synced_at' => now(), 'last_error' => null]);
        } catch (ConnectionException $e) {
            // Still unreachable after retries: a network problem, not a broken connection. Try again next sync.
            report($e);
        } catch (Throwable $e) {
            report($e);
            $account->update(['last_error' => mb_substr($e->getMessage(), 0, 2000)]);
        }
    }

    /**
     * Only download comments where something may have changed: the platform's comment count differs from
     * the last download, it was never downloaded, the platform gives no count, or the copy is old (likes).
     * This keeps frequent syncs cheap on API quota.
     */
    private function itemsNeedingComments()
    {
        return $this->account->contentItems()
            // Skip posts the platform says never had comments.
            ->where(fn ($q) => $q->whereNull('comments_count')->orWhere('comments_count', '>', 0)->orWhere('comments_synced_count', '>', 0))
            ->where(fn ($q) => $q
                ->whereNull('comments_synced_at')
                ->orWhereNull('comments_synced_count')
                ->orWhereColumn('comments_count', '!=', 'comments_synced_count')
                ->orWhere('comments_synced_at', '<', now()->subHours(self::REFRESH_HOURS))
                ->orWhere(fn ($q) => $q->whereNull('comments_count')->where('comments_synced_at', '<', now()->subMinutes(self::UNKNOWN_COUNT_MINUTES))))
            ->latest('published_at')
            ->limit(self::COMMENT_ITEMS)
            ->get();
    }

    private function syncComments(PlatformDriver $driver, ContentItem $item): void
    {
        try {
            $comments = $this->withRetry(fn () => $driver->fetchComments($this->account, $item));
        } catch (ConnectionException $e) {
            // One video's comments timing out shouldn't fail the whole account; it's retried next sync.
            report($e);

            return;
        }

        foreach ($comments as $data) {
            $item->comments()->updateOrCreate(
                ['platform_comment_id' => $data->platformCommentId],
                ['social_account_id' => $this->account->id, ...$data->toAttributes()],
            );
        }

        // When the platform gave us the complete list, remove comments that were deleted there
        // (but keep replies posted from this app in the last few minutes, in case the platform is still catching up).
        if ($driver->returnsAllComments()) {
            $item->comments()
                ->whereNotIn('platform_comment_id', array_map(fn ($c) => $c->platformCommentId, $comments))
                ->where(fn ($q) => $q->where('is_own', false)->orWhere('created_at', '<', now()->subMinutes(10)))
                ->delete();
        }

        $item->update(['comments_synced_count' => $item->comments_count ?? count($comments), 'comments_synced_at' => now()]);
    }

    /** Retry calls that fail on network timeouts/connection resets (not on API errors like an expired login). */
    private function withRetry(callable $call, int $attempts = 3): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $call();
            } catch (ConnectionException $e) {
                if ($try >= $attempts) {
                    throw $e;
                }

                Sleep::for($try * 2)->seconds();
            }
        }
    }

    /** Connect imported content to the post it came from, if it was published through this app. */
    private function linkToPost(ContentItem $item): void
    {
        if ($item->post_target_id) {
            return;
        }

        $targetId = PostTarget::where('social_account_id', $this->account->id)
            ->where('platform_post_id', $item->platform_content_id)
            ->value('id');

        if ($targetId) {
            $item->update(['post_target_id' => $targetId]);
        }
    }
}
