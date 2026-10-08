<?php

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An app allowed to ask users for studio access (DCR-registered or CIMD-fetched). */
class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    protected $fillable = [
        'client_id', 'client_secret_hash', 'name', 'redirect_uris', 'token_endpoint_auth_method',
        'source', 'metadata', 'metadata_fetched_at', 'last_used_at',
    ];

    protected $casts = [
        'redirect_uris' => 'array',
        'metadata' => 'array',
        'metadata_fetched_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = ['client_secret_hash'];

    public function grants(): HasMany
    {
        return $this->hasMany(McpOAuthGrant::class, 'oauth_client_id');
    }

    public function isConfidential(): bool
    {
        return $this->client_secret_hash !== null;
    }
}
