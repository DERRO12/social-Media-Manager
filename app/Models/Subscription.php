<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'plan_id',
        'status',
        'provider',
        'trial_ends_at',
        'ends_at',
        'stripe_subscription_id',
        'notes',
        'trial_reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_reminder_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Whether the client currently has access to the app. */
    public function isValid(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Trialing => $this->trial_ends_at?->isFuture() ?? false,
            // ends_at null on an active plan = no end date (e.g. a lifetime deal the admin granted).
            SubscriptionStatus::Active, SubscriptionStatus::PastDue => $this->ends_at === null || $this->ends_at->isFuture(),
            // Canceled but already paid until the end of the period.
            SubscriptionStatus::Canceled => $this->ends_at?->isFuture() ?? false,
            SubscriptionStatus::Expired => false,
        };
    }

    public function onTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing && $this->isValid();
    }

    public function trialDaysLeft(): int
    {
        return $this->onTrial() ? (int) ceil(now()->diffInHours($this->trial_ends_at) / 24) : 0;
    }

    /** The date access stops, if any. */
    public function accessEndsAt(): ?\Carbon\CarbonInterface
    {
        return $this->status === SubscriptionStatus::Trialing ? $this->trial_ends_at : $this->ends_at;
    }

    /** Status to show, accounting for trials/periods that ran out but haven't been swept yet. */
    public function effectiveStatus(): SubscriptionStatus
    {
        return $this->isValid() ? $this->status : SubscriptionStatus::Expired;
    }

    public function isStripe(): bool
    {
        return $this->provider === 'stripe' && $this->stripe_subscription_id !== null;
    }
}
