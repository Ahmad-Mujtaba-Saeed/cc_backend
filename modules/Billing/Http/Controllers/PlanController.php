<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Http\Requests\StorePlanRequest;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\StripePlanService;
use Stripe\Exception\ApiErrorException;

class PlanController extends Controller
{
    public function activePlans()
    {
        return Plan::where('is_active', true)->get();
    }

    public function index()
    {
        return Plan::latest()->get();
    }

    public function store(StorePlanRequest $request, StripePlanService $stripe)
    {
        $data = $request->validated();

        try {
            // Publish to Stripe first: a local row without a price id cannot
            // be subscribed to.
            $created = $stripe->createPlan($data);

            $plan = Plan::create([
                ...$data,
                'stripe_product_id' => $created['product_id'],
                'stripe_price_id' => $created['price_id'],
                'is_active' => true,
            ]);

            return response()->json($plan, 201);
        } catch (ApiErrorException $e) {
            Log::error('Stripe plan creation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create plan in payment processor',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Update a plan.
     *
     * Stripe prices are immutable, so a billing change (price, currency,
     * interval) retires this plan row and publishes a replacement with a new
     * price on the same product. Existing subscribers stay on the old price
     * until they change plan.
     */
    public function update(Request $request, Plan $plan, StripePlanService $stripe)
    {
        $data = $request->all();

        $billingFieldsChanged =
            (float) ($data['price'] ?? $plan->price) !== (float) $plan->price ||
            ($data['interval'] ?? $plan->interval) !== $plan->interval ||
            (int) ($data['interval_count'] ?? $plan->interval_count) !== (int) $plan->interval_count ||
            strtoupper($data['currency'] ?? $plan->currency) !== strtoupper($plan->currency);

        try {
            // CASE 1: display fields only — update the plan and its product.
            if (!$billingFieldsChanged) {
                $plan->update($data);

                if ($plan->stripe_product_id) {
                    $stripe->syncProduct($plan->stripe_product_id, $plan->only(['name', 'subdesc']));
                }

                return response()->json($plan->fresh());
            }

            // CASE 2: billing change — new price (and plan row), old one retired.
            $merged = array_merge($plan->toArray(), $data);
            $productId = $plan->stripe_product_id;
            $priceId = $productId
                ? $stripe->createPrice($productId, $merged)
                : null;

            if (!$priceId) {
                $created = $stripe->createPlan($merged);
                [$productId, $priceId] = [$created['product_id'], $created['price_id']];
            }

            $newPlan = Plan::create([
                ...$plan->only([
                    'name', 'price', 'daily_credits', 'tier', 'is_popular', 'subdesc',
                    'currency', 'interval', 'interval_count', 'trial_period_days', 'features',
                ]),
                ...$data,
                'stripe_product_id' => $productId,
                'stripe_price_id' => $priceId,
                'is_active' => true,
            ]);

            $plan->update(['is_active' => false]);

            if ($plan->stripe_price_id) {
                $stripe->deactivatePrice($plan->stripe_price_id);
            }

            return response()->json([
                'message' => 'Plan updated with new pricing',
                'old_plan_id' => $plan->id,
                'new_plan' => $newPlan,
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe plan update failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update plan in payment processor',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    public function destroy(Plan $plan, StripePlanService $stripe)
    {
        if ($plan->subscriptions()->exists()) {
            abort(409, 'Plan has active subscriptions');
        }

        $plan->update(['is_active' => false]);

        if ($plan->stripe_price_id) {
            try {
                $stripe->deactivatePrice($plan->stripe_price_id);
            } catch (ApiErrorException $e) {
                // The local row is already hidden; don't fail the request.
                Log::warning('Stripe price deactivation failed: ' . $e->getMessage());
            }
        }
    }
}
