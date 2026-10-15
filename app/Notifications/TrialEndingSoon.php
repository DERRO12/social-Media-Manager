<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrialEndingSoon extends Notification
{
    public function __construct(public Subscription $subscription) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = $this->subscription->trialDaysLeft();

        return (new MailMessage)
            ->subject("Your free trial ends in {$days} ".str('day')->plural($days))
            ->greeting("Hi {$notifiable->name},")
            ->line('Your '.config('app.name').' free trial ends on '.$notifiable->localTime($this->subscription->trial_ends_at, 'l, M j').'.')
            ->line('Choose a plan to keep publishing, scheduling and answering comments without interruption.')
            ->action('Choose a plan', route('billing'))
            ->line('Scheduled posts will not be published after the trial ends unless a plan is active.');
    }
}
