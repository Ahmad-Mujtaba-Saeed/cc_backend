<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SceneDetectionService
 *
 * Free, fully local moment detection using only FFmpeg/FFprobe (no paid APIs).
 *
 * 1. Scene cuts:    ffmpeg select='gt(scene,THRESHOLD)' + showinfo (parsed from stderr)
 * 2. Audio energy:  per-second RMS levels via asetnsamples + astats + ametadata
 * 3. Candidates:    windows between cuts, scored by audio peaks (screams/crashes)
 *                   and local cut density (action intensity)
 */
class SceneDetectionService
{
    /**
     * Get media duration in seconds using ffprobe.
     */
    public function getDuration(string $mediaPath): float
    {
        $process = new Process([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'csv=p=0',
            $mediaPath,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('ffprobe duration failed: ' . ($process->getErrorOutput() ?: $process->getOutput()));
        }

        return (float) trim($process->getOutput());
    }

    /**
     * Detect scene-cut timestamps (seconds).
     * Retries with a lower threshold when too few cuts are found.
     */
    public function detectSceneCuts(string $videoPath, float $threshold = 0.30): array
    {
        $cuts = $this->runSceneDetect($videoPath, $threshold);

        if (count($cuts) < 5 && $threshold > 0.20) {
            Log::info('SceneDetectionService: few cuts found, retrying with lower threshold', [
                'video' => basename($videoPath),
                'cuts_found' => count($cuts),
                'retry_threshold' => 0.20,
            ]);
            $cuts = $this->runSceneDetect($videoPath, 0.20);
        }

        return $cuts;
    }

    /**
     * Run ffmpeg scene detection and parse pts_time values from stderr.
     */
    private function runSceneDetect(string $videoPath, float $threshold): array
    {
        $process = new Process([
            'ffmpeg',
            '-hide_banner',
            '-i', $videoPath,
            '-filter:v', sprintf("select='gt(scene,%.2f)',showinfo", $threshold),
            '-an',
            '-f', 'null',
            '-',
        ]);
        $process->setTimeout(1800);
        $process->run();

        // showinfo writes to stderr; ffmpeg exits 0 on success
        $output = $process->getErrorOutput();

        if (!$process->isSuccessful() && trim($output) === '') {
            throw new \Exception('ffmpeg scene detection failed: ' . $process->getOutput());
        }

        preg_match_all('/pts_time:([0-9]+\.?[0-9]*)/', $output, $matches);
        $cuts = array_map('floatval', $matches[1] ?? []);
        $cuts = array_values(array_unique($cuts));
        sort($cuts);

        return $cuts;
    }

    /**
     * Build a per-second audio RMS profile (second => dBFS, roughly -90..0).
     * Loud seconds usually indicate crashes, screams, impacts or laughter.
     */
    public function getAudioEnergyProfile(string $videoPath): array
    {
        $process = new Process([
            'ffmpeg',
            '-hide_banner',
            '-i', $videoPath,
            '-vn',
            '-af', 'asetnsamples=n=44100,astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file=-',
            '-f', 'null',
            '-',
        ]);
        $process->setTimeout(1800);
        $process->run();

        $profile = [];
        $currentTime = null;

        foreach (preg_split('/\r?\n/', $process->getOutput()) as $line) {
            if (preg_match('/pts_time:([0-9]+\.?[0-9]*)/', $line, $m)) {
                $currentTime = (float) $m[1];
                continue;
            }

            if ($currentTime !== null && preg_match('/RMS_level=(-?[0-9.]+|-inf|inf|nan)/i', $line, $m)) {
                $value = strtolower($m[1]);
                $db = in_array($value, ['-inf', 'inf', 'nan'], true) ? -90.0 : max(-90.0, min(0.0, (float) $m[1]));
                $profile[(int) floor($currentTime)] = $db;
                $currentTime = null;
            }
        }

        return $profile;
    }

    /**
     * Build scored candidate moments for a source video.
     *
     * Returns array of ['start' => float, 'end' => float, 'heuristic_score' => float]
     * sorted by heuristic_score descending, capped at $maxCandidates.
     */
    public function buildCandidateMoments(
        string $videoPath,
        float $maxClipSeconds = 6.0,
        float $minClipSeconds = 2.5,
        int $maxCandidates = 80
    ): array {
        $duration = $this->getDuration($videoPath);
        $cuts = $this->detectSceneCuts($videoPath);

        $profile = [];
        try {
            $profile = $this->getAudioEnergyProfile($videoPath);
        } catch (\Exception $e) {
            Log::warning('SceneDetectionService: audio energy profile failed, scoring on cuts only', [
                'video' => basename($videoPath),
                'error' => $e->getMessage(),
            ]);
        }

        $candidates = [];

        if (count($cuts) >= 5) {
            $boundaries = array_merge([0.0], $cuts, [$duration]);
            $boundaries = array_values(array_unique($boundaries));
            sort($boundaries);

            for ($i = 0; $i < count($boundaries) - 1; $i++) {
                $start = $boundaries[$i];
                $end = $boundaries[$i + 1];

                if (($end - $start) < $minClipSeconds) {
                    continue;
                }

                $candidates[] = [
                    'start' => round($start, 2),
                    'end' => round(min($end, $start + $maxClipSeconds), 2),
                ];
            }
        }

        // Fallback: fixed sliding windows when the source has too few hard cuts
        if (count($candidates) < 5) {
            $candidates = [];
            for ($t = 0.0; ($t + $minClipSeconds) < $duration; $t += $maxClipSeconds) {
                $candidates[] = [
                    'start' => round($t, 2),
                    'end' => round(min($duration, $t + $maxClipSeconds), 2),
                ];
            }
        }

        foreach ($candidates as &$candidate) {
            $candidate['heuristic_score'] = $this->scoreWindow($candidate['start'], $candidate['end'], $profile, $cuts);
        }
        unset($candidate);

        usort($candidates, fn ($a, $b) => $b['heuristic_score'] <=> $a['heuristic_score']);

        $candidates = array_slice($candidates, 0, $maxCandidates);

        Log::info('SceneDetectionService: candidate moments built', [
            'video' => basename($videoPath),
            'duration' => $duration,
            'cut_count' => count($cuts),
            'candidate_count' => count($candidates),
            'top_score' => $candidates[0]['heuristic_score'] ?? null,
        ]);

        return $candidates;
    }

    /**
     * Heuristic score (0..1): 70% loudest second in window + 30% local cut density.
     */
    private function scoreWindow(float $start, float $end, array $profile, array $cuts): float
    {
        $peakDb = -90.0;
        for ($second = (int) floor($start); $second <= (int) floor($end); $second++) {
            if (isset($profile[$second])) {
                $peakDb = max($peakDb, $profile[$second]);
            }
        }
        $audioScore = max(0.0, min(1.0, ($peakDb + 60.0) / 60.0));

        $cutCount = 0;
        foreach ($cuts as $cut) {
            if ($cut >= $start && $cut <= $start + 10.0) {
                $cutCount++;
            }
        }
        $densityScore = min(1.0, $cutCount / 5.0);

        return round(0.7 * $audioScore + 0.3 * $densityScore, 4);
    }
}
