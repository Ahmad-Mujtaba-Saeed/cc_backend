<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\Safepay\SafepayClient;
use Modules\User\Models\User;

/**
 * Writes a Safepay subscription object into local `subscriptions` state.
 *
 * Two callers, one shape: the webhook handler (payload under `data`) and the
 * post-checkout reconcile endpoint (the API's subscription object). The two
 * differ only in a handful of key names, which `normalise()` smooths over.
 */
class SafepaySubscriptionService
{
    public function __construct(private CreditService $credits)
    {
    }

    /**
     * Upsert the local subscription row for `$user` from a Safepay payload and
     * re-sync their daily credits.
     */
    public function apply(User $user, array $payload): ?Subscription
    {
        $data = self::normalise($payload);

        if (!$data['token']) {
            return null;
        }

        $plan = $data['safepay_plan_id']
            ? Plan::where('safepay_plan_id', $data['safepay_plan_id'])->first()
            : null;

        $subscription = DB::transaction(function () use ($user, $data, $plan) {
            $attributes = [
                'name' => $plan->name ?? ($data['plan_name'] ?: 'Subscription'),
                'type' => 'membership',
                'cus_id' => $data['shopper_id'],
                'status' => $data['status'],
                'cancel_at_period_end' => $data['cancel_at_period_end'],
            ];

            // `type_id` is a NOT NULL FK to plans; only overwrite it when the
            // Safepay plan actually resolves to a local row.
            if ($plan) {
                $attributes['type_id'] = $plan->id;
            }

            foreach (['starts_at', 'ends_at', 'trial_ends_at'] as $field) {
                if ($data[$field] !== null) {
                    $attributes[$field] = $data[$field];
                }
            }

            // A terminal subscription ends now, not at the end of the period.
            if (in_array($data['status'], ['cancelled', 'ended'], true)) {
                $attributes['ends_at'] = $data['ended_at'] ?? now();
            }

            return Subscription::updateOrCreate(
                ['user_id' => $user->id, 'sub_id' => $data['token']],
                $attributes
            );
        });

        // Keep the Safepay shopper token on the user for future lookups.
        if ($data['shopper_id'] && $user->safepay_customer_id !== $data['shopper_id']) {
            $user->forceFill(['safepay_customer_id' => $data['shopper_id']])->save();
        }

        // Grant (or revoke) the daily allotment for the new state immediately.
        $this->credits->syncDailyGrant($user->fresh());

        return $subscription;
    }

    /**
     * Flatten either payload shape into one set of local column values.
     *
     * @return array{token:?string,safepay_plan_id:?string,plan_name:string,status:string,shopper_id:?string,starts_at:?\Carbon\CarbonImmutable,ends_at:?\Carbon\CarbonImmutable,trial_ends_at:?\Carbon\CarbonImmutable,ended_at:?\Carbon\CarbonImmutable,cancel_at_period_end:bool}
     */
    public static function normalise(array $p): array
    {
        return [
            // API calls it `token`, webhooks call it `id`.
            'token' => $p['token'] ?? $p['id'] ?? null,
            'safepay_plan_id' => $p['plan_id'] ?? ($p['plan']['token'] ?? null),
            'plan_name' => (string) ($p['plan']['name'] ?? ''),
            'status' => SafepayClient::normaliseStatus($p['status'] ?? null),
            'shopper_id' => $p['user_id'] ?? null,
            'starts_at' => SafepayClient::timestamp(
                $p['current_period_start_date'] ?? $p['start_date'] ?? $p['started_at'] ?? null
            ),
            'ends_at' => SafepayClient::timestamp(
                $p['current_period_end_date'] ?? $p['end_date'] ?? null
            ),
            'trial_ends_at' => SafepayClient::timestamp($p['trial_end_date'] ?? null),
            'ended_at' => SafepayClient::timestamp($p['canceled_at'] ?? $p['updated_at'] ?? null),
            'cancel_at_period_end' => (bool) ($p['cancel_at_period_end'] ?? false),
        ];
    }
}
