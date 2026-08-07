<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ScriptSkeletonService — L1 of the planning tree (roadmap §2, M-next-1).
 *
 * Decides the video's ACTS before any scene exists: an ordered list of
 * phases with intent labels and one-line briefs. The shot-list analyzer is
 * then handed the skeleton as a hard outline, so the 400-decision giant
 * call stops deciding structure and only fills it.
 *
 * The LLM's only real contribution is the briefs and how many work phases
 * the problem needs — the SHAPE is enforced deterministically: whatever
 * comes back is rebuilt into the canonical phase order for its kind, with
 * every required phase present. A garbage response (or no API key) still
 * yields a legal skeleton, so the outline directive is unconditional for
 * maths videos. Math only for now; non-math shapes are M-next-3.
 */
class ScriptSkeletonService
{
    /** Canonical phase orders. `work` / `argument_step` repeat. */
    public const WORKED_INTENTS = ['hook', 'scenario_setup', 'given_find', 'concept', 'work', 'see_answer', 'check', 'practice', 'payoff'];
    public const PROOF_INTENTS = ['hook', 'claim', 'build_object', 'argument_step', 'close', 'payoff'];

    /** Non-math shapes (roadmap M-next-3): one skeleton per storytelling
     *  form, picked by the planning call itself. `generic` is the safe
     *  fallback that mirrors how good explainers already flow. */
    public const GENERIC_SHAPES = [
        'argument' => ['hook', 'context', 'point', 'counter', 'resolution', 'payoff'],
        'journey' => ['hook', 'origin', 'era', 'turning_point', 'legacy'],
        'compare' => ['hook', 'contenders', 'round', 'verdict', 'payoff'],
        'countdown' => ['hook', 'setup', 'ranking_reveal', 'number_one', 'payoff'],
        // A product walkthrough / tutorial had no home here, so the planner
        // picked `argument` — the closest fit — and `argument` REQUIRES a
        // counter and a resolution. That is how project 135, a Vreato demo,
        // grew a quote card reading "Some may doubt AI's editing quality":
        // the shape demanded an objection the script never made. A demo does
        // not argue, it SHOWS, so it gets its own spine.
        'demo' => ['hook', 'problem', 'product_intro', 'demo_step', 'result', 'second_feature', 'payoff'],
        'generic' => ['hook', 'context', 'aspect', 'payoff'],
    ];
    /** Which intents may repeat, per shape. */
    private const GENERIC_REPEATS = [
        'point' => 5,
        'era' => 5,
        'round' => 4,
        'aspect' => 5,
        // The walkthrough IS the video — a demo that collapses "paste the
        // link, pick a template, tune the settings, hit generate, watch it
        // work" into one card has thrown away everything worth watching.
        'demo_step' => 8,
        'second_feature' => 3,
    ];
    /** Which intents may be dropped when the model does not use them. */
    private const GENERIC_OPTIONAL = ['counter', 'turning_point', 'second_feature', 'problem', 'result'];

