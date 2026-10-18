<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the admins about each new sign-up. */
class NewClientSignedUp extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public User $client) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New sign-up: {$this->client->name}")
            ->line("{$this->client->name} ({$this->client->email}) just created an account.")
            ->action('View client', route('admin.client', $this->client));
    }
}
