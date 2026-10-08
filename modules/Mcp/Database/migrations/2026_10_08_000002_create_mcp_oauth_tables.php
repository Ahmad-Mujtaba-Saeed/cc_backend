<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth 2.1 for the MCP studio, so Claude.ai and other connector UIs can show
 * a normal "Connect" button (MCP authorization spec: protected-resource
 * metadata, authorization-server metadata, dynamic client registration or a
 * client-ID metadata document, PKCE).
 *
 *  mcp_oauth_clients  apps that registered (DCR) or were fetched from their
 *                     client-ID metadata document (CIMD)
 *  mcp_oauth_grants   one per "Allow" a user clicked: the connection the
 *                     dashboard lists and revokes; holds the rotating
 *                     refresh token (hash)
 *  mcp_tokens         + grant_id / expires_at: OAuth access tokens are
 *                     short-lived rows in the same table the personal keys
 *                     live in, so the endpoint authenticates both one way
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 255)->unique();
            $table->char('client_secret_hash', 64)->nullable();
            $table->string('name', 200);
            $table->json('redirect_uris');
            $table->string('token_endpoint_auth_method', 40)->default('none');
            $table->string('source', 10)->default('dcr'); // dcr | cimd
            $table->json('metadata')->nullable();
            $table->timestamp('metadata_fetched_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_oauth_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('oauth_client_id')->constrained('mcp_oauth_clients')->cascadeOnDelete();
            $table->string('scope', 200);
            $table->string('resource', 255)->nullable();
            $table->char('refresh_token_hash', 64)->nullable()->unique();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_client', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::table('mcp_tokens', function (Blueprint $table) {
            $table->foreignId('grant_id')->nullable()->after('user_id')->constrained('mcp_oauth_grants')->cascadeOnDelete();
            $table->timestamp('expires_at')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grant_id');
            $table->dropColumn('expires_at');
        });
        Schema::dropIfExists('mcp_oauth_grants');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
