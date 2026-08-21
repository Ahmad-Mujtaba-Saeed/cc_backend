<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\SafepayCheckoutSession;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\CreditService;
use Modules\Billing\Services\Safepay\SafepayClient;
use Modules\Billing\Services\Safepay\SafepayException;
use Modules\Billing\Services\SafepaySubscriptionService;
use Modules\Project\Services\TemplateSettingsService;

/**
 * Customer-facing billing endpoints, backed by Safepay.
 *
 * The big shape change from the old Stripe controller: Safepay has no API to
 * create a subscription. The customer subscribes inside Safepay's hosted
 * checkout, and we learn about it from the `subscription.*` webhooks. So there
 * is no plan-swap either — changing plan means cancelling the current
 * subscription at period end and sending the customer through checkout again.
 */
class SafepayController extends Controller
{
    public function __construct(private SafepayClient $safepay)
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

    /**
     * Start a subscription: mint a checkout session and hand the frontend the
     * hosted Safepay URL to redirect to.
     */
    public function createSubscriptionSession(Request $request, $planId)
    {
        $plan = Plan::find($planId);

        if (!$plan || !$plan->is_active) {
            return response()->json(['error' => 'Plan not found or inactive plan'], 400);
        }

        if (!$plan->safepay_plan_id) {
            return response()->json([
                'error' => 'This plan has not been published to Safepay yet.',
            ], 422);
        }

        try {
            return response()->json($this->buildCheckout(Auth::user(), $plan));
        } catch (SafepayException $e) {
            Log::error('Safepay checkout session failed', [
                'user_id' => Auth::id(),
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to start checkout',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Called by the frontend when Safepay redirects the customer back.
     *
     * The webhook is the source of truth, but it can land a moment later (or
     * not at all if the endpoint isn't configured yet), so this reconciles the
     * reference against Safepay directly and returns the resulting local state.
     */
    public function syncCheckout(Request $request, SafepaySubscriptionService $subscriptions)
    {
        $user = Auth::user();
        $reference = (string) $request->query('reference', '');

        $session = $reference
            ? SafepayCheckoutSession::where('reference', $reference)->where('user_id', $user->id)->first()
            : null;

        // The webhook may already have finished the job.
        if ($session && $session->status === 'completed') {
            return response()->json(['status' => 'completed', 'subscription' => $user->activeSubscription()]);
        }

        if ($session) {
            try {
                $match = $this->findSubscriptionForSession($session);

                if ($match) {
                    $subscriptions->apply($user, $match);
                    $session->forceFill([
                        'status' => 'completed',
                        'subscription_token' => $match['token'] ?? null,
                        'completed_at' => now(),
                    ])->save();

                    return response()->json([
                        'status' => 'completed',
                        'subscription' => $user->fresh()->activeSubscription(),
                    ]);
                }
            } catch (SafepayException $e) {
                Log::warning('Safepay checkout reconcile failed: ' . $e->getMessage());
            }
        }

        $active = $user->activeSubscription();

        return response()->json([
            'status' => $active ? 'completed' : 'pending',
            'subscription' => $active,
        ]);
    }

    /**
     * Schedule cancellation at the end of the current billing period.
     *
     * Safepay's dedicated /cancel endpoint terminates immediately and cannot be
     * undone, so the "keep it until the period ends" behaviour the UI promises
     * comes from an update instead.
     */
    public function cancelSubscription(Request $request, SafepaySubscriptionService $subscriptions)
    {
        $user = Auth::user();

        $subscription = Subscription::where('user_id', $user->id)->latest()->first();

        if (!$subscription || !$subscription->sub_id) {
            return response()->json(['message' => 'Active subscription not found.'], 404);
        }

        try {
            $updated = $this->safepay->updateSubscription($subscription->sub_id, [
                'cancel_at_period_end' => true,
            ]);

            if ($updated) {
                $subscriptions->apply($user, $updated);
            } else {
                $subscription->update(['cancel_at_period_end' => true]);
            }

            return response()->json(['message' => 'Subscription will be cancelled at period end.']);
        } catch (SafepayException $e) {
            return response()->json([
                'message' => 'Failed to cancel subscription.',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Move an existing subscriber onto a different plan.
     *
     * Safepay subscriptions are bound to the plan they were created against —
     * there is no in-place swap. We therefore wind the current subscription
     * down at period end and return a checkout URL for the new plan.
     */
    public function changePlan(Request $request, $planId, SafepaySubscriptionService $subscriptions)
    {
        $plan = Plan::find($planId);

        if (!$plan || !$plan->is_active) {
            return response()->json(['message' => 'Plan not found'], 404);
        }

        if (!$plan->safepay_plan_id) {
            return response()->json([
                'message' => 'This plan has not been published to Safepay yet.',
            ], 422);
        }

        $user = Auth::user();
        $current = Subscription::where('user_id', $user->id)->latest()->first();

        if ($current && $current->type_id == $plan->id && $current->status === 'active') {
            return response()->json(['message' => 'You are already on this plan.'], 409);
        }

        try {
            // Stop the old subscription renewing before opening the new one, so
            // the customer is never billed for both.
            if ($current && $current->sub_id && in_array($current->status, ['active', 'trialing'], true)) {
                $updated = $this->safepay->updateSubscription($current->sub_id, [
                    'cancel_at_period_end' => true,
                ]);

                if ($updated) {
                    $subscriptions->apply($user, $updated);
                } else {
                    $current->update(['cancel_at_period_end' => true]);
                }
            }

            return response()->json($this->buildCheckout($user, $plan) + [
                'message' => 'Complete checkout to move onto the new plan.',
            ]);
        } catch (SafepayException $e) {
            Log::error('Safepay plan change failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to change plan.',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    // ------------------------------------------------------------- Internals

    /**
     * Record a checkout session and build its hosted Safepay URL.
     *
     * @return array{checkoutUrl:string,reference:string,hasTrial:bool}
     */
    private function buildCheckout($user, Plan $plan): array
    {
        $reference = (string) Str::uuid();

        SafepayCheckoutSession::create([
            'reference' => $reference,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'safepay_plan_id' => $plan->safepay_plan_id,
            'status' => 'pending',
        ]);

        $checkoutUrl = $this->safepay->subscriptionCheckoutUrl(
            $plan->safepay_plan_id,
            $reference,
            $this->redirectUrl($reference),
            $this->cancelUrl()
        );

        return [
            'checkoutUrl' => $checkoutUrl,
            'reference' => $reference,
            // Trials are configured on the Safepay plan, not per checkout.
            'hasTrial' => (int) $plan->trial_period_days > 0,
        ];
    }

    private function redirectUrl(string $reference): string
    {
        $base = config('safepay.redirect_url')
            ?: rtrim(config('app.frontend_url') ?: config('app.url'), '/') . '/dashboard/billing';

        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query([
            'safepay' => 'success',
            'reference' => $reference,
        ]);
    }

    private function cancelUrl(): string
    {
        $base = config('safepay.cancel_url')
            ?: rtrim(config('app.frontend_url') ?: config('app.url'), '/') . '/dashboard/billing';

        return $base . (str_contains($base, '?') ? '&' : '?') . 'safepay=cancelled';
    }

    /**
     * Best-effort lookup of the subscription a checkout session produced.
     *
     * Safepay never tells us the subscription token at redirect time, so we
     * take the newest subscription on that plan created since the session was
     * opened and not already claimed by another local subscription.
     */
    private function findSubscriptionForSession(SafepayCheckoutSession $session): ?array
    {
        $candidates = $this->safepay->searchSubscriptions();

        foreach ($candidates as $candidate) {
            if (($candidate['plan_id'] ?? null) !== $session->safepay_plan_id) {
                continue;
            }

            $createdAt = SafepayClient::timestamp($candidate['created_at'] ?? null);
            if (!$createdAt || $createdAt->lt($session->created_at)) {
                continue;
            }

            $token = $candidate['token'] ?? null;
            if (!$token || Subscription::where('sub_id', $token)->exists()) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
