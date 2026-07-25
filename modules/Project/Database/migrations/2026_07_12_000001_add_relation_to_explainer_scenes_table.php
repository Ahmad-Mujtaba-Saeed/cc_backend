<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story relation of a scene to the previous one (copilot.md §3.2) — drives
 * the signature transition and the cut's SFX flavour. Nullable: old
 * storyboards simply have no relation and keep their stored transitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('explainer_scenes', function (Blueprint $table) {
            $table->string('relation')->nullable()->after('transition');
        });
    }

    public function down(): void
    {
        Schema::table('explainer_scenes', function (Blueprint $table) {
            $table->dropColumn('relation');
        });
    }
};
