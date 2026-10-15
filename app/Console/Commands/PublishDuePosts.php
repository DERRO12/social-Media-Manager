<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\Post;
use Illuminate\Console\Command;

class PublishDuePosts extends Command
{
    protected $signature = 'posts:publish-due';

    protected $description = 'Queue publishing for scheduled posts whose time has come';

    public function handle(): int
    {
        $count = 0;

        Post::with('user.subscription')
            ->where('status', PostStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->each(function (Post $post) use (&$count) {
                // Clients whose trial/plan ran out (or who were suspended) don't get their posts published.
                if (! $post->user->hasAccess() || $post->user->isSuspended()) {
                    $post->targets()->update([
                        'status' => TargetStatus::Failed,
                        'error' => 'Not published: the subscription was not active at the scheduled time.',
                    ]);
                    $post->update(['status' => PostStatus::Failed]);

                    return;
                }

                $post->dispatchPublishing();
                $count++;
            });

        $this->info("Queued {$count} post(s).");

        return self::SUCCESS;
    }
}
