<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Storage;

/**
 * BackgroundMusicService
 *
 * Curated stock-music selection keyed by mood (Phase 5). Deliberately NOT
 * generative — it just picks a track from a small, predictable library the
 * operator drops into public storage:
 *
 *   storage/app/public/audio/<mood>/*.mp3   (preferred, mood-specific)
 *
 * When the requested mood has no folder/tracks, it falls back to any track in
 * the allowed mood folders (see $fallbackMoods). It NEVER scans other folders
 * under audio/ — e.g. audio/horror/ belongs to the horror template and must
 * not leak into explainer videos.
 *
 * Selection is deterministic per project so re-renders keep the same track.
 * Returns null (no music) when the library is empty — always safe.
 */
class BackgroundMusicService
{
    private const EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'wav'];
    private const BASE = 'audio';

    /**
     * Pick a relative storage path for the given mood, or null if none exist.
     *
     * @param string[] $fallbackMoods mood folders to scan when the requested
     *                                 mood has no tracks (whitelist — keeps the
     *                                 search inside explainer moods only).
     */
    public function pickTrackForMood(string $mood, int $seed = 0, array $fallbackMoods = []): ?string
    {
        $candidates = $this->tracksIn(self::BASE . '/' . $mood);

        if (empty($candidates)) {
            foreach ($fallbackMoods as $fallbackMood) {
                if ($fallbackMood === $mood) {
                    continue;
                }
                $candidates = array_merge($candidates, $this->tracksIn(self::BASE . '/' . $fallbackMood));
            }
        }

        if (empty($candidates)) {
            return null;
        }

        sort($candidates);
        return $candidates[abs($seed) % count($candidates)];
    }

    /**
     * @return string[] relative storage paths of audio files in a directory
     */
    private function tracksIn(string $dir): array
    {
        if (!Storage::disk('public')->exists($dir)) {
            return [];
        }

        return array_values(array_filter(
            Storage::disk('public')->files($dir),
            fn ($file) => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true)
        ));
    }
}
