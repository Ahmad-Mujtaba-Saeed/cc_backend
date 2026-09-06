<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MomentRankingService
 *
 * Ranks candidate moments for a themed compilation and selects the final clips.
 *
 * - Heuristic pre-ranking is always available (free, local)
 * - A single batched gpt-4o-mini call refines scores by theme relevance
 * - A single batched gpt-4o-mini call writes commentary one-liners
 * - Every OpenAI call has a full local fallback, so the pipeline never
 *   depends on the API succeeding
 *
 * Copyright-risk mitigation enforced in selectClips():
 * - every clip is hard-capped to max_clip_seconds
 * - total seconds used per source video capped at min(90s, 15% of duration)
 * - no overlapping/adjacent windows from the same source (>= 1s gap)
 * - clips are interleaved across sources and shuffled so no contiguous
 *   run reproduces any single source
 */
class MomentRankingService
{
    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('general');
    }

    /**
     * Rank candidate moments against the theme.
     *
     * @param array  $candidatesBySource  [sourceIndex => [['start','end','heuristic_score'], ...]]
     * @param string $theme               e.g. 'disaster moments'
     * @param array  $transcriptsBySource [sourceIndex => [['start','end','text'], ...]]
     * @param int    $targetCount         desired number of clips
     *
     * @return array ranked flat list with source_index, start, end, snippet, final_score
     */
    public function rankMoments(array $candidatesBySource, string $theme, array $transcriptsBySource, int $targetCount): array
    {
        $flat = [];
        $id = 0;

        foreach ($candidatesBySource as $sourceIndex => $candidates) {
            $segments = $transcriptsBySource[$sourceIndex] ?? [];

            foreach ($candidates as $candidate) {
                $flat[] = [
                    'id' => $id++,
                    'source_index' => (int) $sourceIndex,
                    'start' => (float) $candidate['start'],
                    'end' => (float) $candidate['end'],
                    'heuristic_score' => (float) ($candidate['heuristic_score'] ?? 0.0),
                    'snippet' => $this->snippetForWindow($segments, (float) $candidate['start'], (float) $candidate['end']),
                ];
            }
        }

        usort($flat, fn ($a, $b) => $b['heuristic_score'] <=> $a['heuristic_score']);

        // The selector throws a lot of these away (spacing rule, per-source
        // footage budget), so hand it a pool several times the target or it
        // runs out of candidates and ships far fewer moments than requested.
        $pool = array_slice($flat, 0, min(240, max($targetCount * 5, 60)));

        $aiScores = $this->scoreWithOpenAI($pool, $theme);

        foreach ($pool as &$candidate) {
            $aiScore = $aiScores[$candidate['id']] ?? ($candidate['heuristic_score'] * 10.0);
            $candidate['ai_score'] = round($aiScore, 2);
            $candidate['final_score'] = round(0.6 * ($aiScore / 10.0) + 0.4 * $candidate['heuristic_score'], 4);
        }
        unset($candidate);

        usort($pool, fn ($a, $b) => $b['final_score'] <=> $a['final_score']);

        Log::info('MomentRankingService: ranking complete', [
            'pool_size' => count($pool),
            'used_openai' => !empty($aiScores),
            'top_scores' => array_slice(array_column($pool, 'final_score'), 0, 3),
        ]);

        return $pool;
    }

    /**
     * Concatenate transcript text overlapping the window (max 200 chars).
     */
    private function snippetForWindow(array $segments, float $start, float $end): string
    {
        $parts = [];

        foreach ($segments as $segment) {
            $segStart = (float) ($segment['start'] ?? 0);
            $segEnd = (float) ($segment['end'] ?? 0);

            if ($segEnd > $start && $segStart < $end) {
                $text = trim((string) ($segment['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return mb_substr(implode(' ', $parts), 0, 200);
    }

    /**
     * ONE batched OpenAI call scoring theme relevance (0-10) per candidate.
     * Returns [] on any failure so callers fall back to heuristics.
     */
    private function scoreWithOpenAI(array $pool, string $theme): array
    {
        if (empty($this->apiKey) || empty($pool)) {
            return [];
        }

        try {
            $items = array_map(fn ($c) => [
                'id' => $c['id'],
                'snippet' => $c['snippet'],
                'energy' => $c['heuristic_score'],
            ], $pool);

            $response = Http::withToken($this->apiKey)
                ->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You grade video moments for a themed compilation. Respond only with JSON.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Theme: \"{$theme}\". For each candidate moment below (id, transcript snippet, audio energy 0-1), score 0-10 for how well it fits the theme as an exciting standalone compilation moment. Moments with empty snippets can still score well if energy is high. Return JSON exactly as {\"scores\":{\"<id>\":<score>,...}}.\n" . json_encode($items),
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                throw new \Exception('OpenAI HTTP ' . $response->status());
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'moment_scoring');

            $decoded = json_decode((string) $response->json('choices.0.message.content'), true);

            $scores = [];
            foreach (($decoded['scores'] ?? []) as $key => $value) {
                if (is_numeric($value)) {
                    $scores[(int) $key] = max(0.0, min(10.0, (float) $value));
                }
            }

            return $scores;
        } catch (\Exception $e) {
            Log::warning('MomentRankingService: OpenAI scoring failed, falling back to heuristics', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Select the final ordered clip plan with copyright-mitigation rules.
     *
     * @param array $ranked           ranked pool from rankMoments()
     * @param int   $targetCount      desired clip count
     * @param float $maxClipSeconds   hard cap per clip window
     * @param array $sourceDurations  [sourceIndex => duration seconds]
     * @param float $renderPadSeconds extra footage the renderer may pull past a
     *                                window's end when it stretches a clip so
     *                                the commentary fits. The spacing rule has
     *                                to cover it, otherwise two "distinct"
     *                                windows render overlapping footage and the
     *                                viewer sees the same moment twice.
     *
     * @return array ordered clips with position and countdown counter assigned
     */
    public function selectClips(
        array $ranked,
        int $targetCount,
        float $maxClipSeconds,
        array $sourceDurations,
        float $renderPadSeconds = 0.0
    ): array {
        $pad = max(0.0, $renderPadSeconds);

        $budgets = [];
        foreach ($sourceDurations as $sourceIndex => $duration) {
            $budgets[(int) $sourceIndex] = min(90.0, 0.15 * max(1.0, (float) $duration));
        }

        // Pass 1 spaces windows generously so nothing reads as a replay. Pass 2
        // only runs when pass 1 came up short, and it relaxes the SPACING, never
        // the per-source footage budget: spacing is a quality knob, the budget
        // is the copyright mitigation and stays put.
        $picked = $this->pickWindows(
            $ranked,
            $targetCount,
            $maxClipSeconds,
            $budgets,
            max(1.5, $maxClipSeconds * 0.75) + $pad,
            []
        );

        if (count($picked) < $targetCount) {
            $picked = $this->pickWindows(
                $ranked,
                $targetCount,
                $maxClipSeconds,
                $budgets,
                max(1.0, $maxClipSeconds * 0.35) + $pad,
                $picked
            );
        }

        if (empty($picked)) {
            throw new \Exception('No usable clips could be selected from the provided videos');
        }

        // Shuffle so no contiguous run reproduces a single source, then make
        // sure the shuffle did not leave two windows of the same source sitting
        // next to each other - back-to-back same-source cuts are exactly what
        // reads as "it played that clip again".
        shuffle($picked);
        $picked = $this->spreadSources($picked);

        $total = count($picked);
        foreach ($picked as $index => &$clip) {
            $clip['position'] = $index;
            $clip['counter'] = $total - $index;
        }
        unset($clip);

        if ($total < $targetCount) {
            Log::warning('MomentRankingService: fewer clips than requested', [
                'requested' => $targetCount,
                'selected' => $total,
                'reason' => 'per-source footage budget or candidate pool exhausted',
                'budgets' => $budgets,
            ]);
        }

        Log::info('MomentRankingService: clips selected', [
            'requested' => $targetCount,
            'selected' => $total,
            'budgets' => $budgets,
        ]);

        return $picked;
    }

    /**
     * Walk the ranked pool round-robin across sources, taking the best window
     * that clears the spacing rule and the per-source footage budget. Continues
     * from an existing selection so a relaxed second pass keeps the pass-1
     * picks instead of re-deciding (and possibly duplicating) them.
     */
    private function pickWindows(
        array $ranked,
        int $targetCount,
        float $maxClipSeconds,
        array $budgets,
        float $minGap,
        array $picked
    ): array {
        $used = [];
        $seenKeys = [];

        foreach ($picked as $clip) {
            $sourceIndex = (int) $clip['source_index'];
            $used[$sourceIndex] = ($used[$sourceIndex] ?? 0.0) + ((float) $clip['end'] - (float) $clip['start']);
            $seenKeys[$this->windowKey($sourceIndex, (float) $clip['start'])] = true;
        }

        $queues = [];
        foreach ($ranked as $candidate) {
            $queues[(int) $candidate['source_index']][] = $candidate;
        }
        $queues = array_map('array_values', $queues);

        while (count($picked) < $targetCount) {
            $tookOne = false;

            foreach (array_keys($queues) as $sourceIndex) {
                if (count($picked) >= $targetCount) {
                    break;
                }

                while (!empty($queues[$sourceIndex])) {
                    $candidate = array_shift($queues[$sourceIndex]);

                    $start = (float) $candidate['start'];
                    $end = min((float) $candidate['end'], $start + $maxClipSeconds);
                    $duration = $end - $start;

                    if ($duration < 2.0) {
                        continue;
                    }

                    // Same source + same half-second bucket is the same moment.
                    $key = $this->windowKey((int) $sourceIndex, $start);
                    if (isset($seenKeys[$key])) {
                        continue;
                    }

                    if (($used[$sourceIndex] ?? 0.0) + $duration > ($budgets[$sourceIndex] ?? 90.0)) {
                        continue;
                    }

                    if ($this->overlapsPicked($picked, (int) $sourceIndex, $start, $end, $minGap)) {
                        continue;
                    }

                    $candidate['start'] = round($start, 2);
                    $candidate['end'] = round($end, 2);
                    $seenKeys[$key] = true;
                    $picked[] = $candidate;
                    $used[$sourceIndex] = ($used[$sourceIndex] ?? 0.0) + $duration;
                    $tookOne = true;
                    break;
                }
            }

            // Every queue is exhausted or fully rejected - another lap would
            // spin without adding anything.
            if (!$tookOne) {
                break;
            }
        }

        return $picked;
    }

    /**
     * Bucket window starts to the half second: two picks this close are the
     * same moment however they were scored.
     */
    private function windowKey(int $sourceIndex, float $start): string
    {
        return $sourceIndex . ':' . (int) round($start * 2);
    }

    /**
     * Reorder so two clips from the same source are never adjacent whenever the
     * mix allows it.
     *
     * Always takes from the source with the most clips still waiting (skipping
     * the one just used), which is what keeps the tail of the list from
     * collapsing into a run of the same video - a run the viewer reads as the
     * same clip playing over again.
     */
    private function spreadSources(array $picked): array
    {
        $out = [];
        $pool = array_values($picked);

        while (!empty($pool)) {
            $lastSource = empty($out) ? null : (int) $out[count($out) - 1]['source_index'];

            $remaining = [];
            foreach ($pool as $clip) {
                $sourceIndex = (int) $clip['source_index'];
                $remaining[$sourceIndex] = ($remaining[$sourceIndex] ?? 0) + 1;
            }

            $bestSource = null;
            $bestCount = -1;
            foreach ($remaining as $sourceIndex => $count) {
                if ($sourceIndex === $lastSource) {
                    continue;
                }
                if ($count > $bestCount) {
                    $bestSource = $sourceIndex;
                    $bestCount = $count;
                }
            }

            // Only the previous source is left - the run is unavoidable.
            if ($bestSource === null) {
                $bestSource = $lastSource;
            }

            $chosen = 0;
            foreach ($pool as $index => $clip) {
                if ((int) $clip['source_index'] === $bestSource) {
                    $chosen = $index;
                    break;
                }
            }

            $out[] = $pool[$chosen];
            array_splice($pool, $chosen, 1);
        }

        return $out;
    }

    private function overlapsPicked(array $picked, int $sourceIndex, float $start, float $end, float $minGap = 1.0): bool
    {
        foreach ($picked as $clip) {
            if ((int) $clip['source_index'] !== $sourceIndex) {
                continue;
            }

            // Require at least $minGap seconds between windows of the same source
            if ($start < ((float) $clip['end'] + $minGap) && $end > ((float) $clip['start'] - $minGap)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ONE batched OpenAI call generating a short commentary line per clip.
     * Falls back to canned lines on any failure.
     */
    public function generateCommentary(array $clips, string $theme, string $style): array
    {
        $count = count($clips);
        $fallback = $this->fallbackLines($clips, $theme);

        if (empty($this->apiKey) || $count === 0) {
            return $fallback;
        }

        try {
            $styleHints = [
                'energetic' => 'high-energy hype commentator',
                'sarcastic' => 'dry, sarcastic narrator',
                'dramatic' => 'overly dramatic movie-trailer narrator',
                'minimal' => 'minimal, deadpan narrator',
            ];
            $persona = $styleHints[$style] ?? $styleHints['energetic'];

            // Each line has to be sayable inside its own clip. Hand the model
            // the per-clip word budget instead of one blanket "max 12 words":
            // a line that overruns its moment used to be cut off mid-word when
            // the video jumped to the next clip.
            $items = array_map(fn ($clip) => [
                'n' => $clip['counter'],
                'max_words' => $this->wordBudget($clip),
                'snippet' => mb_substr((string) ($clip['snippet'] ?? ''), 0, 220),
            ], $clips);

            $response = Http::withToken($this->apiKey)
                ->timeout(90)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'temperature' => 0.8,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "You are a {$persona} voicing a '{$theme}' video compilation. Respond only with JSON.",
                        ],
                        [
                            'role' => 'user',
                            'content' => "Write one short spoken commentary line (no emojis, no quotation marks) for each of these {$count} numbered moments. Each item carries its own 'max_words' budget - never exceed it, the line has to be spoken before that clip ends.\n"
                                . "Each item has a transcript 'snippet' of what is actually happening/said in that clip. When the snippet has content, your line MUST react to that specific moment so the voiceover matches the footage; do NOT describe a generic scene. When the snippet is empty, write a punchy line that fits the '{$theme}' theme.\n"
                                . "Do not announce the number unless it feels natural. Vary the wording, never repeat a line. Return JSON exactly as {\"lines\":[\"...\"]} with exactly {$count} strings in the same order.\n"
                                . json_encode($items),
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                throw new \Exception('OpenAI HTTP ' . $response->status());
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'clip_commentary');

            $decoded = json_decode((string) $response->json('choices.0.message.content'), true);
            $lines = $decoded['lines'] ?? null;

            if (!is_array($lines) || count($lines) < $count) {
                throw new \Exception('Incomplete commentary response');
            }

            $result = [];
            foreach (array_slice(array_values($lines), 0, $count) as $index => $line) {
                $line = trim((string) $line);
                $result[] = $line !== '' ? $line : $fallback[$index];
            }

            return $result;
        } catch (\Exception $e) {
            Log::warning('MomentRankingService: commentary generation failed, using fallback lines', [
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * How many words fit in the clip's spoken slot. ~2.6 words/second at TTS
     * pace, plus the couple of seconds the renderer may stretch the clip by.
     */
    private function wordBudget(array $clip): int
    {
        $seconds = max(1.0, (float) ($clip['end'] ?? 0) - (float) ($clip['start'] ?? 0));

        return (int) max(4, min(14, floor(($seconds + 2.0) * 2.6)));
    }

    private function fallbackLines(array $clips, string $theme): array
    {
        $templates = [
            'Number %d. Absolutely unbelievable.',
            'Moment %d. It only gets worse from here.',
            'Number %d. Watch this closely.',
            'Moment %d. Nobody saw this coming.',
            'Number %d. Pure chaos.',
            'Moment %d. This one hurts to watch.',
        ];

        $lines = [];
        foreach (array_values($clips) as $index => $clip) {
            $lines[] = sprintf($templates[$index % count($templates)], $clip['counter'] ?? ($index + 1));
        }

        return $lines;
    }
}
