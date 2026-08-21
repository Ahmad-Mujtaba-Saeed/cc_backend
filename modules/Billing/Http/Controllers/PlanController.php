<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Http\Requests\StorePlanRequest;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\Safepay\SafepayException;
use Modules\Billing\Services\SafepayPlanService;

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
            $data = $request->validated();

            // Publish the plan to Safepay first — a local row without a
            // safepay_plan_id cannot be subscribed to.
            $safepay = app(SafepayPlanService::class);
            $created = $safepay->createPlan($data);

            $plan = Plan::create([
                ...$data,
                'safepay_plan_id' => $created['plan_id'],
                'is_active' => true,
            ]);

            return response()->json($plan, 201);
        } catch (SafepayException $e) {
            Log::error('Safepay API Error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create plan in payment processor',
                'error' => $e->getMessage(),
            ], 502);
        } catch (\Exception $e) {
            Log::error('Plan Creation Error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create plan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a plan.
     *
     * Safepay plans are immutable in price and interval — only name,
     * description, trial length and availability can be changed in place. So a
     * billing change retires this plan and publishes a replacement, exactly as
     * the Stripe version created a new price.
     */
    public function update(Request $request, Plan $plan)
    {
        $data = $request->all();
        $safepay = app(SafepayPlanService::class);

        $billingFieldsChanged =
            (float) ($data['price'] ?? $plan->price) !== (float) $plan->price ||
            ($data['interval'] ?? $plan->interval) !== $plan->interval ||
            (int) ($data['interval_count'] ?? $plan->interval_count) !== (int) $plan->interval_count ||
            strtoupper($data['currency'] ?? $plan->currency) !== strtoupper($plan->currency);

        try {
            // CASE 1: metadata only — push it to the existing Safepay plan.
            if (!$billingFieldsChanged) {
                $plan->update($data);

                if ($plan->safepay_plan_id) {
                    $safepay->syncMetadata($plan->safepay_plan_id, $plan->only([
                        'name', 'subdesc', 'trial_period_days', 'is_active',
                    ]));
                }

                return response()->json($plan->fresh());
            }

            // CASE 2: billing change — new Safepay plan, archive the old one.
            $created = $safepay->createPlan(array_merge($plan->toArray(), $data));

            $newPlan = Plan::create([
                ...$plan->only([
                    'name', 'price', 'daily_credits', 'tier', 'is_popular', 'subdesc',
                    'currency', 'interval', 'interval_count', 'trial_period_days', 'features',
                ]),
                ...$data,
                'safepay_plan_id' => $created['plan_id'],
                'is_active' => true,
            ]);

            // Retire the old version: hidden locally, closed to new subscribers
            // on Safepay. Existing subscribers keep billing as before.
            $plan->update(['is_active' => false]);

            if ($plan->safepay_plan_id) {
                $safepay->archivePlan($plan->safepay_plan_id);
            }

            return response()->json([
                'message' => 'Plan updated with new pricing',
                'old_plan_id' => $plan->id,
                'new_plan' => $newPlan,
            ]);
        } catch (SafepayException $e) {
            Log::error('Safepay plan update failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update plan in payment processor',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    public function destroy(Plan $plan)
    {
        if ($plan->subscriptions()->exists()) {
            abort(409, 'Plan has active subscriptions');
        }

        $plan->update(['is_active' => false]);

        if ($plan->safepay_plan_id) {
            try {
                app(SafepayPlanService::class)->archivePlan($plan->safepay_plan_id);
            } catch (SafepayException $e) {
                // The local row is already hidden; surface the archive failure
                // without failing the request.
                Log::warning('Safepay plan archive failed: ' . $e->getMessage());
            }
        }
    }
}
