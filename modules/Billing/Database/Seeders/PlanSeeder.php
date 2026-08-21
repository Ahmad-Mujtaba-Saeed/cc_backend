<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\Safepay\SafepayException;
use Modules\Billing\Services\SafepayPlanService;

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

        $safepayService = app(SafepayPlanService::class);
        $safepayEnabled = $safepayService->enabled();
        $currency = config('safepay.currency', 'USD');

        if (!$safepayEnabled) {
            $this->command->warn('SAFEPAY_SECRET_KEY not configured — seeding plans WITHOUT Safepay plan ids.');
        }

        // Retire any legacy plans that predate the credit tiers so they don't
        // appear alongside the new ladder. (Existing subscribers keep their
        // Safepay subscription; only the catalog entry is hidden.)
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

                $safepayPlanId = null;
                if ($safepayEnabled) {
                    try {
                        $safepayPlanId = $safepayService->createPlan([
                            'name' => $name,
                            'price' => $variant['price'],
                            'currency' => $currency,
                            'interval' => $variant['interval'],
                            'interval_count' => $variant['interval_count'],
                            'subdesc' => $tier['subdesc'],
                        ])['plan_id'];
                    } catch (SafepayException $e) {
                        $this->command->error("Safepay plan creation failed for {$name}: " . $e->getMessage());
                    }
                }

                $attributes = [
                    'name' => $name,
                    'price' => $variant['price'],
                    'daily_credits' => $tier['daily_credits'],
                    'is_popular' => $tier['is_popular'],
                    'currency' => $currency,
                    'interval_count' => $variant['interval_count'],
                    'subdesc' => $tier['subdesc'],
                    'features' => $tier['features'],
                    'is_active' => true,
                ];

                // Only (over)write the Safepay id when we actually created a
                // fresh plan, so re-running without keys preserves existing ids.
                if ($safepayPlanId) {
                    $attributes['safepay_plan_id'] = $safepayPlanId;
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
