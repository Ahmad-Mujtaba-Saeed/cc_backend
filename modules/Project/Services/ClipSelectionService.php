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
        $count = max(1, min(10, $count));

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

        // Greedily accept candidates by score. Each candidate is snapped to
        // sentence boundaries FIRST, then checked against already-accepted
        // clips — snapping moves boundaries, so overlap must be tested on the
        // final boundaries, not the raw AI ones.
        $selected = [];
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

            if ($this->overlapsAccepted($clip, $selected)) {
                continue;
            }

            $selected[] = $clip;

            if (count($selected) >= $count) {
                break;
            }
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
        // from different parts of the video.
        if ($clipCount > 1) {
            $strategy['max_windows'] = min(10, $strategy['max_windows'] + (int) ceil($clipCount / 2));
        }

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

        // If more windows needed than max, sample evenly across duration
        $lastSegment = end($segments);
        $totalDuration = floatval($lastSegment['end'] ?? 0);

        $windowCount = min(
            ceil($totalDuration / ($windowSeconds - $overlapSeconds)),
            $maxWindows
        );

        for ($i = 0; $i < $windowCount; $i++) {
            $start = ($i * ($windowSeconds - $overlapSeconds));
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
                $candidates[] = [
                    'window_index' => $window['window_index'],
                    'start_seconds' => floatval($row['start_seconds'] ?? 0),
                    'end_seconds' => floatval($row['end_seconds'] ?? 0),
                    'subtitle' => $row['subtitle'] ?? '',
                    'highlight_words' => $row['highlight_words'] ?? [],
                    'score' => intval($row['score'] ?? 0),
                    'score_breakdown' => $row['score_breakdown'] ?? [],
                    'reason' => $row['reason'] ?? ''
                ];
            }

            return $candidates;

        } catch (\Exception $e) {
            Log::error('GPT Phase 1 call failed', ['error' => $e->getMessage()]);
            return [];
        }
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
You are an expert viral short-form video editor. From a section of transcript, pick the
best self-contained clip(s) — each one must tell a COMPLETE little story on its own.

A great clip is a MINI-STORY, not a random highlight:
- It opens on a strong HOOK — a sentence that instantly makes people want to keep watching.
- It delivers a clear middle (the setup / the point / the tension).
- It lands a satisfying PAYOFF or resolution at the end. The viewer should feel it "finished",
  not that it was chopped off.

HARD BOUNDARY RULES (critical):
- The clip MUST start at the FIRST WORD OF A SENTENCE. Never start mid-sentence or on filler
  ("um", "so yeah", "and then", "but like").
- The clip MUST end at the END OF A SENTENCE (the words ending in . ! or ?). Never cut mid-sentence.
- Trim any greeting/filler lead-in and any trailing tangent so every second carries the story fast.

LENGTH: Choose whatever length tells the complete story best — anywhere from 15 to 60 seconds.
Do not pad to fill time and do not truncate meaning to be short. Tight and complete beats long.

DEAD AIR: The timestamps reveal silence — a large jump between consecutive segment timestamps
means nobody is talking (music, gameplay noise, pauses). Prefer clips with continuous speech.
A clip whose range contains long silent holes is weaker; pick a tighter continuous story instead.

Scoring criteria (weight):
- Hook strength: irresistible opening line (30%)
- Story completeness: clear setup AND payoff, understandable with zero prior context (30%)
- Emotional resonance: curiosity, surprise, shock, humour, strong feeling (20%)
- Specificity: concrete facts, numbers, stories, examples (10%)
- Audio-only viability: works without visuals ("as you can see" is disqualifying) (10%)

DISQUALIFY: intros, outros, sponsor reads, subscribe begs, and any clip that ends on an
unresolved/incomplete thought.

Return ONLY valid JSON. No markdown. No explanation outside the JSON.
PROMPT;
    }

    private function buildPhase1Prompt(array $segments, float $windowStart, float $windowEnd, float $totalDuration, int $maxCandidates = 1, string $mode = 'story'): string
    {
        $formattedSegments = array_map(function ($seg) {
            $time = number_format($seg['start'] ?? 0, 1);
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
                "build-up and the peak of that single event — never stretch a pick to cover a second event.";
            $emptyRule = 'Score must be 0-100. Always return the best available moments; only return {"clips": []} if this section is truly unusable.';
        } else {
            $clipInstruction = $maxCandidates > 1
                ? "Find up to {$maxCandidates} of the best self-contained clips (each a complete hook -> payoff).\n" .
                  "The clips MUST NOT overlap each other and must each stand alone. Order them best-first.\n" .
                  "Return fewer clips (or an empty list) rather than padding with weak ones."
                : "Find the single best self-contained clip (complete hook -> payoff).";
            $lengthLine = "Make start_seconds land on the first word of the opening sentence and end_seconds on the last\n" .
                "word of the closing sentence. Pick the length (15-60s) that tells the whole story with no filler.";
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
        "hook_strength": 28,
        "story_completeness": 27,
        "emotional_resonance": 18,
        "specificity": 9,
        "audio_viability": 8
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
            $start = number_format($candidate['start_seconds'], 1);
            $end = number_format($candidate['end_seconds'], 1);
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
