<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\StripePlanService;

class PlanSeeder extends Seeder
{
    /**
     * Three credit tiers, each offered monthly and yearly. Yearly = 25% off the
     * monthly price × 12, with the same daily credit allotment.
     */
    public function run()
    {
        // Monthly base definitions per tier.
        $tiers = [
            [
                'tier' => 'starter',
                'name' => 'Starter',
                'monthly_price' => 5.00,
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
                'monthly_price' => 10.00,
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
                'monthly_price' => 20.00,
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

        $stripeEnabled = !empty(config('services.stripe.secret'));
        $stripeService = $stripeEnabled ? app(StripePlanService::class) : null;

        if (!$stripeEnabled) {
            $this->command->warn('STRIPE secret not configured — seeding plans WITHOUT Stripe price ids.');
        }

        // Retire any legacy plans that predate the credit tiers so they don't
        // appear alongside the new ladder. (Existing subscribers keep their
        // Stripe subscription; only the catalog entry is hidden.)
        $retired = Plan::whereNull('tier')->update(['is_active' => false]);
        if ($retired) {
            $this->command->info("Deactivated {$retired} legacy plan(s) without a credit tier.");
        }

        foreach ($tiers as $tier) {
            // Build the monthly + yearly variant for this tier.
            $variants = [
                [
                    'interval' => 'month',
                    'interval_count' => 1,
                    'price' => $tier['monthly_price'],
                ],
                [
                    'interval' => 'year',
                    'interval_count' => 1,
                    // 25% saving vs paying monthly for a year.
                    'price' => round($tier['monthly_price'] * 12 * 0.75, 2),
                ],
            ];

            foreach ($variants as $variant) {
                $name = $tier['name'] . ' (' . ($variant['interval'] === 'year' ? 'Yearly' : 'Monthly') . ')';

                $stripeData = ['product_id' => null, 'price_id' => null];
                if ($stripeEnabled) {
                    try {
                        $stripeData = $stripeService->createPlan([
                            'name' => $name,
                            'price' => $variant['price'],
                            'currency' => 'USD',
                            'interval' => $variant['interval'],
                            'interval_count' => $variant['interval_count'],
                        ]);
                    } catch (\Exception $e) {
                        $this->command->error("Stripe price creation failed for {$name}: " . $e->getMessage());
                    }
                }

                $attributes = [
                    'name' => $name,
                    'price' => $variant['price'],
                    'daily_credits' => $tier['daily_credits'],
                    'is_popular' => $tier['is_popular'],
                    'currency' => 'USD',
                    'interval_count' => $variant['interval_count'],
                    'subdesc' => $tier['subdesc'],
                    'features' => $tier['features'],
                    'is_active' => true,
                ];

                // Only (over)write Stripe ids when we actually created fresh ones,
                // so re-running without Stripe keys preserves existing ids.
                if (!empty($stripeData['price_id'])) {
                    $attributes['stripe_product_id'] = $stripeData['product_id'] ?? null;
                    $attributes['stripe_price_id'] = $stripeData['price_id'];
                }

                $plan = Plan::updateOrCreate(
                    [
                        'tier' => $tier['tier'],
                        'interval' => $variant['interval'],
                    ],
                    $attributes
                );

                $this->command->info("Seeded plan: {$plan->name} ({$tier['daily_credits']} credits/day, \${$variant['price']})");
            }
        }
    }
}
