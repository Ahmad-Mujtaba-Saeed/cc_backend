<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Modules\User\Models\User;

/**
 * One background-music file a user uploaded for their own use.
 *
 * Deliberately shaped like the provider tracks it sits beside in the picker
 * (id / title / duration / url), so the audition UI and the render both treat
 * "my music" as just another source.
 */
class UserMusicTrack extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'path',
        'original_name',
        'size_bytes',
        'duration_seconds',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'duration_seconds' => 'float',
    ];

    protected $appends = ['url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }

    /** The picker's row shape — identical to a provider track. */
    public function toTrack(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => (string) $this->title,
            'duration' => (int) round((float) $this->duration_seconds),
            'url' => $this->url,
        ];
    }
}
