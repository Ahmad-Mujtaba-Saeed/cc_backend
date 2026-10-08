<?php

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\User\Models\User;

/**
 * One OAuth connection: a user clicked "Allow" for an app. Revoking it (the
 * Connect Claude page) kills its refresh token and every access token.
 */
class McpOAuthGrant extends Model
{
    protected $table = 'mcp_oauth_grants';

    protected $fillable = [
        'user_id', 'oauth_client_id', 'scope', 'resource', 'refresh_token_hash', 'refresh_expires_at',
        'revoked_at', 'last_used_at', 'last_client',
    ];

    protected $casts = [
        'refresh_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = ['refresh_token_hash'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpOAuthClient::class, 'oauth_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(McpToken::class, 'grant_id');
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now(), 'refresh_token_hash' => null]);
        McpToken::where('grant_id', $this->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
