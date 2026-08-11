<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\LlmModels;
use Modules\Project\Support\StoryboardRevision;

/**
 * StoryboardRevisionService — "tell the AI what is wrong with the storyboard".
 *
 * Two focused calls, deliberately split, because they answer two questions
 * with different failure modes:
 *
 *   1. PLAN  (temp 0) — WHICH cards is the user's note about? Nothing is
 *      rewritten here, so the only thing that can go wrong is naming the
 *      wrong scene, and that is exactly what the op list makes auditable.
 *      A single call that planned and wrote at the same time would quietly
 *      "improve" cards nobody complained about, and there would be no record
 *      that it had: the user's uploads and cached voiceover would be gone
 *      with no way to tell why.
 *   2. DRAFT (temp 0.3) — write the new version of ONLY those cards. It never
 *      sees the rest of the video, so it cannot touch it.
 *
 * Both steps only ever propose. {@see StoryboardRevision} decides what is
 * legal, and every refusal is surfaced to the user instead of swallowed.
 */
class StoryboardRevisionService
{
    /** Cards that read correctly on the maths board (which draws its own visuals). */
    private const BOARD_TEMPLATES = [
        'single_focus', 'stat_spotlight', 'quote_card', 'math_steps', 'geometry_diagram',
        'function_plot', 'scenario_diagram', 'formula_anatomy', 'practice_card',
        'common_mistake', 'term_card', 'checklist_card', 'icon_grid', 'step_flow',
    ];

    /** How many cards one draft call carries; more than this and answers get thin. */
    private const DRAFT_CHUNK = 5;

    private ?string $apiKey;
    private string $model;
    private int $attempts = 0;

    public function __construct(bool $mathMode = false)
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        // Same escalation the rest of the maths pipeline uses: a note about a
        // worked solution is answered by the model that can do the maths.
        $this->model = $mathMode ? LlmModels::for('math') : LlmModels::for('explainer');
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Step 1: which cards is this note about?
     *
     * @param array $scenes  the CURRENT storyboard, validator-shaped
     * @param array $context title, aspect_ratio, math_mode, uploads
     * @return array{ops: array, reply: string}|null null on any transport/parse failure
     */
    public function plan(array $scenes, string $request, array $context = []): ?array
    {
        if (!$this->isConfigured() || trim($request) === '' || $scenes === []) {
            return null;
        }

        $system = <<<'PROMPT'
You are the editor of the storyboard for a short explainer video. The user has looked at the storyboard and written a note about what they want changed. Your job in this step is ONLY to decide WHICH cards the note is about — you do not write any new content yet.

Return ONLY JSON:
{"reply": "<one or two plain sentences to the user: what you are about to change, or a question if the note is unclear>",
 "ops": [ ...zero or more of the operations below... ]}

The operations:
{"op":"edit","scene_id":"<id>","instruction":"<precisely what must be different about THIS card>"}
{"op":"insert","after":"<id>"|"start","instruction":"<what the new card must say and show>"}
{"op":"delete","scene_id":"<id>"}
{"op":"move","scene_id":"<id>","after":"<id>"|"start"}

THE RULE THAT MATTERS MOST: touch the FEWEST cards that satisfy the note.
Every card you leave out of "ops" keeps the picture the user uploaded to it, its already-recorded voiceover and its timing. Every card you name is rebuilt from scratch and loses all of that. A card the user did not complain about must never appear in "ops".

- The note names a card ("scene 4", "the chart one", "the intro", "the ending") -> that card, and nothing else.
- The note is genuinely about the whole video ("the narration is too long everywhere") -> list each card it really affects, at most 12.
- The note asks for something that is missing -> "insert", anchored after the card it should follow.
- Never "improve" anything you were not asked about.
- Cards marked SYSTEM are inserted automatically: never edit or move one. Delete one only if the user clearly asked for that card to go.
- If you cannot tell which card the user means, return "ops": [] and ask your question in "reply".
- "instruction" is read by the writer in the next step, who will NOT see the rest of the video. Be specific and name the real content ("make it a bar chart of 2019, 2020, 2021 revenue: 12, 40 and 68 million"). Never write "improve it" or "make it better".
PROMPT;

        $parsed = $this->call($system, $this->planUser($scenes, $request, $context), 0.0, 1400, 'revision_plan');
        if ($parsed === null) {
            return null;
        }

        return [
            'ops' => is_array($parsed['ops'] ?? null) ? $parsed['ops'] : [],
            'reply' => trim((string) ($parsed['reply'] ?? '')),
        ];
    }

