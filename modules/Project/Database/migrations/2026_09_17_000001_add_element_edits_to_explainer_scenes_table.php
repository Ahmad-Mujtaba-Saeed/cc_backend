<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hand edits made on the storyboard's preview stage (click an element, drag,
 * restyle, hide), keyed by element id — see Support\ElementEdits and the
 * renderer's components/Editable.tsx. Nullable: an unedited scene renders as
 * designed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('explainer_scenes', function (Blueprint $table) {
            $table->json('element_edits')->nullable()->after('slots');
        });
    }

    public function down(): void
    {
        Schema::table('explainer_scenes', function (Blueprint $table) {
            $table->dropColumn('element_edits');
        });
    }
};
