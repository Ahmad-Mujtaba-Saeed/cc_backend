<?php

namespace Modules\Project\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Project\Models\UserVoice;
use Modules\Project\Models\UserVoiceClip;
use Modules\Project\Support\TtsVoices;
use Modules\Project\Support\WhisperWordTimings;

/**
 * VoiceCloneService — "My Voices".
 *
 * A user uploads one clean recording of themselves; Chatterbox-Nano (running
 * in its own `voice` container, see docker/voice/app.py) turns it into a voice
 * that reads any text aloud — as a stand-alone clip, or as the narrator of any
 * template through the ordinary `tts_voice` setting (`clone_<id>`).
 *
 * Privacy is the feature. A voice belongs to exactly one user:
 *  - every lookup is scoped by user id — a `clone_<id>` arriving from a client
 *    or from a project's settings is matched against the OWNER's rows and is
 *    never trusted on its own;
 *  - recordings live on the private disk and leave the server only through
 *    short-lived signed URLs minted inside owner-scoped responses.
 *
 * The model is slow on CPU, so cloning and stand-alone clips run as jobs on
 * their own queue connection (a long retry_after, so a job that outlives the
 * default 90 s is never delivered twice). Narration for a render is
 * synthesized inline by the render job, like every other engine.
 */
class VoiceCloneService
{
    public const QUEUE = 'voice';

    public const MAX_VOICES_PER_USER = 10;
    public const MAX_KILOBYTES = 51200; // 50 MB — minutes of WAV, far more than a clone needs
    public const MAX_CLIP_CHARS = 5000;
    public const MAX_CLIPS_KEPT = 50;

    public const EXTENSIONS = ['wav', 'mp3', 'm4a', 'aac', 'ogg', 'opus', 'flac', 'webm', 'mp4'];

    /** Sniffed (not client-claimed) types of the containers phones and browsers record into. */
    public const MIME_TYPES = [
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
        'audio/mpeg', 'audio/mp3',
        'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac', 'audio/x-aac', 'audio/x-hx-aac-adts',
        'audio/ogg', 'audio/opus', 'application/ogg',
        'audio/flac', 'audio/x-flac',
        'audio/webm', 'video/webm', 'video/mp4', 'video/quicktime', 'video/ogg',
    ];

