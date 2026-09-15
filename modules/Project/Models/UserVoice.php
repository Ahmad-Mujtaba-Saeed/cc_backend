<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Project\Support\TtsVoices;
use Modules\User\Models\User;

/**
 * One voice a user cloned from their own recording.
 *
 * Its files live together in `directory` on the private disk:
 *   original.<ext>  what they uploaded
 *   reference.wav   the cleaned recording the model conditions on
 *   conds.pt        the cached conditioning tensors (written by the voice service)
 *   sample.wav      a short line spoken in the cloned voice
 *
 * Templates select it through the ordinary `tts_voice` setting as
 * `clone_<id>` ({@see TtsVoices::cloneKey()}).
 */
class UserVoice extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'name',
        'status',
        'directory',
        'original_name',
        'speech_seconds',
        'warnings',
        'error',
        'consented_at',
    ];

    protected $casts = [
        'warnings' => 'array',
        'speech_seconds' => 'float',
        'consented_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(UserVoiceClip::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    /** The id a template's `tts_voice` setting stores for this voice. */
    public function voiceKey(): string
    {
        return TtsVoices::cloneKey((int) $this->id);
    }

    public function referencePath(): string
    {
        return $this->directory . '/reference.wav';
    }

    public function samplePath(): string
    {
        return $this->directory . '/sample.wav';
    }
}
