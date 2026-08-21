<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the billing tables off Stripe and onto Safepay.
 *
 * Safepay has no product/price split — one plan object holds both — so the two
 * Stripe id columns collapse into a single `safepay_plan_id`. Trials live on
 * the plan in Safepay (not on the individual subscription), hence the new
 * `trial_period_days` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('safepay_plan_id')->nullable()->after('interval_count');
            // Safepay applies the trial at the plan level, per new subscriber.
            $table->unsignedInteger('trial_period_days')->default(0)->after('safepay_plan_id');

            $table->index('safepay_plan_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['stripe_product_id']);
            $table->dropIndex(['stripe_price_id']);
            $table->dropColumn(['stripe_product_id', 'stripe_price_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Now holds the Safepay shopper token ("user_...") seen on the
            // subscription object, populated by the webhook handler.
            $table->renameColumn('stripe_customer_id', 'safepay_customer_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            // Was an integer, which silently truncated the minor units of every
            // charge. Safepay reports amounts in the lowest denomination.
            $table->decimal('payment_amount', 12, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->integer('payment_amount')->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('safepay_customer_id', 'stripe_customer_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->index('stripe_product_id');
            $table->index('stripe_price_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['safepay_plan_id']);
            $table->dropColumn(['safepay_plan_id', 'trial_period_days']);
        });
    }
};
