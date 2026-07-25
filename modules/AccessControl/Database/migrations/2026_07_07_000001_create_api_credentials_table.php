<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed API key pool for external providers (rapidapi, apify).
 * Multiple keys per provider; downloads try the default key first and fail
 * over to the next active key when one errors. Keys live ONLY here — the
 * services no longer read RAPIDAPI_KEY / APIFY_TOKEN from .env at runtime.
 *
 * Any key already present in .env at migration time is imported once as the
 * provider's default so existing installs keep working without manual setup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index(); // rapidapi | apify
            $table->string('label')->nullable();
            $table->text('credential');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });

        // One-time import of the env-configured keys so the switch to
        // DB-managed credentials is seamless.
        $imports = [
            ['provider' => 'rapidapi', 'credential' => (string) config('services.rapidapi.key')],
            ['provider' => 'apify', 'credential' => (string) config('services.apify.token')],
        ];

        foreach ($imports as $import) {
            if ($import['credential'] === '') {
                continue;
            }

            DB::table('api_credentials')->insert([
                'provider' => $import['provider'],
                'label' => 'Imported from .env',
                'credential' => $import['credential'],
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_credentials');
    }
};
