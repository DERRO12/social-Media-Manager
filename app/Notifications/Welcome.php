<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent once after sign-up: the first steps that get a new client value quickly. */
class Welcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');
        $trial = $notifiable->subscription?->onTrial() ? $notifiable->subscription->trialDaysLeft() : null;

        return (new MailMessage)
            ->subject("Welcome to {$app}")
            ->greeting("Welcome, {$notifiable->name}!")
            ->line("Thanks for joining {$app}.".($trial ? " Your free trial has {$trial} days left." : ''))
            ->line('Get the most out of it in 10 minutes:')
            ->line('1. Connect your YouTube, Facebook, Instagram, TikTok, LinkedIn or X accounts.')
            ->line('2. Post or schedule your first video to all of them at once.')
            ->line('3. Turn on your public page and add photos so people find you on Google.')
            ->line('4. Start a free course in the Academy and earn a certificate.')
            ->action('Connect your accounts', route('accounts'))
            ->line('Reply to this email if you need help.');
    }
}
