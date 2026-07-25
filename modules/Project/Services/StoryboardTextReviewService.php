<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * StoryboardTextReviewService — the text-side quality pass (roadmap 3b).
 *
 * The deterministic lints catch what a regex can see (dead air, echoes,
 * mojibake); this pass reads the FINAL storyboard's narration the way a
 * script editor would and catches the long tail that only language sense
 * finds: meta-narration ("In this video we will…"), the narrator describing
 * the screen instead of the idea ("as you can see in this chart"), a
 * sentence repeated nearly verbatim across scenes, and placeholder junk.
 *
 * Guarantees over prompts (the v23 lesson): the model only NOMINATES issues
 * — {scene_id, type, quote, fix} — and the pure, guarded `apply()` decides.
 * A quote that does not appear character-for-character in that scene's
 * narration, a fix that balloons the sentence, an edit that would leave a
 * scene with nothing to speak, or an unknown issue type is refused and
 * surfaced in the lint report instead of applied. Every failure path returns
 * the storyboard untouched — the pass is decorative, never load-bearing.
 */
class StoryboardTextReviewService
{
    /** Issue types the pass may act on; anything else is refused. */
    public const TYPES = ['meta_narration', 'figure_callout', 'repeated_sentence', 'placeholder'];

    /** Most edits a single pass may make — a runaway review is a bad review. */
    public const MAX_EDITS = 6;

    /** Never edit the step-locked working narration (one sentence per step). */
    private const PROTECTED_TEMPLATES = ['math_steps'];

    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        // Verbatim-quote nomination behind hard guards is precision work at
        // temp 0 — the reasoning-model default (gpt-5-nano, iter 31) is both
        // cheaper and stricter about "quote must appear verbatim". Empty
        // config falls back to the explainer model.
        $configured = (string) config('services.openai.text_review_model', '');
        $this->model = $configured !== '' ? $configured : LlmModels::for('explainer');
    }

    /**
     * Ask the model for issues in the storyboard's narration. Null on any
     * failure (no key, refusal, junk) — the caller simply skips the pass.
     *
     * @return array<int, array{scene_id: string, type: string, quote: string, fix: string}>|null
     */
    public function review(array $scenes): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $digest = '';
        foreach ($scenes as $scene) {
            if (in_array((string) ($scene['layout_template'] ?? ''), self::PROTECTED_TEMPLATES, true)) {
                continue;
            }
            $text = trim((string) ($scene['narration']['text'] ?? ''));
            if (str_word_count($text) < 4) {
                continue;
            }
            $digest .= sprintf(
                "%s [%s]: %s\n",
                (string) ($scene['scene_id'] ?? ''),
                (string) ($scene['layout_template'] ?? ''),
                mb_substr($text, 0, 420)
            );
        }
        if (trim($digest) === '') {
            return null;
        }

        $system = <<<'PROMPT'
You quality-check the SPOKEN narration of a finished short explainer video. The storyboard is final; you only flag sentences that will sound wrong read aloud.

Return ONLY JSON:
{"issues": [{"scene_id": "<id>", "type": "meta_narration"|"figure_callout"|"repeated_sentence"|"placeholder", "quote": "<the offending sentence EXACTLY as written in that scene>", "fix": "<replacement sentence, or \"\" to delete it>"}]}

Flag ONLY these four things:
- meta_narration: the narrator talks about the VIDEO instead of the topic ("In this video we will…", "Welcome back", "Let's dive in", "As we mentioned earlier", "Don't forget to subscribe"). fix: state the content directly, or "" when the sentence carries none.
- figure_callout: the narrator describes the SCREEN instead of the idea ("As you can see in the chart below…", "This diagram shows…", "Look at the graph"). fix: say the fact itself ("Sales tripled in two years"), never the furniture.
- repeated_sentence: a sentence appears nearly verbatim in TWO scenes. Flag only the LATER scene, quoting ITS copy. fix: "" or a short bridge that adds something new.
- placeholder: template junk that survived ("[topic]", "TODO", "lorem ipsum", "insert name here").

