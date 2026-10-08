<?php

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\User\Models\User;

/**
 * One MCP credential. Two kinds share the table:
 *
 *  - personal keys (`vmcp_…`, grant_id null): made on the Connect Claude page,
 *    no expiry, revoked by hand;
 *  - OAuth access tokens (`vmoa_…`): issued by the token endpoint for a grant,
 *    valid one hour, refreshed by the app with its refresh token.
 *
 * The plaintext exists only in the response that created it; the table keeps
 * its SHA-256.
 */
class McpToken extends Model
{
    public const PREFIX = 'vmcp_';
    public const ACCESS_PREFIX = 'vmoa_';

    protected $fillable = [
        'user_id', 'grant_id', 'name', 'token_hash', 'token_hint', 'last_used_at', 'last_client', 'revoked_at', 'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(McpOAuthGrant::class, 'grant_id');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Mint a personal key for a user.
     *
     * @return array{0: self, 1: string}  the row and the plaintext secret
     */
    public static function mint(User $user, string $name): array
    {
        $plain = self::PREFIX . Str::random(40);
        $row = self::create([
            'user_id' => $user->id,
            'name' => mb_substr(trim($name) ?: 'Claude', 0, 80),
            'token_hash' => self::hash($plain),
            'token_hint' => substr($plain, 0, 11),
        ]);

        return [$row, $plain];
    }

    /**
     * Mint a one-hour OAuth access token for a grant.
     *
     * @return array{0: self, 1: string}
     */
    public static function mintAccess(McpOAuthGrant $grant, string $name, int $ttlSeconds): array
    {
        $plain = self::ACCESS_PREFIX . Str::random(48);
        $row = self::create([
            'user_id' => $grant->user_id,
            'grant_id' => $grant->id,
            'name' => mb_substr($name, 0, 80),
            'token_hash' => self::hash($plain),
            'token_hint' => substr($plain, 0, 11),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return [$row, $plain];
    }

    /** The live (not revoked, not expired) credential for a presented secret, or null. */
    public static function findLive(string $plain): ?self
    {
        $plain = trim($plain);
        if ((!str_starts_with($plain, self::PREFIX) && !str_starts_with($plain, self::ACCESS_PREFIX)) || strlen($plain) > 100) {
            return null;
        }

        return self::where('token_hash', self::hash($plain))
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
    }
}
