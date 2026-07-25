<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Project\Processors\TemplateProcessorFactory;
use Modules\User\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'template_type',
        'video_path',
        'output_path',
        'thumbnail_path',
        'aspect_ratio',
        'r2_url',
        'r2_key',
        'status',
        'progress',
        'settings',
        'error_message',
        'failed_step',
        'processing_state',
        'file_name',
        'file_type',
        'file_size',
        'duration',
        'is_public',
        'published_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'array',
        'processing_state' => 'array',
        'progress' => 'integer',
        'file_size' => 'integer',
        'duration' => 'float',
        'is_public' => 'boolean',
        'published_at' => 'datetime',
        'completed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user that owns the project.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the activities for the project.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class);
    }

    /**
     * Explainer storyboard scenes (ai_explainer_video template).
     */
    public function explainerScenes(): HasMany
    {
        return $this->hasMany(ExplainerScene::class)->orderBy('order');
    }

    /**
     * Uploaded per-slot assets for the explainer storyboard.
     */
    public function explainerAssets(): HasMany
    {
        return $this->hasMany(ExplainerAsset::class);
    }

    /**
     * Scope a query to only include projects with a specific status.
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include public projects.
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope a query to only include completed projects.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include projects in progress.
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Get the file size in a human-readable format.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $size = $this->file_size;
        if ($size >= 1073741824) {
            return number_format($size / 1073741824, 2) . ' GB';
        } elseif ($size >= 1048576) {
            return number_format($size / 1048576, 2) . ' MB';
        } elseif ($size >= 1024) {
            return number_format($size / 1024, 2) . ' KB';
        } elseif ($size > 1) {
            return $size . ' bytes';
        } elseif ($size == 1) {
            return '1 byte';
        } else {
            return '0 bytes';
        }
    }

    /**
     * Get the duration in a human-readable format.
     */
    public function getFormattedDurationAttribute(): string
    {
        $duration = $this->duration;
        if ($duration >= 3600) {
            $hours = floor($duration / 3600);
            $minutes = floor(($duration % 3600) / 60);
            $seconds = $duration % 60;
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        } elseif ($duration >= 60) {
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            return sprintf('%02d:%02d', $minutes, $seconds);
        } else {
            return sprintf('00:%02d', $duration);
        }
    }

    /**
     * Get the status badge color.
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'draft' => 'gray',
            'processing' => 'blue',
            'completed' => 'green',
            'failed' => 'red',
            'published' => 'purple',
            default => 'gray'
        };
    }

    /**
     * Check if project is ready for processing.
     */
    public function isReadyForProcessing(): bool
    {
        if (!in_array($this->status, ['draft', 'failed'], true)) {
            return false;
        }

        $templateConfig = TemplateProcessorFactory::getTemplateConfig($this->template_type);
        $requiresUpload = $templateConfig['requires_upload'] ?? true;

        if ($requiresUpload && empty($this->video_path)) {
            return false;
        }

        return true;
    }

    /**
     * Check if project is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed' && !empty($this->output_path);
    }
}
