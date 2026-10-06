<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\CreditService;
use Modules\Billing\Services\StripeSubscriptionService;
use Modules\Project\Services\TemplateSettingsService;
use Modules\User\Models\User;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Customer-facing billing endpoints, backed by Stripe.
 *
 * New subscriptions go through Stripe Checkout; plan changes swap the price
 * on the existing subscription in place; cards and invoices are managed in
 * Stripe's customer portal. The webhook is the source of truth, and every
 * path here also applies the subscription Stripe returns, so the UI is right
 * even before the webhook lands.
 */
class StripeController extends Controller
{
    public function __construct(private StripeClient $stripe)
    {
    }

    /**
     * Billing + credits snapshot for the authenticated user. Applies the daily
     * grant first so the balance is always current. Drives the header credit
     * pill, the Create/Explainer gating and the billing page.
     */
    public function me(Request $request, CreditService $credits)
    {
        $user = Auth::user();

        $credits->syncDailyGrant($user);
        $user->refresh();

        $subscription = $user->activeSubscription();
        $plan = $subscription?->plan;

        return response()->json([
            'has_subscription' => $subscription !== null,
            'credits' => (int) $user->credits,
            'daily_credits' => (int) ($plan->daily_credits ?? 0),
            'credits_refreshed_on' => optional($user->credits_refreshed_on)->toDateString(),
            'subscription' => $subscription,
            'plan' => $plan,
            'template_costs' => array_map(
                fn (array $row) => $row['credit_cost'],
                TemplateSettingsService::all()
            ),
            'default_cost' => (int) config('credits.default', 3),
            // The explainer is billed per step (storyboard tier, re-render,
            // AI pictures) rather than one flat render fee.
            'explainer_pricing' => \Modules\Project\Support\ExplainerBilling::pricing(),
        ]);
    }

    public function getSubscriptionDetails(Request $request)
    {
        $user = Auth::user();

        $subscription = Subscription::where('user_id', $user->id)
            ->with('plan')
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json(['message' => 'Active subscription not found.'], 404);
        }

