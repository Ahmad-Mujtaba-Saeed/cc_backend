<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One piece of text a user had read aloud in one of their cloned voices. */
class UserVoiceClip extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'user_voice_id',
        'text',
        'status',
        'path',
        'duration_seconds',
        'error',
    ];

    protected $casts = [
        'duration_seconds' => 'float',
    ];

    public function voice(): BelongsTo
    {
        return $this->belongsTo(UserVoice::class, 'user_voice_id');
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }
}