    /**
     * Step 2: write the new version of each planned card.
     *
     * @param array $ops     a normalized op list (edits + inserts only matter here)
     * @param array $scenes  the CURRENT storyboard, for the cards being edited
     * @param array<string,string> $critique  keys whose previous draft was
     *        thrown away, and why — when given, ONLY those are re-drafted.
     *        A card whose content did not parse is worth exactly one more
     *        try with the failure named: the alternative is telling the user
     *        "could not build that" over a fixable JSON shape.
     * @return array<string, array> key => raw scene body
     */
    public function draft(array $ops, array $scenes, string $request, array $context = [], array $critique = []): array
    {
        $jobs = [];
        foreach ($ops as $op) {
            if (!in_array($op['op'] ?? '', ['edit', 'insert'], true)) {
                continue;
            }
            if ($critique !== [] && !isset($critique[(string) ($op['key'] ?? '')])) {
                continue;
            }
            $jobs[] = $op;
        }
        if ($jobs === [] || !$this->isConfigured()) {
            return [];
        }

        $byId = [];
        foreach ($scenes as $scene) {
            $byId[(string) ($scene['scene_id'] ?? '')] = $scene;
        }

        $system = $this->draftSystem($context);
        $drafts = [];

        foreach (array_chunk($jobs, self::DRAFT_CHUNK) as $chunk) {
            $parsed = $this->call(
                $system,
                $this->draftUser($chunk, $byId, $request, $context, $critique),
                0.3,
                3600,
                'revision_draft'
            );
            if ($parsed === null || !is_array($parsed['scenes'] ?? null)) {
                continue;
            }
            foreach ($parsed['scenes'] as $scene) {
                if (!is_array($scene)) {
                    continue;
                }
                $key = (string) ($scene['key'] ?? $scene['scene_id'] ?? '');
                if ($key === '') {
                    continue;
                }
                $drafts[$key] = $scene;
            }
        }

        return $drafts;
    }

    // ------------------------------------------------------------- prompting

    private function planUser(array $scenes, string $request, array $context): string
    {
        $lines = 'THE VIDEO: ' . mb_substr((string) ($context['title'] ?? ''), 0, 160) . "\n";
        if (($context['math_mode'] ?? false) === true) {
            $lines .= "This is a MATHS video: the figures, plots and working are drawn by the system, never uploaded.\n";
        }
        $lines .= "\nTHE STORYBOARD AS IT STANDS:\n"
            . mb_substr(StoryboardRevision::digest($scenes, (array) ($context['uploads'] ?? [])), 0, 14000);

        $lines .= "\nTHE USER'S NOTE:\n\"" . mb_substr(trim($request), 0, 1500) . '"';

        return $lines;
    }

