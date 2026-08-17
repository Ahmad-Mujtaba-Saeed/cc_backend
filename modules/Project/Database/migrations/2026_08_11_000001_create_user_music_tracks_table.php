<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's own background-music library.
 *
 * The Pixabay/Jamendo pickers offer a shared catalogue; this is the shelf
 * beside it that only its owner can see. Rows are scoped by user_id on every
 * read — a track id from the picker is never trusted as a path, exactly like
 * the provider ids it sits next to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_music_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title', 120);
            $table->string('path');                       // storage/public relative
            $table->string('original_name')->nullable();  // what they uploaded
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->decimal('duration_seconds', 8, 2)->default(0);
            $table->timestamps();

            // Every listing is "this user's tracks, newest first".
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_music_tracks');
    }
};
