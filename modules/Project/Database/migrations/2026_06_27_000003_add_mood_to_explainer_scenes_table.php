<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('explainer_scenes', function (Blueprint $table) {
            $table->string('mood')->default('neutral')->after('transition');
        });
    }

    public function down(): void
    {
        Schema::table('explainer_scenes', function (Blueprint $table) {
            $table->dropColumn('mood');
        });
    }
};
