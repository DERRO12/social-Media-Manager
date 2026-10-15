<?php

namespace App\Jobs;

use App\Enums\TargetStatus;
use App\Models\PostTarget;
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

        $this->target->post->refreshStatus();
    }
}
