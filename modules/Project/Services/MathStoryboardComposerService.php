<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MathStoryboardComposerService — L2 + L3 of the planning tree (roadmap §2,
 * M-next-2): the giant do-everything analyze call, retired for worked-math
 * videos.
 *
 * L2 (casting) is DETERMINISTIC here: the skeleton's phase intents map
 * straight onto card types — a work phase IS a math_steps card, the
 * scenario IS a scenario_diagram — so a work phase cannot come back as a
 * big_counter because nothing ever offers it one. L3 (details) is two
 * focused calls plus the deterministic synthesizers as equal citizens:
 *
 *   Call A  the WORKING — every work phase's chain/rule/narration in one
 *           request whose whole prompt is the maths notation rules.
 *   Call B  the PROSE — hook / given–find / concept / payoff text cards.
 *   synth   scenario (focused call), SEE-the-answer plot (zero LLM, from
 *           the working Call A just wrote), CHECK card (zero LLM,
 *           TinyExpr-verified).
 *
 * The composed shot list is RAW — it flows through the same
 * ShotListValidator as the giant call's output, so every clamp, fold and
 * merge stays as the safety net. compose() returns null on ANY failure and
 * the caller falls back to the giant call: the tree can only make things
 * better, never strand a project.
 */
class MathStoryboardComposerService
{
    private ?string $apiKey;
    private string $model;
    private int $attempts = 0;