    private const MAX_REPEAT = 5; // work phases cap (argument steps get 6)
    /** Board sections a solve may occupy. Each becomes ONE math card carrying
     *  a full 3-6 line phase of the working, so a solve reads as a few headed
     *  stages instead of a slideshow of single lines. */
    private const MAX_WORK_PHASES = 3;

    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('math');
    }

    /**
     * Plan the phases for a maths video. ALWAYS returns a legal skeleton
     * (canonical default when the model is unavailable or returns junk).
     *
     * @param  array $mathTopic {kind, subject?, scenario?}
     * @return array<int, array{intent: string, brief: string}>
     */
    public function plan(string $script, array $mathTopic, string $guide = ''): array
    {
        $kind = (string) ($mathTopic['kind'] ?? 'worked_problem');
        $scenario = ($mathTopic['scenario'] ?? false) === true;

        $raw = [];
        try {
            $raw = $this->request($script, $kind, $scenario, $guide);
        } catch (\Throwable $e) {
            Log::info('ScriptSkeletonService: planning call unavailable, using canonical skeleton', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->repair($raw, $kind, $scenario);
    }

    /** @return array<int, array{intent?: string, brief?: string}> */
    private function request(string $script, string $kind, bool $scenario, string $guide = ''): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $intents = $kind === 'proof_concept' ? self::PROOF_INTENTS : self::WORKED_INTENTS;
        $intentList = implode(' | ', $intents);
        $shapeHint = $kind === 'proof_concept'
            ? 'argument_step repeats: ONE per step of the argument (2-6). Each brief names what that step adds to the figure or the equation.'
            : 'work repeats: ONE per PHASE of the solving (1-5 — a three-part question is three work phases). '
            . 'A phase is a STAGE OF THE REASONING that carries several lines of working, NOT a single algebraic move: '
            . '"take the Laplace transform of both sides", "solve the transformed equation for Y", "invert back to the time domain" '
            . 'are three phases — "subtract 1.5", "factor out Y", "divide by s+2" are three keystrokes of ONE phase and must NOT '
            . 'each become their own. If a brief could be written as one line of algebra, it is too small: merge it with its neighbours. '
            . 'Each work brief names the stage and what it achieves ("solve the transformed equation for Y(s)").'
            . ($scenario ? ' scenario_setup is REQUIRED here — the problem describes a physical situation.' : '');

        $system = <<<PROMPT
You are the STRUCTURE planner for a short maths explainer video. Do not write scenes — decide the ACTS.

Return ONLY JSON: {"phases": [{"intent": "<one of: {$intentList}>", "brief": "<=12 words, concrete"}]}

Rules:
- Phases appear in teaching order. Every brief is specific to THIS problem, never generic filler.
- {$shapeHint}
- No other intents, no nesting, no extra keys.
PROMPT;

        // The user's own brief for the video. It decides the ACTS as much as
        // the script does — "open with the exercise and question number" is a
        // structural instruction, so the planner has to see it.
        if (trim($guide) !== '') {
            $system .= "\n\nTHE USER'S GUIDE — it outranks the generic shape advice above; "
                . "the FIRST phase must open the way it asks:\n" . mb_substr(trim($guide), 0, 1200);
        }

        $response = Http::withToken($this->apiKey)
            ->timeout(45)
            ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "PROBLEM / SCRIPT:\n" . mb_substr(trim($script), 0, 2400)],
                ],
                'temperature' => 0.0,
                'max_tokens' => 350,
                'response_format' => ['type' => 'json_object'],
            ], 'low'));

        if (!$response->successful()) {
            throw new \RuntimeException("skeleton request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'script_skeleton');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);

        return is_array($parsed['phases'] ?? null) ? $parsed['phases'] : [];
    }

    /**
     * Plan the phases for a NON-math video: the call both PICKS the shape
     * (argument / journey / compare / countdown / generic) and writes the
     * briefs. Additive by design: any failure returns [] — no directive,
     * exactly today's behavior — because non-math has no deterministic
     * backstops and a wrong forced structure would cost more than none.
     *
     * @return array<int, array{intent: string, brief: string}> may be []
     */
    public function planGeneric(string $script, string $guide = '', int $targetSeconds = 60): array
    {
        if (empty($this->apiKey) || trim($script) === '') {
            return [];
        }

        $shapeDefs = <<<'SHAPES'
  argument:  the video CLAIMS something and proves it (why X happened, why Y is better). Phases: hook, context, point (2-5, one claim+evidence each), counter (optional: the strongest objection), resolution, payoff.
  journey:   history / evolution / a life. Phases: hook, origin, era (2-5 chapters in time order), turning_point (optional), legacy.
  compare:   two named things head to head. Phases: hook, contenders (introduce both), round (2-4, one dimension each), verdict, payoff.
  countdown: a ranked top-N list. Phases: hook, setup (the criteria), ranking_reveal (the countdown itself), number_one (dwell on the winner), payoff.
  demo:      a product / app / tool being SHOWN in use — a walkthrough, tutorial or launch video. Phases: hook, problem (optional: the pain it removes), product_intro (name it), demo_step (2-8 — ONE PHASE PER ACTUAL ACTION the script performs: copy the link, paste it, choose the template, change the settings, press generate, watch it process...), result (optional: what you end up with), second_feature (optional, 0-3: another capability the script pivots to), payoff.
  generic:   none of the above fits cleanly. Phases: hook, context, aspect (2-5 facets), payoff.
SHAPES;

        // Beat budget. Left unsaid, the planner returns its comfortable 6-8
        // phases whatever the length — and since the composer writes exactly
        // one scene per phase, a 90-second script came back as 8 cards of ~9s
        // each. That is both monotonous AND lossy: project 135's entire demo
        // walkthrough and its whole second-feature act vanished because there
        // were no phases left to hold them.
        $lo = max(4, (int) round($targetSeconds / 9));
        $hi = max($lo + 2, (int) round($targetSeconds / 5.5));

        $system = <<<PROMPT
You are the STRUCTURE planner for a short explainer video. Do not write scenes — pick the story SHAPE and decide the ACTS.

Return ONLY JSON: {"shape": "argument|journey|compare|countdown|demo|generic", "phases": [{"intent": "<a phase name of that shape>", "brief": "<=12 words, concrete to THIS script"}]}

The shapes:
{$shapeDefs}

Rules:
- Phases in story order; every brief specific to THIS script, never filler.
- Repeatable phases get ONE ENTRY PER BEAT the script actually contains. Do not summarise several beats into one phase.
- The video runs about {$targetSeconds}s, so return roughly {$lo}-{$hi} phases. COVER THE WHOLE SCRIPT: walk it start to finish and make sure its last act has phases of its own. A section of the script with no phase will not appear in the video at all.
- Only claim a phase the SCRIPT supports. Never invent an objection, a statistic or a counter-argument the script does not make — if the shape offers a phase the script has no material for, and that phase is optional, leave it out.
PROMPT;

        // The user's brief outranks the generic shape advice: it routinely
        // dictates structure ("show the earnings graph first, then the URL
        // copy, then the settings screen") and it names the visuals they
        // already have, which is exactly what the acts should be built around.
        if (trim($guide) !== '') {
            $system .= "\n\nTHE USER'S GUIDE for this video — it OUTRANKS the shape advice above. "
                . "Where it asks for something to be shown, or shown in a particular order, give that its own phase, "
                . "in that order:\n" . mb_substr(trim($guide), 0, 1200);
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                    'model' => LlmModels::for('explainer'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "SCRIPT / TOPIC:\n" . mb_substr(trim($script), 0, 2400)],
                    ],
                    'temperature' => 0.0,
                    // A long demo can legitimately plan 16 phases; the old
                    // 400-token ceiling truncated the JSON and the tail acts
                    // were lost before repairGeneric ever saw them.
                    'max_tokens' => 900,
                    'response_format' => ['type' => 'json_object'],
                ], 'low'));
            if (!$response->successful()) {
                return [];
            }
            CostTracker::recordChat(
                LlmModels::for('explainer'),
                $response->json('usage'),
                'script_skeleton'
            );
            $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        } catch (\Throwable $e) {
            Log::info('ScriptSkeletonService: generic planning unavailable', ['error' => $e->getMessage()]);
            return [];
        }

        if (!is_array($parsed)) {
            return [];
        }

        return $this->repairGeneric(
            (string) ($parsed['shape'] ?? ''),
            is_array($parsed['phases'] ?? null) ? $parsed['phases'] : []
        );
    }

    /**
     * Canonicalise a non-math skeleton: unknown shape -> generic, unknown
     * intents dropped, singletons deduped, repeats capped, optional phases
     * kept only when the model used them, order fixed to the shape's law.
     * Returns [] when nothing usable came back (no directive, no harm).
     *
     * @return array<int, array{intent: string, brief: string}>
     */
    public function repairGeneric(string $shape, array $raw): array
    {
        $shape = strtolower(trim($shape));
        if (!isset(self::GENERIC_SHAPES[$shape])) {
            $shape = 'generic';
        }
        $order = self::GENERIC_SHAPES[$shape];

        $briefs = [];
        foreach ($raw as $p) {
            if (!is_array($p)) {
                continue;
            }
            $intent = strtolower(trim((string) (is_scalar($p['intent'] ?? null) ? $p['intent'] : '')));
            $brief = trim((string) (is_scalar($p['brief'] ?? null) ? $p['brief'] : ''));
            if (in_array($intent, $order, true) && $brief !== '') {
                $briefs[$intent][] = mb_substr($brief, 0, 90);
            }
        }
        if ($briefs === []) {
            return []; // nothing usable — additive means no directive at all
        }

        $out = [];
        foreach ($order as $intent) {
            $cap = self::GENERIC_REPEATS[$intent] ?? 1;
            $have = array_slice($briefs[$intent] ?? [], 0, $cap);
            if ($have === []) {
                if (in_array($intent, self::GENERIC_OPTIONAL, true)) {
                    continue; // optional and unused — drop
                }
                $have = [$this->genericFallbackBrief($intent)];
            }
            foreach ($have as $b) {
                $out[] = ['intent' => $intent, 'brief' => $b];
            }
        }

        return $out;
    }

    /**
     * Group keystroke-level work briefs into real PHASES.
     *
     * The planner is told a phase is a stage of the reasoning, not one
     * algebraic move — and it ignores that whenever the SCRIPT has a paragraph
     * per move, which is most of the time. Left alone the consequences cascade:
     * each one-move brief gives the composer nothing to write but a single
     * line, so every math card comes back one step tall, and the validator's
     * thin-card merges then weld the whole solve back into one heading-less
     * slab (project 38: five briefs -> five 1-step cards -> one 70s card).
     *
     * So the grouping is a GUARANTEE, not a request: consecutive work briefs
     * are bucketed into at most MAX_WORK_PHASES, and each bucket's briefs join
     * into one instruction, which the composer then expands into a full 3-6
     * line phase. Fewer briefs than the cap are already phase-sized — untouched.
     *
     * @param  array<int, string>  $works
     * @return array<int, string>
     */
    private function groupWorkPhases(array $works): array
    {
        $works = array_values(array_filter(array_map('trim', $works), fn ($b) => $b !== ''));
        if (count($works) <= self::MAX_WORK_PHASES) {
            return $works;
        }

        $buckets = self::MAX_WORK_PHASES;
        $total = count($works);
        $out = [];
        $start = 0;
        for ($i = 0; $i < $buckets; $i++) {
            // Distribute the remainder over the FIRST buckets, so a 5-into-3
            // split is 2/2/1 rather than 1/1/3 — the working gets heavier
            // early, where the substitutions are, not at the answer.
            $size = intdiv($total - $start, $buckets - $i) + (($total - $start) % ($buckets - $i) > 0 ? 1 : 0);
            $slice = array_slice($works, $start, $size);
            if ($slice === []) {
                break;
            }
            $out[] = mb_substr(implode('; then ', $slice), 0, 200);
            $start += $size;
        }

        return $out;
    }

    private function genericFallbackBrief(string $intent): string
    {
        return match ($intent) {
            'hook' => 'open on the question the video answers',
            'context' => 'the background the viewer needs',
            'point' => 'the next claim, with its evidence',
            'resolution' => 'what it all adds up to',
            'origin' => 'where the story starts',
            'era' => 'the next chapter of the story',
            'legacy' => 'what it left behind',
            'contenders' => 'introduce both sides',
            'round' => 'the next dimension compared',
            'verdict' => 'the call, with the reason',
            'setup' => 'the criteria for the ranking',
            'ranking_reveal' => 'count down the list',
            'number_one' => 'dwell on the winner',
            'aspect' => 'the next facet of the topic',
            'problem' => 'the pain the product removes',
            'product_intro' => 'name the product and what it is',
            'demo_step' => 'the next action performed on screen',
            'result' => 'what the viewer ends up with',
            'second_feature' => 'another thing it can do',
            default => 'the payoff worth remembering',
        };
    }

    /**
     * Rebuild whatever came back into the canonical shape: required phases
     * present, singletons deduped, repeats capped, order fixed. The model
     * contributes briefs; the structure is law.
     *
     * @return array<int, array{intent: string, brief: string}>
     */
    public function repair(array $raw, string $kind, bool $scenario): array
    {
        // Collect the usable briefs by intent, preserving repeat order.
        $briefs = [];
        foreach ($raw as $p) {
            if (!is_array($p)) {
                continue;
            }
            $intent = strtolower(trim((string) (is_scalar($p['intent'] ?? null) ? $p['intent'] : '')));
            $brief = trim((string) (is_scalar($p['brief'] ?? null) ? $p['brief'] : ''));
            $legal = $kind === 'proof_concept' ? self::PROOF_INTENTS : self::WORKED_INTENTS;
            if (!in_array($intent, $legal, true)) {
                continue;
            }
            $briefs[$intent][] = mb_substr($brief, 0, 90);
        }

        $take = function (string $intent, string $fallback) use (&$briefs): array {
            $brief = '';
            if (!empty($briefs[$intent])) {
                $brief = trim((string) array_shift($briefs[$intent]));
            }

            return ['intent' => $intent, 'brief' => $brief !== '' ? $brief : $fallback];
        };

        if ($kind === 'proof_concept') {
            $out = [
                $take('hook', 'state the claim as a question worth caring about'),
                $take('claim', 'state exactly what is being proven'),
                $take('build_object', 'draw the object the argument talks about'),
            ];
            $steps = array_slice($briefs['argument_step'] ?? [], 0, 6);
            if ($steps === []) {
                $steps = ['grow the figure one element at a time', 'show the two quantities are the same'];
            }
            foreach ($steps as $b) {
                $out[] = ['intent' => 'argument_step', 'brief' => $b !== '' ? $b : 'add the next element of the argument'];
            }
            $out[] = $take('close', 'the beat where the two sides meet');
            $out[] = $take('payoff', 'what the result lets you do');

            return $out;
        }

        $out = [$take('hook', 'state the problem and why it matters')];
        if ($scenario) {
            $out[] = $take('scenario_setup', 'draw the situation: actors, given values, the question');
        }
        $out[] = $take('given_find', 'state exactly what we know and what we want');
        // Unconditional, like every other phase here. Leaving it up to whether
        // the planner happened to return a concept brief meant the same script
        // gained or lost its formula/idea beat between runs — the structure is
        // law, and "the idea that unlocks the solve" is part of the structure.
        // The composer decides the CARD: a formula with labelled parts becomes
        // formula_anatomy, anything thinner degrades to the text beat.
        $out[] = $take('concept', 'the idea that unlocks the solve');
        $works = $this->groupWorkPhases(array_slice($briefs['work'] ?? [], 0, self::MAX_REPEAT));
        if ($works === []) {
            $works = ['work the solution step by step to the answer'];
        }
        foreach ($works as $b) {
            $out[] = ['intent' => 'work', 'brief' => $b !== '' ? $b : 'the next phase of the working'];
        }
        $out[] = $take('see_answer', 'show the answer on the graph or number line');
        $out[] = $take('check', 'substitute the answer back and watch it hold');
        // Doing one beats watching one. The composer synthesizes this beat and
        // drops it silently when it cannot verify an answer, so planning it is
        // never a promise the storyboard has to keep.
        $out[] = $take('practice', 'hand the viewer one of the same kind to try');
        $out[] = $take('payoff', 'the trick worth remembering');

        return $out;
    }
}