    /** Spoken in the new voice as soon as it is cloned, so the user hears the result first. */
    private const SAMPLE_TEXT = 'Hi there! This is my voice, recreated with AI. '
        . 'I can narrate your videos, tell your stories, and explain your ideas, and it still sounds just like me.';

    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.voice_clone.url', 'http://voice:8000'), '/');
        $this->timeout = (int) config('services.voice_clone.timeout', 3000);
    }

    public static function enabled(): bool
    {
        return (bool) config('services.voice_clone.enabled', true);
    }

    public static function queueConnection(): string
    {
        return (string) config('services.voice_clone.queue_connection', 'voice');
    }

    // ------------------------------------------------------------------ library (owner-scoped)

    /** @return Collection<int, UserVoice> */
    public static function voicesFor(int $userId): Collection
    {
        return $userId > 0
            ? UserVoice::where('user_id', $userId)->orderByDesc('id')->get()
            : collect();
    }

    public static function findVoice(int $userId, int $voiceId): ?UserVoice
    {
        return $userId > 0 && $voiceId > 0
            ? UserVoice::where('user_id', $userId)->where('id', $voiceId)->first()
            : null;
    }

    public static function findClip(int $userId, int $clipId): ?UserVoiceClip
    {
        return $userId > 0 && $clipId > 0
            ? UserVoiceClip::where('user_id', $userId)->where('id', $clipId)->first()
            : null;
    }

    /**
     * The ready voice a `clone_<id>` names — only when it belongs to $userId
     * and its reference recording is still on disk. Null otherwise, never an
     * exception: a narrator that cannot be used falls back, it does not kill a
     * render.
     */
    public static function usable(?int $userId, ?string $voiceKey): ?UserVoice
    {
        $id = TtsVoices::cloneRowId($voiceKey);
        if ($id === null || (int) $userId <= 0) {
            return null;
        }

        try {
            $voice = self::findVoice((int) $userId, $id);
            if (!$voice || !$voice->isReady()) {
                return null;
            }

            // The worker is long-running; never answer from a stale stat cache.
            $reference = Storage::disk('local')->path($voice->referencePath());
            clearstatcache(true, $reference);

            return is_file($reference) ? $voice : null;
        } catch (\Throwable $e) {
            Log::warning('VoiceCloneService: usable() failed (non-fatal): ' . $e->getMessage());

            return null;
        }
    }

    /** @return array<string, string> `clone_<id>` → name, for the voice pickers */
    public static function optionsFor(int $userId): array
    {
        return self::voicesFor($userId)
            ->filter(fn (UserVoice $v) => $v->isReady())
            ->mapWithKeys(fn (UserVoice $v) => [$v->voiceKey() => $v->name])
            ->all();
    }

    /**
     * Store an uploaded recording as a new (not yet cloned) voice. The caller
     * dispatches {@see \Modules\Project\Jobs\PrepareUserVoiceJob}.
     *
     * @throws \RuntimeException with a message meant for the user
     */
    public static function createVoice(int $userId, UploadedFile $file, string $name): UserVoice
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Please sign in again.');
        }
        if (UserVoice::where('user_id', $userId)->count() >= self::MAX_VOICES_PER_USER) {
            throw new \RuntimeException(
                'You can keep up to ' . self::MAX_VOICES_PER_USER . ' voices. Delete one to clone another.'
            );
        }

        // Our names, never the client's: an uploaded filename is untrusted and
        // has no business being a path. ffmpeg sniffs the real format anyway.
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'audio';
        }
        $directory = "voices/{$userId}/" . Str::random(24);

        if (!$file->storeAs($directory, "original.{$extension}", 'local')) {
            throw new \RuntimeException('The recording could not be saved. Please try again.');
        }

        return UserVoice::create([
            'user_id' => $userId,
            'name' => self::cleanName($name),
            'status' => UserVoice::STATUS_QUEUED,
            'directory' => $directory,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 180),
            'consented_at' => now(),
        ]);
    }

    public static function rename(UserVoice $voice, string $name): void
    {
        $voice->update(['name' => self::cleanName($name)]);
    }

    /** Delete one of this user's voices: its files, its clips, its cached tensors. */
    public function removeVoice(int $userId, int $voiceId): bool
    {
        $voice = self::findVoice($userId, $voiceId);
        if (!$voice) {
            return false;
        }

        $disk = Storage::disk('local');
        foreach (UserVoiceClip::where('user_voice_id', $voice->id)->whereNotNull('path')->pluck('path') as $path) {
            self::deleteQuietly((string) $path);
        }

        // Only ever a directory this service created for this user.
        if (str_starts_with((string) $voice->directory, "voices/{$userId}/")) {
            $this->post('/voices/forget', ['voice_dir' => $disk->path($voice->directory)], 5);
            try {
                $disk->deleteDirectory($voice->directory);
            } catch (\Throwable $e) {
                Log::warning('VoiceCloneService: could not delete voice files: ' . $e->getMessage());
            }
        }

        $voice->delete(); // clips cascade

        return true;
    }

    public static function removeClip(int $userId, int $clipId): bool
    {
        $clip = self::findClip($userId, $clipId);
        if (!$clip) {
            return false;
        }
        if ($clip->path) {
            self::deleteQuietly((string) $clip->path);
        }
        $clip->delete();

        return true;
    }

    /**
     * A relative, expiring, signed URL for one private audio file. `<audio>`
     * tags cannot send a bearer token, so this is how cloned audio reaches the
     * browser — and only owner-scoped responses ever mint one. Relative so a
     * TLS-terminating proxy in front of the API cannot break the signature.
     */
    public static function mediaUrl(string $kind, int $id, bool $download = false): string
    {
        $parameters = ['kind' => $kind, 'id' => $id] + ($download ? ['download' => 1] : []);

        return URL::temporarySignedRoute('voices.media', now()->addHour(), $parameters, false);
    }

    // ------------------------------------------------------------------ engine work

    /** Clone a queued voice: clean the recording, build the voice, speak a sample. */
    public function prepare(UserVoice $voice): void
    {
        $disk = Storage::disk('local');
        $original = collect($disk->files($voice->directory))
            ->first(fn (string $file) => str_starts_with(basename($file), 'original.'));

        if (!$original) {
            $this->failVoice($voice, 'The uploaded recording is missing. Please upload it again.');
            return;
        }

        $voice->update(['status' => UserVoice::STATUS_PROCESSING, 'error' => null]);

        $result = $this->post('/voices/prepare', [
            'input_path' => $disk->path($original),
            'voice_dir' => $disk->path($voice->directory),
        ]);

        if (!($result['success'] ?? false)) {
            Log::warning('VoiceCloneService: prepare failed', [
                'voice_id' => $voice->id,
                'code' => $result['code'] ?? null,
                'error' => $result['error'] ?? 'unknown',
            ]);
            $this->failVoice($voice, self::friendlyError($result));
            return;
        }

        // Not fatal: the voice works whether or not its sample rendered.
        $sample = $this->synthesizeTo($voice, self::SAMPLE_TEXT, $disk->path($voice->samplePath()));
        if (!$sample['success']) {
            Log::warning('VoiceCloneService: sample synthesis failed', ['voice_id' => $voice->id]);
        }

        $voice->update([
            'status' => UserVoice::STATUS_READY,
            'speech_seconds' => round((float) ($result['speech_seconds'] ?? 0), 2),
            'warnings' => array_values(array_filter(array_map('strval', (array) ($result['warnings'] ?? [])))),
            'error' => null,
        ]);

        Log::info('VoiceCloneService: voice ready', [
            'voice_id' => $voice->id,
            'speech_seconds' => $result['speech_seconds'] ?? null,
            'snr_db' => $result['snr_db'] ?? null,
        ]);
    }

    /** Read a queued stand-alone clip aloud. */
    public function renderClip(UserVoiceClip $clip): void
    {
        $voice = self::findVoice((int) $clip->user_id, (int) $clip->user_voice_id);
        if (!$voice || !$voice->isReady()) {
            $clip->update(['status' => UserVoiceClip::STATUS_FAILED, 'error' => 'This voice is no longer available.']);
            return;
        }

        $clip->update(['status' => UserVoiceClip::STATUS_PROCESSING, 'error' => null]);

        $relative = "voices/{$clip->user_id}/clips/" . Str::random(24) . '.wav';
        $result = $this->synthesizeTo($voice, (string) $clip->text, Storage::disk('local')->path($relative));

        if (!$result['success']) {
            $clip->update(['status' => UserVoiceClip::STATUS_FAILED, 'error' => self::friendlyError($result)]);
            return;
        }

        $clip->update([
            'status' => UserVoiceClip::STATUS_READY,
            'path' => $relative,
            'duration_seconds' => round((float) $result['duration'], 2),
        ]);

        self::pruneClips((int) $clip->user_id);
    }

    /**
     * Narration through a cloned voice — the same contract as
     * {@see OpenAiTtsService::generateTTS()}, so {@see TTSGenerationService}
     * can route to it and fall back from it like any engine.
     *
     * @return array{success: bool, audio_path?: string, word_timings?: array, duration?: float, engine: string, error?: string}
     */
    public function generateTTS(string $text, UserVoice $voice, string $outputPath, bool $wordTiming = false): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'error' => 'Empty narration text', 'engine' => 'clone'];
        }

        // The voice container shares only storage/. A caller writing somewhere
        // else (a system temp dir) gets the audio rendered inside storage and
        // copied out, so every template can use a cloned narrator.
        $inStorage = self::insideStorage($outputPath);
        $renderPath = $inStorage
            ? $outputPath
            : Storage::disk('local')->path('voices/tmp/' . Str::random(24) . '.wav');

        $result = $this->synthesizeTo($voice, $text, $renderPath);
        if (!$result['success']) {
            return $result + ['engine' => 'clone'];
        }

        $timings = $wordTiming ? WhisperWordTimings::recover($renderPath, 'VoiceCloneService') : [];

        if (!$inStorage) {
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $copied = @copy($renderPath, $outputPath);
            @unlink($renderPath);
            if (!$copied) {
                return ['success' => false, 'error' => 'Could not move the cloned narration into place', 'engine' => 'clone'];
            }
        }

        return [
            'success' => true,
            'audio_path' => $outputPath,
            'word_timings' => $timings,
            'duration' => $result['duration'],
            'engine' => 'clone',
        ];
    }

    /** @return array{success: bool, duration?: float, error?: string, code?: ?string} */
    public function synthesizeTo(UserVoice $voice, string $text, string $absoluteOutput): array
    {
        $dir = dirname($absoluteOutput);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $result = $this->post('/synthesize', [
            'voice_dir' => Storage::disk('local')->path($voice->directory),
            'text' => $text,
            'output_path' => $absoluteOutput,
        ]);

        clearstatcache(true, $absoluteOutput);
        if (!($result['success'] ?? false) || !is_file($absoluteOutput) || filesize($absoluteOutput) <= 44) {
            Log::warning('VoiceCloneService: synthesis failed', [
                'voice_id' => $voice->id,
                'code' => $result['code'] ?? null,
                'error' => $result['error'] ?? 'no audio produced',
            ]);

            return [
                'success' => false,
                'error' => (string) ($result['error'] ?? 'The voice engine produced no audio'),
                'code' => $result['code'] ?? null,
            ];
        }

        Log::info('VoiceCloneService: synthesized', [
            'voice_id' => $voice->id,
            'chars' => strlen($text),
            'duration' => $result['duration'] ?? null,
            'seconds_taken' => $result['seconds_taken'] ?? null,
        ]);

        return ['success' => true, 'duration' => (float) ($result['duration'] ?? 0)];
    }

    /** The engine's own health report; `status` is `unreachable` when it is down. */
    public function health(): array
    {
        try {
            $response = Http::timeout(3)->get("{$this->baseUrl}/health");

            return $response->successful() ? (array) $response->json() : ['status' => 'unhealthy'];
        } catch (\Throwable) {
            return ['status' => 'unreachable'];
        }
    }

    // ------------------------------------------------------------------ internals

    private function post(string $uri, array $payload, ?int $timeout = null): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout($timeout ?? $this->timeout)
                ->post($this->baseUrl . $uri, $payload);

            $json = (array) ($response->json() ?? []);
            if (!$response->successful()) {
                return ['success' => false, 'code' => $json['code'] ?? 'http_' . $response->status(), 'error' => $json['error'] ?? "HTTP {$response->status()}"];
            }

            return $json;
        } catch (\Throwable $e) {
            return ['success' => false, 'code' => 'unreachable', 'error' => $e->getMessage()];
        }
    }

    private function failVoice(UserVoice $voice, string $message): void
    {
        $voice->update(['status' => UserVoice::STATUS_FAILED, 'error' => mb_substr($message, 0, 500)]);
    }

    /** What the user reads. The engine's own wording only where it was written for them. */
    private static function friendlyError(array $result): string
    {
        return match ($result['code'] ?? null) {
            'too_short', 'empty_text' => (string) ($result['error'] ?? 'Not enough clear speech in that recording.'),
            'decode_failed' => 'That file could not be read as audio. Try exporting it as WAV or MP3.',
            'unreachable', 'engine_unavailable' => 'The voice engine is not available right now. Please try again in a few minutes.',
            'too_long' => 'That text is too long to read in one go.',
            'missing' => 'This voice\'s files are missing. Please delete it and clone it again.',
            default => 'Something went wrong while generating the voice. Please try again.',
        };
    }

    private static function pruneClips(int $userId): void
    {
        UserVoiceClip::where('user_id', $userId)
            ->orderByDesc('id')
            ->skip(self::MAX_CLIPS_KEPT)
            ->take(500)
            ->get()
            ->each(fn (UserVoiceClip $clip) => self::removeClip($userId, (int) $clip->id));
    }

    private static function deleteQuietly(string $relative): void
    {
        try {
            Storage::disk('local')->delete($relative);
        } catch (\Throwable $e) {
            Log::info('VoiceCloneService: could not delete file: ' . $e->getMessage());
        }
    }

    private static function insideStorage(string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', storage_path()), '/') . '/';
        $dir = realpath(dirname($path)) ?: dirname($path);

        return str_starts_with(rtrim(str_replace('\\', '/', $dir), '/') . '/', $root);
    }

    private static function cleanName(string $raw): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', strip_tags($raw)));

        return mb_substr($name !== '' ? $name : 'My voice', 0, 80);
    }
}
