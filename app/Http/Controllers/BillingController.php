<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\StripeBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class BillingController extends Controller
{
    public function __construct(private StripeBilling $stripe) {}

    public function checkout(Request $request, Plan $plan): RedirectResponse
    {
        abort_unless($plan->is_active, 404);

        if (! $this->stripe->enabled() || ! $plan->stripe_price_id) {
            return to_route('billing')->with('error', 'Online payment is not available for this plan. Please contact us to activate it.');
        }

        if ($request->user()->subscription?->isStripe() && $request->user()->subscription->isValid()) {
            return $this->portal($request); // already paying: change plan in the portal
        }

        try {
            return redirect()->away($this->stripe->checkoutUrl($request->user(), $plan));
        } catch (Throwable $e) {
            report($e);

            return to_route('billing')->with('error', 'Could not start checkout: '.$e->getMessage());
        }
    }

    public function portal(Request $request): RedirectResponse
    {
        try {
            return redirect()->away($this->stripe->portalUrl($request->user()));
        } catch (Throwable $e) {
            report($e);

            return to_route('billing')->with('error', 'Could not open the billing portal: '.$e->getMessage());
        }
    }

    /** Stripe sends the client back here after paying. The webhook does the real update; this makes it instant. */
    public function success(Request $request): RedirectResponse
    {
        if ($sessionId = $request->query('session_id')) {
            try {
                $session = $this->stripe->client()->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription']]);

                if ((string) $session->client_reference_id === (string) $request->user()->id && $session->subscription) {
                    $this->stripe->sync($session->subscription);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return to_route('billing')->with('status', 'Thank you! Your subscription is active.');
    }
}
