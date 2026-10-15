<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Free trial',
            self::Active => 'Active',
            self::PastDue => 'Payment due',
            self::Canceled => 'Canceled',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Trialing => 'sky',
            self::Active => 'green',
            self::PastDue => 'amber',
            self::Canceled => 'zinc',
            self::Expired => 'red',
        };
    }

    /** Map a Stripe subscription status onto ours. */
    public static function fromStripe(string $status): self
    {
        return match ($status) {
            'trialing' => self::Trialing,
            'active' => self::Active,
            'past_due', 'unpaid', 'incomplete' => self::PastDue,
            'canceled' => self::Canceled,
            default => self::Expired, // incomplete_expired, paused
        };
    }
}
