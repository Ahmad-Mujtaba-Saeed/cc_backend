<?php

namespace Modules\Project\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Project\Models\Project;
use Modules\Project\Models\UserMusicTrack;

/**
 * UserMusicLibrary — "my own background music".
 *
 * Pixabay and Jamendo offer a shared catalogue; this is the private shelf
 * beside it. A user uploads a file once and it is theirs to reuse on every
 * project from then on, and nobody else's picker ever lists it.
 *
 * It joins the existing selection contract instead of inventing a second one:
 * a project still stores `music_category` + `music_track_id`, with the
 * category set to the reserved value {@see CATEGORY}. That is the whole reason
 * every template gets this for free — the four places that resolve a bed
 * (the explainer renderer, AbstractVideoProcessor, and the horror and
 * image-shorts processors) call {@see resolveForProject()} first and fall
 * through to the provider unchanged.
 *
 * The ownership check is the security boundary: a track id arriving from the
 * client is matched against THAT user's rows and is never treated as a path.
 */
class UserMusicLibrary
{
    /** The reserved `music_category` meaning "one of my own uploads". */
    public const CATEGORY = 'custom';

    /** Per-user ceiling, so a library cannot grow without bound. */
    public const MAX_PER_USER = 50;

    /** Anything smaller than this is a failed upload, not a song. */
    public const MIN_BYTES = 8 * 1024;

    /** What Chrome can decode inside the render, which is the real constraint. */
    public const MIME_TYPES = [
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave',
        'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'application/ogg',
    ];

    public const MAX_KILOBYTES = 20480; // 20 MB

    /** Is this project asking for one of its owner's own uploads? */
    public static function isCustom(?string $category): bool
    {
        return strtolower(trim((string) $category)) === self::CATEGORY;
    }

    /**
     * The relative path of the user track this project selected, or null when
     * it did not select one (so the caller falls through to the provider).
     *
     * Null — never an exception — on every failure path: a missing row, a
     * track belonging to somebody else, a file that vanished. A background bed
     * must never be the reason a render dies.
     */
    public static function resolveForProject(Project $project, ?string $category, ?string $trackId): ?string
    {
        if (!self::isCustom($category)) {
            return null;
        }

        try {
            $track = self::find((int) $project->user_id, $trackId);
            if ($track === null) {
                Log::info('UserMusicLibrary: no usable custom track for project', [
                    'project_id' => $project->id,
                    'track_id' => $trackId,
                ]);

                return null;
            }

            return $track->path;
        } catch (\Throwable $e) {
            Log::warning('UserMusicLibrary: resolve failed (non-fatal): ' . $e->getMessage());

            return null;
        }
    }

    /**
     * One of this user's tracks whose file is actually on disk.
     *
     * With no id (or an id that no longer resolves) the newest upload wins —
     * the user asked for "my music", and answering with silence because a
     * track was deleted would be a worse reading of that than playing the one
     * they added most recently.
     */
    public static function find(int $userId, ?string $trackId = null): ?UserMusicTrack
    {
        if ($userId <= 0) {
            return null;
        }

        $query = UserMusicTrack::where('user_id', $userId);
        if ($trackId !== null && trim($trackId) !== '' && ctype_digit(trim($trackId))) {
            $chosen = (clone $query)->where('id', (int) trim($trackId))->first();
            if ($chosen && self::isHealthy($chosen)) {
                return $chosen;
            }
        }

        foreach ($query->orderByDesc('id')->limit(self::MAX_PER_USER)->get() as $track) {
            if (self::isHealthy($track)) {
                return $track;
            }
        }

        return null;
    }

    /** @return array<int, array> the picker's rows for this user */
    public static function listFor(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        return UserMusicTrack::where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (UserMusicTrack $t) => $t->toTrack() + [
                'size_bytes' => $t->size_bytes,
                'uploaded_at' => $t->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public static function countFor(int $userId): int
    {
        return $userId > 0 ? UserMusicTrack::where('user_id', $userId)->count() : 0;
    }

    /**
     * Store an uploaded file as one of this user's tracks.
     *
     * Lands on the PUBLIC disk because that is what the renderer can fetch:
     * the explainer plays its bed as an <Audio> tag inside headless Chrome,
     * which needs a URL, and the ffmpeg-mixing templates need a real path.
     * The filename is ours (a random slug), never the user's — an uploaded
     * name is untrusted input and has no business being a path.
     *
     * @throws \RuntimeException with a message meant for the user
     */
    public static function store(int $userId, UploadedFile $file, ?string $title = null): UserMusicTrack
    {
        if (self::countFor($userId) >= self::MAX_PER_USER) {
            throw new \RuntimeException(
                'Your music library is full (' . self::MAX_PER_USER . ' tracks). Delete one to add another.'
            );
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'mp3');
        if (!in_array($extension, ['mp3', 'wav', 'm4a', 'aac', 'ogg'], true)) {
            $extension = 'mp3';
        }

        $directory = "audio/user/{$userId}";
        $name = Str::random(24) . '.' . $extension;
        $path = $file->storeAs($directory, $name, 'public');
        if (!$path) {
            throw new \RuntimeException('The file could not be saved. Please try again.');
        }

        $size = (int) Storage::disk('public')->size($path);
        if ($size < self::MIN_BYTES) {
            Storage::disk('public')->delete($path);
            throw new \RuntimeException('That file is too small to be a music track.');
        }

        return UserMusicTrack::create([
            'user_id' => $userId,
            'title' => self::cleanTitle($title ?: $file->getClientOriginalName()),
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 180),
            'size_bytes' => $size,
            'duration_seconds' => self::probeDuration(Storage::disk('public')->path($path)),
        ]);
    }

    /** Delete one of this user's tracks, file and row. */
    public static function remove(int $userId, int $trackId): bool
    {
        $track = UserMusicTrack::where('user_id', $userId)->where('id', $trackId)->first();
        if (!$track) {
            return false;
        }

        try {
            if ($track->path && Storage::disk('public')->exists($track->path)) {
                Storage::disk('public')->delete($track->path);
            }
        } catch (\Throwable $e) {
            Log::warning('UserMusicLibrary: could not delete file: ' . $e->getMessage());
        }

        $track->delete();

        return true;
    }

    // ------------------------------------------------------------- internals

    private static function isHealthy(UserMusicTrack $track): bool
    {
        try {
            $disk = Storage::disk('public');
            // The worker is long-running and the upload may have landed from
            // the web container moments ago — never answer from the stat cache.
            clearstatcache(true, $disk->path((string) $track->path));

            return $track->path && $disk->exists($track->path) && $disk->size($track->path) >= self::MIN_BYTES;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A display title from the filename. Cosmetic — never a path. */
    private static function cleanTitle(string $raw): string
    {
        $title = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', trim($raw)) ?: $raw;
        $title = trim((string) preg_replace('/[_\-]+/', ' ', $title));
        $title = trim((string) preg_replace('/\s{2,}/', ' ', $title));

        return mb_substr($title !== '' ? $title : 'Untitled track', 0, 120);
    }

    /** Length in seconds; 0 when it cannot be probed (display only). */
    private static function probeDuration(string $absolutePath): float
    {
        try {
            $format = \FFMpeg\FFProbe::create()->format($absolutePath);

            return round((float) $format->get('duration'), 2);
        } catch (\Throwable $e) {
            Log::info('UserMusicLibrary: could not probe duration: ' . $e->getMessage());

            return 0.0;
        }
    }
}
