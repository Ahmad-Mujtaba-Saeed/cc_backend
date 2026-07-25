<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('trial_used_at');
            $table->string('lang', 10)->default('en')->after('bio');
            $table->string('time_zone', 50)->nullable()->after('lang');
            $table->boolean('email_notif')->default(true)->after('time_zone');
            $table->boolean('push_notif')->default(true)->after('email_notif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bio',
                'lang',
                'time_zone',
                'email_notif',
                'push_notif'
            ]);
        });
    }
};