Rules:
- quote is matched LITERALLY against that scene's narration — copy it character-for-character, whole sentence.
- fix is ONE natural spoken sentence, the same length or shorter. Never invent new facts or numbers.
- 0-6 issues total. An empty list is the normal answer for a clean storyboard.
- Never flag ordinary content, opinions or style. Only the four types above.
PROMPT;

        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "NARRATION BY SCENE:\n" . mb_substr($digest, 0, 9000)],
                ],
                'temperature' => 0.0,
                'max_tokens' => 700,
                'response_format' => ['type' => 'json_object'],
            ]));

        if (!$response->successful()) {
            throw new \RuntimeException("text review request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'text_review');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        if (!is_array($parsed) || !is_array($parsed['issues'] ?? null)) {
            return null;
        }

        $issues = [];
        foreach (array_slice($parsed['issues'], 0, 8) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $issues[] = [
                'scene_id' => (string) ($issue['scene_id'] ?? ''),
                'type' => (string) ($issue['type'] ?? ''),
                'quote' => (string) ($issue['quote'] ?? ''),
                'fix' => (string) ($issue['fix'] ?? ''),
            ];
        }

        return $issues;
    }

    /**
     * Apply nominated issues under hard guards. Pure and static so the rules
     * are unit-testable without a key. Refused issues become lint findings.
     *
     * @return array{scenes: array, findings: array<int, array>, applied: int}
     */
    public static function apply(array $scenes, array $issues): array
    {
        $findings = [];
        $applied = 0;

        $byId = [];
        foreach ($scenes as $i => $scene) {
            $byId[(string) ($scene['scene_id'] ?? '')] = $i;
        }

        $manual = function (array $issue, string $why) use (&$findings): void {
            $quote = mb_substr(trim($issue['quote'] ?? ''), 0, 90);
            $findings[] = [
                'severity' => 'info',
                'code' => 'text_pass_manual',
                'scene_id' => (string) ($issue['scene_id'] ?? '') ?: null,
                'message' => "Narration review flagged \"{$quote}\" ({$issue['type']}) but it was not auto-fixed: {$why}.",
            ];
        };

        foreach ($issues as $issue) {
            $type = (string) ($issue['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) {
                continue; // an invented category earns no finding either
            }
            if ($applied >= self::MAX_EDITS) {
                $manual($issue, 'the per-pass edit budget was spent');
                continue;
            }

            $idx = $byId[(string) ($issue['scene_id'] ?? '')] ?? null;
            if ($idx === null) {
                $manual($issue, 'no such scene');
                continue;
            }
            if (in_array((string) ($scenes[$idx]['layout_template'] ?? ''), self::PROTECTED_TEMPLATES, true)) {
                $manual($issue, 'the working narration is step-locked');
                continue;
            }

            $narration = (string) ($scenes[$idx]['narration']['text'] ?? '');
            $quote = trim((string) ($issue['quote'] ?? ''));
            if (mb_strlen($quote) < 12) {
                $manual($issue, 'the quote is too short to match safely');
                continue;
            }
            if (!str_contains($narration, $quote)) {
                $manual($issue, 'the quote does not appear verbatim in the scene');
                continue;
            }

            $fix = trim((string) ($issue['fix'] ?? ''));
            if ($fix !== '' && ($fix === $quote || mb_strlen($fix) > mb_strlen($quote) * 1.6 + 20)) {
                $manual($issue, 'the replacement is not a conservative rewrite');
                continue;
            }

            // One occurrence only — a quote that appears twice in one scene is
            // ambiguous, and replacing both would double the edit.
            $pos = mb_strpos($narration, $quote);
            $next = trim(
                mb_substr($narration, 0, $pos)
                . ($fix !== '' ? $fix . ' ' : '')
                . mb_substr($narration, $pos + mb_strlen($quote))
            );
            $next = (string) preg_replace('/ {2,}/', ' ', $next);

            // A scene must keep something to speak — silencing it entirely
            // breaks pacing and leaves a dead frame.
            if (mb_strlen($next) < 20) {
                $manual($issue, 'the edit would leave the scene with nothing to say');
                continue;
            }

            $scenes[$idx]['narration']['text'] = $next;
            $applied++;
            $findings[] = [
                'severity' => 'info',
                'code' => 'text_pass_fixed',
                'scene_id' => (string) $issue['scene_id'],
                'message' => $fix === ''
                    ? "Narration review removed {$type} \"" . mb_substr($quote, 0, 90) . '".'
                    : "Narration review rewrote {$type} \"" . mb_substr($quote, 0, 90) . '" → "' . mb_substr($fix, 0, 90) . '".',
            ];
        }

        return ['scenes' => $scenes, 'findings' => $findings, 'applied' => $applied];
    }
}
