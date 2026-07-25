<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ExplainerAsset extends Model
{
    protected $fillable = [
        'project_id',
        'scene_id',
        'slot_key',
        'type',
        'path',
        'original_name',
    ];

    protected $appends = ['url'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }
}
