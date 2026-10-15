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

    /** How many items (newest first, only ones that have comments) to refresh comments for per sync. */
    private const COMMENT_ITEMS = 50;

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
                // Skip items the platform says have no comments (null = count unknown, so check it).
                $account->contentItems()
                    ->where(fn ($q) => $q->whereNull('comments_count')->orWhere('comments_count', '>', 0))
                    ->latest('published_at')->limit(self::COMMENT_ITEMS)->get()
                    ->each(fn (ContentItem $item) => $this->syncComments($driver, $item));
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
