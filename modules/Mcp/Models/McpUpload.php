<?php

namespace Modules\Mcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Project\Models\Project;

/**
 * A signed, expiring upload link the model handed to the user — for the
 * presenter recording, or for a picture a scene should show.
 */
class McpUpload extends Model
{
    public const PURPOSES = ['presenter', 'image'];

    protected $fillable = [
        'user_id', 'project_id', 'purpose', 'asset_name', 'token_hash', 'status',
        'original_name', 'expected_bytes', 'received_bytes', 'path', 'error', 'meta', 'expires_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'expires_at' => 'datetime',
        'expected_bytes' => 'integer',
        'received_bytes' => 'integer',
    ];

    protected $hidden = ['token_hash'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array{0: self, 1: string}  the row and the plaintext link token
     */
    public static function mint(Project $project, string $purpose, ?string $assetName = null): array
    {
        $plain = Str::random(48);
        $row = self::create([
            'user_id' => $project->user_id,
            'project_id' => $project->id,
            'purpose' => $purpose,
            'asset_name' => $assetName,
            'token_hash' => hash('sha256', $plain),
            'status' => 'pending',
            'expires_at' => now()->addHours((int) config('mcp.upload.ttl_hours', 48)),
        ]);

        return [$row, $plain];
    }

    public static function findByToken(string $plain): ?self
    {
        if ($plain === '' || strlen($plain) > 100) {
            return null;
        }

        return self::where('token_hash', hash('sha256', $plain))->first();
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Where the bytes accumulate while chunks arrive (private disk, never served). */
    public function partPath(): string
    {
        return "mcp_uploads/{$this->project_id}/upload_{$this->id}.part";
    }
}
