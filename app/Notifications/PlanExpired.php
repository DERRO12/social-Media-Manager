<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when a trial or plan runs out, so the client can renew. */
class PlanExpired extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public bool $wasTrial = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = $this->wasTrial ? 'free trial' : 'plan';

        return (new MailMessage)
            ->subject("Your {$what} has ended")
            ->greeting("Hi {$notifiable->name},")
            ->line('Your '.config('app.name')." {$what} has ended, so scheduled posts are paused and your public page is hidden.")
            ->line('Your accounts, content and certificates are safe. Choose a plan to pick up where you left off.')
            ->action('Choose a plan', route('billing'));
    }
}
