<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\StripePlanService;
use Stripe\Exception\ApiErrorException;

class PlanSeeder extends Seeder
{
    /**
     * Three credit tiers, each offered monthly and yearly. Yearly = 25% off the
     * monthly price × 12, with the same daily credit allotment.
     *
     * Safe to re-run: an existing Stripe price is reused unless the amount
     * changed, in which case a new price is created and the old one retired.
     */
    public function run()
    {
        // Monthly base definitions per tier.
        $tiers = [
            [
                'tier' => 'starter',
                'name' => 'Starter',
                'monthly_price' => 10.00,
                'daily_credits' => 100,
                'is_popular' => false,
                'subdesc' => 'For getting started with daily content.',
                'features' => [
                    '100 credits per day',
                    'All video templates',
                    '1080p HD exports',
                    'Standard render queue',
                    'Email support',
                ],
            ],
            [
                'tier' => 'creator',
                'name' => 'Creator',
                'monthly_price' => 15.00,
                'daily_credits' => 300,
                'is_popular' => true,
                'subdesc' => 'For creators publishing every day.',
                'features' => [
                    '300 credits per day',
                    'All video templates',
                    '1080p HD exports',
                    'Priority render queue',
                    'Background music & captions',
                    'Priority email support',
                ],
            ],
            [
                'tier' => 'studio',
                'name' => 'Studio',
                'monthly_price' => 30.00,
                'daily_credits' => 1000,
                'is_popular' => false,
                'subdesc' => 'For teams and high-volume output.',
                'features' => [
                    '1000 credits per day',
                    'All video templates',
                    '1080p HD exports',
                    'Fastest render queue',
                    'Commercial usage rights',
                    'Dedicated support',
                ],
            ],
        ];

        $unpublished = [];
        $stripe = app(StripePlanService::class);
        $stripeEnabled = $stripe->enabled();
        $currency = strtoupper(config('stripe.currency', 'usd'));

        if (!$stripeEnabled) {
            $this->command->warn('STRIPE_SECRET not configured — seeding plans WITHOUT Stripe price ids.');
        }

        // Retire any legacy plans that predate the credit tiers so they don't
        // appear alongside the new ladder. (Existing subscribers keep their
        // Stripe subscription; only the catalog entry is hidden.)
        $retired = Plan::whereNull('tier')->update(['is_active' => false]);
        if ($retired) {
            $this->command->info("Deactivated {$retired} legacy plan(s) without a credit tier.");
        }

        foreach ($tiers as $tier) {
            $variants = [
                ['interval' => 'month', 'interval_count' => 1, 'price' => $tier['monthly_price']],
                // 25% saving vs paying monthly for a year.
                ['interval' => 'year', 'interval_count' => 1, 'price' => round($tier['monthly_price'] * 12 * 0.75, 2)],
            ];

            foreach ($variants as $variant) {
                $name = $tier['name'] . ' (' . ($variant['interval'] === 'year' ? 'Yearly' : 'Monthly') . ')';

                $plan = Plan::firstOrNew(['tier' => $tier['tier'], 'interval' => $variant['interval']]);
                $priceChanged = $plan->exists && (float) $plan->price !== (float) $variant['price'];

                $plan->fill([
                    'name' => $name,
                    'price' => $variant['price'],
                    'daily_credits' => $tier['daily_credits'],
                    'is_popular' => $tier['is_popular'],
                    'currency' => $currency,
                    'interval_count' => $variant['interval_count'],
                    'subdesc' => $tier['subdesc'],
                    'features' => $tier['features'],
                    'is_active' => true,
                ]);

                if ($stripeEnabled && (!$plan->stripe_price_id || $priceChanged)) {
                    try {
                        $oldPrice = $plan->stripe_price_id;
                        $payload = $plan->only(['name', 'price', 'currency', 'interval', 'interval_count', 'subdesc']);

                        if ($plan->stripe_product_id) {
                            $plan->stripe_price_id = $stripe->createPrice($plan->stripe_product_id, $payload);
                        } else {
                            $created = $stripe->createPlan($payload);
                            $plan->stripe_product_id = $created['product_id'];
                            $plan->stripe_price_id = $created['price_id'];
                        }

                        if ($oldPrice) {
                            $stripe->deactivatePrice($oldPrice);
                        }
                    } catch (ApiErrorException $e) {
                        $this->command->error("Stripe price creation failed for {$name}: " . $e->getMessage());
                    }
                }

                $plan->save();

                if ($plan->stripe_price_id) {
                    $this->command->info("Seeded plan: {$plan->name} ({$tier['daily_credits']} credits/day, {$currency} {$variant['price']}) -> {$plan->stripe_price_id}");
                } else {
                    $unpublished[] = $plan->name;
                    $this->command->warn("Seeded plan WITHOUT a Stripe price: {$plan->name} - nobody can subscribe to it yet.");
                }
            }
        }

        if ($unpublished) {
            $this->command->newLine();
            $this->command->error(
                count($unpublished) . ' plan(s) were not published to Stripe: ' . implode(', ', $unpublished)
            );
            $this->command->warn(
                'Checkout will reject these with a 422 until they have a stripe_price_id. '
                . 'Set STRIPE_SECRET and re-run this seeder - it back-fills the ids in place.'
            );
        }
    }
}
