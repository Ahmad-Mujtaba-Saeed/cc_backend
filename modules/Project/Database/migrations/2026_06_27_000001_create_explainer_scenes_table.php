<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explainer_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('scene_id');          // e.g. "scene_1" (stable per project)
            $table->unsignedInteger('order')->default(0);
            $table->decimal('duration_seconds', 8, 2)->default(6);
            $table->text('narration')->nullable();
            $table->string('layout_template');
            $table->json('slots');               // full slot definitions (content, asset_request, asset_ref)
            $table->string('transition')->default('fade');
            $table->timestamps();

            $table->unique(['project_id', 'scene_id']);
            $table->index(['project_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('explainer_scenes');
    }
};
