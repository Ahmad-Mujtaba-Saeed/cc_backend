<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safepay subscriptions are created by the customer inside hosted checkout,
 * not by an API call from us, so we never learn the subscription token at
 * redirect time. The only thread back to a local user is the `reference` we
 * put on the checkout URL, which Safepay echoes on the subscription webhooks.
 *
 * This table is that thread: one row per checkout redirect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safepay_checkout_sessions', function (Blueprint $table) {
            // The `reference` sent to Safepay Checkout (a UUID we generate).
            $table->string('reference')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('safepay_plan_id');
            // pending | completed | cancelled
            $table->string('status')->default('pending');
            // Filled in once the subscription.created webhook arrives.
            $table->string('subscription_token')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['safepay_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safepay_checkout_sessions');
    }
};
