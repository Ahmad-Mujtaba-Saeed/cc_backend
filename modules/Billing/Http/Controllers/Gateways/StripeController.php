<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\CreditService;
use Modules\Project\Services\TemplateSettingsService;

class StripeController extends Controller
{
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

        if (!$subscription || !$subscription->type_id) {
            return response()->json(['message' => 'Active subscription not found.'], 404);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            // $subscriptionStripe = \Stripe\Subscription::retrieve([
            //     'id' => $subscription->sub_id,
            //     'expand' => []
            // ]);

            return response()->json([
                'subscription' => $subscription,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve subscription details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


public function createSubscriptionSession(Request $request, $planId)
{
    $plan = Plan::find($planId);
    if (!$plan || $plan->is_active == 0) {
        return response()->json([
            'error' => 'Plan not found or inactive plan',
        ], 400);
    }

    Stripe::setApiKey(config('services.stripe.secret'));
    
    $user = Auth::user();
    $customerId = $user->stripe_customer_id;
    
    // Create Stripe customer if doesn't exist
    if (!$customerId) {
        try {
            $customer = \Stripe\Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'phone' => $user->phone,
                'metadata' => [
                    'user_id' => $user->id
                ]
            ]);
            $customerId = $customer->id;
            $user->stripe_customer_id = $customerId;
            $user->save();
        } catch (\Exception $e) {
            \Log::error('Failed to create Stripe customer', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'error' => 'Failed to create customer',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Check if customer already has any subscriptions
    $hasExistingSubscription = false;
    $hasUsedTrial = false;

    try {
        // Get customer's subscriptions
        $subscriptions = \Stripe\Subscription::all([
            'customer' => $customerId,
            'status' => 'all', // Include past subscriptions
            'limit' => 10
        ]);

        foreach ($subscriptions->data as $subscription) {
            // If customer has any active or past due subscription, they've used the service
            if (in_array($subscription->status, ['active', 'past_due', 'canceled', 'incomplete'])) {
                $hasExistingSubscription = true;
                
                // Check if this subscription had a trial
                if ($subscription->trial_start !== null) {
                    $hasUsedTrial = true;
                    break;
                }
            }
        }

        // Alternative: Check if customer has any payment methods (indicates they've paid before)
        $paymentMethods = \Stripe\PaymentMethod::all([
            'customer' => $customerId,
            'type' => 'card',
        ]);
        
        $hasPaymentMethods = count($paymentMethods->data) > 0;

    } catch (\Exception $e) {
        \Log::error('Error checking customer subscription history', [
            'customer_id' => $customerId,
            'error' => $e->getMessage()
        ]);
    }

    // Determine if free trial should be offered
    $shouldOfferTrial = $request->isFreeTrial && 
                       !$hasUsedTrial && 
                       !$hasExistingSubscription && 
                       !$hasPaymentMethods;

    $subscriptionData = [
        'metadata' => [
            'type' => 'subscription',
            'user_id' => Auth::id() ?? null,
        ],
    ];

    // Only add trial if eligible
    if ($shouldOfferTrial) {
        $subscriptionData['trial_period_days'] = 7;
    }

    $session = Session::create([
        'payment_method_types' => ['card'],
        'mode' => 'subscription',
        'customer' => $customerId,
        'line_items' => [[
            'price' => $plan->stripe_price_id,
            'quantity' => 1,
        ]],
        'allow_promotion_codes' => true,
        'subscription_data' => $subscriptionData,
        'success_url' => 'https://slateblue-snake-907020.hostingersite.com/welcome?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => 'https://slateblue-snake-907020.hostingersite.com/',
    ]);

    return response()->json([
        'sessionId' => $session->id,
        'checkoutUrl' => $session->url,
        'hasTrial' => $shouldOfferTrial,
    ]);
}

    public function cancelSubscription(Request $request)
    {
    $user = Auth::user();

    $subscription = Subscription::where('user_id', $user->id)
        ->latest()
        ->first();

    if (!$subscription || !$subscription->sub_id) {
        return response()->json(['message' => 'Active subscription not found.'], 404);
    }

        Stripe::setApiKey(config('services.stripe.secret'));

    try {
        \Stripe\Subscription::update(
            $subscription->sub_id,
            ['cancel_at_period_end' => true]
        );

        $subscriptionStripe =  \Stripe\Subscription::retrieve([
            'id' => $subscription->sub_id,
            'expand' => []
        ]);

        $subscription->update([
            'ends_at' => \Carbon\Carbon::createFromTimestamp($subscriptionStripe->cancel_at),
            'cancel_at_period_end' => 1,
        ]);


        return response()->json(['message' => 'Subscription will be cancelled at period end.']);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Failed to cancel subscription.',
            'error' => $e->getMessage()
        ], 500);
    }
    }

    public function getPaymentMethod(Request $request)
    {
        $user = Auth::user();

        $subscription = Subscription::where('user_id', $user->id)
            ->latest()
            ->first();

        if (!$subscription || !$subscription->cus_id) {
            return response()->json(['message' => 'Active subscription not found.'], 404);
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        
        try {
            $customer = \Stripe\Customer::retrieve($subscription->cus_id);
            
            // Get default payment method ID
            $defaultPaymentMethodId = $customer->invoice_settings->default_payment_method;

            // return $defaultPaymentMethodId;
            $paymentMethods = \Stripe\PaymentMethod::all([
                'customer' => $subscription->cus_id,
                'type' => 'card',
            ]);            
            foreach ($paymentMethods->data as $pm) {
                $isDefault = ($pm->id == $defaultPaymentMethodId);
                $pm->default = $isDefault;
            }
            return response()->json($paymentMethods);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve payment method.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deletePaymentMethod(Request $request, $id)
    {
        $user = Auth::user();

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($id);
            $paymentMethod->detach();

            return response()->json(['message' => 'Payment method deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete payment method.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createSetupIntent($customerId)
    {
    Stripe::setApiKey(config('services.stripe.secret'));

    $intent = \Stripe\SetupIntent::create([
        'customer' => $customerId,
        'payment_method_types' => ['card'],
    ]);

    return response()->json([
        'clientSecret' => $intent->client_secret,
    ]);
   }

   public function makeDefaultPaymentMethod(Request $request, $customerId)
   {

    Stripe::setApiKey(config('services.stripe.secret'));

    \Stripe\Customer::update($customerId, [
        'invoice_settings' => [
            'default_payment_method' => $request->payment_method_id,
        ],
    ]);   

    return response()->json(['message' => 'Payment method updated successfully.']);
    }

    public function changePlan(Request $request, $planId)
    {
        $plan = Plan::find($planId);
        if(!$plan){
            return response()->json([
                'message' => 'Plan not found',
            ], 404);
        }
        $user = Auth::user();
        $subscription = Subscription::where('user_id', $user->id)
            ->latest()
            ->first();
            
        if(!$subscription){
            return response()->json([
                'message' => 'Subscription not found',
            ], 404);
        }
        
        $subscriptionId = $subscription->sub_id;

        Stripe::setApiKey(config('services.stripe.secret'));
        // Retrieve the current subscription
        $subscription = \Stripe\Subscription::retrieve($subscriptionId);
    
        // Update the subscription with new plan
        $updated = \Stripe\Subscription::update($subscriptionId, [
            'items' => [[
                'id' => $subscription->items->data[0]->id, // keep same item
                'price' => $plan->stripe_price_id, // new plan price ID
            ]],
            'proration_behavior' => 'create_prorations', // immediate adjustment
        ]);
    
        return $updated;
    }

    public function getStripeCredit()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    
        $user = Auth::user();
        if (!$user->stripe_customer_id) {
            return response()->json(['credit' => 0]);
        }
    
        $customer =  \Stripe\Customer::retrieve($user->stripe_customer_id);
    
        // Stripe stores balance in CENTS & NEGATIVE means customer has CREDIT
        $credit = $customer->balance < 0 ? abs($customer->balance) / 100 : 0;
    
        return response()->json([
            'credit' => $credit,
        ]);
    }
}