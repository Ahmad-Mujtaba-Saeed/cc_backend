<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's own colour schemes.
 *
 * The registry ships fourteen palettes and every video had to be one of them.
 * This is the shelf beside that catalogue: a scheme a user mixes stays theirs,
 * is offered on every project they make afterwards, and is invisible to
 * everyone else — rows are scoped by user_id on every read, and a scheme name
 * arriving from the client is never trusted as a lookup key on its own.
 *
 * The colours are the same seven fields the registry's schemes carry, so a row
 * here is interchangeable with a built-in one everywhere downstream (the
 * validator, the renderer's theme, the thumbnail). `name` is a slug unique
 * PER USER, not globally: two people may both have a "sunset".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_color_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name', 60);        // slug, unique per user
            $table->string('label', 60);       // what the picker shows
            $table->string('bg_from', 9);
            $table->string('bg_to', 9);
            $table->string('accent', 9);
            $table->string('accent2', 9);
            $table->string('text', 9);
            $table->string('muted', 9);
            $table->string('panel', 9);
            $table->timestamps();

            // Every listing is "this user's schemes, newest first".
            $table->index(['user_id', 'created_at']);
            // Two schemes of the same name would make the picker ambiguous and
            // the project's stored `color_scheme` unresolvable.
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_color_schemes');
    }
};
