<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\SafepayCheckoutSession;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\Safepay\SafepayWebhookSignature;
use Modules\Billing\Services\SafepaySubscriptionService;
use Modules\User\Models\User;

/**
 * Receives Safepay webhook events.
 *
 * Configure the endpoint in the Safepay dashboard under Developers >
 * Endpoints, pointing at POST /api/billing/safepay/webhook, and subscribe to
 * the `subscription.*` events. The shared secret shown there goes in
 * SAFEPAY_WEBHOOK_SECRET.
 *
 * Safepay signs each delivery with an HMAC-SHA512 hex digest in the
 * `X-SFPY-SIGNATURE` header and retries anything that does not answer 2xx
 * within 10 seconds, so every handler here is idempotent.
 */
class SafepayWebhookController extends Controller
{
    public function handle(Request $request, SafepaySubscriptionService $subscriptions)
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $secret = (string) config('safepay.webhook_secret');
        $signature = (string) $request->header('X-SFPY-SIGNATURE', '');

        if ($secret === '') {
            Log::error('Safepay webhook received but SAFEPAY_WEBHOOK_SECRET is not set.');

            return response()->json(['error' => 'Webhook secret not configured'], 500);
        }

        if (!SafepayWebhookSignature::verify($rawBody, $payload, $signature, $secret)) {
            Log::error('Safepay webhook signature verification failed', [
                'type' => $payload['type'] ?? null,
                'token' => $payload['token'] ?? null,
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $eventId = $payload['token'] ?? null;
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? [];

        if (!$eventId || !$type) {
            return response()->json(['error' => 'Malformed event'], 400);
        }

        // Idempotency: claim this event token. If it was already claimed, a
        // previous delivery handled it — never apply it twice.
        $claimed = DB::table('safepay_events')->insertOrIgnore([
            'id' => $eventId,
            'type' => $type,
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            Log::info('Safepay webhook: duplicate event ignored', ['event_id' => $eventId]);

            return response()->json(['status' => 'duplicate']);
        }

        try {
            match (true) {
                str_starts_with($type, 'subscription.') => $this->handleSubscriptionEvent($type, $data, $subscriptions),
                default => Log::info('Safepay webhook: unhandled event type ' . $type),
            };
        } catch (\Throwable $e) {
            // Release the claim so Safepay's retry can reprocess the event.
            DB::table('safepay_events')->where('id', $eventId)->delete();

            Log::error('Safepay webhook error: ' . $e->getMessage(), [
                'event_id' => $eventId,
                'type' => $type,
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * All `subscription.*` events carry the same subscription snapshot, so one
     * handler covers created / canceled / ended / paused / resumed and both
     * payment outcomes.
     */
    private function handleSubscriptionEvent(string $type, array $data, SafepaySubscriptionService $subscriptions): void
    {
        $user = $this->resolveUser($data);

        if (!$user) {
            throw new \RuntimeException(
                'Could not resolve a local user for Safepay subscription ' . ($data['id'] ?? '?')
            );
        }

        $subscription = $subscriptions->apply($user, $data);

        $this->closeCheckoutSession($data, $subscription);

        // A recurring charge (successful or not) becomes a payment record.
        if (in_array($type, ['subscription.payment.succeeded', 'subscription.payment.failed'], true)) {
            $this->recordPayment($user, $data, $type);
        }
    }

    /**
     * Turn a subscription payment event into a `payments` row.
     *
     * Keyed on the Safepay transaction token so a redelivery updates rather
     * than duplicates.
     */
    private function recordPayment(User $user, array $data, string $type): void
    {
        $transactionId = $data['transaction_id'] ?? null;

        if (!$transactionId) {
            return;
        }

        $plan = !empty($data['plan_id'])
            ? Plan::where('safepay_plan_id', $data['plan_id'])->first()
            : null;

        Payment::updateOrCreate(
            ['payment_transaction_id' => $transactionId],
            [
                'user_id' => $user->id,
                'related_type' => 'membership',
                'related_type_id' => $plan?->id,
                // Safepay amounts are in the currency's lowest denomination.
                'payment_amount' => ((int) ($data['amount'] ?? 0)) / 100,
                'payment_gateway' => 'safepay',
                'payment_status' => strtolower(
                    $data['transaction_status'] ?? ($type === 'subscription.payment.succeeded' ? 'complete' : 'failed')
                ),
                'payment_currency' => strtoupper($data['currency'] ?? config('safepay.currency', 'PKR')),
                'note' => $data['transaction_error_message'] ?? null,
            ]
        );
    }

    /** Mark the originating checkout session done once its subscription lands. */
    private function closeCheckoutSession(array $data, ?Subscription $subscription): void
    {
        $reference = $data['reference'] ?? null;

        $session = $reference
            ? SafepayCheckoutSession::find($reference)
            : SafepayCheckoutSession::where('subscription_token', $data['id'] ?? '')->first();

        if (!$session || $session->status === 'completed') {
            return;
        }

        $session->forceFill([
            'status' => 'completed',
            'subscription_token' => $data['id'] ?? $subscription?->sub_id,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Resolve the local user behind a Safepay subscription event.
     *
     * Safepay creates subscriptions inside its own hosted checkout, so the
     * usual Stripe trick of a customer id we minted does not exist. In order of
     * reliability:
     *   1. the `reference` we put on the checkout URL,
     *   2. a local subscription already carrying this subscription token,
     *   3. the Safepay shopper id previously stored on the user,
     *   4. the email the customer typed into checkout,
     *   5. the one pending checkout session for this plan (last resort).
     */
    private function resolveUser(array $data): ?User
    {
        if (!empty($data['reference'])) {
            $session = SafepayCheckoutSession::find($data['reference']);
            if ($session?->user) {
                return $session->user;
            }
        }

        if (!empty($data['id'])) {
            $existing = Subscription::where('sub_id', $data['id'])->first();
            if ($existing?->user) {
                return $existing->user;
            }
        }

        if (!empty($data['user_id'])) {
            $user = User::where('safepay_customer_id', $data['user_id'])->first();
            if ($user) {
                return $user;
            }
        }

        if (!empty($data['customer_email'])) {
            $user = User::where('email', $data['customer_email'])->first();
            if ($user) {
                return $user;
            }
        }

        // Last resort: exactly one pending checkout for this plan, opened
        // recently. Ambiguity is logged and left unresolved rather than guessed.
        if (!empty($data['plan_id'])) {
            $pending = SafepayCheckoutSession::where('safepay_plan_id', $data['plan_id'])
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subHours(6))
                ->get();

            if ($pending->count() === 1) {
                Log::warning('Safepay webhook: user resolved by sole pending checkout session', [
                    'reference' => $pending->first()->reference,
                    'subscription' => $data['id'] ?? null,
                ]);

                return $pending->first()->user;
            }
        }

        Log::error('Safepay webhook: unable to resolve user', [
            'subscription' => $data['id'] ?? null,
            'plan_id' => $data['plan_id'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
        ]);

        return null;
    }
}
