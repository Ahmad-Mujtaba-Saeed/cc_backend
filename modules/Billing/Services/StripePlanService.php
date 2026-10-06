<?php

namespace Modules\Billing\Services;

use Stripe\StripeClient;

/**
 * Keeps local `plans` rows in step with Stripe products and prices.
 *
 * One local plan = one Stripe product + one recurring price. Stripe prices are
 * immutable (amount, currency, interval), so a billing change creates a new
 * price and deactivates the old one; existing subscribers stay on the old
 * price until they change plan.
 */
class StripePlanService
{
    public function __construct(private StripeClient $stripe)
    {
    }

    public function enabled(): bool
    {
        return (string) config('stripe.secret') !== '';
    }

    /**
     * Create the Stripe product + price behind a local plan row.
     *
     * @param array{name:string,price:int|float|string,currency?:string,interval:string,interval_count?:int,subdesc?:string|null} $data
     *
     * @return array{product_id:string,price_id:string}
     */
    public function createPlan(array $data): array
    {
        $product = $this->stripe->products->create(array_filter([
            'name' => $data['name'],
            'description' => $data['subdesc'] ?? null,
        ]));

        return [
            'product_id' => $product->id,
            'price_id' => $this->createPrice($product->id, $data),
        ];
    }

    /** A new recurring price on an existing product (for a billing change). */
    public function createPrice(string $productId, array $data): string
    {
        return $this->stripe->prices->create([
            'product' => $productId,
            // Stripe wants the smallest currency unit (cents).
            'unit_amount' => (int) round(((float) $data['price']) * 100),
            'currency' => strtolower($data['currency'] ?? config('stripe.currency', 'usd')),
            'recurring' => [
                'interval' => self::interval($data['interval']),
                'interval_count' => (int) ($data['interval_count'] ?? 1),
            ],
        ])->id;
    }

    /** Push display fields (name, description, availability) to the product. */
    public function syncProduct(string $productId, array $data): void
    {
        $payload = array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['subdesc'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (array_key_exists('is_active', $data)) {
            $payload['active'] = (bool) $data['is_active'];
        }

        if ($payload) {
            $this->stripe->products->update($productId, $payload);
        }
    }

    /** Stop a price accepting new subscribers. Existing ones keep billing. */
    public function deactivatePrice(string $priceId): void
    {
        $this->stripe->prices->update($priceId, ['active' => false]);
    }

    /** Local intervals are "month"/"year"; tolerate a few other spellings. */
    public static function interval(string $interval): string
    {
        return match (strtolower(rtrim($interval, 's'))) {
            'day', 'daily' => 'day',
            'week', 'weekly' => 'week',
            'year', 'yearly', 'annual' => 'year',
            default => 'month',
        };
    }
}
