<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per money-costing external call made while producing a render:
 * OpenAI chat completions (script analysis, directors, commentary…), OpenAI
 * TTS narration, Fal image generation. Self-hosted work (Kokoro, Whisper,
 * Demucs) and free APIs (Pixabay) cost nothing and are not recorded.
 *
 * project/user/template are denormalized so spend history survives project
 * deletion. cost_usd is computed at record time with the rates configured
 * then — editing rates later does not rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_cost_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('template_type', 60)->nullable()->index();
            $table->string('service', 40)->index();   // openai_chat | openai_tts | fal_image
            $table->string('label', 100);              // which pipeline step spent it
            $table->decimal('units', 12, 4)->default(0);
            $table->string('unit', 20);                 // tokens | minutes | images
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_cost_events');
    }
};
