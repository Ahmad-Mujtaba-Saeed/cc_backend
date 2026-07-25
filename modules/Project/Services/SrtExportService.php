<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ExplainerTimingMap;

/**
 * SrtExportService (copilot.md §10.7): builds an .srt caption file for a
 * rendered explainer from the per-scene Kokoro/Whisper word timings, offset
 * onto the composition clock by ExplainerTimingMap (so cue times survive
 * slides transition overlaps and hybrid chapter math). Standard limits:
 * ≤42 chars per line, ≤2 lines per cue; a new cue starts at any ≥0.7s pause.
 * Scenes without word timings fall back to one evenly-split cue run over the
 * scene's narration text. Written next to the MP4; never blocks anything.
 */
class SrtExportService
{
    private const MAX_LINE = 42;
    private const MAX_LINES = 2;
    private const PAUSE_SPLIT_S = 0.7;

    /**
     * @return string|null  Relative storage path of the .srt, or null.
     */
    public function export(Project $project, string $outputRelativeMp4): ?string
    {
        try {
            $settings = $project->settings ?? [];
            $scenes = $project->explainerScenes()->orderBy('order')->get();
            if ($scenes->isEmpty()) {
                return null;
            }

            $windows = ExplainerTimingMap::sceneWindows(
                $scenes->map(fn ($s) => [
                    'scene_id' => (string) $s->scene_id,
                    'duration_seconds' => (float) $s->duration_seconds,
                    'transition' => (string) $s->transition,
                ])->all(),
                (string) ($settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode()),
                $settings['chapter_plan'] ?? null
            );

            $cues = [];
            foreach ($scenes as $scene) {
                $window = $windows[(string) $scene->scene_id] ?? null;
                $text = trim((string) $scene->narration);
                if ($window === null || $text === '') {
                    continue;
                }

                $words = $this->wordTimings($project, (string) $scene->scene_id);
                if (!empty($words)) {
                    $cues = array_merge($cues, $this->cuesFromWords($words, (float) $window['start'], (float) $window['end']));
                } else {
                    $cues = array_merge($cues, $this->cuesFromText($text, (float) $window['start'], (float) $window['end']));
                }
            }

            if (empty($cues)) {
                return null;
            }

            $srt = '';
            foreach ($cues as $i => $cue) {
                $srt .= ($i + 1) . "\n"
                    . $this->stamp($cue['start']) . ' --> ' . $this->stamp($cue['end']) . "\n"
                    . implode("\n", $cue['lines']) . "\n\n";
            }

            $relative = (preg_replace('/\.mp4$/i', '', $outputRelativeMp4) ?: $outputRelativeMp4) . '.srt';
            Storage::disk('public')->put($relative, $srt);

            return $relative;
        } catch (\Throwable $e) {
            Log::warning('SrtExportService: export failed (non-fatal): ' . $e->getMessage());

            return null;
        }
    }

    /** @return array<int, array{word: string, start: float, end: float}> */
    private function wordTimings(Project $project, string $sceneId): array
    {
        $sidecar = "projects/{$project->id}/explainer/narration_{$sceneId}.words.json";
        if (!Storage::disk('public')->exists($sidecar)) {
            return [];
        }
        $decoded = json_decode((string) Storage::disk('public')->get($sidecar), true);

        return is_array($decoded) ? array_values(array_filter($decoded, fn ($w) => is_array($w) && trim((string) ($w['word'] ?? '')) !== '')) : [];
    }

    /**
     * Word-accurate cues: fill up to two 42-char lines, breaking early at any
     * real pause so a cue never spans a breath.
     *
     * @return array<int, array{start: float, end: float, lines: string[]}>
     */
    private function cuesFromWords(array $words, float $sceneStart, float $sceneEnd): array
    {
        $cues = [];
        $buffer = [];
        $bufStart = 0.0;
        $bufEnd = 0.0;

        $flush = function () use (&$cues, &$buffer, &$bufStart, &$bufEnd, $sceneStart, $sceneEnd): void {
            if (empty($buffer)) {
                return;
            }
            $lines = $this->wrap(implode(' ', $buffer));
            $cues[] = [
                'start' => min($sceneEnd, $sceneStart + $bufStart),
                'end' => min($sceneEnd, $sceneStart + max($bufEnd, $bufStart + 0.4)),
                'lines' => $lines,
            ];
            $buffer = [];
        };

        foreach ($words as $w) {
            $word = trim((string) $w['word']);
            $start = (float) ($w['start'] ?? 0);
            $end = (float) ($w['end'] ?? $start);

            $candidate = trim(implode(' ', array_merge($buffer, [$word])));
            $tooLong = mb_strlen($candidate) > self::MAX_LINE * self::MAX_LINES;
            $pause = !empty($buffer) && ($start - $bufEnd) >= self::PAUSE_SPLIT_S;
            if ($tooLong || $pause) {
                $flush();
            }
            if (empty($buffer)) {
                $bufStart = $start;
            }
            $buffer[] = $word;
            $bufEnd = $end;
        }
        $flush();

        return $cues;
    }

    /**
     * No timings: split the narration into cue-sized chunks and spread them
     * evenly across the scene window.
     *
     * @return array<int, array{start: float, end: float, lines: string[]}>
     */
    private function cuesFromText(string $text, float $sceneStart, float $sceneEnd): array
    {
        $chunks = [];
        $current = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $candidate = trim($current . ' ' . $word);
            if (mb_strlen($candidate) > self::MAX_LINE * self::MAX_LINES && $current !== '') {
                $chunks[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        if (empty($chunks)) {
            return [];
        }

        $span = max(0.8, $sceneEnd - $sceneStart - 0.4); // leave the pacing tail silent
        $per = $span / count($chunks);
        $cues = [];
        foreach ($chunks as $i => $chunk) {
            $cues[] = [
                'start' => $sceneStart + $i * $per,
                'end' => $sceneStart + ($i + 1) * $per - 0.05,
                'lines' => $this->wrap($chunk),
            ];
        }

        return $cues;
    }

    /** Wrap text into ≤2 lines of ≤42 chars (word-boundary greedy). */
    private function wrap(string $text): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = trim($current . ' ' . $word);
            if (mb_strlen($candidate) > self::MAX_LINE && $current !== '') {
                $lines[] = $current;
                $current = $word;
                if (count($lines) === self::MAX_LINES) {
                    break;
                }
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '' && count($lines) < self::MAX_LINES) {
            $lines[] = $current;
        }

        return array_slice($lines, 0, self::MAX_LINES);
    }

    /** 00:01:02,345 */
    private function stamp(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        $h = (int) floor($seconds / 3600);
        $m = (int) floor(fmod($seconds, 3600) / 60);
        $s = (int) floor(fmod($seconds, 60));
        $ms = (int) round(($seconds - floor($seconds)) * 1000);
        if ($ms === 1000) {
            $ms = 0;
            $s++;
        }

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $ms);
    }
}
