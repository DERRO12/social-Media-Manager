<?php

namespace App\Jobs;

use App\Models\ContentItem;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Social\Contracts\PlatformDriver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pull the latest content, metrics and comments for one connected account.
 */
class SyncSocialAccount implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    /** How many of the newest items to refresh comments for on each sync. */
    private const COMMENT_ITEMS = 10;

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
                foreach ($driver->fetchContent($account) as $data) {
                    $item = $account->contentItems()->updateOrCreate(
                        ['platform_content_id' => $data->platformContentId],
                        $data->toAttributes(),
                    );

                    $this->linkToPost($item);
                }
            }

            if ($driver->supports(PlatformDriver::COMMENTS)) {
                $account->contentItems()->latest('published_at')->limit(self::COMMENT_ITEMS)->get()
                    ->each(fn (ContentItem $item) => $this->syncComments($driver, $item));
            }

            $account->update(['last_synced_at' => now(), 'last_error' => null]);
        } catch (Throwable $e) {
            report($e);
            $account->update(['last_error' => mb_substr($e->getMessage(), 0, 2000)]);
        }
    }

    private function syncComments(PlatformDriver $driver, ContentItem $item): void
    {
        foreach ($driver->fetchComments($this->account, $item) as $data) {
            $item->comments()->updateOrCreate(
                ['platform_comment_id' => $data->platformCommentId],
                ['social_account_id' => $this->account->id, ...$data->toAttributes()],
            );
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
