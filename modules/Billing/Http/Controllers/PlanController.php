<?php

namespace Modules\Billing\Http\Controllers;

use Modules\Billing\Models\Plan;
use Modules\Billing\Http\Requests\StorePlanRequest;
use Modules\Billing\Http\Requests\UpdatePlanRequest;
use Modules\Billing\Services\StripePlanService;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

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

    public function store(StorePlanRequest $request)
    {
        try {
            // Create Stripe product and price
            $stripeService = app(StripePlanService::class);
            $stripeData = $stripeService->createPlan($request->validated());

            // Create local plan record
            $plan = Plan::create([
                ...$request->validated(),
                'stripe_product_id' => $stripeData['product_id'] ?? null,
                'stripe_price_id'   => $stripeData['price_id'] ?? null,
                'is_active' => true, // Explicitly set default value
            ]);

            return response()->json($plan, 201);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('Stripe API Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create plan in payment processor',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Plan Creation Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $request->all();

        $billingFieldsChanged =
            $data['price'] !== $plan->price ||
            $data['interval'] !== $plan->interval ||
            $data['interval_count'] !== $plan->interval_count ||
            $data['currency'] !== $plan->currency;

        // CASE 1: Only metadata change
        if (! $billingFieldsChanged) {
            $plan->update($data);
            return response()->json($plan);
        }

        // CASE 2: Billing change → create new Stripe price
        $stripeService = app(StripePlanService::class);

        $newPrice = $stripeService->createPriceForExistingProduct(
            $plan->stripe_product_id,
            $data
        );

        // Deactivate old plan (soft)
        $plan->update([
            'is_active' => false,
        ]);

        // Create new plan version
        $newPlan = Plan::create([
            ...$data,
            'stripe_product_id' => $plan->stripe_product_id,
            'stripe_price_id' => $newPrice['price_id'],
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Plan updated with new pricing',
            'old_plan_id' => $plan->id,
            'new_plan' => $newPlan,
        ]);
    }


    public function destroy(Plan $plan)
    {
        if ($plan->subscriptions()->exists()) {
            abort(409, 'Plan has active subscriptions');
        }

        $plan->update(['is_active' => false]);
    }
}
