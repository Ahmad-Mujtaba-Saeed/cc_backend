<?php

namespace Modules\Project\Support;

/**
 * ExplainerTimingMap — the PHP twin of remotion-render/src/timing.ts.
 *
 * Computes each scene's REAL start/end seconds inside the final composition,
 * per composition mode:
 *   - slides: a transition overlaps the two scenes it joins, pulling every
 *     later scene earlier by TRANSITION_SECONDS (scene 1 has no incoming cut);
 *   - canvas: scenes play back to back on one continuous camera timeline;
 *   - hybrid: chapter windows first (chapter transitions overlap exactly like
 *     scene transitions), then each chapter's scenes by its own mode.
 *
 * Consumers: SrtExportService (caption cue offsets) and VlmFrameReviewService
 * (mapping a sampled frame time back to the scene that produced it). Keep the
 * constant in sync with types.ts TRANSITION_SECONDS.
 */
class ExplainerTimingMap
{
    public const TRANSITION_SECONDS = 0.55;

    /** Frame math happens on the renderer's clock; mirror its rounding. */
    private const FPS = 30;

    /**
     * @param  array<int, array>  $scenes  storyboard order; each needs
     *         scene_id, duration_seconds, transition
     * @param  string  $mode  'slides' | 'canvas_journey' | 'hybrid' | 'math_board'
     * @param  array|null  $chapterPlan  settings['chapter_plan'] for hybrid
     * @return array<string, array{start: float, end: float}> keyed by scene_id
     */
    public static function sceneWindows(array $scenes, string $mode, ?array $chapterPlan = null): array
    {
        if ($mode === 'hybrid' && !empty($chapterPlan['chapters'])) {
            return self::hybridWindows($scenes, $chapterPlan);
        }
        // canvas_journey and math_board both play scenes back to back on one
        // continuous clock — no transition overlaps to subtract.
        if ($mode === 'canvas_journey' || $mode === 'math_board') {
            return self::linearWindows($scenes, false);
        }

        return self::linearWindows($scenes, true);
    }

    /** @return array<string, array{start: float, end: float}> */
    private static function linearWindows(array $scenes, bool $subtractOverlaps, float $base = 0.0): array
    {
        $tf = self::frames(self::TRANSITION_SECONDS);
        $cursor = self::frames($base);
        $out = [];
        foreach (array_values($scenes) as $i => $scene) {
            if ($subtractOverlaps && $i > 0 && (($scene['transition'] ?? 'fade') !== 'none')) {
                $cursor -= $tf;
            }
            $frames = max(1, (int) round(((float) ($scene['duration_seconds'] ?? 6)) * self::FPS));
            $out[(string) $scene['scene_id']] = [
                'start' => $cursor / self::FPS,
                'end' => ($cursor + $frames) / self::FPS,
            ];
            $cursor += $frames;
        }

        return $out;
    }

    /** @return array<string, array{start: float, end: float}> */
    private static function hybridWindows(array $scenes, array $chapterPlan): array
    {
        $byId = [];
        foreach ($scenes as $scene) {
            $byId[(string) $scene['scene_id']] = $scene;
        }

        $tf = self::frames(self::TRANSITION_SECONDS);
        $cursor = 0;
        $out = [];
        foreach ((array) $chapterPlan['chapters'] as $i => $chapter) {
            $chapterScenes = [];
            foreach ((array) ($chapter['scene_ids'] ?? []) as $id) {
                if (isset($byId[(string) $id])) {
                    $chapterScenes[] = $byId[(string) $id];
                }
            }
            if (empty($chapterScenes)) {
                continue;
            }

            if ($i > 0 && (($chapter['transition_in'] ?? 'fade') !== 'none')) {
                $cursor -= $tf;
            }

            $slides = ($chapter['mode'] ?? 'slides') !== 'canvas';
            $windows = self::linearWindows($chapterScenes, $slides, $cursor / self::FPS);
            $out += $windows;

            $last = end($windows);
            $cursor = (int) round($last['end'] * self::FPS);
        }

        // Scenes the plan somehow missed still get a window at the tail so no
        // caller ever has to null-check.
        foreach ($scenes as $scene) {
            $id = (string) $scene['scene_id'];
            if (!isset($out[$id])) {
                $dur = (float) ($scene['duration_seconds'] ?? 6);
                $out[$id] = ['start' => $cursor / self::FPS, 'end' => $cursor / self::FPS + $dur];
                $cursor += self::frames($dur);
            }
        }

        return $out;
    }

    /** The scene whose window contains second `t` (last match wins on overlap). */
    public static function sceneAt(array $windows, float $t): ?string
    {
        $found = null;
        foreach ($windows as $sceneId => $w) {
            if ($t >= $w['start'] && $t < $w['end']) {
                $found = (string) $sceneId;
            }
        }

        return $found;
    }

    /** Total composition seconds implied by the windows. */
    public static function totalSeconds(array $windows): float
    {
        $max = 0.0;
        foreach ($windows as $w) {
            $max = max($max, (float) $w['end']);
        }

        return $max;
    }

    private static function frames(float $seconds): int
    {
        return max(0, (int) round($seconds * self::FPS));
    }
}
