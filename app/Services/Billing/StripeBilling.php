<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

/**
 * Optional online payments through Stripe Checkout + the Stripe customer portal.
 * The admin turns this on by saving Stripe keys in Admin → Settings and a Stripe Price ID on each plan.
 */
class StripeBilling
{
    public function __construct(private SubscriptionManager $subscriptions) {}

    public function enabled(): bool
    {
        return filled($this->secret());
    }

    public function client(): StripeClient
    {
        return new StripeClient($this->secret());
    }

    public function webhookSecret(): ?string
    {
        return Setting::get('stripe.webhook_secret', config('services.stripe.webhook_secret'));
    }

    private function secret(): ?string
    {
        return Setting::get('stripe.secret', config('services.stripe.secret'));
    }

    public function customerId(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => $user->id],
        ]);

        $user->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    /** Create a Checkout page for this plan and return its URL. */
    public function checkoutUrl(User $user, Plan $plan): string
    {
        $subscriptionData = ['metadata' => ['user_id' => $user->id, 'plan_id' => $plan->id]];

        // Keep the rest of a running trial; Stripe requires the trial end to be at least 48h away.
        $trialEnd = $user->subscription?->onTrial() ? $user->subscription->trial_ends_at : null;
        if ($trialEnd && $trialEnd->gt(now()->addDays(2))) {
            $subscriptionData['trial_end'] = $trialEnd->getTimestamp();
        }

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $this->customerId($user),
            'client_reference_id' => (string) $user->id,
            'line_items' => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
            'subscription_data' => $subscriptionData,
            'success_url' => route('billing.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing'),
        ]);

        return $session->url;
    }

    public function portalUrl(User $user): string
    {
        return $this->client()->billingPortal->sessions->create([
            'customer' => $this->customerId($user),
            'return_url' => route('billing'),
        ])->url;
    }

    /** Copy a Stripe subscription onto the client's local subscription row. */
    public function sync(StripeSubscription $stripe): ?Subscription
    {
        $user = User::find($stripe->metadata['user_id'] ?? null)
            ?? User::firstWhere('stripe_customer_id', $stripe->customer)
            ?? Subscription::firstWhere('stripe_subscription_id', $stripe->id)?->user;

        if (! $user) {
            return null;
        }

        $item = $stripe->items->data[0] ?? null;
        $priceId = $item?->price?->id;
        $plan = Plan::firstWhere('stripe_price_id', $priceId) ?? Plan::find($stripe->metadata['plan_id'] ?? null);

        // Newer Stripe API versions put the billing period on the subscription item.
        $periodEnd = $item->current_period_end ?? $stripe->current_period_end ?? null;
        $status = SubscriptionStatus::fromStripe($stripe->status);

        if ($stripe->cancel_at_period_end && $status === SubscriptionStatus::Active) {
            $status = SubscriptionStatus::Canceled; // still has access until ends_at
        }

        return $this->subscriptions->record($user, [
            'provider' => 'stripe',
            'stripe_subscription_id' => $stripe->id,
            'plan_id' => $plan?->id,
            'status' => $status,
            'trial_ends_at' => $stripe->trial_end ? Carbon::createFromTimestamp($stripe->trial_end) : null,
            'ends_at' => match (true) {
                $stripe->status === 'canceled' => Carbon::createFromTimestamp($stripe->ended_at ?? time()),
                $periodEnd !== null => Carbon::createFromTimestamp($periodEnd),
                default => null,
            },
        ]);
    }
}