    private function draftSystem(array $context): string
    {
        $templates = ($context['composition_mode'] ?? '') === 'math_board'
            ? self::BOARD_TEMPLATES
            : array_keys(GenericStoryboardComposerService::cardDocs());

        // Never offer a card the registry does not actually have.
        $templates = array_values(array_filter($templates, fn ($t) => ExplainerRegistry::hasTemplate($t)));
        $docs = GenericStoryboardComposerService::docsFor($templates);

        $system = <<<PROMPT
You rewrite INDIVIDUAL cards of a storyboard for a short explainer video. You are given only the cards that must change, each with an instruction. Everything else in the video is already final, is not shown to you, and is not yours to fix.

Return ONLY JSON:
{"scenes": [{"key": "<exactly the key you were given>", "layout_template": "<one of the cards below>", "narration": "<REQUIRED — what the voice says out loud over this card>", "seconds": <number>, "slots": { <that template's slots> }}]}

The cards you may use, and the exact shape of their content:
{$docs}
Rules:
- Return exactly one entry per key you were given, using that key verbatim. Never invent a key or return a card you were not asked for.
- "narration" is never optional. Every card is spoken over, and a card returned without narration is thrown away.
- "slots" REPLACES the card's content, so return every slot the chosen template declares — a slot you leave out is filled with a generic placeholder.
- Do exactly what the instruction says and nothing more. If it only asks for different wording, keep the same layout_template and re-send its slots with the wording fixed.
- When the card CHANGES, rewrite the narration to match it. The voice must never still be describing the content you just replaced.
- Narration is spoken aloud. Never describe the screen ("as you can see", "this chart shows"), never talk about the video itself, and never read a slot's text back word for word.
- "seconds" is roughly the spoken word count divided by 2.5. Omit it if you are unsure; the system will time the card from its content.
- Every number, name and quotation must come from the instruction or from the card you were given. Never invent a statistic or a source.
- For an image slot, asset_request.description is ONE concrete photographable subject, written so the user recognises the shot they are meant to upload — never text, charts or diagrams.
PROMPT;

        if (($context['math_mode'] ?? false) === true) {
            $system .= "\n- This is a MATHS video: write equations in the linear notation the cards describe"
                . " (frac{a}{b}, sqrt{x}, x^2), never LaTeX and never unicode symbols.";
        }
        if (($context['composition_mode'] ?? '') === 'math_board') {
            $system .= "\n- This video is solved on a continuous board that draws every visual itself."
                . ' Never use an image or video slot: there is nothing to upload and nothing would fill it.';
        }
        $guide = trim((string) ($context['guide'] ?? ''));
        if ($guide !== '') {
            $system .= "\n\nTHE USER'S ORIGINAL BRIEF for this video, which still applies:\n"
                . mb_substr($guide, 0, 1200);
        }

        return $system;
    }

    private function draftUser(array $chunk, array $byId, string $request, array $context, array $critique = []): string
    {
        $out = 'THE VIDEO: ' . mb_substr((string) ($context['title'] ?? ''), 0, 160)
            . ' (' . (string) ($context['aspect_ratio'] ?? '16:9') . ")\n"
            . "THE USER'S NOTE, in their own words: \"" . mb_substr(trim($request), 0, 700) . "\"\n\n";

        if ($critique !== []) {
            $out .= "YOUR PREVIOUS ANSWER WAS THROWN AWAY. Send the SAME cards again, fixing the problem named "
                . "under each one. Re-read that template's content shape above and return every field it lists, "
                . "with real values from the instruction.\n\n";
        }

        $out .= "CARDS TO WRITE:\n";

        foreach ($chunk as $op) {
            $key = (string) $op['key'];
            $out .= "\n--- key: {$key}\n";
            $out .= 'instruction: ' . mb_substr((string) ($op['instruction'] ?? ''), 0, 700) . "\n";
            if (isset($critique[$key])) {
                $out .= 'WHAT WENT WRONG LAST TIME: ' . $critique[$key] . "\n";
            }

            if (($op['op'] ?? '') === 'edit' && isset($byId[(string) $op['scene_id']])) {
                $out .= "this card right now:\n"
                    . mb_substr(StoryboardRevision::sceneJson($byId[(string) $op['scene_id']]), 0, 2400) . "\n";
            } else {
                $anchor = (string) ($op['after'] ?? '');
                $out .= $anchor === '' || !isset($byId[$anchor])
                    ? "this is a NEW card, opening the video.\n"
                    : "this is a NEW card, placed straight after this one:\n  \""
                        . mb_substr($this->narrationOf($byId[$anchor]), 0, 300) . "\"\n";
            }
        }

        return $out;
    }

    private function narrationOf(array $scene): string
    {
        $narration = $scene['narration'] ?? '';

        return is_array($narration) ? (string) ($narration['text'] ?? '') : (string) $narration;
    }

    // ------------------------------------------------------------- transport

    /** One chat round returning decoded JSON. Null on any failure. */
    private function call(string $system, string $user, float $temperature, int $maxTokens, string $costLabel): ?array
    {
        $this->attempts++;

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'response_format' => ['type' => 'json_object'],
                ], $costLabel === 'revision_plan' ? 'minimal' : 'low'));
        } catch (\Throwable $e) {
            Log::info('StoryboardRevisionService: request failed', ['step' => $costLabel, 'error' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::info('StoryboardRevisionService: request rejected', [
                'step' => $costLabel,
                'status' => $response->status(),
            ]);

            return null;
        }

        CostTracker::recordChat($this->model, $response->json('usage'), $costLabel);

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);

        return is_array($parsed) ? $parsed : null;
    }
}
