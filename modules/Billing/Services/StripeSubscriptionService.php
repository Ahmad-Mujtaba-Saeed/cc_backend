<?php

namespace Modules\Billing\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\User\Models\User;
use Stripe\Subscription as StripeSubscription;

/**
 * Writes a Stripe subscription object into local `subscriptions` state and
 * re-syncs the user's daily credits.
 *
 * Every caller funnels through apply(): the webhook, the post-checkout
 * confirm, cancel and plan change. So the local row always mirrors the last
 * subscription object Stripe handed us, whichever path delivered it.
 */
class StripeSubscriptionService
{
    public function __construct(private CreditService $credits)
    {
    }

    public function apply(User $user, StripeSubscription $sub): Subscription
    {
        // API 2025-03-31 ("basil") moved the billing period onto the item.
        $item = $sub->items->data[0] ?? null;
        $priceId = $item?->price?->id;
        $plan = $priceId ? Plan::where('stripe_price_id', $priceId)->first() : null;

        $status = self::status($sub->status);

        $attributes = [
            'name' => $plan->name ?? 'Subscription',
            'type' => 'membership',
            'cus_id' => is_string($sub->customer) ? $sub->customer : $sub->customer?->id,
            'status' => $status,
            'cancel_at_period_end' => (bool) $sub->cancel_at_period_end,
            'starts_at' => self::time($item->current_period_start ?? $sub->start_date ?? null),
            'ends_at' => self::time($item->current_period_end ?? null),
            'trial_ends_at' => self::time($sub->trial_end ?? null),
        ];

        // `type_id` is the plan FK; only set it when the price resolves to a
        // local plan (a price created by hand in the dashboard would not).
        if ($plan) {
            $attributes['type_id'] = $plan->id;
        }

        // A finished subscription ends when it ended, not at period end.
        if ($status === 'cancelled') {
            $attributes['ends_at'] = self::time($sub->ended_at ?? null) ?? now();
        }

        $previous = Subscription::with('plan')->where('user_id', $user->id)->where('sub_id', $sub->id)->first();

        $subscription = DB::transaction(fn () => Subscription::updateOrCreate(
            ['user_id' => $user->id, 'sub_id' => $sub->id],
            $attributes
        ));

        $changes = [];
        if ($attributes['cus_id'] && $user->stripe_customer_id !== $attributes['cus_id']) {
            $changes['stripe_customer_id'] = $attributes['cus_id'];
        }
        if ($sub->trial_end && !$user->trial_used) {
            $changes += ['trial_used' => true, 'trial_used_at' => now()];
        }
        if ($changes) {
            $user->forceFill($changes)->save();
        }

        // Grant (or revoke) today's allotment for the new state right away.
        $this->credits->syncDailyGrant($user->fresh());

        // A live plan change: top today's balance up by the bigger allotment.
        if ($plan && $previous?->plan && $previous->plan->id !== $plan->id && in_array($status, ['active', 'trialing'], true)) {
            $this->credits->topUpForPlanChange(
                $user->fresh(),
                (int) $previous->plan->daily_credits,
                (int) $plan->daily_credits,
                $plan->id
            );
        }

        return $subscription;
    }

    /**
     * Stripe's status vocabulary -> the app's. `User::activeSubscription()`
     * counts only "active" and "trialing".
     */
    public static function status(?string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'trialing' => 'trialing',
            'canceled', 'incomplete_expired' => 'cancelled',
            'past_due', 'unpaid' => 'past_due',
            'paused' => 'paused',
            default => 'incomplete',
        };
    }

    private static function time($unix): ?Carbon
    {
        return $unix ? Carbon::createFromTimestamp((int) $unix) : null;
    }
}
