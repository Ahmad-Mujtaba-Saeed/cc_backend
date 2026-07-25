<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes MySQL "1038 Out of sort memory" on the project listing.
 *
 * Cause: the per-user listing runs `WHERE user_id = ? ORDER BY created_at DESC`
 * but there was no (user_id, created_at) index, so MySQL filesorted the matched
 * rows — and those rows carry a `processing_state` JSON column that had grown to
 * megabytes (the YT-gameplay processor was persisting the full transcript, up to
 * 3000+ segments). Sorting wide rows that include a large JSON column overflows
 * the sort buffer.
 *
 * Two-part fix:
 *  1. Add (user_id, created_at) so the ORDER BY is served by the index (no filesort).
 *  2. Strip the dead transcript blobs from existing rows so they stop being huge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'projects_user_id_created_at_index');
        });

        // Remove the previously-persisted (write-only) transcript blobs that
        // bloated existing rows. Safe no-op when the keys aren't present.
        DB::statement(<<<'SQL'
            UPDATE projects
            SET processing_state = JSON_REMOVE(processing_state, '$.transcript_segments', '$.full_text')
            WHERE processing_state IS NOT NULL
              AND JSON_CONTAINS_PATH(processing_state, 'one', '$.transcript_segments', '$.full_text')
        SQL);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_user_id_created_at_index');
        });
    }
};
