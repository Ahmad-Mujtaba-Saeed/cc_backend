<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;

/**
 * ClipEditPlanService
 *
 * Turns a selected clip window into an EDIT PLAN: an ordered list of keep
 * ranges (source-time) that skip the empty parts — silence, music-only holes,
 * dead air between sentences — so the rendered short is continuous speech.
 *
 * The plan has two sources, and they cover for each other:
 *
 *  - TRANSCRIPT GAPS. Consecutive speech segments whose gap is small (a
 *    natural breath) stay in one block; larger gaps are cut out.
 *  - MEASURED SILENCE, passed in by the caller from ffmpeg silencedetect.
 *    YouTube's auto-captions are timed back-to-back — one segment's end IS
 *    the next one's start, even across ten seconds of nothing — so on the
 *    YouTube path the transcript shows no gaps at all and gap-based editing
 *    silently does nothing. Listening to the audio is the only way to find
 *    dead air in that transcript.
 *
 * Each kept block is padded slightly so cuts never clip a word.
 *
 * The service also maps source timestamps onto the edited timeline
 * (toEditedTime) so captions stay in sync after the silent parts are removed.
 */
class ClipEditPlanService
{
    /** Gaps up to this many seconds are natural pauses and stay in the clip. */
    private const MAX_KEEP_GAP = 0.9;

    /** Padding added around each speech block so cuts never clip a word. */
    private const PAD_BEFORE = 0.20;
    private const PAD_AFTER = 0.40;

    /** Don't bother re-cutting (and re-encoding) to save less than this. */
    private const MIN_SAVINGS_SECONDS = 1.25;

    /** Never produce an edited clip shorter than this. */
    private const MIN_EDITED_DURATION = 10.0;

    /** Hard cap on cut points — beyond this the clip feels choppy anyway. */
    private const MAX_RANGES = 24;

    /** A measured silence shorter than this isn't worth a visible cut. */
    private const MIN_SILENCE_TO_CUT = 0.45;

    /** Keep pieces below this are slivers; drop them with the silence. */
    private const MIN_KEEP_PIECE = 0.6;

    /**
     * Build the edit plan for a clip window.
     *
     * @param array $segments Full transcript segments [{start, end, text}, ...] (seconds)
     * @param float $clipStart Selected clip start (source seconds)
     * @param float $clipEnd   Selected clip end (source seconds)
     * @param array<int, array{start: float, end: float}> $measuredSilences
     *        Silence intervals measured from the audio, in source seconds.
     *        Optional — omit to get the transcript-only plan.
     *
     * @return array{
     *   ranges: array<int, array{start: float, end: float}>,
     *   edited_duration: float,
     *   removed_seconds: float,
     *   is_edited: bool
     * }
     */
    public function plan(array $segments, float $clipStart, float $clipEnd, array $measuredSilences = []): array
    {
        $window = max(0.0, $clipEnd - $clipStart);
        $contiguous = [
            'ranges' => [['start' => $clipStart, 'end' => $clipEnd]],
            'edited_duration' => round($window, 3),
            'removed_seconds' => 0.0,
            'is_edited' => false,
        ];

        if ($window <= 0) {
            return $contiguous;
        }

        // Speech spans that overlap the clip window, clamped to it.
        $spans = [];
        foreach ($segments as $seg) {
            $s = (float) ($seg['start'] ?? 0);
            $e = (float) ($seg['end'] ?? 0);
            if ($e <= $clipStart || $s >= $clipEnd || $e <= $s) {
                continue;
            }
            if (trim((string) ($seg['text'] ?? '')) === '') {
                continue;
            }
            $spans[] = ['start' => max($s, $clipStart), 'end' => min($e, $clipEnd)];
        }

        if (empty($spans)) {
            // No transcript inside the window (music, action, missing
            // captions). Measured silence is then the only editor available.
            return $this->applyMeasuredSilence(
                [['start' => $clipStart, 'end' => $clipEnd]],
                $measuredSilences, $clipStart, $clipEnd, $window, $contiguous
            );
        }

        usort($spans, fn ($a, $b) => $a['start'] <=> $b['start']);

        // Merge spans separated by at most MAX_KEEP_GAP into speech blocks.
        $blocks = [];
        $current = $spans[0];
        foreach (array_slice($spans, 1) as $span) {
            if ($span['start'] - $current['end'] <= self::MAX_KEEP_GAP) {
                $current['end'] = max($current['end'], $span['end']);
            } else {
                $blocks[] = $current;
                $current = $span;
            }
        }
        $blocks[] = $current;

        // Pad each block, clamp to the window, and re-merge any overlap the
        // padding created.
        $ranges = [];
        foreach ($blocks as $block) {
            $start = max($clipStart, $block['start'] - self::PAD_BEFORE);
            $end = min($clipEnd, $block['end'] + self::PAD_AFTER);
            if ($end - $start <= 0.05) {
                continue;
            }
            if (!empty($ranges) && $start - $ranges[count($ranges) - 1]['end'] <= 0.05) {
                $ranges[count($ranges) - 1]['end'] = max($ranges[count($ranges) - 1]['end'], $end);
            } else {
                $ranges[] = ['start' => $start, 'end' => $end];
            }
        }

        if (empty($ranges)) {
            return $contiguous;
        }

        return $this->applyMeasuredSilence($ranges, $measuredSilences, $clipStart, $clipEnd, $window, $contiguous);
    }

