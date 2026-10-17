<?php

namespace App\Notifications;

use App\Models\PaymentClaim;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the client their payment was confirmed (plan active) or couldn't be found. */
class PaymentClaimReviewed extends Notification
{
    public function __construct(public PaymentClaim $claim) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->claim;
        $mail = (new MailMessage)->greeting("Hi {$notifiable->name},");

        if ($c->status === 'approved') {
            $until = $notifiable->subscription?->ends_at;

            return $mail->subject("Payment confirmed: your {$c->plan?->name} plan is active")
                ->line("We've confirmed your payment (ref {$c->reference}). Your {$c->plan?->name} plan is active".($until ? ' until '.$notifiable->localTime($until, 'F j, Y') : '').'.')
                ->action('Go to your dashboard', route('dashboard'))
                ->line('Thank you for subscribing!');
        }

        return $mail->subject('We couldn’t confirm your payment')
            ->line("We couldn't find or confirm your payment with reference {$c->reference}.")
            ->when($c->admin_note, fn ($m) => $m->line("Reason: {$c->admin_note}"))
            ->line('Please check the reference and submit it again, or reply to this email for help.')
            ->action('Go to billing', route('billing'));
    }
}
