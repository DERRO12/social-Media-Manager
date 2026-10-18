<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\PaymentClaim;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Every change to a client's subscription goes through here, whether it comes from
 * the admin panel (manual) or from Stripe.
 */
class SubscriptionManager
{
    public function defaultTrialDays(): int
    {
        return (int) Setting::get('billing.trial_days', 14);
    }

    public function defaultTrialPlan(): ?Plan
    {
        $id = Setting::get('billing.trial_plan_id');

        return ($id ? Plan::find($id) : null) ?? Plan::active()->first();
    }

    /** Called when a new client signs up or is created by the admin. */
    public function startDefaultTrial(User $user): void
    {
        $days = $this->defaultTrialDays();

        if ($days > 0) {
            $this->startTrial($user, $days, $this->defaultTrialPlan());
        } else {
            $this->record($user, ['status' => SubscriptionStatus::Expired, 'plan_id' => null]);
        }
    }

    /** Give (or reset) a free trial lasting $days from now. */
    public function startTrial(User $user, int $days, ?Plan $plan = null, ?string $notes = null): Subscription
    {
        return $this->record($user, [
            'status' => SubscriptionStatus::Trialing,
            'plan_id' => $plan?->id ?? $user->subscription?->plan_id ?? $this->defaultTrialPlan()?->id,
            'trial_ends_at' => now()->addDays($days),
            'ends_at' => null,
            'trial_reminder_sent_at' => null,
            'notes' => $notes ?? $user->subscription?->notes,
        ]);
    }

    /** Add days to the current trial (or start one if there isn't a running trial). */
    public function extendTrial(User $user, int $days): Subscription
    {
        $sub = $user->subscription;

        if ($sub?->onTrial()) {
            return $this->record($user, [
                'trial_ends_at' => $sub->trial_ends_at->clone()->addDays($days),
                'trial_reminder_sent_at' => null,
            ]);
        }

        return $this->startTrial($user, $days);
    }

    /**
     * Activate a plan by hand (paid by bank transfer, mobile money, a deal, …).
     * $endsAt null means no end date.
     */
    public function activateManually(User $user, Plan $plan, ?CarbonInterface $endsAt, ?string $notes = null): Subscription
    {
        return $this->record($user, [
            'status' => SubscriptionStatus::Active,
            'provider' => 'manual',
            'plan_id' => $plan->id,
            'trial_ends_at' => null,
            'ends_at' => $endsAt,
            'notes' => $notes ?? $user->subscription?->notes,
        ]);
    }

    /**
     * Approve a client's "I've paid" claim: activate their plan for one billing period. If they're already
     * paid up on the same plan, the new period is added on top, so paying early never loses days.
     */
    public function approvePayment(PaymentClaim $claim, User $admin): Subscription
    {
        $user = $claim->user;
        $plan = $claim->plan ?? throw new \RuntimeException('This payment is not linked to a plan.');
        $current = $user->subscription;

        $from = $current?->isValid() && $current->plan_id === $plan->id && $current->ends_at?->isFuture() && ! $current->onTrial()
            ? $current->ends_at
            : now();

        $claim->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);

        return $this->activateManually($user, $plan, $plan->periodEnd($from),
            trim(($current?->notes ? $current->notes."\n" : '').'Paid via '.$claim->methodLabel().', ref '.$claim->reference.' ('.now()->toDateString().')'));
    }

    public function rejectPayment(PaymentClaim $claim, User $admin, ?string $reason): void
    {
        $claim->update(['status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'admin_note' => $reason]);
    }

    /** Stop access immediately. */
    public function expire(User $user): Subscription
    {
        return $this->record($user, [
            'status' => SubscriptionStatus::Expired,
            'trial_ends_at' => null,
            'ends_at' => now(),
        ]);
    }

    /** Mark subscriptions whose trial/period has run out as expired (keeps statuses honest for reporting). */
    public function sweepExpired(): int
    {
        $count = 0;

        Subscription::whereIn('status', [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Canceled])
            ->each(function (Subscription $sub) use (&$count) {
                if (! $sub->isValid()) {
                    $wasTrial = $sub->status === SubscriptionStatus::Trialing;
                    $sub->update(['status' => SubscriptionStatus::Expired]);
                    rescue(fn () => $sub->user?->notify(new \App\Notifications\PlanExpired($wasTrial)));
                    $count++;
                }
            });

        return $count;
    }

    public function record(User $user, array $attributes): Subscription
    {
        $sub = $user->subscription()->updateOrCreate([], $attributes);
        $user->setRelation('subscription', $sub);

        return $sub;
    }
}
