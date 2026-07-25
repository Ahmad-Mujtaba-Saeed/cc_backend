<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explainer_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('scene_id');          // matches explainer_scenes.scene_id
            $table->string('slot_key');          // e.g. "slot_left"
            $table->string('type')->default('image'); // image | video
            $table->string('path');              // storage/public relative path
            $table->string('original_name')->nullable();
            $table->timestamps();

            // One asset per (project, scene, slot) — re-upload overwrites.
            $table->unique(['project_id', 'scene_id', 'slot_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('explainer_assets');
    }
};
