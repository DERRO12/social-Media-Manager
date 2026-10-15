<?php

namespace App\Http\Controllers;

use App\Services\Billing\StripeBilling;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Point a Stripe webhook at APP_URL/stripe/webhook with these events:
 * checkout.session.completed, customer.subscription.created, customer.subscription.updated,
 * customer.subscription.deleted, invoice.payment_failed, invoice.paid
 */
class StripeWebhookController extends Controller
{
    public function __construct(private StripeBilling $stripe) {}

    public function __invoke(Request $request): Response
    {
        $secret = $this->stripe->webhookSecret();
        abort_unless($secret, 404);

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature', 400);
        }

        $object = $event->data->object;

        $subscriptionId = match ($event->type) {
            'checkout.session.completed' => $object->subscription,
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $object->id,
            'invoice.paid', 'invoice.payment_failed' => $object->subscription
                ?? $object->parent?->subscription_details?->subscription ?? null,
            default => null,
        };

        // Always re-read the subscription from Stripe so events arriving out of order can't leave stale data.
        if ($subscriptionId) {
            $this->stripe->sync($this->stripe->client()->subscriptions->retrieve($subscriptionId));
        }

        return response('ok');
    }
}
