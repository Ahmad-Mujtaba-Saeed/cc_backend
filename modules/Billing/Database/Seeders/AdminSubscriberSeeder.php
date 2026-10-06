<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\AccessControl\Models\Role;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\CreditService;
use Modules\User\Models\User;

/**
 * Seeds an admin user with an ACTIVE subscription to the $20/month Studio plan,
 * then grants their daily credits.
 *
 * Depends on PlanSeeder (for the Studio monthly plan) and RolePermissionSeeder
 * (for the `admin` role). Re-runnable: uses updateOrCreate throughout.
 */
class AdminSubscriberSeeder extends Seeder
{
    public function run(CreditService $credits): void
    {
        // 1. The $20/month plan is the Studio tier billed monthly.
        $plan = Plan::where('tier', 'studio')
            ->where('interval', 'month')
            ->first();

        if (!$plan) {
            $this->command->error('Studio (Monthly) plan not found — run PlanSeeder first.');
            return;
        }

        // 2. Create / update the admin user.
        $admin = User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'phone' => '1234567890',
                'password' => Hash::make('AIProj@techtrack'),
                'email_verified_at' => now(),
            ]
        );

        // 3. Give them the admin role (if the role exists).
        $adminRole = Role::where('slug', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        } else {
            $this->command->warn('Admin role not found — skipping role assignment.');
        }

        // 4. Attach an active subscription to the Studio monthly plan.
        //    `type_id` is the FK the Subscription model uses for its plan relation.
        $subscription = Subscription::updateOrCreate(
            [
                'user_id' => $admin->id,
                'sub_id' => 'seed_admin_studio_monthly',
            ],
            [
                'name' => $plan->name,
                'type' => 'membership',
                'type_id' => $plan->id,
                'cus_id' => $admin->stripe_customer_id ?? 'seed_admin_customer',
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
                'trial_ends_at' => null,
                'cancel_at_period_end' => false,
            ]
        );

        // 5. Grant the daily credit allotment for the new subscriber.
        $credits->syncDailyGrant($admin->fresh());

        $this->command->info(
            "Admin admin@admin.com subscribed to {$plan->name} (\${$plan->price}/mo) "
            . "with {$plan->daily_credits} daily credits."
        );
    }
}