    /**
     * Subtract measured silence from the transcript-derived keep ranges and
     * finish the plan (cut-count cap, savings guards, logging).
     *
     * @param array<int, array{start: float, end: float}> $ranges
     * @param array<int, array{start: float, end: float}> $measuredSilences
     */
    private function applyMeasuredSilence(
        array $ranges,
        array $measuredSilences,
        float $clipStart,
        float $clipEnd,
        float $window,
        array $contiguous
    ): array {
        $ranges = $this->subtractSilences($ranges, $measuredSilences);

        if (empty($ranges)) {
            return $contiguous;
        }

        // Too many cuts → repeatedly merge across the smallest silence gap
        // (keeps the least-noticeable silences, drops the fewest).
        while (count($ranges) > self::MAX_RANGES) {
            $bestIdx = 0;
            $bestGap = PHP_FLOAT_MAX;
            for ($i = 0; $i < count($ranges) - 1; $i++) {
                $gap = $ranges[$i + 1]['start'] - $ranges[$i]['end'];
                if ($gap < $bestGap) {
                    $bestGap = $gap;
                    $bestIdx = $i;
                }
            }
            $ranges[$bestIdx]['end'] = $ranges[$bestIdx + 1]['end'];
            array_splice($ranges, $bestIdx + 1, 1);
        }

        $edited = 0.0;
        foreach ($ranges as $range) {
            $edited += $range['end'] - $range['start'];
        }
        $removed = $window - $edited;

        // Not worth an edit (or it would gut the clip) → keep the straight cut.
        if ($removed < self::MIN_SAVINGS_SECONDS || $edited < self::MIN_EDITED_DURATION) {
            return $contiguous;
        }

        Log::info('ClipEditPlanService: edit plan built', [
            'clip' => [round($clipStart, 2), round($clipEnd, 2)],
            'ranges' => count($ranges),
            'edited_duration' => round($edited, 2),
            'removed_seconds' => round($removed, 2),
            'measured_silences' => count($measuredSilences),
        ]);

        return [
            'ranges' => array_map(
                fn ($r) => ['start' => round($r['start'], 3), 'end' => round($r['end'], 3)],
                $ranges
            ),
            'edited_duration' => round($edited, 3),
            'removed_seconds' => round($removed, 3),
            'is_edited' => true,
        ];
    }

    /**
     * Remove the measured silent intervals from a set of keep ranges.
     *
     * Each silence is shrunk by the same padding used around speech blocks,
     * so a cut still lands in the quiet rather than on the first syllable
     * after it, and slivers left behind are dropped rather than turned into
     * a extra hard cut nobody can see the point of.
     *
     * @param array<int, array{start: float, end: float}> $ranges
     * @param array<int, array{start: float, end: float}> $silences
     * @return array<int, array{start: float, end: float}>
     */
    private function subtractSilences(array $ranges, array $silences): array
    {
        if (empty($silences)) {
            return $ranges;
        }

        $cuts = [];
        foreach ($silences as $silence) {
            $start = (float) ($silence['start'] ?? 0) + self::PAD_AFTER;
            $end = (float) ($silence['end'] ?? 0) - self::PAD_BEFORE;
            if ($end - $start >= self::MIN_SILENCE_TO_CUT) {
                $cuts[] = ['start' => $start, 'end' => $end];
            }
        }

        if (empty($cuts)) {
            return $ranges;
        }

        usort($cuts, fn ($a, $b) => $a['start'] <=> $b['start']);

        $result = [];
        foreach ($ranges as $range) {
            $pieces = [$range];
            foreach ($cuts as $cut) {
                $next = [];
                foreach ($pieces as $piece) {
                    if ($cut['end'] <= $piece['start'] || $cut['start'] >= $piece['end']) {
                        $next[] = $piece;
                        continue;
                    }
                    if ($cut['start'] > $piece['start']) {
                        $next[] = ['start' => $piece['start'], 'end' => $cut['start']];
                    }
                    if ($cut['end'] < $piece['end']) {
                        $next[] = ['start' => $cut['end'], 'end' => $piece['end']];
                    }
                }
                $pieces = $next;
            }
            foreach ($pieces as $piece) {
                if ($piece['end'] - $piece['start'] >= self::MIN_KEEP_PIECE) {
                    $result[] = $piece;
                }
            }
        }

        return $result;
    }

    /**
     * Map a source timestamp onto the edited timeline defined by keep ranges.
     * Times inside a removed gap collapse onto the gap's cut point.
     */
    public function toEditedTime(float $sourceTime, array $ranges): float
    {
        $elapsed = 0.0;

        foreach ($ranges as $range) {
            if ($sourceTime < $range['start']) {
                return $elapsed;
            }
            if ($sourceTime <= $range['end']) {
                return $elapsed + ($sourceTime - $range['start']);
            }
            $elapsed += $range['end'] - $range['start'];
        }

        return $elapsed;
    }
}