        return response()->json([
            'subscription' => $subscription,
            'user' => $user,
        ]);
    }

    /** Start a subscription: a Stripe Checkout session for the plan's price. */
    public function createSubscriptionSession(Request $request, $planId)
    {
        $plan = Plan::find($planId);

        if (!$plan || !$plan->is_active) {
            return response()->json(['error' => 'Plan not found or inactive plan'], 400);
        }

        if (!$plan->stripe_price_id) {
            return response()->json(['error' => 'This plan has not been published to Stripe yet.'], 422);
        }

        $user = Auth::user();

        // A second Checkout would create a second, parallel subscription.
        if ($user->activeSubscription()) {
            return response()->json([
                'error' => 'You already have a subscription. Change plan instead.',
            ], 409);
        }

        try {
            $customerId = $this->customerFor($user);

            // Trials are a plan setting, offered once per user.
            $trialDays = (int) $plan->trial_period_days;
            $offerTrial = $trialDays > 0 && !$user->trial_used;

            $session = $this->stripe->checkout->sessions->create([
                'mode' => 'subscription',
                'customer' => $customerId,
                'client_reference_id' => (string) $user->id,
                'line_items' => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
                'allow_promotion_codes' => true,
                'subscription_data' => array_filter([
                    'metadata' => ['user_id' => (string) $user->id, 'plan_id' => (string) $plan->id],
                    'trial_period_days' => $offerTrial ? $trialDays : null,
                ]),
                'success_url' => $this->frontendUrl('stripe.success_url', [
                    'stripe' => 'success',
                ]) . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $this->frontendUrl('stripe.cancel_url', ['stripe' => 'cancelled']),
            ]);

            return response()->json([
                'checkoutUrl' => $session->url,
                'sessionId' => $session->id,
                'hasTrial' => $offerTrial,
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe checkout session failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to start checkout', 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Called by the billing page when Checkout redirects back
     * (?stripe=success&session_id=cs_...). The webhook does the same work, but
     * can land a moment later, so confirm the session directly.
     */
    public function syncCheckout(Request $request, StripeSubscriptionService $subscriptions)
    {
        $user = Auth::user();
        $sessionId = (string) $request->query('session_id', '');

        if (str_starts_with($sessionId, 'cs_')) {
            try {
                $session = $this->stripe->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription']]);

                // Only ever apply a session that belongs to this user.
                $owner = (string) $session->client_reference_id === (string) $user->id
                    || ($user->stripe_customer_id && $session->customer === $user->stripe_customer_id);

                if ($owner && $session->subscription instanceof \Stripe\Subscription) {
                    $subscriptions->apply($user, $session->subscription);
                }
            } catch (ApiErrorException $e) {
                Log::warning('Stripe checkout confirm failed: ' . $e->getMessage());
            }
        }

        $active = $user->fresh()->activeSubscription();

        return response()->json([
            'status' => $active ? 'completed' : 'pending',
            'subscription' => $active,
        ]);
    }

    /** Stop renewing at the end of the current period (credits last until then). */
    public function cancelSubscription(Request $request, StripeSubscriptionService $subscriptions)
    {
        $user = Auth::user();
        $subscription = $user->activeSubscription();

        if (!$subscription || !$subscription->sub_id) {
            return response()->json(['message' => 'Active subscription not found.'], 404);
        }

        try {
            $updated = $this->stripe->subscriptions->update($subscription->sub_id, [
                'cancel_at_period_end' => true,
            ]);
            $subscriptions->apply($user, $updated);

            return response()->json(['message' => 'Subscription will be cancelled at period end.']);
        } catch (ApiErrorException $e) {
            return response()->json(['message' => 'Failed to cancel subscription.', 'error' => $e->getMessage()], 502);
        }
    }

    /**
     * Move a subscriber to another plan in place.
     *
     * The price difference is prorated and invoiced immediately, and
     * `pending_if_incomplete` means the swap only takes effect once that
     * invoice is paid: nobody gets a bigger daily allotment on an unpaid
     * upgrade. Someone without a live subscription is sent to Checkout.
     */
    public function changePlan(Request $request, $planId, StripeSubscriptionService $subscriptions)
    {
        $plan = Plan::find($planId);

        if (!$plan || !$plan->is_active) {
            return response()->json(['message' => 'Plan not found'], 404);
        }

        if (!$plan->stripe_price_id) {
            return response()->json(['message' => 'This plan has not been published to Stripe yet.'], 422);
        }

        $user = Auth::user();
        $current = $user->activeSubscription();

        if (!$current || !$current->sub_id) {
            return $this->createSubscriptionSession($request, $planId);
        }

        if ((int) $current->type_id === (int) $plan->id) {
            return response()->json(['message' => 'You are already on this plan.'], 409);
        }

        try {
            $remote = $this->stripe->subscriptions->retrieve($current->sub_id);
            $itemId = $remote->items->data[0]->id ?? null;

            if (!$itemId) {
                return response()->json(['message' => 'Subscription has no item to change.'], 409);
            }

            $updated = $this->stripe->subscriptions->update($current->sub_id, [
                'items' => [['id' => $itemId, 'price' => $plan->stripe_price_id]],
                'proration_behavior' => 'always_invoice',
                'payment_behavior' => 'pending_if_incomplete',
                // Changing plan also undoes a scheduled cancellation.
                'cancel_at_period_end' => false,
            ]);

            $subscriptions->apply($user, $updated);

            if ($updated->pending_update) {
                return response()->json([
                    'message' => 'The payment for the new plan did not go through. Update your card and try again.',
                    'pending' => true,
                ], 402);
            }

            return response()->json([
                'message' => 'Plan changed.',
                'subscription' => $user->fresh()->activeSubscription(),
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe plan change failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to change plan.', 'error' => $e->getMessage()], 502);
        }
    }

    /**
     * Stripe's hosted customer portal: cards, invoices, receipts. Replaces the
     * old payment-method endpoints, which took any customer/card id from the
     * URL and so let one user act on another's cards.
     */
    public function portal(Request $request)
    {
        $user = Auth::user();

        if (!$user->stripe_customer_id) {
            return response()->json(['message' => 'No billing account yet. Subscribe first.'], 404);
        }

        try {
            $session = $this->stripe->billingPortal->sessions->create([
                'customer' => $user->stripe_customer_id,
                'return_url' => $this->frontendUrl('stripe.portal_return_url', []),
            ]);

            return response()->json(['url' => $session->url]);
        } catch (ApiErrorException $e) {
            return response()->json(['message' => 'Failed to open the billing portal.', 'error' => $e->getMessage()], 502);
        }
    }

    // ------------------------------------------------------------- Internals

    /** The user's Stripe customer, created on first checkout. */
    private function customerFor(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create(array_filter([
            'email' => $user->email,
            'name' => $user->name,
            'phone' => $user->phone,
            'metadata' => ['user_id' => (string) $user->id],
        ]));

        $user->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    /** A configured URL, or the frontend's billing page, plus query params. */
    private function frontendUrl(string $configKey, array $query): string
    {
        $base = config($configKey)
            ?: rtrim(config('app.frontend_url') ?: config('app.url'), '/') . '/dashboard/billing';

        if (!$query) {
            return $base;
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query);
    }
}
