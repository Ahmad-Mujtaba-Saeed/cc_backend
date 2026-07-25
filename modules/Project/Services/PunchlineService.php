<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PunchlineService
 *
 * Picks the "punch lines" of an explainer video: short, quotable phrases
 * lifted VERBATIM from each scene's narration that deserve to pop on screen
 * as the narrator says them. A small LLM pass proposes them; everything is
 * validated against the actual narration text (a punchline that is not a
 * verbatim substring is repaired or dropped), and a deterministic heuristic
 * covers LLM failure so the feature degrades gracefully, never fatally.
 *
 * The service also ALIGNS a chosen phrase to Kokoro's word timings so the
 * renderer can highlight each word at the exact moment it is spoken.
 */
class PunchlineService
{
    // "glass" is the flagship flat "punch card" look (legacy name "plate"
    // still renders — the Remotion side maps it to the same look).
    private const STYLES = ['glass', 'stamp', 'quote'];

    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('explainer');
    }

    /**
     * Choose punchlines for a set of scenes. Not every scene gets one — a
     * punchline that appears on every single beat stops being a punch.
     *
     * @param  array<int, array{scene_id: string, narration: string}>  $scenes
     * @return array<string, array{text: string, style: string}>  keyed by scene_id
     */
    public function extract(array $scenes): array
    {
        $eligible = array_values(array_filter($scenes, fn ($s) => str_word_count((string) $s['narration']) >= 6));
        if (empty($eligible)) {
            return [];
        }

        $picked = [];
        try {
            $picked = $this->requestPunchlines($eligible);
        } catch (\Throwable $e) {
            Log::warning('PunchlineService: LLM pass failed, using heuristic', ['error' => $e->getMessage()]);
        }

        $byScene = [];
        $styleCursor = 0;
        $narrations = [];
        foreach ($eligible as $scene) {
            $narrations[(string) $scene['scene_id']] = (string) $scene['narration'];
        }

        foreach ($picked as $entry) {
            $sid = (string) ($entry['scene_id'] ?? '');
            $text = trim((string) ($entry['text'] ?? ''));
            if ($sid === '' || $text === '' || !isset($narrations[$sid]) || isset($byScene[$sid])) {
                continue;
            }

            // The phrase must exist verbatim in the narration (ignoring case
            // and punctuation) or word-sync is impossible.
            $verbatim = $this->matchVerbatim($text, $narrations[$sid]);
            if ($verbatim === null) {
                continue;
            }

            $wordCount = count(preg_split('/\s+/', $verbatim));
            if ($wordCount < 2 || $wordCount > 9) {
                continue;
            }

            $style = in_array($entry['style'] ?? '', self::STYLES, true)
                ? $entry['style']
                : self::STYLES[$styleCursor % count(self::STYLES)];
            $styleCursor++;

            $byScene[$sid] = ['text' => $verbatim, 'style' => $style];
        }

        // Heuristic backstop: guarantee at least one punchline per ~3 scenes.
        $want = max(1, (int) ceil(count($eligible) / 3));
        if (count($byScene) < $want) {
            foreach ($this->heuristicPicks($eligible, $byScene) as $sid => $pick) {
                if (count($byScene) >= $want) {
                    break;
                }
                $pick['style'] = self::STYLES[$styleCursor++ % count(self::STYLES)];
                $byScene[$sid] = $pick;
            }
        }

        return $byScene;
    }

    /**
     * Align a punchline (a verbatim narration substring) to the narration's
     * word timings. Returns start/end (seconds) plus per-word windows; when
     * timings are missing/unmatchable the caller should fall back to
     * {@see distributeAcross()}.
     *
     * @param  array<int, array{word: string, start: float, end: float}>  $wordTimings
     * @return array{start: float, end: float, words: array}|null
     */
    public function align(string $punchline, array $wordTimings): ?array
    {
        if (empty($wordTimings)) {
            return null;
        }

        $target = $this->tokens($punchline);
        if (empty($target)) {
            return null;
        }

        $timed = [];
        foreach ($wordTimings as $t) {
            $norm = $this->norm((string) ($t['word'] ?? ''));
            if ($norm !== '') {
                $timed[] = ['norm' => $norm, 'word' => (string) $t['word'], 'start' => (float) $t['start'], 'end' => (float) $t['end']];
            }
        }

        $n = count($target);
        $best = null;
        $bestScore = 0;
        for ($i = 0; $i + $n <= count($timed); $i++) {
            $score = 0;
            for ($k = 0; $k < $n; $k++) {
                if ($timed[$i + $k]['norm'] === $target[$k]) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
            if ($score === $n) {
                break; // perfect match
            }
        }

        // Accept fuzzy matches (Kokoro tokenization differs slightly from
        // plain whitespace splitting) but never wild guesses.
        if ($best === null || $bestScore < max(2, (int) ceil($n * 0.7))) {
            return null;
        }

        $span = array_slice($timed, $best, $n);
        $words = array_map(fn ($t) => [
            'word' => $t['word'],
            'start' => round($t['start'], 3),
            'end' => round($t['end'], 3),
        ], $span);

        return [
            'start' => round($span[0]['start'], 3),
            'end' => round($span[$n - 1]['end'], 3),
            'words' => $words,
        ];
    }

    /**
     * Fallback timing when no word timestamps exist: place the line in the
     * middle stretch of the narration and spread the words evenly.
     *
     * @return array{start: float, end: float, words: array}
     */
    public function distributeAcross(string $punchline, float $narrationSeconds): array
    {
        $words = preg_split('/\s+/', trim($punchline)) ?: [];
        $count = max(1, count($words));

        $start = round(max(0.4, $narrationSeconds * 0.35), 3);
        $span = min(3.2, max(1.2, $count * 0.42));
        $end = round(min($narrationSeconds - 0.2, $start + $span), 3);
        if ($end <= $start) {
            $end = $start + 1.2;
        }

        $step = ($end - $start) / $count;
        $timed = [];
        foreach ($words as $i => $word) {
            $timed[] = [
                'word' => $word,
                'start' => round($start + $step * $i, 3),
                'end' => round($start + $step * ($i + 1), 3),
            ];
        }

        return ['start' => $start, 'end' => $end, 'words' => $timed];
    }

    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array{scene_id: string, narration: string}>  $scenes
     * @return array<int, array{scene_id: string, text: string, style?: string}>
     */
    private function requestPunchlines(array $scenes): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $lines = [];
        foreach ($scenes as $scene) {
            $lines[] = sprintf('- %s: "%s"', $scene['scene_id'], mb_substr(trim($scene['narration']), 0, 320));
        }
        $brief = implode("\n", $lines);

        $system = <<<PROMPT
You are the on-screen text editor of a premium explainer video. From the narration of each scene, choose the PUNCH LINES: the short phrases powerful enough to flash on screen as the narrator says them.

RULES:
- A punchline must be an EXACT verbatim substring of that scene's narration — copy the words precisely, 3 to 8 words, no rewording, no added punctuation.
- Pick only phrases that carry the punch: numbers and stats, bold claims, turning points, superlatives, memorable images. Never pick filler ("in this video", "let's take a look").
- Choose punchlines for roughly HALF of the scenes — the strongest ones only. A scene with nothing punchy gets nothing.
- Vary "style": "glass" (default — a bold centered statement on a flat poster card), "stamp" (a shouted stat/claim slammed on screen, great for numbers), "quote" (a human, quotable line).

Return ONLY JSON: {"punchlines": [{"scene_id": "...", "text": "...", "style": "glass|stamp|quote"}]}
PROMPT;

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "SCENE NARRATIONS:\n" . $brief],
                ],
                'temperature' => 0.4,
                'max_tokens' => 1200,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Punchline request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'punchlines');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);

        return is_array($parsed['punchlines'] ?? null) ? $parsed['punchlines'] : [];
    }

    /**
     * Deterministic picks when the LLM under-delivers: prefer clauses with
     * digits or superlative markers; otherwise the tightest clause of the
     * longest narration.
     *
     * @return array<string, array{text: string}>
     */
    private function heuristicPicks(array $scenes, array $already): array
    {
        $picks = [];
        foreach ($scenes as $scene) {
            $sid = (string) $scene['scene_id'];
            if (isset($already[$sid])) {
                continue;
            }
            $clauses = preg_split('/[.!?;,\x{2014}]/u', (string) $scene['narration']) ?: [];
            $bestClause = null;
            $bestScore = 0;
            foreach ($clauses as $clause) {
                $clause = trim($clause);
                $words = preg_split('/\s+/', $clause) ?: [];
                $count = count($words);
                if ($count < 3 || $count > 8) {
                    continue;
                }
                $score = 1;
                if (preg_match('/\d/', $clause)) {
                    $score += 3;
                }
                if (preg_match('/\b(most|best|first|never|every|only|biggest|fastest|free|secret|key)\b/i', $clause)) {
                    $score += 2;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestClause = $clause;
                }
            }
            if ($bestClause !== null && $bestScore >= 3) {
                $picks[$sid] = ['text' => $bestClause];
            }
        }

        return $picks;
    }

    /** Case/punctuation-insensitive verbatim match; returns the original span. */
    private function matchVerbatim(string $needle, string $haystack): ?string
    {
        $target = $this->tokens($needle);
        if (empty($target)) {
            return null;
        }

        // Split the narration into words while remembering raw offsets.
        preg_match_all('/\S+/u', $haystack, $m, PREG_OFFSET_CAPTURE);
        $raw = $m[0] ?? [];
        $norms = array_map(fn ($w) => $this->norm($w[0]), $raw);

        $n = count($target);
        for ($i = 0; $i + $n <= count($raw); $i++) {
            $ok = true;
            for ($k = 0; $k < $n; $k++) {
                if ($norms[$i + $k] !== $target[$k]) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $startByte = $raw[$i][1];
                $endByte = $raw[$i + $n - 1][1] + strlen($raw[$i + $n - 1][0]);
                $span = substr($haystack, $startByte, $endByte - $startByte);
                // Strip trailing punctuation for a cleaner on-screen line.
                return trim(preg_replace('/[\s.,;:!?]+$/u', '', $span));
            }
        }

        return null;
    }

    /** @return string[] normalized word tokens */
    private function tokens(string $text): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];

        return array_values(array_filter(array_map(fn ($w) => $this->norm($w), $words), fn ($w) => $w !== ''));
    }

    private function norm(string $word): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($word)) ?? '';
    }
}
