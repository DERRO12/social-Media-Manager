<?php

namespace App\Notifications;

use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** A post didn't go out on a platform. Sent once per post, for the first platform that failed. */
class PostFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PostTarget $target) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $platform = $this->target->account->platform->label();
        $title = Str::limit($this->target->post->title ?: $this->target->post->caption ?: 'your post', 60);

        return (new MailMessage)
            ->subject("Your post didn't publish on {$platform}")
            ->greeting("Hi {$notifiable->name},")
            ->line("“{$title}” could not be published on {$platform}.")
            ->line('Reason: '.Str::limit((string) $this->target->error, 300))
            ->line('Often reconnecting the account fixes it. You can retry from the post list.')
            ->action('See the post', route('posts'));
    }
}
