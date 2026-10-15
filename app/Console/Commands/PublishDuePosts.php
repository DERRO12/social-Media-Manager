<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Console\Command;

class PublishDuePosts extends Command
{
    protected $signature = 'posts:publish-due';

    protected $description = 'Queue publishing for scheduled posts whose time has come';

    public function handle(): int
    {
        $count = 0;

        Post::where('status', PostStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->each(function (Post $post) use (&$count) {
                $post->dispatchPublishing();
                $count++;
            });

        $this->info("Queued {$count} post(s).");

        return self::SUCCESS;
    }
}
