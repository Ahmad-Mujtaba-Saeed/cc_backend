<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records every Stripe webhook event id we have already processed so a
     * redelivered event is never applied twice (duplicate payments / credits).
     */
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary(); // Stripe event id (evt_...)
            $table->string('type')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
