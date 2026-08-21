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
            'name' => self::safeName($data['name']),
            // Safepay expects the lowest denomination (cents / paisa).
            'amount' => (int) round(((float) $data['price']) * 100),
            'currency' => $currency,
            'interval' => self::interval($data['interval']),
            'interval_count' => (int) ($data['interval_count'] ?? 1),
            'product' => self::safeProduct(config('safepay.product', 'subscription')),
            'type' => 'RECURRING',
            'active' => true,
        ];

        if (!empty($data['subdesc'])) {
            $payload['description'] = self::safeName($data['subdesc'], 255);
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
            'name' => isset($data['name']) ? self::safeName($data['name']) : null,
            'description' => isset($data['subdesc']) ? self::safeName($data['subdesc'], 255) : null,
            'product' => self::safeProduct(config('safepay.product', 'subscription')),
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
     * Strip a display string down to what Safepay's plan validator accepts.
     *
     * Safepay rejects anything else with `name: must be in a valid format` —
     * our "Starter (Monthly)" failed on the brackets. The format itself is
     * undocumented, so this allows only what their API reference demonstrates:
     * letters, digits, spaces and underscores ("new plan name", "pk_102").
     *
     * This affects ONLY the name shown on Safepay's checkout page and merchant
     * dashboard. The local `plans.name` keeps its original punctuation and is
     * what the app's own UI renders.
     */
    public static function safeName(string $value, int $max = 64): string
    {
        // Turn separators into spaces first so "Starter (Monthly)" reads as
        // "Starter Monthly" rather than "Starter Monthly" losing the gap.
        $clean = preg_replace('/[^A-Za-z0-9 _]+/u', ' ', $value);
        $clean = trim(preg_replace('/\s+/', ' ', (string) $clean));

        if ($clean === '') {
            $clean = 'Plan';
        }

        return mb_substr($clean, 0, $max);
    }

    /**
     * The `product` field is a grouping label under the same validator. Their
     * examples ("bananas", "pk_102") show no spaces, so keep it a slug.
     */
    public static function safeProduct(string $value, int $max = 64): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]+/u', '_', $value);
        $clean = trim((string) preg_replace('/_+/', '_', $clean), '_');

        return mb_substr($clean !== '' ? $clean : 'subscription', 0, $max);
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
