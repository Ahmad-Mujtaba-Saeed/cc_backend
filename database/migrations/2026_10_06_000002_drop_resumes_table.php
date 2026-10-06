<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The Resume module (a CV parser/builder unrelated to video) was deleted on
 * 2026-10-06. Its table was empty; its creating migration went with the
 * module, so this is the only record that the table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('resumes');
    }

    public function down(): void
    {
        // Not recreated: the feature and its code are gone.
    }
};
