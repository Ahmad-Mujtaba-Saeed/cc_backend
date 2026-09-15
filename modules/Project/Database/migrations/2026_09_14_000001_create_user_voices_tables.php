<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My Voices": a user's own cloned voices, and the text-to-speech clips made
 * with them.
 *
 * A voice is private to its owner. Rows are scoped by user_id on every read,
 * and the recordings live on the PRIVATE disk under `directory` — never a
 * public URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_voices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name', 80);
            $table->string('status', 16)->default('queued'); // queued|processing|ready|failed
            $table->string('directory');                      // private disk: voices/{user}/{random}
            $table->string('original_name')->nullable();      // what they uploaded (display only)
            $table->decimal('speech_seconds', 8, 2)->default(0);
            $table->json('warnings')->nullable();              // recording-quality advice
            $table->string('error', 500)->nullable();
            $table->timestamp('consented_at')->nullable();     // "this is my voice / I have permission"
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('user_voice_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_voice_id')->constrained('user_voices')->onDelete('cascade');
            $table->text('text');
            $table->string('status', 16)->default('queued');
            $table->string('path')->nullable();                // private disk
            $table->decimal('duration_seconds', 8, 2)->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_voice_clips');
        Schema::dropIfExists('user_voices');
    }
};
