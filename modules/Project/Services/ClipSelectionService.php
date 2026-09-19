<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Modules\Project\Support\LlmModels;

/**
 * ClipSelectionService
 * 
 * Intelligent multi-pass hierarchical grading system to find the best 20-60s clip
 * from a transcript of any length (handles 2+ hour videos efficiently).
 * 
 * Phase 1: Score windows in parallel with GPT
 * Phase 2: Filter and rank top candidates
 * Phase 3: Final head-to-head selection
 */
class ClipSelectionService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        if (empty($this->apiKey)) {
            throw new \Exception('OPENAI_API_KEY is not set');
        }
        $this->model = LlmModels::for('general');
    }

    /**
     * Find the best clip from a full transcript
     * 
     * Returns: [
     *   'final_start_seconds' => float,
     *   'final_end_seconds' => float,
     *   'subtitle' => string,
     *   'highlight_words' => array,
     *   'reason' => string,
     *   'final_score' => int
     * ]
     */
    public function findBestClip(array $transcriptSegments, float $totalDurationSeconds): array
    {
        $clips = $this->findBestClips($transcriptSegments, $totalDurationSeconds, 1);

        if (empty($clips)) {
            throw new \Exception('No suitable clips found in transcript — video content may not contain a strong standalone segment');
        }

        return $clips[0];
    }

    /**
     * Find up to $count of the best NON-OVERLAPPING clips from a transcript.
     *
     * Reuses the Phase 1 scoring and ranking, then greedily selects clips by
     * score while skipping any candidate that overlaps an already-selected
     * clip. Returns as many clips as are available (never fails just because
     * fewer than $count strong candidates exist).
     *
     * Each returned clip has the same shape as findBestClip():
     *   final_start_seconds, final_end_seconds, subtitle, highlight_words,
     *   final_score, reason
     *
     * @return array<int, array>
     */
    public function findBestClips(array $transcriptSegments, float $totalDurationSeconds, int $count = 1, string $mode = 'story'): array
    {
        $count = max(1, min(12, $count));

        Log::info('ClipSelectionService: Starting clip selection', [
            'total_segments' => count($transcriptSegments),
            'total_duration' => $totalDurationSeconds,
            'requested_clips' => $count,
            'mode' => $mode
        ]);

        $strategy = $this->calculateWindowStrategy($totalDurationSeconds, $count);
        $windows = $this->buildWindows($transcriptSegments, $strategy);

        Log::info('ClipSelectionService: Windows calculated', [
            'window_count' => count($windows),
            'strategy' => $strategy
        ]);

        // Phase 1: Score all windows. When several clips are wanted, ask each
        // window for its top candidates instead of just one so the pool is
        // large enough to pick $count non-overlapping winners from.
        $perWindow = $count > 1 ? min(3, max(2, (int) ceil(($count * 2) / max(1, count($windows))))) : 1;
        $phase1Results = $this->scoreWindowsParallel($windows, $totalDurationSeconds, $perWindow, $mode);

        Log::info('ClipSelectionService: Phase 1 complete', [
            'candidates' => count($phase1Results),
            'top_scores' => array_slice(array_column($phase1Results, 'score'), 0, 5)
        ]);

        // Phase 2: Filter, dedupe near-identical windows finds, and rank.
        $poolSize = max(6, $count * 3);
        $topCandidates = $this->filterAndRank($phase1Results, $poolSize, $mode);

        if (empty($topCandidates)) {
            Log::warning('ClipSelectionService: No candidates passed ranking');
            return [];
        }

        Log::info('ClipSelectionService: Phase 2 complete', [
            'top_candidates' => count($topCandidates),
            'scores' => array_column($topCandidates, 'score')
        ]);

        // When only a single clip is requested, run the Phase 3 head-to-head
        // refinement on the top candidates for the best possible pick.
        if ($count === 1) {
            try {
                $winner = $this->runFinalSelection(array_slice($topCandidates, 0, 3));
                if ($mode !== 'moments') {
                    $winner = $this->snapClipToSentences($winner, $transcriptSegments, $totalDurationSeconds);
                }
                $this->validateClipSelection($winner, $totalDurationSeconds, $mode);
                return [$winner];
            } catch (\Exception $e) {
                Log::warning('ClipSelectionService: Phase 3 refinement failed, using top ranked clip', [
                    'error' => $e->getMessage()
                ]);
                // fall through to the greedy pick below
            }
        }

        // Snap + validate every candidate ONCE, in score order. Snapping moves
        // boundaries, so overlap and spacing must be tested on the final
        // boundaries, not the raw AI ones.
        $ready = [];
        foreach ($topCandidates as $candidate) {
            $start = floatval($candidate['start_seconds'] ?? 0);
            $end = floatval($candidate['end_seconds'] ?? 0);

            if ($end <= $start) {
                continue;
            }

            $clip = [
                'final_start_seconds' => $start,
                'final_end_seconds' => $end,
                'subtitle' => $candidate['subtitle'] ?? '',
                'highlight_words' => $candidate['highlight_words'] ?? [],
                'final_score' => intval($candidate['score'] ?? 0),
                'reason' => $candidate['reason'] ?? ''
            ];

            // Moments are single short action events, often with sparse speech —
            // sentence snapping would inflate them to ≥15s of unrelated footage
            // around the event, so only story clips get snapped.
            if ($mode !== 'moments') {
                $clip = $this->snapClipToSentences($clip, $transcriptSegments, $totalDurationSeconds);
            }

            try {
                $this->validateClipSelection($clip, $totalDurationSeconds, $mode);
            } catch (\Exception $e) {
                Log::warning('ClipSelectionService: skipping invalid clip candidate', [
                    'error' => $e->getMessage(),
                    'start' => $start,
                    'end' => $end
                ]);
                continue;
            }

            // Re-score on the FINAL boundaries. Snapping moves them, and the
            // hook/payoff/pacing signals are all boundary-dependent, so this
            // has to happen after the snap and before any ranking.
            if ($mode !== 'moments') {
                $clip['ai_score'] = $clip['final_score'];
                $clip['final_score'] = max(0, min(100, $clip['final_score'] + $this->viralAdjustment($clip)));
            }

            $ready[] = $clip;
        }

        // The adjustment reorders the pool, so re-rank before picking.
        usort($ready, fn ($a, $b) => $b['final_score'] <=> $a['final_score']);

        // Pass 1: greedy by score, but keep the winners SPREAD across the
        // video. Model scores bunch in the high 80s and PHP's sort is stable,
        // so a pure score sort resolves every tie chronologically and hands
        // back four clips from the opening minutes of a two-hour stream.
        $selected = $this->pickSpread($ready, $count, $this->minSeparation($totalDurationSeconds, $count));

        // Pass 2: a strict spread can starve the result on a video whose good
        // material really is clustered. Backfill by score with the overlap
        // rule alone rather than return fewer shorts than were asked for.
        if (count($selected) < $count) {
            foreach ($ready as $clip) {
                if (count($selected) >= $count) {
                    break;
                }
                if ($this->overlapsAccepted($clip, $selected) || $this->alreadySelected($clip, $selected)) {
                    continue;
                }
                $selected[] = $clip;
            }
            usort($selected, fn ($a, $b) => $b['final_score'] <=> $a['final_score']);
        }

        // Final pass: one head-to-head call over the winners. Phase 1 graded
        // each clip against its own window in isolation and never saw the
        // others, so its scores cannot rank the batch. This pass does, and the
        // caller's quality gate cuts the tail of exactly this ordering.
        if (count($selected) > 1 && $mode !== 'moments') {
            $selected = $this->rankFinalists($selected);
        }

        Log::info('ClipSelectionService: Clip selection complete', [
            'requested' => $count,
            'selected' => count($selected),
            'clips' => array_map(fn($c) => [
                'start' => $c['final_start_seconds'],
                'end' => $c['final_end_seconds'],
                'score' => $c['final_score']
            ], $selected)
        ]);

        return $selected;
    }

    /**
     * Words that open a scroll-stopping first sentence. Checked against the
     * clip's opening only — the same word twenty seconds in proves nothing.
     */
    private const HOOK_OPENERS = [
        'why', 'how', 'what', 'who', 'when', 'imagine', 'nobody', 'everyone', 'most people',
        'the truth', 'the secret', 'the problem', 'the biggest', 'the worst', 'the reason',
        'never', 'always', 'stop', 'listen', 'look', 'turns out', 'i was', 'i lost', 'i quit',
        'i got', 'you think', 'people think', 'they told', 'nobody talks', 'here is', "here's",
        'this is why', 'that is when', "that's when",
    ];

    /** Emotional / stakes vocabulary that travels in the feed. */
    private const EMOTION_WORDS = [
        'crazy', 'insane', 'shocking', 'unbelievable', 'terrifying', 'hilarious', 'brutal',
        'wild', 'died', 'death', 'scared', 'furious', 'angry', 'hate', 'love', 'worst',
        'best', 'biggest', 'illegal', 'arrested', 'fired', 'bankrupt', 'lawsuit', 'secret',
        'lied', 'lying', 'stole', 'ruined', 'disaster', 'nightmare', 'obsessed', 'humiliating',
    ];

    /** Channel housekeeping — never viral, always drags a clip down. */
    private const KILL_PHRASES = [
        'subscribe', 'sponsor', 'link in the description', 'link in the bio', 'patreon',
        'like and share', 'smash that like', 'as you can see', 'on the screen', 'up here',
        'welcome back to', 'in this video', 'before we get started', 'hit the bell',
        'thanks for watching', 'use my code', 'promo code',
    ];

    /**
     * A virality adjustment computed from the clip's own text and timing.
     *
     * The model's scores bunch in the high 80s — it grades every candidate as
     * "pretty good" — so on their own they neither rank the batch nor tell the
     * quality gate where to cut. These signals are cheap, deterministic and
     * measure the things the model reliably under-weights: whether the FIRST
     * sentence actually hooks, whether the clip ends on a finished thought,
     * how densely packed the speech is, and whether it is channel housekeeping
     * wearing a story's clothes.
     *
     * Returns a delta in roughly [-30, +20]; the caller clamps the sum to 0-100.
     */
    private function viralAdjustment(array $clip): int
    {
        $text = trim((string) ($clip['subtitle'] ?? ''));
        if ($text === '') {
            return -10;
        }

        $duration = max(0.1, (float) $clip['final_end_seconds'] - (float) $clip['final_start_seconds']);
        $lower = mb_strtolower($text);
        $words = preg_split('/\s+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $wordCount = count($words);
        $opening = implode(' ', array_slice($words, 0, 14));

        $delta = 0;

        // ── Second 0-3: does the clip open on a hook? ──
        foreach (self::HOOK_OPENERS as $opener) {
            if (str_contains($opening, $opener)) {
                $delta += 7;
                break;
            }
        }
        // A question or an exclamation early on is an open loop by construction.
        $openingRaw = mb_substr($text, 0, 120);
        if (str_contains($openingRaw, '?') || str_contains($openingRaw, '!')) {
            $delta += 4;
        }
        // Receipts: a number in the opening beats a number buried at the end.
        if (preg_match('/\d/', $openingRaw)) {
            $delta += 3;
        } elseif (preg_match('/\d/', $text)) {
            $delta += 1;
        }

        // ── Shareability vocabulary (capped, so keyword spam cannot win) ──
        $emotion = 0;
        foreach (self::EMOTION_WORDS as $word) {
            if (str_contains($lower, $word)) {
                $emotion++;
            }
        }
        $delta += min(8, $emotion * 2);

        // ── Channel housekeeping drags hard ──
        $kills = 0;
        foreach (self::KILL_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                $kills++;
            }
        }
        $delta -= min(25, $kills * 10);

        // ── Retention: dense speech reads as fast-paced; sparse reads as dead air ──
        $wordsPerSecond = $wordCount / $duration;
        if ($wordsPerSecond < 1.4) {
            $delta -= 8;
        } elseif ($wordsPerSecond < 2.0) {
            $delta -= 3;
        } elseif ($wordsPerSecond >= 2.4) {
            $delta += 4;
        }

        // ── Length: the sweet spot for a talking-head short ──
        if ($duration >= 18.0 && $duration <= 45.0) {
            $delta += 5;
        } elseif ($duration > 55.0) {
            $delta -= 5;
        } elseif ($duration < 17.0) {
            $delta -= 3;
        }

        // ── Does it land? An unfinished last sentence has no payoff. ──
        $lastChar = mb_substr(rtrim($text), -1);
        $delta += in_array($lastChar, ['.', '!', '?', '"', '”'], true) ? 3 : -6;

        return max(-30, min(20, $delta));
    }

    /**
     * How far apart two chosen clips should ideally start, so a set of shorts
     * samples the whole video instead of one hot patch. Scales with the source
     * length and is capped so it never dominates a short video.
     */
    private function minSeparation(float $totalDuration, int $count): float
    {
        if ($totalDuration <= 0 || $count <= 1) {
            return 0.0;
        }

        return min(420.0, $totalDuration / ($count * 2));
    }

    /**
     * Greedy pick by score that also requires each winner to start at least
     * $minSeparation away from every clip already taken. Returns as many as it
     * can find - the caller backfills if that is fewer than requested.
     */
    private function pickSpread(array $ready, int $count, float $minSeparation): array
    {
        $selected = [];

        foreach ($ready as $clip) {
            if (count($selected) >= $count) {
                break;
            }
            if ($this->overlapsAccepted($clip, $selected)) {
                continue;
            }

            $tooClose = false;
            foreach ($selected as $chosen) {
                if (abs($clip['final_start_seconds'] - $chosen['final_start_seconds']) < $minSeparation) {
                    $tooClose = true;
                    break;
                }
            }
            if ($tooClose) {
                continue;
            }

            $selected[] = $clip;
        }

        return $selected;
    }

    /** Identity check for the backfill pass (same snapped boundaries). */
    private function alreadySelected(array $clip, array $selected): bool
    {
        foreach ($selected as $chosen) {
            if (abs($clip['final_start_seconds'] - $chosen['final_start_seconds']) < 0.01
                && abs($clip['final_end_seconds'] - $chosen['final_end_seconds']) < 0.01) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this (already snapped) clip meaningfully overlap any accepted clip?
     * A tiny post-snap fringe (< 2s and < 15% of the clip) is tolerated so a
     * shared boundary sentence doesn't cost us a whole video.
     */
    private function overlapsAccepted(array $clip, array $accepted): bool
    {
        $start = $clip['final_start_seconds'];
        $end = $clip['final_end_seconds'];
        $duration = max(0.001, $end - $start);

        foreach ($accepted as $chosen) {
            $overlap = min($end, $chosen['final_end_seconds']) - max($start, $chosen['final_start_seconds']);
            if ($overlap > 0 && ($overlap > 2.0 || ($overlap / $duration) > 0.15)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Snap a clip's start/end to sentence boundaries from the punctuated
     * transcript so the short opens on a full hook sentence and closes on a
     * resolved sentence — never a mid-sentence chop. Keeps the result within
     * the 15–60s target, extending/trimming to the nearest sentence end.
     */
    private function snapClipToSentences(array $clip, array $segments, float $totalDuration): array
    {
        $segments = array_values(array_filter($segments, fn ($s) => isset($s['start'], $s['end'], $s['text'])));
        $n = count($segments);
        if ($n === 0) {
            return $clip;
        }

        $origStart = (float) $clip['final_start_seconds'];
        $origEnd = (float) $clip['final_end_seconds'];

        // ---- START: include the whole opening sentence ----
        $startIdx = $this->nearestIndexByStart($segments, $origStart);
        $startIdx = $this->findSentenceStartIndex($segments, $startIdx, 8);
        $newStart = (float) $segments[$startIdx]['start'];

        // ---- END: finish on a sentence-ending segment ----
        $endIdx = $this->nearestIndexByEnd($segments, $origEnd);
        if ($endIdx < $startIdx) {
            $endIdx = $startIdx;
        }
        $endIdx = $this->findSentenceEndIndex($segments, $endIdx, 8);
        $newEnd = (float) $segments[$endIdx]['end'];

        // ---- Keep within 15–60s, snapping to sentence ends where possible ----
        $minDur = 15.0;
        $maxDur = 60.0;

        // Extend forward until we both clear the minimum duration AND finish on
        // a sentence end — but never overshoot the max. (Stops mid-sentence only
        // if a single sentence is longer than maxDur, handled by the trim below.)
        while ($endIdx < $n - 1) {
            $dur = $newEnd - $newStart;
            if ($dur >= $minDur && $this->segmentEndsSentence($segments, $endIdx)) {
                break;
            }
            if ($dur >= $maxDur) {
                break;
            }
            $endIdx++;
            $newEnd = (float) $segments[$endIdx]['end'];
        }

        // Too long → trim back to the last sentence end that still fits.
        if (($newEnd - $newStart) > $maxDur) {
            for ($i = $endIdx; $i > $startIdx; $i--) {
                $candidateEnd = (float) $segments[$i]['end'];
                if (($candidateEnd - $newStart) <= $maxDur && $this->segmentEndsSentence($segments, $i)) {
                    $newEnd = $candidateEnd;
                    break;
                }
            }
            // Still too long (no sentence end fit) → hard clamp.
            if (($newEnd - $newStart) > $maxDur) {
                $newEnd = $newStart + $maxDur;
            }
        }

        // Bounds safety.
        $newStart = max(0.0, $newStart);
        $newEnd = min($totalDuration > 0 ? $totalDuration : $newEnd, $newEnd);
        if (($newEnd - $newStart) < $minDur) {
            // Degenerate snap — keep the original AI boundaries.
            return $clip;
        }

        if (abs($newStart - $origStart) > 0.05 || abs($newEnd - $origEnd) > 0.05) {
            Log::info('ClipSelectionService: snapped clip to sentence boundaries', [
                'from' => [round($origStart, 2), round($origEnd, 2)],
                'to' => [round($newStart, 2), round($newEnd, 2)],
            ]);
        }

        $clip['final_start_seconds'] = $newStart;
        $clip['final_end_seconds'] = $newEnd;
        return $clip;
    }

    private function nearestIndexByStart(array $segments, float $t): int
    {
        $best = 0;
        $bestDiff = PHP_FLOAT_MAX;
        foreach ($segments as $i => $seg) {
            $diff = abs(((float) $seg['start']) - $t);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $i;
            }
        }
        return $best;
    }

    private function nearestIndexByEnd(array $segments, float $t): int
    {
        $best = 0;
        $bestDiff = PHP_FLOAT_MAX;
        foreach ($segments as $i => $seg) {
            $diff = abs(((float) $seg['end']) - $t);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $i;
            }
        }
        return $best;
    }

    /**
     * Walk to the first segment of the sentence containing $idx (so we keep the
     * whole opening sentence). Search backward within $window; if the sentence
     * start is too far back, instead snap forward to the next sentence start.
     */
    private function findSentenceStartIndex(array $segments, int $idx, int $window): int
    {
        for ($i = $idx, $steps = 0; $i >= 0 && $steps < $window; $i--, $steps++) {
            if ($this->segmentStartsSentence($segments, $i)) {
                return $i;
            }
        }
        // Backward search exhausted — try forward to a clean sentence start.
        $n = count($segments);
        for ($i = $idx, $steps = 0; $i < $n && $steps < $window; $i++, $steps++) {
            if ($this->segmentStartsSentence($segments, $i)) {
                return $i;
            }
        }
        return $idx;
    }

    /**
     * Walk forward to the next sentence-ending segment within $window; if none,
     * walk backward to the previous sentence end. Falls back to $idx.
     */
    private function findSentenceEndIndex(array $segments, int $idx, int $window): int
    {
        $n = count($segments);
        for ($i = $idx, $steps = 0; $i < $n && $steps < $window; $i++, $steps++) {
            if ($this->segmentEndsSentence($segments, $i)) {
                return $i;
            }
        }
        for ($i = $idx, $steps = 0; $i >= 0 && $steps < $window; $i--, $steps++) {
            if ($this->segmentEndsSentence($segments, $i)) {
                return $i;
            }
        }
        return $idx;
    }

    /**
     * A segment starts a sentence when it's the first segment or the previous
     * segment's text ends with sentence-ending punctuation.
     */
    private function segmentStartsSentence(array $segments, int $idx): bool
    {
        if ($idx <= 0) {
            return true;
        }
        return $this->segmentEndsSentence($segments, $idx - 1);
    }

    private function segmentEndsSentence(array $segments, int $idx): bool
    {
        $text = rtrim((string) ($segments[$idx]['text'] ?? ''));
        // Strip trailing closing quotes/brackets before testing the punctuation.
        $text = rtrim($text, "\"'”’)]");
        $last = substr($text, -1);
        return $last === '.' || $last === '!' || $last === '?';
    }

    /**
     * Calculate window strategy based on total video duration
     * 
     * Shorter videos: more windows, smaller windows (finer granularity)
     * Longer videos: fewer windows, larger windows (efficiency)
     */
    private function calculateWindowStrategy(float $totalDurationSeconds, int $clipCount = 1): array
    {
        if ($totalDurationSeconds <= 600) {          // ≤10 min
            $strategy = ['window_seconds' => 120, 'overlap_seconds' => 30, 'max_windows' => 5];
        } elseif ($totalDurationSeconds <= 1800) {   // ≤30 min
            $strategy = ['window_seconds' => 300, 'overlap_seconds' => 60, 'max_windows' => 6];
        } elseif ($totalDurationSeconds <= 3600) {   // ≤1 hour
            $strategy = ['window_seconds' => 600, 'overlap_seconds' => 120, 'max_windows' => 6];
        } elseif ($totalDurationSeconds <= 7200) {   // ≤2 hours
            $strategy = ['window_seconds' => 1200, 'overlap_seconds' => 180, 'max_windows' => 6];
        } else {                                      // >2 hours
            $strategy = ['window_seconds' => 1800, 'overlap_seconds' => 300, 'max_windows' => 6];
        }

        // Multi-clip runs need wider coverage so distinct stories can come
        // from different parts of the video. A 12-short batch cannot be filled
        // from 6 windows without stacking picks on top of each other, so the
        // ceiling scales with the ask.
        if ($clipCount > 1) {
            $strategy['max_windows'] = min(24, $strategy['max_windows'] + (int) ceil($clipCount * 0.75));
        }

        // A batch needs a candidate pool comfortably larger than the batch:
        // dedupe, the overlap rule and the spread rule all eat candidates. The
        // natural stride alone gives ~8 windows however many shorts are asked
        // for, so ask for extra windows (which simply overlap more) instead.
        $strategy['min_windows'] = min($strategy['max_windows'], $clipCount + 2);

        return $strategy;
    }

    /**
     * Build overlapping time windows from transcript
     * 
     * Each window contains all segments that fall within [start, end).
     * Overlap ensures no good clip gets split across boundaries.
     */
    private function buildWindows(array $segments, array $strategy): array
    {
        $windows = [];
        $windowSeconds = $strategy['window_seconds'];
        $overlapSeconds = $strategy['overlap_seconds'];
        $maxWindows = $strategy['max_windows'];

        $lastSegment = end($segments);
        $totalDuration = floatval($lastSegment['end'] ?? 0);

        $minWindows = max(1, (int) ($strategy['min_windows'] ?? 1));
        $step = max(1.0, (float) ($windowSeconds - $overlapSeconds));
        $natural = max(1, (int) ceil($totalDuration / $step));
        $windowCount = (int) min(max($natural, $minWindows), $maxWindows);

        // Spread the windows across the WHOLE video instead of walking
        // contiguously from 0. Walking from 0 meant a capped run only ever
        // read the opening stretch of a long video — every short in a batch
        // came out of the first half hour of a three-hour stream. The stride
        // now stretches (sampling with gaps) or shrinks (denser overlap, for a
        // big batch) so the last window lands on the end of the video, with a
        // floor so extra windows can never collapse onto each other.
        if ($windowCount > 1) {
            $step = max($windowSeconds * 0.3, ($totalDuration - $windowSeconds) / ($windowCount - 1));
        }

        for ($i = 0; $i < $windowCount; $i++) {
            $start = max(0.0, $i * $step);
            $end = $start + $windowSeconds;

            $windowSegments = array_filter($segments, function ($seg) use ($start, $end) {
                $segStart = floatval($seg['start'] ?? 0);
                $segEnd = floatval($seg['end'] ?? 0);
                return $segEnd > $start && $segStart < $end;
            });

            if (!empty($windowSegments)) {
                $windows[] = [
                    'window_index' => $i,
                    'start_time' => $start,
                    'end_time' => $end,
                    'segments' => array_values($windowSegments)
                ];
            }
        }

        return $windows;
    }

    /**
     * Score all windows, collecting up to $candidatesPerWindow clip candidates
     * from each.
     */
    private function scoreWindowsParallel(array $windows, float $totalDuration, int $candidatesPerWindow = 1, string $mode = 'story'): array
    {
        $candidates = [];

        foreach ($windows as $window) {
            try {
                $windowCandidates = $this->scoreWindow($window, $totalDuration, $candidatesPerWindow, $mode);
                foreach ($windowCandidates as $candidate) {
                    $candidates[] = $candidate;
                }
            } catch (\Exception $e) {
                Log::warning("Window scoring failed for window {$window['window_index']}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $candidates;
    }

    /**
     * Score a single window via GPT Phase 1 prompt.
     *
     * Returns up to $maxCandidates candidates from the window (the model is
     * asked for a ranked, non-overlapping list). Tolerates both the list shape
     * ({"clips": [...]}) and the legacy single-object shape.
     */
    private function scoreWindow(array $window, float $totalDuration, int $maxCandidates = 1, string $mode = 'story'): array
    {
        $systemPrompt = $this->buildPhase1SystemPrompt($mode);
        $userPrompt = $this->buildPhase1Prompt(
            $window['segments'],
            $window['start_time'],
            $window['end_time'],
            $totalDuration,
            $maxCandidates,
            $mode
        );

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 400 + 500 * $maxCandidates,
                    'response_format' => ['type' => 'json_object']
                ]);

            if (!$response->successful()) {
                Log::error('GPT Phase 1 request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return [];
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'clip_selection_phase1');

            $data = $response->json();
            $result = $data['choices'][0]['message']['content'] ?? null;
            $parsed = json_decode($result, true);

            if (!is_array($parsed)) {
                Log::warning('GPT Phase 1: Invalid JSON response', ['response' => substr($result ?? '', 0, 200)]);
                return [];
            }

            if (isset($parsed['clips']) && is_array($parsed['clips'])) {
                $rows = $parsed['clips'];
            } elseif (isset($parsed['start_seconds'])) {
                $rows = [$parsed];
            } else {
                $rows = [];
            }

            $candidates = [];
            foreach (array_slice($rows, 0, $maxCandidates) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $candidate = [
                    'window_index' => $window['window_index'],
                    'start_seconds' => floatval($row['start_seconds'] ?? 0),
                    'end_seconds' => floatval($row['end_seconds'] ?? 0),
                    'subtitle' => $row['subtitle'] ?? '',
                    'highlight_words' => $row['highlight_words'] ?? [],
                    'score' => intval($row['score'] ?? 0),
                    'score_breakdown' => $row['score_breakdown'] ?? [],
                    'reason' => $row['reason'] ?? ''
                ];

                if (!$this->candidateIsInWindow($candidate, $window, $mode)) {
                    continue;
                }

                $candidates[] = $candidate;
            }

            return $candidates;

        } catch (\Exception $e) {
            Log::error('GPT Phase 1 call failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Reject a Phase-1 candidate whose timestamps cannot have come from this
     * window's transcript.
     *
     * The model is shown one slice of the video and must answer in SOURCE
     * seconds. When it answers with a number from somewhere else — a relative
     * offset, a mis-parsed timestamp, a zero-length range — the candidate is
     * worthless but still carries a high score, so it wins the ranking and
     * then gets sentence-snapped into a random stretch of footage. Dropping it
     * here costs one candidate; keeping it costs a whole short.
     */
    private function candidateIsInWindow(array $candidate, array $window, string $mode): bool
    {
        $start = $candidate['start_seconds'];
        $end = $candidate['end_seconds'];
        $length = $end - $start;

        // Only kill answers that cannot be repaired. An under-sized story
        // pick usually means the model found the right moment and stopped
        // one sentence early - sentence snapping grows that back to 15s+, so
        // throwing it away would lose real material. A near-zero range is a
        // parse failure and there is nothing to grow.
        $minLength = $mode === 'moments' ? 4.0 : 3.0;

        // A little slack: the model may land a boundary just outside the slice
        // it was shown (the overlap region of the neighbouring window).
        $slack = 30.0;
        $lo = $window['start_time'] - $slack;
        $hi = $window['end_time'] + $slack;

        $reason = null;
        if ($length < $minLength) {
            $reason = 'degenerate length';
        } elseif ($start < $lo || $end > $hi) {
            $reason = 'outside window range';
        }

        if ($reason === null) {
            return true;
        }

        Log::warning('ClipSelectionService: discarding implausible Phase 1 candidate', [
            'reason' => $reason,
            'window' => $window['window_index'],
            'window_range' => [$window['start_time'], $window['end_time']],
            'candidate' => [round($start, 1), round($end, 1)],
            'score' => $candidate['score'],
        ]);

        return false;
    }

    private function buildPhase1SystemPrompt(string $mode = 'story'): string
    {
        // 'moments' mode: for ranking/countdown templates over action content
        // (compilations, sports, gameplay) where speech is sparse. The story
        // prompt below would (correctly, per its rules) return zero clips for
        // such videos — commentary never forms complete hook→payoff stories.
        if ($mode === 'moments') {
            return <<<'PROMPT'
You are an expert short-form editor picking the most EXCITING MOMENTS in a video for a
countdown ranking short (like "Top 5 Best Moments"). The video may be a compilation,
sports footage, gameplay or other action content where speech is sparse — that is
expected and completely fine.

A MOMENT is EXACTLY ONE event — a single goal, play, stunt, reveal, punchline, drop,
or big reaction. It is NOT a narrated story and NEVER a montage:
- one pick = one event. If a section contains several great events, return them as
  SEPARATE picks; never stretch one pick's boundaries to cover two or more events
- the transcript is a MAP to the action: commentary spikes, names, numbers,
  exclamations and hype words mark where something notable happens
- large gaps between timestamps mean crowd noise / music / action without speech —
  in this content that is often the best part, so do NOT penalise silence

BOUNDARY RULES:
- start slightly before the action builds and end just after the peak resolves
- when speech is present at a boundary, land on full sentences where possible

LENGTH: each moment should be 6 to 20 seconds — just the build-up and the peak of
that ONE event. A window long enough to contain a second event is too long.

Scoring criteria (weight):
- Peak excitement / impact of the moment (40%)
- Standalone clarity: watchable with zero prior context (25%)
- Commentary or crowd reaction energy — a bonus, never a requirement (20%)
- Distinctiveness from the other picks in this section (15%)

IMPORTANT: if this section has ANY usable content, ALWAYS return moments — pick the
best available even when none are perfect, and score them honestly (a weak section
gets low scores). Only return an empty list when the section is truly unusable.

Return ONLY valid JSON. No markdown. No explanation outside the JSON.
PROMPT;
        }

        return <<<'PROMPT'
You are a top short-form editor who has cut thousands of clips that passed a million views.
From a section of transcript, pick the clip(s) most likely to GO VIRAL as a standalone short.

Judge every candidate the way the feed does — second by second:

SECOND 0-3 (the scroll-stopper). The very first sentence decides everything. It must be a
claim, a question, a number, a confession, a contradiction or a promise that creates an
instant open loop ("Nobody tells you this", "I lost $40,000 in one night", "Here's why that's
completely wrong"). If the first sentence is context, throat-clearing, a greeting, a name
drop, or "so basically", the clip is DEAD — move the start later to the sentence that
actually hooks, or pick a different clip.

SECOND 3-20 (retention). Every sentence must either raise the tension or pay part of it off.
Tangents, backtracking, repeated setup and "anyway, as I was saying" kill retention.

THE ENDING (the payoff). It must LAND: the open loop closes, the punchline hits, the lesson
snaps shut. A viewer should feel it finished, not that it was chopped. A clip that trails off
into "...and yeah, so that's kind of it" is worthless no matter how good the hook was.

WHAT MAKES PEOPLE SHARE (look for at least one):
- a surprising or counter-intuitive claim that contradicts what people assume
- a concrete, quotable line someone would repeat verbatim
- real stakes: money, failure, danger, conflict, an embarrassing admission
- specific numbers, names, dates, receipts — vague inspiration does not travel
- strong emotion: shock, outrage, awe, secondhand cringe, genuine laughter

HARD BOUNDARY RULES (critical):
- The clip MUST start at the FIRST WORD OF A SENTENCE. Never start mid-sentence or on filler
  ("um", "so yeah", "and then", "but like").
- The clip MUST end at the END OF A SENTENCE (the words ending in . ! or ?). Never cut mid-sentence.
- Trim any greeting/filler lead-in and any trailing tangent so every second carries the story fast.

LENGTH: Choose whatever length tells the complete story best — anywhere from 15 to 60 seconds.
20-45s is the sweet spot. Do not pad to fill time and do not truncate meaning to be short.
Tight and complete beats long.

DEAD AIR: The timestamps reveal silence — a large jump between consecutive segment timestamps
means nobody is talking (music, gameplay noise, pauses). Prefer clips with continuous speech.
A clip whose range contains long silent holes is weaker; pick a tighter continuous story instead.

Scoring criteria (weight):
- Hook strength: does the FIRST SENTENCE alone stop a scroll? (35%)
- Payoff: the ending lands and closes the loop it opened (25%)
- Shareability: surprise, stakes, emotion, or a quotable line (20%)
- Standalone clarity: fully understandable with zero prior context (10%)
- Specificity: concrete facts, numbers, names, examples (5%)
- Audio-only viability: works without visuals ("as you can see" is disqualifying) (5%)

Be a harsh grader. 90+ means you would personally post it. 70 is decent but forgettable.
Below 50 means it should not be published. Do not inflate — an honest 55 is far more useful
than a generous 85, because low scorers are dropped rather than shipped.

DISQUALIFY: intros, outros, sponsor reads, subscribe begs, channel housekeeping, and any clip
that ends on an unresolved/incomplete thought.

Return ONLY valid JSON. No markdown. No explanation outside the JSON.
PROMPT;
    }

    /**
     * A timestamp the model can read back verbatim: one decimal, NO thousands
     * separator. See the note in buildPhase1Prompt() for why this matters.
     */
    private static function formatSeconds(float $seconds): string
    {
        return number_format($seconds, 1, '.', '');
    }

    private function buildPhase1Prompt(array $segments, float $windowStart, float $windowEnd, float $totalDuration, int $maxCandidates = 1, string $mode = 'story'): string
    {
        // NOTE: never number_format() a timestamp here. Its default thousands
        // separator renders 1543.3s as "1,543.3s", and the model reads that
        // comma as a decimal group and answers with 1.5 — so on any video
        // longer than 16m40s every window past the first returned timestamps
        // collapsed into the opening minutes. Plain '.'-only formatting.
        $formattedSegments = array_map(function ($seg) {
            $time = self::formatSeconds((float) ($seg['start'] ?? 0));
            $text = $seg['text'] ?? '';
            return "[{$time}s]: {$text}";
        }, $segments);

        $segmentText = implode("\n", $formattedSegments);

        if ($mode === 'moments') {
            $clipInstruction = $maxCandidates > 1
                ? "Find the {$maxCandidates} most exciting NON-OVERLAPPING moments in this section, ordered best-first.\n" .
                  "Return fewer only if the section genuinely does not contain that many usable moments."
                : "Find the single most exciting moment in this section.";
            $lengthLine = "Each pick must contain exactly ONE event. Pick the length (6-20s) that captures the\n" .
                "build-up and the peak of that single event — never stretch a pick to cover a second event.\n" .
                "Both numbers are ABSOLUTE seconds copied from the [..s] labels above, not offsets into this section.";
            $emptyRule = 'Score must be 0-100. Always return the best available moments; only return {"clips": []} if this section is truly unusable.';
        } else {
            $clipInstruction = $maxCandidates > 1
                ? "Find up to {$maxCandidates} of the best self-contained clips (each a complete hook -> payoff).\n" .
                  "The clips MUST NOT overlap each other and must each stand alone. Order them best-first.\n" .
                  "Return fewer clips (or an empty list) rather than padding with weak ones."
                : "Find the single best self-contained clip (complete hook -> payoff).";
            $lengthLine = "Make start_seconds land on the first word of the opening sentence and end_seconds on the last\n" .
                "word of the closing sentence. Pick the length (15-60s) that tells the whole story with no filler.\n" .
                "HARD RULE: end_seconds MUST be at least 15 and at most 60 greater than start_seconds. A range\n" .
                "shorter than 15s is not a clip - extend it to the sentence that completes the thought.\n" .
                "Both numbers are ABSOLUTE seconds copied from the [..s] labels above, not offsets into this section.";
            $emptyRule = 'Score must be 0-100. If no clip in this section forms a complete story, return {"clips": []}.';
        }

        return <<<PROMPT
Video section from {$windowStart}s to {$windowEnd}s (out of {$totalDuration}s total).
Each line is "[start_seconds]: text". Use the timestamps to set your start/end.

Transcript segments:
{$segmentText}

{$clipInstruction}
{$lengthLine}
Return JSON in exactly this shape (a "clips" array even for a single clip):
{
  "clips": [
    {
      "start_seconds": 23.4,
      "end_seconds": 58.1,
      "subtitle": "exact words in this clip, from the hook sentence to the payoff sentence",
      "highlight_words": ["word1", "word2", "word3"],
      "score": 87,
      "score_breakdown": {
        "hook_strength": 31,
        "payoff": 22,
        "shareability": 17,
        "standalone_clarity": 9,
        "specificity": 4,
        "audio_viability": 4
      },
      "reason": "one sentence: the hook and the payoff that make this a complete story"
    }
  ]
}

{$emptyRule}
PROMPT;
    }

    /**
     * Filter out low-scoring candidates, drop near-duplicates (overlapping
     * windows often rediscover the same moment), and rank by score.
     */
    private function filterAndRank(array $candidates, int $topN, string $mode = 'story'): array
    {
        // Filter: score >= 40
        $filtered = array_filter($candidates, fn($c) => $c['score'] >= 40);

        // If too few, lower threshold to 20
        if (count($filtered) < 2) {
            $filtered = array_filter($candidates, fn($c) => $c['score'] >= 20);
        }

        // Moments mode: a countdown just needs the best of whatever exists —
        // never discard the whole pool over low scores.
        if ($mode === 'moments' && count($filtered) < 2) {
            $filtered = array_filter($candidates, fn($c) => $c['score'] > 0);
        }

        // Sort by score descending
        usort($filtered, fn($a, $b) => $b['score'] <=> $a['score']);

        // Dedupe: two candidates whose boundaries are within 3s of each other
        // are the same moment — keep the higher-scored one (first, post-sort).
        $unique = [];
        foreach ($filtered as $candidate) {
            foreach ($unique as $kept) {
                if (
                    abs($candidate['start_seconds'] - $kept['start_seconds']) < 3.0 &&
                    abs($candidate['end_seconds'] - $kept['end_seconds']) < 3.0
                ) {
                    continue 2;
                }
            }
            $unique[] = $candidate;
        }

        // Take top N
        return array_slice($unique, 0, $topN);
    }

    /**
     * Rank the chosen clips against each other for viral potential.
     *
     * Returns them best-first with final_score replaced by the head-to-head
     * verdict. Entirely non-fatal: any bad response leaves the input order
     * (already score-descending) untouched, so a failed call costs ordering
     * quality, never the run.
     *
     * @param array<int, array> $clips
     * @return array<int, array>
     */
    private function rankFinalists(array $clips): array
    {
        $clips = array_values($clips);

        $lines = '';
        foreach ($clips as $i => $clip) {
            $start = self::formatSeconds((float) $clip['final_start_seconds']);
            $end = self::formatSeconds((float) $clip['final_end_seconds']);
            $length = round((float) $clip['final_end_seconds'] - (float) $clip['final_start_seconds']);
            $text = trim(preg_replace('/\s+/u', ' ', (string) $clip['subtitle']));

            $lines .= "CLIP {$i} | {$start}s-{$end}s | {$length}s\n";
            $lines .= "\"" . mb_substr($text, 0, 700) . "\"\n\n";
        }

        $total = count($clips);

        $systemPrompt = <<<'PROMPT'
You rank finished short-form clips by how well they will actually perform in a feed.
You are ruthless and you never flatter: most clips are average, and saying so is the job.

Rank on:
1. Does the FIRST SENTENCE stop a scroll on its own? (the single biggest factor)
2. Does the ending land the promise the opening made?
3. Would a normal viewer send this to a friend — surprise, stakes, emotion, a quotable line?
4. Does it stand alone with zero context from the rest of the video?

Score 0-100 and spread the scores out. Two clips should almost never share a score.
90+ = you would post it yourself. 70 = watchable but forgettable. Below 50 = do not publish.
Return ONLY valid JSON.
PROMPT;

        $userPrompt = <<<PROMPT
Here are {$total} clips cut from one video. Rank them best-first for viral potential.

{$lines}
Return JSON in exactly this shape, with every clip index appearing exactly once,
ordered best-first:
{
  "ranking": [
    { "index": 3, "viral_score": 92, "reason": "opens on a concrete confession, lands the payoff" }
  ]
}
PROMPT;

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 200 + 120 * $total,
                    'response_format' => ['type' => 'json_object'],
                ]));

            if (!$response->successful()) {
                Log::warning('ClipSelectionService: final ranking request failed, keeping score order', [
                    'status' => $response->status(),
                ]);
                return $clips;
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'clip_selection_rank');

            $parsed = json_decode($response->json('choices.0.message.content') ?? '', true);
            $ranking = is_array($parsed) ? ($parsed['ranking'] ?? null) : null;

            if (!is_array($ranking) || empty($ranking)) {
                return $clips;
            }

            $ordered = [];
            $seen = [];
            foreach ($ranking as $row) {
                if (!is_array($row) || !isset($row['index'])) {
                    continue;
                }
                $idx = (int) $row['index'];
                if (!isset($clips[$idx]) || isset($seen[$idx])) {
                    continue;
                }
                $seen[$idx] = true;

                $clip = $clips[$idx];
                $clip['rank_reason'] = (string) ($row['reason'] ?? '');
                if (isset($row['viral_score']) && is_numeric($row['viral_score'])) {
                    $clip['pre_rank_score'] = $clip['final_score'];
                    $clip['final_score'] = max(0, min(100, (int) $row['viral_score']));
                }
                $ordered[] = $clip;
            }

            // Anything the model forgot keeps its place at the back rather
            // than vanishing — a dropped clip is a lost short.
            foreach ($clips as $i => $clip) {
                if (!isset($seen[$i])) {
                    $ordered[] = $clip;
                }
            }

            if (count($ordered) !== count($clips)) {
                return $clips;
            }

            Log::info('ClipSelectionService: finalists ranked head-to-head', [
                'order' => array_map(fn ($c) => [
                    'start' => round($c['final_start_seconds'], 1),
                    'score' => $c['final_score'],
                ], $ordered),
            ]);

            return $ordered;

        } catch (\Exception $e) {
            Log::warning('ClipSelectionService: final ranking failed, keeping score order', [
                'error' => $e->getMessage(),
            ]);
            return $clips;
        }
    }

    /**
     * Final Phase 3: Head-to-head comparison of top candidates
     */
    private function runFinalSelection(array $topCandidates): array
    {
        $systemPrompt = $this->buildPhase3SystemPrompt();
        $userPrompt = $this->buildPhase3Prompt($topCandidates);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 800,
                    'response_format' => ['type' => 'json_object']
                ]);

            if (!$response->successful()) {
                Log::error('GPT Phase 3 request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \Exception('GPT Phase 3 request failed: ' . $response->body());
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'clip_selection_phase3');

            $data = $response->json();
            $result = $data['choices'][0]['message']['content'] ?? null;
            $parsed = json_decode($result, true);

            return [
                'final_start_seconds' => floatval($parsed['final_start_seconds'] ?? 0),
                'final_end_seconds' => floatval($parsed['final_end_seconds'] ?? 0),
                'subtitle' => $parsed['subtitle'] ?? '',
                'highlight_words' => $parsed['highlight_words'] ?? [],
                'final_score' => intval($parsed['final_score'] ?? 0),
                'reason' => $parsed['reason'] ?? ''
            ];

        } catch (\Exception $e) {
            Log::error('GPT Phase 3 call failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function buildPhase3SystemPrompt(): string
    {
        return <<<'PROMPT'
You are choosing the absolute best short-form video clip from finalists.
Pick the ONE that is the most complete, self-contained story (strong hook AND a satisfying payoff)
with the highest viral potential. Reject any finalist that ends on an unresolved thought.
Keep its boundaries on full sentences and its length between 15 and 60 seconds.
PROMPT;
    }

    private function buildPhase3Prompt(array $topCandidates): string
    {
        $candidatesText = '';
        foreach ($topCandidates as $i => $candidate) {
            $start = self::formatSeconds((float) $candidate['start_seconds']);
            $end = self::formatSeconds((float) $candidate['end_seconds']);
            $score = $candidate['score'];
            $reason = $candidate['reason'];

            $candidatesText .= "CANDIDATE " . ($i + 1) . ": Score {$score} | {$start}s – {$end}s\n";
            $candidatesText .= "Transcript: \"" . substr($candidate['subtitle'], 0, 150) . "\"\n";
            $candidatesText .= "Why it was selected: {$reason}\n\n";
        }

        $candidateCount = count($topCandidates);

        return <<<PROMPT
Here are the top {$candidateCount} clip candidates from a video. Choose the single best one for a viral short.

{$candidatesText}

Return ONLY a JSON object in exactly this shape:
{
  "winner_index": 0,
  "final_start_seconds": 23.4,
  "final_end_seconds": 58.1,
  "subtitle": "exact words of winning clip",
  "highlight_words": ["most", "important", "words"],
  "final_score": 87,
  "reason": "why this beats the others"
}
PROMPT;
    }

    /**
     * Validate clip selection meets requirements
     */
    private function validateClipSelection(array $selection, float $totalDuration, string $mode = 'story'): void
    {
        $duration = $selection['final_end_seconds'] - $selection['final_start_seconds'];

        // Moments are single action events: legitimately short, and possibly
        // speech-free (the action IS the content, so no subtitle is fine).
        $minDuration = $mode === 'moments' ? 4 : 15;
        $maxDuration = $mode === 'moments' ? 45 : 65;

        if ($duration < $minDuration) {
            throw new \Exception("Selected clip too short: {$duration}s (minimum {$minDuration}s)");
        }

        if ($duration > $maxDuration) {
            throw new \Exception("Selected clip too long: {$duration}s (maximum {$maxDuration}s)");
        }

        if ($selection['final_start_seconds'] < 0) {
            throw new \Exception("Clip starts before video beginning");
        }

        if ($selection['final_end_seconds'] > $totalDuration) {
            throw new \Exception("Clip ends after video ends");
        }

        if ($mode !== 'moments' && empty($selection['subtitle'])) {
            throw new \Exception("No subtitle text in selection");
        }
    }
}
