<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Daily credit allotment granted to active subscribers (no rollover).
            $table->unsignedInteger('daily_credits')->default(0)->after('price');
            // Logical tier key shared by the monthly + yearly variant of a plan.
            $table->string('tier')->nullable()->after('daily_credits');
            // Highlight a plan as "most popular" in the pricing UI.
            $table->boolean('is_popular')->default(false)->after('tier');

            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['tier']);
            $table->dropColumn(['daily_credits', 'tier', 'is_popular']);
        });
    }
};