    /** The user's own direction for this video (create-page "Guide for the AI").
     *  The composer REWRITES narration rather than reusing the script's
     *  sentences, so anything the guide asked for at the top of the video only
     *  survives if the guide reaches these prompts too. */
    private string $guide = '';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('math');
    }

    /** Set the user's guide before composing; empty string clears it. */
    public function setGuide(?string $guide): self
    {
        $this->guide = trim((string) $guide);

        return $this;
    }

    /** The GUIDE block for a composer prompt, or '' when the user gave none. */
    private function guideBlock(): string
    {
        if ($this->guide === '') {
            return '';
        }

        return "\n\nTHE USER'S GUIDE for this video — follow it exactly where it "
            . "applies to what you are writing; it outranks the generic advice above, "
            . "and the FIRST card must open the way it asks:\n"
            . mb_substr($this->guide, 0, 1200);
    }

    /** LLM round trips used (cost signal, mirrors ScriptAnalysisService). */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Below this many derivation lines in TOTAL, a short working is a stub
     * rather than a merge, and the video is better off with the giant call
     * than with a solve that stops before the answer.
     */
    private const MIN_WORKING_STEPS = 3;

    /**
     * Keep the first $keep `work` phases and drop the rest, leaving every
     * other phase untouched. Used when the working merged the planned buckets:
     * the plan has to match what will actually be rendered, or assemble()
     * walks a phase it has no chain for.
     *
     * @param  array<int, array{intent: string, brief: string}> $skeleton
     * @return array<int, array{intent: string, brief: string}>
     */
    /**
     * Is there enough in a proof response to build an argument from?
     *
     * EITHER a usable opening figure OR at least one usable argument step —
     * not both. Each is a drawing in its own right, and the composer's real
     * floor (>=2 figure scenes, checked after assembly) decides whether what
     * arrived is an argument or a slideshow.
     */
    private function proofPartsUsable(mixed $parsed): bool
    {
        if (!is_array($parsed)) {
            return false;
        }
        if (is_array($parsed['object']['slot'] ?? null)) {
            return true;
        }
        foreach ((array) ($parsed['steps'] ?? []) as $step) {
            if (is_array($step) && is_array($step['slot'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Total derivation lines across every returned work phase. */
    private function totalSteps(array $working): int
    {
        return array_sum(array_map(fn ($w) => count((array) ($w['steps'] ?? [])), $working));
    }

    private function trimWorkPhases(array $skeleton, int $keep): array
    {
        $seen = 0;
        $out = [];
        foreach ($skeleton as $phase) {
            if (($phase['intent'] ?? '') === 'work') {
                $seen++;
                if ($seen > $keep) {
                    continue;
                }
            }
            $out[] = $phase;
        }

        return $out;
    }

    /**
     * Compose the raw shot list for a worked problem. Null when anything
     * essential is missing — the caller falls back to the giant call.
     *
     * @param array $skeleton L1 phases [{intent, brief}]
     * @param array $mathTopic {kind, subject?, scenario?}
     */
    public function compose(string $script, array $skeleton, array $mathTopic, string $title = ''): ?array
    {
        if (empty($this->apiKey) || trim($script) === '' || $skeleton === []) {
            return null;
        }

        $workBriefs = [];
        foreach ($skeleton as $phase) {
            if (($phase['intent'] ?? '') === 'work') {
                $workBriefs[] = (string) ($phase['brief'] ?? '');
            }
        }
        if ($workBriefs === []) {
            return null; // nothing to solve — not this composer's video
        }

        $working = $this->requestWorking($script, $workBriefs, (string) ($mathTopic['subject'] ?? ''));

        // A SHORT working is the worst failure this composer has: assemble()
        // drops the work phases it has no entry for, so the video simply stops
        // mid-solve — project 40 ended on "Y(s) = 1.5/(s+2)" and never showed
        // y = 1.5e^(-2t) at all. The viewer is left without the answer, which
        // is the one thing a worked-problem video exists to deliver. So: one
        // corrective retry naming the phases that came back missing, and if it
        // is STILL short, hand the whole video to the giant call rather than
        // publish an unfinished solve.
        // "Short" is not the only way a solve arrives unfinished. When the
        // model returns the RIGHT NUMBER of phases but only a couple of lines
        // in them, nothing here used to object: project 98 shipped a quadratic
        // video whose entire working was "x^2 - 6x + 4 = 0" followed by the
        // formula — it never substituted, never reached a root, and so there
        // was no answer for the see-the-answer beat to draw either. Thin counts
        // as unfinished, and gets the same corrective retry.
        $thin = is_array($working) && $this->totalSteps($working) < self::MIN_WORKING_STEPS;
        if (is_array($working) && count($working) > 0
            && (count($working) < count($workBriefs) || $thin)) {
            Log::info('MathStoryboardComposer: working came back unfinished, retrying', [
                'got' => count($working),
                'want' => count($workBriefs),
                'steps' => $this->totalSteps($working),
            ]);
            $retry = $this->requestWorking(
                $script,
                $workBriefs,
                (string) ($mathTopic['subject'] ?? ''),
                count($working)
            );
            // Take the retry when it is fuller by EITHER measure — more phases,
            // or more working in the phases it did return.
            if (is_array($retry) && (count($retry) > count($working)
                || $this->totalSteps($retry) > $this->totalSteps($working))) {
                $working = $retry;
            }
        }

        if ($working === null || count($working) === 0) {
            Log::info('MathStoryboardComposer: working call unusable, falling back');
            return null;
        }
        if ($this->totalSteps($working) < self::MIN_WORKING_STEPS) {
            // Still a stub after the retry. A worked-problem video that never
            // computes its answer is the one thing this composer must not
            // publish, so this is what the giant call is genuinely for.
            Log::warning('MathStoryboardComposer: working still a stub after retry, falling back', [
                'phases' => count($working),
                'steps' => $this->totalSteps($working),
            ]);

            return null;
        }
        if (count($working) < count($workBriefs)) {
            // A working that came back short but SUBSTANTIAL has almost always
            // merged the planned buckets rather than truncated the solve — the
            // planner's bucketing is a suggestion, and the model routinely
            // answers three briefs with two fuller chains.
            //
            // Handing those videos to the giant call (what used to happen here)
            // cost far more than it saved: the whole storyboard changed shape,
            // so the SAME script came back as a board of worked chains on one
            // run and a deck of big_counter/step_flow cards on the next — the
            // single largest source of run-to-run variance in the template, and
            // the giant call does not guarantee a finished solve either. It is
            // the fallback of last resort, not a response to an off-by-one.
            //
            // So: keep the tree whenever the working is a real solve, and trim
            // the plan to what actually arrived so no work phase is left
            // standing with nothing to render. A stub still falls back — that
            // is the case the original guard was written for.
            Log::info('MathStoryboardComposer: working merged the planned phases, keeping the tree', [
                'got' => count($working),
                'want' => count($workBriefs),
                'steps' => $this->totalSteps($working),
            ]);
            $skeleton = $this->trimWorkPhases($skeleton, count($working));
        }

        $proseIntents = array_values(array_filter(
            array_column($skeleton, 'intent'),
            fn ($i) => in_array($i, ['hook', 'given_find', 'concept', 'payoff'], true)
        ));
        $prose = $this->requestProse($script, $skeleton, $proseIntents);
        // Prose is nice-to-have: a missing card degrades to a narration-only
        // text scene, so a failed call does not sink the tree.

        return $this->assemble($script, $skeleton, $mathTopic, $title, $working, $prose ?? []);
    }

    /**
     * Compose a PROOF/CONCEPT video: the argument is an EVOLVING figure.
     * One focused call returns the claim, the object, and one step per
     * argument phase — each step repeating the figure plus ONE new element
     * — then the close. Null on anything thin: the giant call remains the
     * fallback.
     */
    public function composeProof(string $script, array $skeleton, array $mathTopic, string $title = ''): ?array
    {
        if (empty($this->apiKey) || trim($script) === '' || $skeleton === []) {
            return null;
        }
        $stepBriefs = [];
        foreach ($skeleton as $p) {
            if (($p['intent'] ?? '') === 'argument_step') {
                $stepBriefs[] = (string) ($p['brief'] ?? '');
            }
        }
        if ($stepBriefs === []) {
            return null;
        }

        $list = '';
        foreach ($stepBriefs as $i => $b) {
            $list .= '  ' . ($i + 1) . '. ' . $b . "\n";
        }
        $n = count($stepBriefs);

        $system = <<<PROMPT
You write the ARGUMENT of a maths proof/concept video as an EVOLVING figure. Return ONLY JSON:

{"claim": {"heading": "the claim in plain words (<=40 chars)", "statement": "<=60 the identity/claim in linear notation", "narration": "2 sentences"},
 "object": {"template": "geometry_diagram"|"function_plot", "slot": {<content>}, "narration": "2-3 sentences introducing the drawing"},
 "steps": [exactly {$n} of {"template": "geometry_diagram"|"function_plot"|"math_steps", "slot": {<content>}, "narration": "2-3 sentences"}],
 "close": {"steps": [2-4 of {"expr": "<=60", "note?": "<=6 words"}], "narration": "the beat where the two sides meet"}}

The argument steps, in order:
{$list}
Figure content shapes:
- geometry_diagram slot: {"shape": "triangle|right_triangle|square|rectangle|circle|polygon|angle|unit_circle|number_line|coordinate_plane|fraction_bar|area_model", "points?": [{"x":0..1,"y":0..1 (y UP),"label"}], "side_labels?": [one per edge], "side_squares?": [one per edge, "" for none — "a^2" erects a labelled square on that side], "highlight_side?": edge index, "angle_marks?": [{"at":vertex,"label":"90°","right?":true}], "segments?": [{"from":"vertex label/index","to":"...","label?","dashed?":true}] draws an INTERNAL line — a cevian, median, diagonal, or the parallel cut of a similar-triangle proof, "circumcircle?": true draws the circle through the first 3 vertices (angle-in-a-semicircle, cyclic figures), "terms?": ["a","b"] with shape area_model builds the (a+b)^2 box carved into a^2/ab/ab/b^2 ("col_terms?" for a rectangle product (a+b)(c+d)), "angle_deg?/angle2_deg?" for unit_circle, "fill?": true}
- function_plot slot: {"expression": calculator syntax in x, "x_min?","x_max?","marks?":[{"x","label"}], "expression2?","tangent_at?","shade?":{"from","to"}}
- math_steps slot: {"steps":[2-4 of {"expr","note?"}], "rule?":{"name","formula","why"}}
Non-negotiable:
- The figure GROWS: each step's slot REPEATS the previous figure's content PLUS exactly one new element (a square on the next side, a highlight, a second angle, a shaded region). Never draw a different object.
- Linear notation everywhere (x^2, sqrt{...}, +-, pi) — no LaTeX backslashes.
- Every narration says WHY, not just what.
PROMPT;

        $parsed = $this->requestJson($system, "TOPIC / SCRIPT:\n" . mb_substr(trim($script), 0, 2400), 1700, 'tree_proof');

        // The opening drawing and the argument steps are separate parts of the
        // answer, and demanding BOTH threw away responses that had one of them
        // — a proof with five good steps was discarded because `object` came
        // back malformed, and the giant call then cast a photo panel into a
        // maths video (project 99). One corrective retry, then build with
        // whatever real figures arrived; the >=2 figures floor below is the
        // honest test of whether an argument survived.
        if (!$this->proofPartsUsable($parsed)) {
            Log::info('MathStoryboardComposer: proof call unusable, retrying');
            $parsed = $this->requestJson(
                $system,
                "TOPIC / SCRIPT:\n" . mb_substr(trim($script), 0, 2400)
                    . "\n\nYour previous answer was not usable. Return the FULL JSON: object.slot must be an"
                    . " object, and steps must be an array of exactly {$n} entries, each with its own slot.",
                1700,
                'tree_proof'
            );
        }
        if (!$this->proofPartsUsable($parsed)) {
            Log::info('MathStoryboardComposer: proof call still unusable after retry, falling back');

            return null;
        }
        $parsed['steps'] = is_array($parsed['steps'] ?? null) ? $parsed['steps'] : [];

        $prose = $this->requestProse($script, $skeleton, ['hook', 'payoff']) ?? [];
        $scenes = [];
        $nId = 0;
        $slotFor = fn (string $tpl): array => match ($tpl) {
            'geometry_diagram' => ['key' => 'slot_geometry', 'ct' => 'geometry'],
            'function_plot' => ['key' => 'slot_plot', 'ct' => 'function_plot'],
            default => ['key' => 'slot_math', 'ct' => 'math_steps'],
        };
        $figScene = function (string $tpl, array $slot, string $narr, float $dur) use (&$nId, $slotFor): ?array {
            if (!in_array($tpl, ['geometry_diagram', 'function_plot', 'math_steps'], true)) {
                return null;
            }
            $meta = $slotFor($tpl);
            $slot['content_type'] = $meta['ct'];
            $nId++;

            return [
                'scene_id' => "scene_{$nId}",
                'layout_template' => $tpl,
                'duration_seconds' => $dur,
                'narration' => ['text' => $narr],
                'mood' => 'neutral',
                'slots' => [$meta['key'] => $slot],
            ];
        };

        // hook — a missing prose card is a one-line stub and gets 5s, not a
        // 10-second static hold (the textScene lesson from the worked path).
        $hookCard = $prose['hook'] ?? null;
        $nId++;
        $scenes[] = [
            'scene_id' => "scene_{$nId}", 'layout_template' => 'single_focus',
            'duration_seconds' => $hookCard !== null ? 10 : 5,
            'narration' => ['text' => trim((string) ($hookCard['narration'] ?? '')) ?: 'Here is a claim worth doubting — until you see it.'],
            'mood' => 'neutral',
            'slots' => ['slot_main' => ['content_type' => 'text_block',
                'heading' => trim((string) ($hookCard['heading'] ?? '')),
                'bullets' => (array) ($hookCard['bullets'] ?? [])]],
        ];
        // claim — project 91 taught this card two lessons. (1) The model
        // echoes the schema's own "<=40" as the heading more readily here
        // than anywhere else, so a constraint-looking heading falls back
        // (the validator scrubs any that slip other paths). (2) A ~10s
        // single-bullet text hold is exactly what the static_hold lint
        // exists to catch, so the card always carries TWO reveal bullets:
        // the statement plus the narration's own opening sentence.
        $claim = (array) ($parsed['claim'] ?? []);
        $claimHeading = trim((string) ($claim['heading'] ?? ''));
        if ($claimHeading === '' || preg_match('/^(?:[<>]=?|[≤≥])\s*\d+/u', $claimHeading)) {
            $claimHeading = 'The Claim';
        }
        $claimNarr = trim((string) ($claim['narration'] ?? '')) ?: 'Here is exactly what we are going to prove.';
        $claimBullets = array_values(array_filter([trim((string) ($claim['statement'] ?? ''))]));
        foreach (preg_split('/(?<=[.!?])\s+/', $claimNarr) ?: [] as $sent) {
            if (count($claimBullets) >= 2) {
                break;
            }
            $sent = trim($sent);
            if ($sent !== '' && mb_strlen($sent) <= 60 && !in_array($sent, $claimBullets, true)) {
                $claimBullets[] = $sent;
            }
        }
        $nId++;
        $scenes[] = [
            'scene_id' => "scene_{$nId}", 'layout_template' => 'single_focus', 'duration_seconds' => 10,
            'narration' => ['text' => $claimNarr],
            'mood' => 'neutral',
            'slots' => ['slot_main' => ['content_type' => 'text_block',
                'heading' => $claimHeading,
                'bullets' => $claimBullets]],
        ];
        // object + steps + close
        if (is_array($parsed['object']['slot'] ?? null)) {
            $obj = $figScene(
                (string) ($parsed['object']['template'] ?? 'geometry_diagram'),
                $parsed['object']['slot'],
                trim((string) ($parsed['object']['narration'] ?? '')),
                12
            );
            if ($obj !== null) {
                $scenes[] = $obj;
            }
        }
        foreach ($parsed['steps'] as $st) {
            if (!is_array($st) || !is_array($st['slot'] ?? null)) {
                continue;
            }
            $sc = $figScene((string) ($st['template'] ?? ''), $st['slot'], trim((string) ($st['narration'] ?? '')), 12);
            if ($sc !== null) {
                $scenes[] = $sc;
            }
        }
        if (is_array($parsed['close']['steps'] ?? null) && $parsed['close']['steps'] !== []) {
            $sc = $figScene('math_steps', ['steps' => $parsed['close']['steps']], trim((string) ($parsed['close']['narration'] ?? '')), 12);
            if ($sc !== null) {
                $scenes[] = $sc;
            }
        }
        // payoff — same stub rule as the hook: no prose card, no 10s hold.
        $pay = $prose['payoff'] ?? null;
        $nId++;
        $scenes[] = [
            'scene_id' => "scene_{$nId}", 'layout_template' => 'single_focus',
            'duration_seconds' => $pay !== null ? 10 : 5,
            'narration' => ['text' => trim((string) ($pay['narration'] ?? '')) ?: 'And that is why it is true — not memorised, seen.'],
            'mood' => 'neutral',
            'slots' => ['slot_main' => ['content_type' => 'text_block',
                'heading' => trim((string) ($pay['heading'] ?? '')),
                'bullets' => (array) ($pay['bullets'] ?? [])]],
        ];

        $figures = array_filter($scenes, fn ($s) => in_array($s['layout_template'], ['geometry_diagram', 'function_plot', 'math_steps'], true));
        if (count($figures) < 2) {
            return null; // an argument with one drawing is not an argument
        }

        // The drawn proof is ONE figure that grows, not a slideshow of near-
        // identical redraws. Fuse the run of consecutive geometry scenes (the
        // object + each argument step that adds a square) into a single slide
        // whose squares rise in step with the joined narration — "now a square
        // on side b" spoken exactly as that square stands up.
        $scenes = $this->fuseEvolvingGeometry($scenes);

        foreach ($scenes as $i => &$s) {
            $s['order'] = $i + 1;
        }
        unset($s);
        Log::info('MathStoryboardComposer: proof composed by the tree', ['scenes' => count($scenes), 'attempts' => $this->attempts]);

        return ['scenes' => $scenes, 'summary' => trim((string) ($mathTopic['subject'] ?? '')) ?: 'A maths proof, drawn.'];
    }

    /**
     * Collapse a run of consecutive geometry_diagram scenes that draw "a square
     * on each side of a triangle" (Pythagoras and every areas-on-the-sides
     * argument) into ONE evolving slide, built DETERMINISTICALLY.
     *
     * The model cannot be trusted to grow one figure: project 37 came back as a
     * right_triangle followed by four SEPARATE, sometimes-degenerate `square`
     * figures with their own coordinates — a slideshow of broken squares, not a
     * triangle gaining squares. So we ignore the steps' figures entirely: take
     * the triangle, erect a square on every LABELLED side (named `<label>^2`),
     * and reveal them one at a time in step with the joined narration. The
     * argument steps survive only as narration + time.
     *
     * Fires only on the first contiguous run of >=2 geometry scenes whose first
     * triangle carries >=2 side labels AND where the run actually erects squares
     * (a side_squares entry anywhere, or a step drawn as a square/rectangle).
     * Everything else is returned untouched.
     */
    private function fuseEvolvingGeometry(array $scenes): array
    {
        $n = count($scenes);
        $geoAt = fn ($i) => isset($scenes[$i]) && ($scenes[$i]['layout_template'] ?? '') === 'geometry_diagram';

        $start = null;
        $end = null;
        for ($i = 0; $i < $n; $i++) {
            if (!$geoAt($i)) {
                continue;
            }
            $j = $i;
            while ($geoAt($j + 1)) {
                $j++;
            }
            if ($j > $i) {
                $start = $i;
                $end = $j;
                break;
            }
            $i = $j;
        }
        if ($start === null) {
            return $scenes;
        }

        $run = array_slice($scenes, $start, $end - $start + 1);
        $slotOf = fn (array $s): array => (array) ($s['slots']['slot_geometry'] ?? []);

        // --- Path A: squares on the sides of a triangle -----------------------
        // The strongest, most-abused pattern (Pythagoras): rebuilt from scratch
        // because the model often draws the steps as separate square figures.
        [$slot, $revealOrder, $baseIdx] = $this->buildTriangleSquares($run, $slotOf);

        // --- Path B: the SAME base figure gaining elements --------------------
        // Any evolving figure the model DID keep consistent (same shape + same
        // points across the run): a coordinate plane collecting points/lines, a
        // circle gaining a chord, a polygon gaining marks. Union what each step
        // added; the squares (if any) still narration-sync, the rest builds on
        // the one slide instead of flickering across near-identical redraws.
        if ($slot === null) {
            [$slot, $revealOrder, $baseIdx] = $this->unionSameBase($run, $slotOf);
        }

        if ($slot === null) {
            return $scenes; // not an evolving figure we can safely fuse
        }

        return $this->assembleFused($scenes, $start, $end, $run, $baseIdx, $slot, $revealOrder);
    }

    /**
     * Path A — a triangle with a square erected on each labelled side. Returns
     * [slot, revealOrder, baseIdx] or [null, [], 0] when the run isn't this.
     */
    private function buildTriangleSquares(array $run, callable $slotOf): array
    {
        $baseIdx = null;
        foreach ($run as $k => $s) {
            $sh = strtolower(trim((string) ($slotOf($s)['shape'] ?? '')));
            if (in_array($sh, ['right_triangle', 'triangle'], true) && count((array) ($slotOf($s)['points'] ?? [])) >= 3) {
                $baseIdx = $k;
                break;
            }
        }
        if ($baseIdx === null) {
            return [null, [], 0];
        }
        $base = $slotOf($run[$baseIdx]);

        $squaresIndicated = false;
        foreach ($run as $k => $s) {
            $g = $slotOf($s);
            if (array_filter((array) ($g['side_squares'] ?? []), fn ($x) => trim((string) $x) !== '') !== []) {
                $squaresIndicated = true;
                break;
            }
            if ($k !== $baseIdx && in_array(strtolower(trim((string) ($g['shape'] ?? ''))), ['square', 'rectangle'], true)) {
                $squaresIndicated = true;
                break;
            }
        }
        if (!$squaresIndicated) {
            return [null, [], 0];
        }

        $labels = array_values((array) ($base['side_labels'] ?? []));
        $squares = [];
        $revealOrder = [];
        foreach ($labels as $i => $lbl) {
            $lbl = trim((string) $lbl);
            $existing = trim((string) ($base['side_squares'][$i] ?? ''));
            $label = $existing !== '' ? $existing : ($lbl !== '' ? $lbl . '^2' : '');
            $squares[$i] = $label;
            if ($label !== '') {
                $revealOrder[] = $i;
            }
        }
        if (count($revealOrder) < 2) {
            return [null, [], 0];
        }

        $slot = $base;
        $slot['side_squares'] = array_values($squares);
        $slot['progressive'] = true;
        $slot['reveal_order'] = $revealOrder;
        if (!isset($base['highlight_side']) || !is_numeric($base['highlight_side'])) {
            $slot['highlight_side'] = end($revealOrder);
        }
        foreach ($run as $s) {
            if (!empty($slotOf($s)['fill'])) {
                $slot['fill'] = true;
            }
        }

        return [$slot, $revealOrder, $baseIdx];
    }

    /**
     * Path B — the run keeps ONE base figure (same shape + same points) and each
     * step adds to it. Union the additive attributes onto the first scene's slot
     * so the whole argument plays on one slide. Returns [slot, revealOrder, 0]
     * or [null, [], 0] when the figures differ (a genuine cut, left as scenes).
     */
    private function unionSameBase(array $run, callable $slotOf): array
    {
        $shape = strtolower(trim((string) ($slotOf($run[0])['shape'] ?? '')));
        if ($shape === '') {
            return [null, [], 0];
        }
        $pointsKey = fn (array $s): string => json_encode($slotOf($s)['points'] ?? null);
        $baseKey = $pointsKey($run[0]);
        foreach ($run as $s) {
            if (strtolower(trim((string) ($slotOf($s)['shape'] ?? ''))) !== $shape || $pointsKey($s) !== $baseKey) {
                return [null, [], 0]; // different figures — a real cut, don't fuse
            }
        }

        $slot = $slotOf($run[0]);
        // Element-wise first-non-empty union of the erected squares.
        $squares = (array) ($slot['side_squares'] ?? []);
        $extra = (array) ($slot['extra_points'] ?? []);
        $coords = (array) ($slot['coords'] ?? []);
        $marks = (array) ($slot['angle_marks'] ?? []);
        $segments = (array) ($slot['segments'] ?? []);
        $seen = fn (array $bag, string $key): bool => in_array($key, array_map('json_encode', $bag), true);
        foreach ($run as $s) {
            $g = $slotOf($s);
            foreach ((array) ($g['side_squares'] ?? []) as $i => $lbl) {
                if (trim((string) $lbl) !== '' && trim((string) ($squares[$i] ?? '')) === '') {
                    $squares[$i] = $lbl;
                }
            }
            foreach ((array) ($g['extra_points'] ?? []) as $ep) {
                if (is_array($ep) && !$seen($extra, json_encode($ep))) {
                    $extra[] = $ep;
                }
            }
            foreach ((array) ($g['coords'] ?? []) as $c) {
                if (is_array($c) && !$seen($coords, json_encode($c))) {
                    $coords[] = $c;
                }
            }
            foreach ((array) ($g['angle_marks'] ?? []) as $m) {
                if (is_array($m) && !$seen($marks, json_encode($m))) {
                    $marks[] = $m;
                }
            }
            foreach ((array) ($g['segments'] ?? []) as $sg) {
                if (is_array($sg) && !$seen($segments, json_encode($sg))) {
                    $segments[] = $sg;
                }
            }
            if (!empty($g['fill'])) {
                $slot['fill'] = true;
            }
            if (!empty($g['circumcircle'])) {
                $slot['circumcircle'] = true;
            }
            if (!empty($g['rise_run'])) {
                $slot['rise_run'] = true;
            }
            if (isset($g['line_through']) && is_array($g['line_through'])) {
                $slot['line_through'] = $g['line_through'];
            }
            if (isset($g['highlight_side']) && is_numeric($g['highlight_side'])) {
                $slot['highlight_side'] = (int) $g['highlight_side'];
            }
        }
        if ($squares !== []) {
            $slot['side_squares'] = array_values($squares);
        }
        if ($extra !== []) {
            $slot['extra_points'] = $extra;
        }
        if ($coords !== []) {
            $slot['coords'] = $coords;
        }
        if ($marks !== []) {
            $slot['angle_marks'] = $marks;
        }
        if ($segments !== []) {
            $slot['segments'] = $segments;
        }

        $revealOrder = [];
        foreach ((array) ($slot['side_squares'] ?? []) as $i => $lbl) {
            if (trim((string) $lbl) !== '') {
                $revealOrder[] = $i;
            }
        }
        $slot['progressive'] = true;
        if ($revealOrder !== []) {
            $slot['reveal_order'] = $revealOrder;
        }

        return [$slot, $revealOrder, 0];
    }

    /**
     * Shared tail: join the run's narration, sum its time, sync each square to
     * the step that introduces it, and splice the single merged scene back in.
     */
    private function assembleFused(array $scenes, int $start, int $end, array $run, int $baseIdx, array $slot, array $revealOrder): array
    {
        $narrs = array_values(array_filter(array_map(
            fn ($s) => trim((string) ($s['narration']['text'] ?? '')),
            $run
        ), fn ($t) => $t !== ''));
        $wc = fn (string $t): int => max(1, str_word_count($t));
        $total = array_sum(array_map($wc, $narrs)) ?: 1;
        $objWords = isset($narrs[0]) ? $wc($narrs[0]) : 1;
        $slot['reveal_start_frac'] = round(min(0.5, max(0.12, $objWords / $total)), 3);

        // Line each square up with the word its step begins on: the opening
        // narration is the bare figure, then step k raises square k. Uses the
        // first N step segments when there are more steps than squares.
        $stepSegs = array_slice($narrs, 1);
        if ($revealOrder !== [] && count($stepSegs) >= count($revealOrder) && $stepSegs !== []) {
            $fracs = [];
            $boundary = $objWords;
            for ($k = 0; $k < count($revealOrder); $k++) {
                $fracs[] = round(min(0.95, $boundary / $total), 3);
                $boundary += $wc($stepSegs[$k] ?? '');
            }
            $slot['reveal_fracs'] = $fracs;
        }

        $duration = 0.0;
        foreach ($run as $s) {
            $duration += (float) ($s['duration_seconds'] ?? 0);
        }

        $merged = $run[$baseIdx]; // keep the base scene's id/mood as anchor
        $merged['slots'] = ['slot_geometry' => $slot];
        $merged['narration'] = ['text' => trim(implode(' ', $narrs))];
        $merged['duration_seconds'] = round($duration, 2);

        Log::info('MathStoryboardComposer: fused evolving figure', [
            'scenes' => count($run),
            'shape' => $slot['shape'] ?? '?',
            'squares' => count($revealOrder),
        ]);

        return array_merge(
            array_slice($scenes, 0, $start),
            [$merged],
            array_slice($scenes, $end + 1)
        );
    }

    /**
     * Call A — the working. One entry per work phase, chains + rules +
     * step-locked narration, under a prompt that is ONLY the maths rules.
     *
     * @return array<int, array{heading: string, steps: array, rule: ?array, narration: string}>|null
     */
    private function requestWorking(string $script, array $briefs, string $subject, int $shortBy = 0): ?array
    {
        $phaseList = '';
        foreach ($briefs as $i => $b) {
            $phaseList .= '  ' . ($i + 1) . '. ' . ($b !== '' ? $b : 'the next phase of the working') . "\n";
        }
        $n = count($briefs);

        $system = <<<PROMPT
You write the WORKING for a maths explainer video — nothing else. Return ONLY JSON:

{"phases": [{"heading": "<=40 chars naming the phase",
  "steps": [3-6 of {"expr": "<=80 chars", "note": "<=6 words naming the MOVE (optional)", "ref": "the formula/identity THIS line uses, <=48 chars (optional)", "arrows": [{"from": "token in the PREVIOUS line", "to": "what it becomes in THIS line"}] (optional, <=3)}],
  "rule": {"name": "<=40", "formula": "<=60 general statement", "why": "<=120 plain words"} or null,
  "narration": "one sentence PER STEP, in step order — the lines land as the voice reaches them"}]}

Exactly {$n} entries, one per phase, in this order:
{$phaseList}
Rules — non-negotiable:
- expr is LINEAR MATH NOTATION: frac{a}{b}, sqrt{...}, x^2 powers, x_1 subscripts; write pi, theta, +-, <=, >=, != as words/ASCII. NO LaTeX backslashes, NO \$ delimiters.
- Named operators are written as WORDS and get typeset: "Laplace{y'}", "int", "sum", "partial", "therefore". Write "Laplace", never "L" — "L" renders as a variable.
- EVERY step is a COMPLETE STATEMENT with both sides ("t = -28 / -9.8", never a bare "2.86").
- ONE equals sign per step. "Laplace{y'} = sY - 1.5 + 2Y = 0" is not a step, it is two different statements welded together and it is FALSE — the transform of y' is not the whole equation. Write the identity on its own line, then the substituted equation on the next.
- Steps form a CHAIN: each line is the previous line after exactly ONE move.
- SHOW THE WORKING IN FULL. Each phase gets 3-6 lines, and it is the SUBSTITUTIONS and rearrangements that earn them — write the line where the values go IN and the line where they come OUT, not just the result. A phase that resolves in one line is a phase you skipped the middle of: expand it. Never compress two moves into one line to save space; the board has room and the viewer is learning this for the first time.
- ref: the formula or identity the line leans on, written as maths ("Laplace{y'} = sY - y(0)", "a^2 - b^2 = (a-b)(a+b)"). It renders in the margin as "as we know …". Put one on EVERY line that applies a standard result, a transform, or a named identity — that citation is the difference between a video that shows a solution and one that teaches it. Omit it only on lines that are pure arithmetic.
- arrows: include them whenever a term MOVES sides, DISTRIBUTES, CANCELS or is SUBSTITUTED — that is most algebraic lines, not a rare case. They draw the pen stroke from the old token to the new one.
- Phase 1's FIRST step states the given equation itself (e.g. "h(t) = -4.9t^2 + 28t + 3") so the video opens the working from the problem.
- The LAST step of a phase is that phase's result; the LAST step of the LAST phase is the final answer, with its unit in the note.
- rule: the named law/identity the phase applies — fill it whenever one exists; null only for pure arithmetic.
- NEVER fake the maths. If the script's numbers are wrong, do the working correctly.
- FINISH THE PROBLEM. The final phase must arrive at the ANSWER the question asked for, in the variable it asked for — a transform problem is not finished at "Y(s) = 1.5/(s + 2)", it is finished at "y = 1.5e^(-2t)". Never stop one move short.
- NARRATION MUST TEACH, NOT LABEL. "Next, we apply the Laplace transform to y prime" says nothing the viewer cannot already see. STATE THE RULE IN WORDS AS YOU USE IT: name it, say what it does, then apply it — "As we know, the Laplace transform of a derivative is s times Y minus y at zero. That's the rule that turns a derivative into algebra. With y at zero equal to one point five, y prime becomes s Y minus one point five." Every step whose line carries a `ref` MUST have its formula spoken in the narration too; the margin note and the voice say the same thing. Speak maths aloud in words, never symbols.
PROMPT;

        $system .= $this->guideBlock();

        // The corrective retry: naming the shortfall explicitly is what gets
        // the missing tail phases back — a bare "try again" returns the same
        // truncated solve.
        if ($shortBy > 0) {
            $missing = $n - $shortBy;
            $system .= "\n\nYOUR PREVIOUS ATTEMPT RETURNED ONLY {$shortBy} OF THE {$n} PHASES — it stopped "
                . "{$missing} phase(s) short and the video ended before the answer. Return ALL {$n} phases this "
                . "time, in order, and make sure the LAST phase ends on the final answer to the question.";
        }

        $parsed = $this->requestJson($system, "PROBLEM / SCRIPT:\n" . mb_substr(trim($script), 0, 2400), 1400, 'tree_working');
        if (!is_array($parsed) || !is_array($parsed['phases'] ?? null)) {
            return null;
        }

        $out = [];
        foreach ($parsed['phases'] as $p) {
            if (!is_array($p) || !is_array($p['steps'] ?? null) || count($p['steps']) < 1) {
                continue;
            }
            $out[] = [
                'heading' => trim((string) ($p['heading'] ?? '')),
                'steps' => $p['steps'],
                'rule' => is_array($p['rule'] ?? null) ? $p['rule'] : null,
                'narration' => trim((string) ($p['narration'] ?? '')),
            ];
        }

        return $out !== [] ? $out : null;
    }

    /**
     * Call B — the prose cards (hook, given–find, concept, payoff).
     *
     * @return array<string, array{heading: string, bullets: array, narration: string}>|null keyed by intent
     */
    private function requestProse(string $script, array $skeleton, array $intents): ?array
    {
        if ($intents === []) {
            return [];
        }

        $wanted = implode(', ', array_unique($intents));
        $briefLines = '';
        foreach ($skeleton as $phase) {
            $i = (string) ($phase['intent'] ?? '');
            if (in_array($i, $intents, true)) {
                $briefLines .= "  {$i}: " . (string) ($phase['brief'] ?? '') . "\n";
            }
        }

        $system = <<<PROMPT
You write the short TEXT CARDS for a maths explainer video. Return ONLY JSON:

{"cards": [{"phase": "<one of: {$wanted}>", "heading": "<=40 chars", "bullets": [1-3 of <=60 chars], "narration": "2-3 spoken sentences"}]}

One card per requested phase:
{$briefLines}
Rules:
- hook: the problem stated so the viewer NEEDS the answer — a question, a dare, a stake. Its narration states the problem fully.
- given_find: heading "Given & Find" (or similar); bullets literally state "Given: ..." and "Find: ..." from the problem.
- concept: name the idea that unlocks the solve, in plain words. When ONE formula drives the whole solve, ALSO give the concept card `formula` (the equation in linear notation, <=80 chars, e.g. "h(t) = -4.9t^2 + 28t + 3") and `parts`: 2-4 of {match: an EXACT substring of formula ("-4.9", "h_0"), label: what that piece means in THIS problem (<=48 chars, "half of gravity, pulling down")} — the card typesets the equation and labels each piece before the working starts. Every match must appear character-for-character inside formula. Its narration then walks the pieces in the same order.
- payoff: the trick worth remembering — what this problem teaches beyond itself. When there is ONE error a viewer is very likely to make with this method, ALSO give the payoff card `wrong` (the line people actually write, <=60 chars, linear notation), `correct` (the SAME move done properly, <=60 — the same step, so what changed is visible) and `why` (one plain line naming the error, <=100). The card then strikes the wrong line through and stamps the right one in. Both lines are CHECKED against real arithmetic, so only write them if you are certain which is which.
- No equations in bullets beyond simple inline values; the working lives on the math cards.
PROMPT;

        $system .= $this->guideBlock();

        try {
            $parsed = $this->requestJson($system, "PROBLEM / SCRIPT:\n" . mb_substr(trim($script), 0, 2000), 700, 'tree_prose');
        } catch (\Throwable $e) {
            Log::info('MathStoryboardComposer: prose call failed, degrading to narration-only', ['error' => $e->getMessage()]);
            return [];
        }
        if (!is_array($parsed) || !is_array($parsed['cards'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($parsed['cards'] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $phase = (string) ($c['phase'] ?? '');
            if ($phase === '' || isset($out[$phase])) {
                continue;
            }
            $out[$phase] = [
                'heading' => trim((string) ($c['heading'] ?? '')),
                'bullets' => array_values(array_filter(array_map(
                    fn ($b) => trim((string) (is_scalar($b) ? $b : '')),
                    (array) ($c['bullets'] ?? [])
                ), fn ($b) => $b !== '')),
                'narration' => trim((string) ($c['narration'] ?? '')),
                // concept only: the driving formula + its labelled parts.
                // Raw here — clampFormulaAnatomyContent is the gate, and a
                // failing payload degrades the beat back to the text card.
                'formula' => trim((string) ($c['formula'] ?? '')),
                // payoff only: the trap and its fix. Raw here too —
                // clampMistakeContent is the gate (it evaluates both lines)
                // and a refused payload degrades back to the text card.
                'wrong' => trim((string) ($c['wrong'] ?? '')),
                'correct' => trim((string) ($c['correct'] ?? '')),
                'why' => trim((string) ($c['why'] ?? '')),
                'parts' => array_values(array_filter(
                    (array) ($c['parts'] ?? []),
                    fn ($p) => is_array($p)
                        && trim((string) ($p['match'] ?? '')) !== ''
                        && trim((string) ($p['label'] ?? '')) !== ''
                )),
            ];
        }

        return $out;
    }

    /** One JSON round trip at temp 0.2 (the tree is structure-prescribed).
     *  On a gpt-5* math model, tune() swaps that for reasoning_effort 'low'
     *  — a beat of reasoning is what the maths calls are paying for. */
    private function requestJson(string $system, string $user, int $maxTokens, string $costTag): ?array
    {
        $this->attempts++;
        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.2,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
            ], 'low'));

        if (!$response->successful()) {
            throw new \RuntimeException("composer request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), $costTag);

        return json_decode((string) $response->json('choices.0.message.content'), true);
    }

    /**
     * L4-adjacent: deterministic assembly. Scenes in skeleton order, each
     * phase cast to the ONE card type its intent allows, synthesizers
     * filling the drawings. Raw output — the validator owns legality.
     */
    private function assemble(
        string $script,
        array $skeleton,
        array $mathTopic,
        string $title,
        array $working,
        array $prose
    ): ?array {
        $synth = new MathVisualSynthesisService();
        $scenes = [];
        $n = 0;
        $workIdx = 0;

        $textScene = function (string $intent, string $fallbackNarration) use (&$n, $prose): array {
            $card = $prose[$intent] ?? null;
            $n++;

            // Project 91's payoff taught the stub lesson: a MISSING prose
            // card degrades to a one-line narration — which does not earn a
            // 10-second hold (that is a static_hold by construction). A stub
            // gets 5s; a real card keeps its 10. And every text card tops up
            // to TWO reveal bullets from its own narration where it can, the
            // same guarantee the proof claim got in iter 30.
            $narration = trim((string) ($card['narration'] ?? '')) ?: $fallbackNarration;
            $bullets = array_values(array_filter(array_map(
                fn ($b) => trim((string) (is_scalar($b) ? $b : '')),
                (array) ($card['bullets'] ?? [])
            ), fn ($b) => $b !== ''));
            foreach (preg_split('/(?<=[.!?])\s+/', $narration) ?: [] as $sent) {
                if (count($bullets) >= 2) {
                    break;
                }
                $sent = trim($sent);
                if ($sent !== '' && mb_strlen($sent) <= 60 && !in_array($sent, $bullets, true)) {
                    $bullets[] = $sent;
                }
            }

            return [
                'scene_id' => "scene_{$n}",
                'layout_template' => 'single_focus',
                'duration_seconds' => $card !== null ? 10 : 5,
                'narration' => ['text' => $narration],
                'mood' => 'neutral',
                'slots' => ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => trim((string) ($card['heading'] ?? '')),
                    'bullets' => $bullets,
                ]],
            ];
        };

        foreach ($skeleton as $phase) {
            $intent = (string) ($phase['intent'] ?? '');
            $brief = (string) ($phase['brief'] ?? '');

            switch ($intent) {
                case 'hook':
                    $scenes[] = $textScene('hook', 'Here is the problem we are going to crack.');
                    break;

                case 'scenario_setup':
                    if (($mathTopic['scenario'] ?? false) === true) {
                        try {
                            $scenario = $synth->scenario($script);
                        } catch (\Throwable) {
                            $scenario = null;
                        }
                        if ($scenario !== null) {
                            $n++;
                            $scenario['scene_id'] = "scene_{$n}";
                            $scenes[] = $scenario;
                        }
                    }
                    break;

                case 'given_find':
                    $scenes[] = $textScene('given_find', 'Here is what we know, and what we are looking for.');
                    break;

                case 'concept':
                    // A formula-driven solve gets the anatomy card: the
                    // equation typeset with each piece labelled. The validator
                    // re-clamps (matches must anchor) and degrades a thin
                    // payload back to exactly the text card built below.
                    $conceptCard = $prose['concept'] ?? null;
                    if (
                        is_array($conceptCard)
                        && trim((string) ($conceptCard['formula'] ?? '')) !== ''
                        && count((array) ($conceptCard['parts'] ?? [])) >= 2
                    ) {
                        $n++;
                        $scenes[] = [
                            'scene_id' => "scene_{$n}",
                            'layout_template' => 'formula_anatomy',
                            'duration_seconds' => 12,
                            'narration' => ['text' => trim((string) ($conceptCard['narration'] ?? ''))
                                ?: ($brief !== '' ? $brief : 'Here is the formula that unlocks this problem.')],
                            'mood' => 'neutral',
                            'slots' => ['slot_formula' => array_filter([
                                'content_type' => 'formula',
                                'formula' => trim((string) $conceptCard['formula']),
                                'parts' => (array) $conceptCard['parts'],
                                'heading' => trim((string) ($conceptCard['heading'] ?? '')) ?: null,
                            ], fn ($v) => $v !== null)],
                        ];
                        break;
                    }
                    $scenes[] = $textScene('concept', $brief !== '' ? $brief : 'The idea that unlocks this problem.');
                    break;

                case 'work':
                    if (!isset($working[$workIdx])) {
                        break; // fewer phases came back than planned — keep going
                    }
                    $w = $working[$workIdx];
                    $workIdx++;
                    $n++;
                    $slot = [
                        'content_type' => 'math_steps',
                        'steps' => $w['steps'],
                    ];
                    if ($w['heading'] !== '') {
                        $slot['heading'] = $w['heading'];
                    }
                    if ($w['rule'] !== null) {
                        $slot['rule'] = $w['rule'];
                    }
                    $scenes[] = [
                        'scene_id' => "scene_{$n}",
                        'layout_template' => 'math_steps',
                        'duration_seconds' => 14,
                        'narration' => ['text' => $w['narration'] !== '' ? $w['narration'] : ($brief !== '' ? $brief : 'The next phase of the working.')],
                        'mood' => 'neutral',
                        'slots' => ['slot_math' => $slot],
                    ];
                    break;

                case 'see_answer':
                    // A LADDER, not a single attempt. The plot is the best
                    // picture when the working states a function; when it does
                    // not (plain algebra, a geometry solve) the answers still
                    // get shown on a number line. Only a solve that never
                    // reaches a number produces no beat — previously ANY of
                    // those cases produced none, which is how a maths video
                    // ended up with nothing drawn in it at all.
                    $answerBeat = $synth->plotFromScenes($scenes, $title)
                        ?? $synth->answerLineFromScenes($scenes, $title);
                    if ($answerBeat !== null) {
                        $n++;
                        $answerBeat['scene_id'] = "scene_{$n}";
                        $scenes[] = $answerBeat;
                    }
                    break;

                case 'check':
                    $check = $synth->checkFromScenes($scenes, $title);
                    if ($check !== null) {
                        $n++;
                        $check['scene_id'] = "scene_{$n}";
                        $scenes[] = $check;
                    }
                    break;

                case 'practice':
                    // Synthesized, not asked for: the practice problem's answer
                    // must be verified before it can ship, and the synth is
                    // where that check (and its one retry) lives. A null here
                    // simply means the video ends without a practice beat.
                    $practice = $synth->practiceFromScenes($scenes, $title);
                    if ($practice !== null) {
                        $n++;
                        $practice['scene_id'] = "scene_{$n}";
                        $scenes[] = $practice;
                    }
                    break;

                case 'payoff':
                    // The payoff doubles as the trap beat when the model named
                    // one: the error the viewer is about to make is the most
                    // useful thing left to say. Same shape as the concept
                    // phase's formula_anatomy upgrade.
                    $payoffCard = $prose['payoff'] ?? null;
                    if (
                        is_array($payoffCard)
                        && trim((string) ($payoffCard['wrong'] ?? '')) !== ''
                        && trim((string) ($payoffCard['correct'] ?? '')) !== ''
                    ) {
                        $n++;
                        $scenes[] = [
                            'scene_id' => "scene_{$n}",
                            'layout_template' => 'common_mistake',
                            'duration_seconds' => 11,
                            'narration' => ['text' => trim((string) ($payoffCard['narration'] ?? ''))
                                ?: 'Before you go, here is the mistake almost everyone makes with this — and what to write instead.'],
                            'mood' => 'neutral',
                            'slots' => ['slot_mistake' => array_filter([
                                'content_type' => 'mistake',
                                'wrong' => trim((string) $payoffCard['wrong']),
                                'correct' => trim((string) $payoffCard['correct']),
                                'why' => trim((string) ($payoffCard['why'] ?? '')) ?: null,
                                'heading' => trim((string) ($payoffCard['heading'] ?? '')) ?: null,
                            ], fn ($v) => $v !== null)],
                        ];
                        break;
                    }
                    $scenes[] = $textScene('payoff', 'And that is the trick worth remembering.');
                    break;
            }
        }

        // A tree that lost its working is not a video — fall back.
        $mathCards = array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'math_steps');
        if (count($mathCards) === 0) {
            return null;
        }

        foreach ($scenes as $i => &$scene) {
            $scene['order'] = $i + 1;
        }
        unset($scene);

        Log::info('MathStoryboardComposer: storyboard composed by the tree', [
            'scenes' => count($scenes),
            'work_phases' => $workIdx,
            'attempts' => $this->attempts,
        ]);

        return [
            'scenes' => $scenes,
            'summary' => trim((string) ($mathTopic['subject'] ?? '')) ?: 'A worked maths problem.',
        ];
    }
}
