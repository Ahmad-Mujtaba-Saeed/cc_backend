<?php

namespace Modules\Billing\Services;

use Modules\Billing\Services\Safepay\SafepayClient;

/**
 * Keeps local `plans` rows in step with Safepay plan objects.
 *
 * Replaces the old StripePlanService. The shapes differ in two ways that
 * matter:
 *
 *  - Safepay has a single `plan` object (no product/price split), so one local
 *    plan maps to exactly one `safepay_plan_id`.
 *  - Amount and interval are immutable once a plan exists. Re-pricing means
 *    creating a new plan and archiving the old one, which is exactly what
 *    PlanController::update() does.
 */
class SafepayPlanService
{
    public function __construct(private SafepayClient $safepay)
    {
    }

    public function enabled(): bool
    {
        return $this->safepay->configured();
    }

    /**
     * Create the Safepay plan behind a local plan row.
     *
     * @param array{name:string,price:int|float|string,currency?:string,interval:string,interval_count?:int,subdesc?:string|null,trial_period_days?:int|null} $data
     *
     * @return array{plan_id:string}
     */
    public function createPlan(array $data): array
    {
        $currency = strtoupper($data['currency'] ?? $this->safepay->currency());

        $payload = [
            'name' => $data['name'],
            // Safepay expects the lowest denomination (cents / paisa).
            'amount' => (int) round(((float) $data['price']) * 100),
            'currency' => $currency,
            'interval' => self::interval($data['interval']),
            'interval_count' => (int) ($data['interval_count'] ?? 1),
            'product' => config('safepay.product', 'subscription'),
            'type' => 'RECURRING',
            'active' => true,
        ];

        if (!empty($data['subdesc'])) {
            $payload['description'] = (string) $data['subdesc'];
        }

        if (!empty($data['trial_period_days'])) {
            $payload['trial_period_days'] = (int) $data['trial_period_days'];
        }

        return ['plan_id' => $this->safepay->createPlan($payload)];
    }

    /**
     * Push the display-only fields of a plan (name, description, trial length,
     * availability) to Safepay. Price and interval cannot be changed here.
     */
    public function syncMetadata(string $planId, array $data): void
    {
        $payload = array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['subdesc'] ?? null,
            'product' => config('safepay.product', 'subscription'),
            'trial_period_days' => isset($data['trial_period_days']) ? (int) $data['trial_period_days'] : null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('is_active', $data)) {
            $payload['active'] = (bool) $data['is_active'];
        }

        if ($payload) {
            $this->safepay->updatePlan($planId, $payload);
        }
    }

    /** Archive the Safepay plan so it stops accepting new subscribers. */
    public function archivePlan(string $planId): void
    {
        $this->safepay->archivePlan($planId);
    }

    /**
     * Local plans store intervals the Stripe way ("month", "year"); Safepay
     * wants DAY | WEEK | MONTH | YEAR.
     */
    public static function interval(string $interval): string
    {
        return match (strtolower(rtrim($interval, 's'))) {
            'day', 'daily' => 'DAY',
            'week', 'weekly' => 'WEEK',
            'year', 'yearly', 'annual' => 'YEAR',
            default => 'MONTH',
        };
    }
}
