<?php

namespace App\Jobs;

use App\Enums\TargetStatus;
use App\Models\PostTarget;
use App\Notifications\PostFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishPostTarget implements ShouldQueue
{
    use Queueable;

    /** Never retry automatically: a retry after a partial success could post the video twice. */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public PostTarget $target) {}

    public function handle(): void
    {
        $target = $this->target->load('post.media', 'account');

        try {
            $result = $target->account->driver()->publish($target);

            $target->update([
                'status' => TargetStatus::Published,
                'platform_post_id' => $result->platformPostId,
                'permalink' => $result->permalink,
                'published_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            $target->update([
                'status' => TargetStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            $this->tellOwner($target);
        }

        $target->post->refreshStatus();
    }

    public function failed(?Throwable $e): void
    {
        // Covers timeouts / worker crashes where handle() never reached its catch block.
        $this->target->update([
            'status' => TargetStatus::Failed,
            'error' => $e ? mb_substr($e->getMessage(), 0, 2000) : 'The publish job stopped unexpectedly.',
        ]);
        $this->tellOwner($this->target);

        $this->target->post->refreshStatus();
    }

    /** Email the client about the first platform a post failed on (not one email per platform or retry). */
    private function tellOwner(PostTarget $target): void
    {
        $otherFailures = $target->post->targets()->where('status', TargetStatus::Failed)->whereKeyNot($target->id)->exists();

        if (! $otherFailures && ($owner = $target->account?->user)) {
            rescue(fn () => $owner->notify(new PostFailed($target->load('post', 'account'))));
        }
    }
}
