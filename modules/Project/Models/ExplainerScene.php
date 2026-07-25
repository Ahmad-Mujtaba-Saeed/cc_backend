<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExplainerScene extends Model
{
    protected $fillable = [
        'project_id',
        'scene_id',
        'order',
        'duration_seconds',
        'narration',
        'layout_template',
        'slots',
        'transition',
        'relation',
        'mood',
    ];

    protected $casts = [
        'order' => 'integer',
        'duration_seconds' => 'float',
        'slots' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ExplainerAsset::class, 'scene_id', 'scene_id')
            ->where('explainer_assets.project_id', $this->project_id);
    }

    /**
     * Slot keys that are image slots and therefore require an uploaded asset.
     *
     * @return string[]
     */
    public function requiredAssetSlots(): array
    {
        $keys = [];
        foreach ($this->slots ?? [] as $slotKey => $slot) {
            if (!in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                continue;
            }
            // Stock b-roll slots (§8) are fetched by the pipeline, never
            // required from the user.
            if (trim((string) ($slot['stock_query'] ?? '')) !== '') {
                continue;
            }
            $keys[] = $slotKey;
        }
        return $keys;
    }
}
