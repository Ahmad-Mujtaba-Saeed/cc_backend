<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves billing back from Safepay to Stripe (reverses 2026_08_21_000001-3).
 *
 * Stripe splits a plan into a product and a price, so the single
 * `safepay_plan_id` goes back to the two Stripe ids. `trial_period_days`
 * stays: Stripe applies it per checkout, and it is still a plan setting.
 * Safepay never went live (no checkout sessions, events or payments were
 * recorded), so its tables are dropped rather than migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_product_id')->nullable()->after('interval_count');
            $table->string('stripe_price_id')->nullable()->after('stripe_product_id');
            $table->index('stripe_product_id');
            $table->index('stripe_price_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['safepay_plan_id']);
            $table->dropColumn('safepay_plan_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('safepay_customer_id', 'stripe_customer_id');
        });

        // Webhook idempotency, keyed on Stripe's evt_ id.
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::dropIfExists('safepay_checkout_sessions');
        Schema::dropIfExists('safepay_events');
    }

    public function down(): void
    {
        Schema::create('safepay_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('safepay_checkout_sessions', function (Blueprint $table) {
            $table->string('reference')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('safepay_plan_id');
            $table->string('status')->default('pending');
            $table->string('subscription_token')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['safepay_plan_id', 'status']);
        });

        Schema::dropIfExists('stripe_events');

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('stripe_customer_id', 'safepay_customer_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('safepay_plan_id')->nullable()->after('interval_count');
            $table->index('safepay_plan_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['stripe_product_id']);
            $table->dropIndex(['stripe_price_id']);
            $table->dropColumn(['stripe_product_id', 'stripe_price_id']);
        });
    }
};
