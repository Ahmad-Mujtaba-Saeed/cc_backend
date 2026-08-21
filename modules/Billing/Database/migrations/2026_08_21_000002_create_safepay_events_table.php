<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records every Safepay webhook event token we have already processed so a
 * redelivery is never applied twice (duplicate payments / credit grants).
 *
 * Safepay retries any event it does not get a 2xx for, so this claim table is
 * load-bearing, not an optimisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safepay_events', function (Blueprint $table) {
            $table->string('id')->primary(); // Safepay event token (evt_...)
            $table->string('type')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // The Stripe equivalent is dead once the gateway is switched.
        Schema::dropIfExists('stripe_events');
    }

    public function down(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::dropIfExists('safepay_events');
    }
};
