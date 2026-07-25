<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Current spendable credit balance. Only subscribers ever hold > 0.
            $table->unsignedInteger('credits')->default(0)->after('stripe_customer_id');
            // The calendar day (Y-m-d) the daily allotment was last applied.
            $table->date('credits_refreshed_on')->nullable()->after('credits');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['credits', 'credits_refreshed_on']);
        });
    }
};
