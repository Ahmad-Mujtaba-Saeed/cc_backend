<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claude / MCP video studio.
 *
 *  mcp_tokens   one row per "connection" a user makes on the Connect page.
 *               Only the SHA-256 of the secret is stored; the plaintext is
 *               shown once. A dedicated table (not Sanctum's) so an MCP
 *               secret can only ever drive the MCP endpoint — never the rest
 *               of the account API.
 *
 *  mcp_uploads  signed, expiring upload links the model hands to the user:
 *               the presenter recording (a talking-head video of the script)
 *               or a picture for a scene. Received in chunks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->char('token_hash', 64)->unique();
            // First characters of the secret, so the user can tell two
            // connections apart without the secret ever being shown again.
            $table->string('token_hint', 16);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_client', 120)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('mcp_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('purpose', 20); // presenter | image
            $table->string('asset_name', 60)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending'); // pending|uploading|processing|ready|failed
            $table->string('original_name', 255)->nullable();
            $table->unsignedBigInteger('expected_bytes')->nullable();
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->string('path', 500)->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['project_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_uploads');
        Schema::dropIfExists('mcp_tokens');
    }
};
