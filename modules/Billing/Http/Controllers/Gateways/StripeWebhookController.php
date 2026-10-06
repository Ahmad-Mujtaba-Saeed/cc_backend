<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\StripeSubscriptionService;
use Modules\User\Models\User;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;

/**
 * Receives Stripe webhook events (setup: config/stripe.php).
 *
 * Stripe retries anything that doesn't answer 2xx and may deliver an event
 * more than once or out of order, so: every event id is claimed once in
 * `stripe_events`; a failure releases the claim (500) so the retry
 * reprocesses it; and subscription state is re-read from Stripe rather than
 * trusted from an older event's snapshot.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private StripeClient $stripe)
    {
    }

    public function handle(Request $request, StripeSubscriptionService $subscriptions)
    {
        $secret = (string) config('stripe.webhook_secret');

        if ($secret === '') {
            Log::error('Stripe webhook received but STRIPE_WEBHOOK_SECRET is not set.');

            return response()->json(['error' => 'Webhook secret not configured'], 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $secret
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('Stripe webhook rejected: ' . $e->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $claimed = DB::table('stripe_events')->insertOrIgnore([
            'id' => $event->id,
            'type' => $event->type,
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $this->dispatch($event, $subscriptions);
        } catch (\Throwable $e) {
            DB::table('stripe_events')->where('id', $event->id)->delete();

            Log::error('Stripe webhook error: ' . $e->getMessage(), [
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    private function dispatch(Event $event, StripeSubscriptionService $subscriptions): void
    {
        $object = $event->data->object;

        switch ($event->type) {
            case 'checkout.session.completed':
                if ($object->mode !== 'subscription' || !$object->subscription) {
                    return;
                }
                $user = $this->resolveUser($object->customer, $object->client_reference_id);
                $subscriptions->apply($this->required($user, $object->subscription), $this->fresh($object->subscription));
                return;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
            case 'customer.subscription.paused':
            case 'customer.subscription.resumed':
                $user = $this->resolveUser($object->customer, $object->metadata['user_id'] ?? null);
                $subscriptions->apply($this->required($user, $object->id), $this->fresh($object->id, $object));
                return;

            case 'invoice.paid':
            case 'invoice.payment_failed':
                $this->recordPayment($object, $event->type);
                return;

            default:
                Log::info('Stripe webhook: unhandled event type ' . $event->type);
        }
    }

    /**
     * The subscription as Stripe has it now. A deleted subscription can no
     * longer be listed with every field, so the event's own copy stands in.
     */
    private function fresh(string $subscriptionId, ?StripeSubscription $fallback = null): StripeSubscription
    {
        try {
            return $this->stripe->subscriptions->retrieve($subscriptionId);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($fallback) {
                return $fallback;
            }
            throw $e;
        }
    }

    /** One `payments` row per invoice, updated in place on redelivery. */
    private function recordPayment(\Stripe\Invoice $invoice, string $type): void
    {
        // A $0 invoice (e.g. the start of a free trial) isn't a payment.
        if ((int) $invoice->amount_due === 0 && (int) $invoice->amount_paid === 0) {
            return;
        }

        $user = $this->resolveUser($invoice->customer, null, $invoice->customer_email);
        if (!$user) {
            // Not fatal: the subscription events carry the access state.
            Log::warning('Stripe webhook: no local user for invoice ' . $invoice->id);
            return;
        }

        $line = $invoice->lines->data[0] ?? null;
        $priceId = $line?->pricing?->price_details?->price ?? null;
        $plan = $priceId ? Plan::where('stripe_price_id', $priceId)->first() : null;
        $paid = $type === 'invoice.paid';

        Payment::updateOrCreate(
            ['payment_transaction_id' => $invoice->id],
            [
                'user_id' => $user->id,
                'related_type' => 'membership',
                'related_type_id' => $plan?->id,
                'payment_amount' => ($paid ? $invoice->amount_paid : $invoice->amount_due) / 100,
                'payment_gateway' => 'stripe',
                'payment_status' => $paid ? 'paid' : 'failed',
                'payment_currency' => strtoupper($invoice->currency),
                'note' => $invoice->hosted_invoice_url,
            ]
        );
    }

    /**
     * The local user behind a Stripe customer, most reliable first:
     *   1. the customer id stored when we created the customer,
     *   2. the user id we put on the Checkout session / subscription metadata,
     *   3. the customer's email.
     */
    private function resolveUser($customer, $userId = null, ?string $email = null): ?User
    {
        $customerId = is_string($customer) ? $customer : $customer?->id;

        if ($customerId && ($user = User::where('stripe_customer_id', $customerId)->first())) {
            return $user;
        }

        if ($userId && ($user = User::find($userId))) {
            return $user;
        }

        if (!$email && $customerId) {
            $email = $this->stripe->customers->retrieve($customerId)->email ?? null;
        }

        return $email ? User::where('email', $email)->first() : null;
    }

    private function required(?User $user, string $subscriptionId): User
    {
        if (!$user) {
            // Thrown so the claim is released and Stripe retries later.
            throw new \RuntimeException("No local user for Stripe subscription {$subscriptionId}");
        }

        return $user;
    }
}
