<?php

namespace Modules\Billing\Http\Controllers\Gateways;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\CreditService;
// use App\Mail\SubscriptionWelcomeMail;
// use App\Mail\SubscriptionCancelledMail;
// use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiErrorException;
use Exception;


class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = env('STRIPE_WEBHOOK_SECRET');
    
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Exception $e) {
            Log::error('Stripe webhook signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Idempotency: claim this event id. If it was already claimed, a previous
        // delivery handled it (or is handling it) — never apply it twice.
        $claimed = DB::table('stripe_events')->insertOrIgnore([
            'id' => $event->id,
            'type' => $event->type,
            'created_at' => now(),
        ]);
        if ($claimed === 0) {
            Log::info('Stripe webhook: duplicate event ignored', ['event_id' => $event->id]);
            return response()->json(['status' => 'duplicate']);
        }


        try {
            switch ($event->type) {

                // case 'invoice.payment_succeeded' :
                //     $invoice = $event->data->object;

                    
                //     $email = $invoice->customer_email;
                    
                //     $user = User::where('email', $email)->first();
                    
                //     $price_id = $invoice->lines->data[0]->pricing->price_details->price;
                    

                //     $plan = Plan::where('stripe_price_id', $price_id)->first();

                //     $payment = Payment::create([
                //         'user_id' => $user->id,
                //         'related_type' => 'membership',
                //         'related_type_id' => $plan->id,
                //         'payment_amount' => $invoice->total / 100,  // Convert from cents to dollars
                //         'payment_transaction_id' => $invoice->id,
                //         'payment_gateway' => 'stripe',
                //         'payment_status' => $invoice->status,
                //         'payment_currency' => strtoupper($invoice->currency), // Ensure uppercase currency code
                //     ]);

                //     break;


                case 'customer.subscription.created' :
                    $subscription = $event->data->object;
                    $customerId = $subscription->customer;

                    $user = $this->resolveUser($customerId, $subscription->metadata ?? null);
                    if (!$user) {
                        throw new \Exception("User not found for customer {$customerId}");
                    }

                    // Keep the customer id mapped for robust future resolution.
                    if (empty($user->stripe_customer_id)) {
                        $user->stripe_customer_id = $customerId;
                        $user->save();
                    }


                    \DB::beginTransaction();
    
                // Get the first subscription item (since there's only one)
                $subscriptionItem = $subscription->items->data[0];
                $plan = $subscriptionItem->plan;
                $price = $subscriptionItem->price;
                
                $plan = Plan::where('stripe_price_id', $price->id)->first();


                $invoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);

                $payment = Payment::create([
                    'user_id' => $user->id,
                    'related_type' => 'membership',
                    'related_type_id' => $plan->id,
                    'payment_amount' => $invoice->amount_paid / 100,  // Use actual amount paid from invoice
                    'payment_transaction_id' => $subscription->latest_invoice,
                    'payment_gateway' => 'stripe',
                    'payment_status' => $invoice->status,
                    'payment_currency' => strtoupper($invoice->currency), // Use currency from invoice
                ]);
                
                // Handle trial end date (can be null if no trial)
                $trialEndsAt = $subscription->trial_end 
                    ? \Carbon\Carbon::createFromTimestamp($subscription->trial_end)
                    : null;
                
                    
                 
                $subscriptionEndsAt = $subscriptionItem->current_period_end 
                    ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_end)
                    : null;
                    
               
                $subscriptionStartsAt = $subscriptionItem->current_period_start 
                    ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_start)
                    : null;
                
                
                $subscriptionModel = Subscription::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'sub_id'  => $subscription->id,
                        ],
                        [
                            'name'          => $plan->name,
                            'type'          => 'membership',
                            'type_id'       => $plan->id,
                            'payment_id'    => $payment->id,
                            'cus_id'        => $customerId,
                            'trial_ends_at' => $trialEndsAt,
                            'starts_at'     => $subscriptionStartsAt,
                            'ends_at'       => $subscriptionEndsAt,
                            'status'        => $subscription->status,
                        ]
                    );

                
                    // Mark in user's record that they've used trial
                    $user->trial_used = true;
                    $user->trial_used_at = now();
                    $user->save();

                    // Send welcome email
                    // try {
                    //     Mail::to($user->email)->send(new SubscriptionWelcomeMail($user, $plan , $subscriptionEndsAt , $subscriptionStartsAt));
                    // } catch (\Exception $e) {
                    //     return response()->json(['error' => 'Failed to send welcome email ' . $e->getMessage()], 500);
                    //     // Log the error but don't fail the webhook
                    //     Log::error('Failed to send welcome email: ' . $e->getMessage());
                    // }

                    \DB::commit();

                    // Grant the first day's credits immediately so the new
                    // subscriber can render right away.
                    app(CreditService::class)->syncDailyGrant($user->fresh());

                break;
                
                case 'customer.subscription.updated':
                    $subscription = $event->data->object;
                    $customerId = $subscription->customer;

                    $user = $this->resolveUser($customerId, $subscription->metadata ?? null);
                    if (!$user) {
                        throw new \Exception("User not found for customer {$customerId}");
                    }


                    \DB::beginTransaction();
    
                // Get the first subscription item (since there's only one)
                $subscriptionItem = $subscription->items->data[0];
                $plan = $subscriptionItem->plan;
                $price = $subscriptionItem->price;

                $plan = Plan::where('stripe_price_id', $price->id)->first();

                $invoice = \Stripe\Invoice::retrieve($subscription->latest_invoice);

                
                $payment = Payment::create([
                    'user_id' => $user->id,
                    'related_type' => 'membership',
                    'related_type_id' => $plan->id,
                    'payment_amount' => $invoice->amount_paid / 100,  // Use actual amount paid from invoice
                    'payment_transaction_id' => $subscription->latest_invoice,
                    'payment_gateway' => 'stripe',
                    'payment_status' => $invoice->status,
                    'payment_currency' => strtoupper($invoice->currency), // Use currency from invoice
                ]);
                
                // Handle trial end date (can be null if no trial)
                $trialEndsAt = $subscription->trial_end 
                ? \Carbon\Carbon::createFromTimestamp($subscription->trial_end)
                : null;
                
                
                
                $subscriptionEndsAt = $subscriptionItem->current_period_end 
                ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_end)
                : null;
                
                $subscriptionStartsAt = $subscriptionItem->current_period_start 
                    ? \Carbon\Carbon::createFromTimestamp($subscriptionItem->current_period_start)
                    : null;
                
                
                    
                    Subscription::updateOrCreate([
                        'user_id' => $user->id,
                        'sub_id' => $subscription->id,
                    ],[
                        'name' => $plan->name,
                    'type' => 'membership',
                    'type_id' => $plan->id,
                    'payment_id' => $payment->id,
                    'cus_id' => $customerId,
                    'trial_ends_at' => $trialEndsAt,
                    'ends_at' => $subscriptionEndsAt,
                    'starts_at' => $subscriptionStartsAt,
                    'status' => $subscription->status
                ]);
                
    
    

                    $user->save();
                    \DB::commit();

                    // Re-sync credits (e.g. plan change → different daily allotment).
                    app(CreditService::class)->syncDailyGrant($user->fresh());

                    break;



                case 'checkout.session.completed' :
                    $session = $event->data->object;
                    $customer_email = $session->customer_email;
    
                    $user = User::where('email', $customer_email)->first();
                    if (!$user) {
                        Log::warning("Stripe webhook: User not found with email {$customer_email}");
                        return response()->json(['error' => 'User not found'], 404);
                    }
    
                    if (isset($session->metadata->type) && $session->metadata->type == 'ticket') {
                        $eventId = $session->metadata->event_id;
                        $ticketTypeId = $session->metadata->ticket_type_id;
    
                    }
                    
                    break;
    
                
                    case 'customer.subscription.deleted':
                        $session = $event->data->object;
                    
                        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
                        $stripeCustomer = \Stripe\Customer::retrieve($session->customer);
                        $customer_email = $stripeCustomer->email ?? null;
                    
                        if (!$customer_email) {
                            Log::warning("Stripe webhook: Email not found for customer ID {$session->customer}");
                            return response()->json(['error' => 'Email not found'], 404);
                        }
                    
                        $user = User::where('email', $customer_email)->first();
                        if (!$user) {
                            Log::warning("Stripe webhook: User not found with email {$customer_email}");
                            return response()->json(['error' => 'User not found'], 404);
                        }
                    
                        // Mark subscription as cancelled in your DB
                        $localSubscription = Subscription::where('user_id', $user->id)
                            ->where('sub_id', $session->id)
                            ->latest()
                            ->first();


                        if ($localSubscription) {
                            $localSubscription->status = 'cancelled';
                            $localSubscription->ends_at = now();
                            $localSubscription->save();
                            
                            // Get the plan details
                            $plan = Plan::find($localSubscription->type_id);
                            
                            // Send cancellation email
                            // try {
                            //     Mail::to($user->email)->send(new SubscriptionCancelledMail(
                            //         $user, 
                            //         $plan ?? null, 
                            //         now()
                            //     ));
                            // } catch (\Exception $e) {
                            //     // Log the error but don't fail the webhook
                            //     Log::error('Failed to send cancellation email: ' . $e->getMessage());
                            // }
                        }
                    
                        break;
                    
    
                default:
                    Log::info('Stripe webhook: Unhandled event type ' . $event->type);
                    break;
            }
        } catch (\Exception $ex) {
            \DB::rollBack();
            // Release the idempotency claim so Stripe's retry can reprocess.
            DB::table('stripe_events')->where('id', $event->id)->delete();
            Log::error('Stripe webhook error: ' . $ex->getMessage());
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Resolve the local user for a Stripe event, robustly:
     *   1. by stored stripe_customer_id (set at checkout),
     *   2. by subscription metadata.user_id (set when creating the session),
     *   3. by the Stripe customer's email (last resort).
     */
    private function resolveUser(?string $customerId, $metadata = null): ?User
    {
        if ($customerId) {
            $user = User::where('stripe_customer_id', $customerId)->first();
            if ($user) {
                return $user;
            }
        }

        $userId = is_object($metadata) ? ($metadata->user_id ?? null) : ($metadata['user_id'] ?? null);
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                return $user;
            }
        }

        if ($customerId) {
            try {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                $customer = \Stripe\Customer::retrieve($customerId, []);
                if (!empty($customer->email)) {
                    return User::where('email', $customer->email)->first();
                }
            } catch (\Exception $e) {
                Log::warning('Stripe webhook: customer lookup failed: ' . $e->getMessage());
            }
        }

        return null;
    }
}
