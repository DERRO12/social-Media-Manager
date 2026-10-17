<?php

namespace App\Notifications;

use App\Models\PaymentClaim;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the admins a client says they've paid, so they can check and approve it. */
class PaymentClaimSubmitted extends Notification
{
    public function __construct(public PaymentClaim $claim) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->claim;

        return (new MailMessage)
            ->subject("Payment to check: {$c->user->name}, {$c->plan?->name}")
            ->line("{$c->user->name} ({$c->user->email}) says they paid for the {$c->plan?->name} plan.")
            ->line("Method: {$c->methodLabel()} · Reference: {$c->reference} · Amount: {$c->currency} {$c->amount}")
            ->when($c->note, fn ($m) => $m->line("Note: {$c->note}"))
            ->line('Check it in your PayPal or Yo! Payments account, then approve or reject it.')
            ->action('Review payment', route('admin.payments'));
    }
}
