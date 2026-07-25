<?php

namespace Modules\Resume\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\User\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resume extends Model
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
        'cv_path',
        'cv_resumejson',
        'file_name',
        'file_type',
        'file_size',
        'is_default',
        'is_public',
        'last_modified_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'cv_resumejson' => 'array',
        'is_default' => 'boolean',
        'is_public' => 'boolean',
        'file_size' => 'integer',
        'last_modified_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user that owns the resume.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the activities for the resume.
     */
    // public function activities(): HasMany
    // {
    //     return $this->hasMany(CvRecentActivity::class, 'type_id')
    //         ->where('type', 'resume');
    // }

    /**
     * Scope a query to only include default resumes.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to only include public resumes.
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
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
}
