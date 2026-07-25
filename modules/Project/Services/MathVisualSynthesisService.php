<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Modules\Project\Support\TinyExpr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MathVisualSynthesisService — the deterministic backstop for the two visuals
 * a worked-math video is not allowed to ship without.
 *
 * The analyzer's MATH MODE directive demands them, and one corrective retry
 * re-demands them, and the model still sometimes returns a storyboard of pure
 * symbol-pushing (project 34, second run: a classified word problem with
 * scenario:true came back with zero scenario_diagram and zero function_plot
 * across BOTH attempts). Past two attempts, asking harder is not a strategy —
 * the missing scene is BUILT instead:
 *
 *   - scenario(): one tiny, single-purpose LLM call whose ONLY job is the
 *     setup drawing (entities / connectors / question). A focused request
 *     with a 300-token answer succeeds where rule #23 of a 200-line system
 *     prompt gets ignored.
 *
 *   - plotFromScenes(): no LLM at all. When the working already contains the
 *     function ("h(t) = -4.9t^2 + 28t + 3" as a step), the plot expression,
 *     the marks (the answers the chains computed) and the domain are all
 *     extractable mechanically.
 *
 * Everything degrades to null — callers insert nothing and the lint report
 * still tells the user the video never draws.
 */
class MathVisualSynthesisService
{
    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        // These are the focused maths calls — they ride the math valve (iter
        // 31), not the general explainer model: correctness-critical
        // structured output is exactly what the valve exists to escalate.
        $this->model = LlmModels::for('math');
    }

    /**
     * Build the scenario_diagram scene for a word problem, from the script
     * alone. Returns a RAW scene (validator-shaped input) or null.
     */
    public function scenario(string $script): ?array
    {
        if (empty($this->apiKey) || trim($script) === '') {
            return null;
        }

        $system = <<<'PROMPT'
You draw the SETUP DIAGRAM for a maths word problem. Given the problem, return ONLY JSON:

{"heading": "<=40 chars, e.g. 'The Setup'",
 "narration": "3-4 sentences (~10-12s spoken) walking the viewer through the drawing: name each actor, each given value, and what we are asked to find. Plain words, no symbols spoken as symbols.",
 "layout": "the SHAPE of the sketch — pick the ONE that makes the drawing LOOK like the situation:
    'arc'     = goes up and comes back down (projectiles, thrown/kicked balls),
    'climb'   = rises steadily (rocket ascending, tank filling, growth),
    'fall'    = drops (released object, draining, descent),
    'compare' = two or more rivals weighed side by side (A vs B, which is faster/cheaper, when are they equal) — ONE entity per lane,
    'split'   = ONE source fans out into outcomes (a probability tree, the ways something can happen, work shared between helpers, a whole divided up) — the FIRST entity is the source, the rest are the branches,
    'cycle'   = a closed loop of stages that returns to where it began (a repeating process, a clock / orbit / lap) — entities are the stages in order around the loop,
    'line'    = flat left-to-right, the default (two things approaching, A to B distance, a sequence of steps)",
 "entities": [2-4 of {"label": "<=16 chars",
    "icon": "<one lucide icon name: car, truck, plane, rocket, map-pin, flag, home, user, droplet, clock, ruler, mountain, target, circle-dot>",
    "value": "<=24 chars, the GIVEN value, e.g. 'v0 = 28 m/s'",
    "sprite": "<=8 words: ONE drawable real object for this actor, e.g. 'a red hatchback car, side view' — see the sprite rule below",
    "emphasis": "put 'key' on the SINGLE entity the question is really about (the thing we must FIND, the winner, the pay-off branch); omit it everywhere else"}],
 "connectors": [one per gap, positional (connector i sits on the gap after entity i): {"label": "<=18 chars", "sub": "<=18 chars", "style": "arrow"|"line"|"both"}],
 "question": "<=24 chars, what we must FIND, e.g. 'h max = ?'"}

The entities are the problem's ACTORS/OBJECTS. Order them by the layout: line/arc/climb/fall in spatial order
(start -> middle -> end); 'split' with the SOURCE first then its branches; 'compare' one contender per entity;
'cycle' the stages in loop order. ALWAYS at least 2 entities — a situation has stages even when it has one actor.
Example, "a projectile follows h(t) = -4.9t^2 + 28t + 3, find max height and flight time":
  layout: "arc"
  entities: [{"label": "Launch", "icon": "rocket", "value": "h0 = 3 m", "sprite": "a small toy rocket lifting off, side view"},
             {"label": "Peak", "icon": "mountain", "value": "h max = ?", "emphasis": "key"},
             {"label": "Ground", "icon": "flag", "value": "h = 0"}]
  connectors: [{"label": "rises", "style": "arrow"}, {"label": "falls back", "style": "arrow"}]
  question: "h max = ?  t = ?"
(The renderer draws 'arc' as a real parabola with the peak on top — entity order must follow the motion.)
SPRITE RULE: give a `sprite` to EVERY actor that is a concrete drawable thing (a car, a coin, a beaker, a runner,
a rocket) — a scene of real objects beats a row of boxes. Leave `sprite` out only for an abstract place/stage
("Peak", "Start") and fall back to its `icon`. Omit "value" when an entity has no given number.
CONNECTORS carry the relationship on each gap and change meaning with the layout:
  line/arc/climb/fall -> the motion from neighbour i to i+1 ("rises", "60 km");
  split -> the source -> branch weight (the probability or share, e.g. "0.6", "3/4");
  compare -> the rate/price driving that lane (e.g. "60 km/h", "$1.20/kg");
  cycle -> the hand-off from stage i to stage i+1.
The question chip is the FIND, written as maths shorthand.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "PROBLEM:\n" . mb_substr(trim($script), 0, 2000)],
        ];

        // Two named entities is the validator's bar for a drawable scenario;
        // a single box gets the whole scene degraded to text. One corrective
        // retry — the first live run returned exactly one entity for the
        // projectile problem even with the example in the prompt.
        $parsed = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = Http::withToken($this->apiKey)
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'max_tokens' => 400,
                    'response_format' => ['type' => 'json_object'],
                ], 'low'));

            if (!$response->successful()) {
                Log::info('MathVisualSynthesisService: scenario request failed', ['status' => $response->status()]);
                return null;
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'math_scenario_synth');

            $candidate = json_decode((string) $response->json('choices.0.message.content'), true);
            if (is_array($candidate) && $this->namedEntityCount($candidate) >= 2) {
                $parsed = $candidate;
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => (string) $response->json('choices.0.message.content')];
            $messages[] = ['role' => 'user', 'content' =>
                'That has fewer than 2 named entities, so it cannot be drawn. Split the situation into its '
                . 'stages or places (start -> middle -> end, like Launch/Peak/Ground) and return the same '
                . 'JSON with 2-4 entities, each with a label. Return ONLY the JSON.',
            ];
        }

        if ($parsed === null) {
            Log::info('MathVisualSynthesisService: scenario synthesis never reached 2 entities');
            return null;
        }

        $narration = trim((string) ($parsed['narration'] ?? ''));
        if ($narration === '') {
            $narration = 'Here is the situation we are working with, drawn out — the actors, the values we know, and the thing we are asked to find.';
        }

        // The sketch's shape: what the model chose when legal, else inferred
        // from the problem statement itself — the richest text there is.
        $layout = \Modules\Project\Support\ScenarioLayout::resolve(
            is_scalar($parsed['layout'] ?? null) ? (string) $parsed['layout'] : null,
            $script . ' ' . $narration
        );

        // Raw validator input — clampScenarioContent owns the real rules
        // (icon whitelist, caps, label lengths); this only has to be honest.
        return [
            'scene_id' => 'scene_scenario_synth',
            'layout_template' => 'scenario_diagram',
            'duration_seconds' => 12,
            'narration' => ['text' => $narration],
            'mood' => 'neutral',
            'slots' => [
                'slot_scenario' => array_filter([
                    'content_type' => 'scenario',
                    'heading' => mb_substr(trim((string) ($parsed['heading'] ?? 'The Setup')), 0, 40),
                    'entities' => $parsed['entities'],
                    'connectors' => is_array($parsed['connectors'] ?? null) ? $parsed['connectors'] : [],
                    'question' => trim((string) ($parsed['question'] ?? '')) ?: null,
                    'layout' => $layout,
                ], fn ($v) => $v !== null),
            ],
        ];
    }

    /** Entities that would survive the validator's label bar. */
    private function namedEntityCount(array $parsed): int
    {
        $named = 0;
        foreach ((array) ($parsed['entities'] ?? []) as $e) {
            if (is_array($e) && trim((string) (is_scalar($e['label'] ?? null) ? $e['label'] : '')) !== '') {
                $named++;
            }
        }

        return $named;
    }

    /**
     * The single-variable function the working (or the title) states —
     * "h(t) = -4.9t^2 + 28t + 3" as a step, "y = ..." — compiled and ready
     * to evaluate. The shared foundation of the SEE-the-answer plot and the
     * CHECK-the-answer card.
     *
     * @return array{fname: string, argVar: string, rhsSrc: string, exprX: string, f: \Closure}|null
     */
    private function extractFunction(array $scenes, string $extra): ?array
    {
        $fname = null;
        $argVar = null;
        $rhsSrc = null; // rhs with the ORIGINAL variable

        foreach ($scenes as $scene) {
            if (!is_array($scene) || ($scene['layout_template'] ?? '') !== 'math_steps') {
                continue;
            }
            foreach ((array) (($scene['slots']['slot_math'] ?? [])['steps'] ?? []) as $step) {
                $expr = trim((string) (is_array($step) ? ($step['expr'] ?? '') : ''));
                if ($expr === '') {
                    continue;
                }
                // The function statement: h(t) = rhs   |   y = rhs (in x).
                if (preg_match('/^([a-z][a-z0-9]{0,10})\(([a-z])\)\s*=\s*(.{3,})$/i', $expr, $m)) {
                    $fname = strtolower($m[1]);
                    $argVar = strtolower($m[2]);
                    $rhsSrc = trim($m[3]);
                    break 2;
                }
                if (preg_match('/^y\s*=\s*(.{3,})$/i', $expr, $m)) {
                    $fname = 'y';
                    $argVar = 'x';
                    $rhsSrc = trim($m[1]);
                    break 2;
                }
            }
        }

        // Fallback: the title/extra text — users literally type the function
        // there ("A projectile follows h(t)=−4.9t.t+28t+3. Find ..."), and a
        // lazy analyzer run can keep the statement out of the steps entirely
        // (project 34 run 4: the identification beat came back as prose).
        if ($rhsSrc === null && trim($extra) !== '') {
            $hay = strtr($extra, ['−' => '-', '×' => '*', '·' => '*']);
            // "t.t" (a user's t·t) — letter DOT letter is never a decimal.
            $hay = (string) preg_replace('/([a-z])\.(?=[a-z])/i', '$1*', $hay);
            if (preg_match('/([A-Za-z][A-Za-z0-9]{0,10})\(([A-Za-z])\)\s*=\s*(.{3,})/', $hay, $m)) {
                $rhs = $m[3];
                // The expression ends where prose begins: at the first
                // letter-run that is not grammar vocabulary ("...28t+3. Find
                // Maximum height..."), or the first char outside the
                // calculator alphabet.
                if (preg_match_all('/[A-Za-z]{2,}/', $rhs, $runs, PREG_OFFSET_CAPTURE)) {
                    foreach ($runs[0] as [$word, $off]) {
                        if (!in_array(strtolower($word), ['sin', 'cos', 'tan', 'sqrt', 'abs', 'log', 'ln', 'exp', 'pi'], true)) {
                            $rhs = substr($rhs, 0, $off);
                            break;
                        }
                    }
                }
                if (preg_match('/[^0-9A-Za-z+\-*\/^().\s]/', $rhs, $bad, PREG_OFFSET_CAPTURE)) {
                    $rhs = substr($rhs, 0, (int) $bad[0][1]);
                }
                $rhs = rtrim(trim($rhs), '. ');
                if (mb_strlen($rhs) >= 3) {
                    $fname = strtolower($m[1]);
                    $argVar = strtolower($m[2]);
                    $rhsSrc = $rhs;
                }
            }
        }

        if ($rhsSrc === null || $argVar === null || $fname === null) {
            return null;
        }

        // Rewrite the argument variable to x; any OTHER letters left over
        // (an unsubstituted a, b, c) mean symbolic coefficients — nothing
        // evaluable. NOT \b: there is no word boundary between a digit and
        // a letter, so \bx\b misses the x in "4.9x" (v19's exact trap).
        $exprX = (string) preg_replace(
            '/(?<![A-Za-z])' . preg_quote($argVar, '/') . '(?![A-Za-z])/',
            'x',
            $rhsSrc
        );
        $residue = (string) preg_replace('/(?<![A-Za-z])(?:sin|cos|tan|sqrt|abs|log|ln|exp|pi|e|x)(?![A-Za-z])/i', '', $exprX);
        if (preg_match('/[A-Za-z]/', $residue)) {
            return null;
        }

        // Must compile — TinyExpr speaks the same grammar as the renderer's
        // evaluator, so a pass here means a drawable, evaluable curve.
        $f = \Modules\Project\Support\TinyExpr::compile($exprX);
        if ($f === null) {
            return null;
        }

        return ['fname' => $fname, 'argVar' => $argVar, 'rhsSrc' => $rhsSrc, 'exprX' => $exprX, 'f' => $f];
    }

    /**
     * Numeric answers the chains produced for the argument variable
     * ("t = 2.86", "t = 5.8 s"), in order of appearance.
     *
     * @return float[]
     */
    private function extractAnswers(array $scenes, string $argVar): array
    {
        $answers = [];
        foreach ($scenes as $scene) {
            if (!is_array($scene) || ($scene['layout_template'] ?? '') !== 'math_steps') {
                continue;
            }
            foreach ((array) (($scene['slots']['slot_math'] ?? [])['steps'] ?? []) as $step) {
                $expr = trim((string) (is_array($step) ? ($step['expr'] ?? '') : ''));
                // "=" or the approximations the model writes for rounded
                // results ("t ≈ 2.86", "t ~ 2.86") — an answer either way.
                if ($expr !== '' && preg_match(
                    '/^' . preg_quote($argVar, '/') . '\s*(?:=|≈|~=?)\s*(-?\d+(?:\.\d+)?)\s*[a-z%°\/\s]{0,12}$/iu',
                    $expr,
                    $m
                )) {
                    $answers[] = (float) $m[1];
                }
            }
        }

        return array_values(array_filter($answers, fn ($v) => is_finite($v)));
    }

    /** "2.86" — a float printed the way a board line would write it. */
    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Build the SEE-the-answer function_plot from the working itself — no
     * LLM. The curve is the function the working states, the marks are the
     * answers the chains computed (labelled "t = 2.86", not a bare number)
     * — or the curve's own zeros when the chains carried none. Null when
     * nothing states a single-variable function (a triangle-area solve has
     * no curve to show).
     *
     * @param array  $scenes RAW scenes (validator input shape).
     * @param string $extra  Additional haystack (project title etc.).
     */
    public function plotFromScenes(array $scenes, string $extra = ''): ?array
    {
        $fn = $this->extractFunction($scenes, $extra);
        if ($fn === null) {
            return null;
        }

        $marks = $this->extractAnswers($scenes, $fn['argVar']);
        $marks = array_values(array_unique($marks));
        sort($marks);
        $marks = array_slice($marks, 0, 3);

        // No answers in the chains? The curve's own zeros are the story
        // ("where does the height return to zero" IS the flight time).
        if (empty($marks)) {
            $marks = array_slice(\Modules\Project\Support\TinyExpr::zeros($fn['f']), 0, 3);
        }

        // Domain: hug the marked answers when there are any.
        if (!empty($marks)) {
            $xMin = floor(min(min($marks) - 1, 0));
            $xMax = ceil(max($marks) * 1.2 + 0.5);
        } else {
            $xMin = -5;
            $xMax = 5;
        }

        return [
            'scene_id' => 'scene_plot_synth',
            'layout_template' => 'function_plot',
            'duration_seconds' => 10,
            'narration' => ['text' =>
                'Here is everything we found, on one picture. Watch the curve — the marked points are exactly the values we calculated.',
            ],
            'mood' => 'neutral',
            'slots' => [
                'slot_plot' => [
                    'content_type' => 'function_plot',
                    'heading' => 'See the Answer',
                    'expression' => mb_substr($fn['exprX'], 0, 60),
                    'x_min' => $xMin,
                    'x_max' => $xMax,
                    'marks' => array_map(
                        fn ($v) => ['x' => $v, 'label' => "{$fn['argVar']} = {$this->num($v)}"],
                        $marks
                    ),
                ],
            ],
        ];
    }

    /**
     * The SEE-the-answer beat for a solve with no curve to draw: the answers
     * marked on a number line. Zero LLM, like the plot.
     *
     * This exists because `see_answer` used to be plot-or-nothing, and a plain
     * algebra solve ("x^2 - 6x + 4 = 0") states no function — so the phase
     * silently produced NO scene and the video ended up with no picture at all
     * (project 98, caught by the `math_no_visual` lint on one run out of
     * three). Every worked problem that reaches a number can show WHERE that
     * number lives; two roots on a line is genuinely the right picture for a
     * quadratic anyway.
     *
     * Answers are read from the working's closing lines and EVALUATED, so a
     * surd or a ± pair becomes real positions ("x = 3 +- sqrt{5}" marks 0.76
     * and 5.24). Null when the working never lands on a number — nothing is
     * invented here.
     *
     * @param array  $scenes RAW scenes (validator input shape).
     * @param string $extra  Additional haystack (project title etc.).
     */
    public function answerLineFromScenes(array $scenes, string $extra = ''): ?array
    {
        $values = [];
        $var = 'x';

        foreach ($scenes as $scene) {
            if (!is_array($scene) || ($scene['layout_template'] ?? '') !== 'math_steps') {
                continue;
            }
            foreach ((array) (($scene['slots']['slot_math'] ?? [])['steps'] ?? []) as $step) {
                $expr = self::normalizeExpr(trim((string) (is_array($step) ? ($step['expr'] ?? '') : '')));
                // Steps routinely carry a prose lead-in — "Divide by 2: x = 3
                // +- sqrt{5}", "Plug in a = 1, b = -6, c = 4: x = ...". The
                // answer is what follows the LAST colon, so drop the label.
                $expr = (string) preg_replace('/^.*:\s*/u', '', $expr);

                // Every "<var> = <rhs>" the line states, so an alternation
                // ("x = 1 or x = 3") yields both. The lhs must be a bare name:
                // "x^2 - 6x + 4 = 0" is the problem, not its answer.
                if (!preg_match_all(
                    // The value may also be closed off by ANOTHER "=": a
                    // chained equality is how a solve states its answer in one
                    // line ("c = sqrt{225} = 15"), and without this the whole
                    // step matched nothing at all.
                    // "approx" is written as a WORD as often as it is written
                    // as a symbol ("t approx 5.08 seconds").
                    '/(?<![A-Za-z0-9_])([a-z][a-z0-9_]{0,10})\s*(?:=|≈|~=?|approx(?:imately)?)\s*([^=;,]+?)(?=$|[;,=]|\s+or\s+|\s+and\s+)/iu',
                    $expr,
                    $all,
                    PREG_SET_ORDER
                )) {
                    continue;
                }

                $fromThisStep = [];
                foreach ($all as $m) {
                    $lhs = strtolower($m[1]);
                    // An answer usually arrives wearing its unit ("c = 15 cm",
                    // "t = 5.08 seconds"). The unit is not part of the value
                    // and the evaluator cannot read it, so drop it — the line
                    // still marks the number the working landed on.
                    $rhs = (string) preg_replace(
                        '/\s+(cm|mm|km|m|ft|in|kg|g|lb|s|sec|secs|seconds?|minutes?|mins?|hours?|hrs?|'
                        . 'metres?|meters?|miles?|litres?|liters?|dollars?|degrees?|units?)\.?$/i',
                        '',
                        // Steps are written as sentences and end like them.
                        rtrim(trim($m[2]), '.')
                    );
                    // The rhs must be CLOSED — no symbols left in it at all.
                    // Checking only for the unknown itself is not enough: the
                    // evaluator reads every unrecognised letter as its
                    // variable, so "run = x2 - x1" would happily "evaluate" to
                    // 0 and mark a definition on the line as an answer.
                    if (!self::isClosedNumeric($rhs)) {
                        continue;
                    }
                    foreach ($this->plusMinusVariants($rhs) as $variant) {
                        $f = TinyExpr::compile($variant);
                        if ($f === null) {
                            continue;
                        }
                        $v = $f(0.0);
                        if (is_finite($v)) {
                            $var = $lhs;
                            $fromThisStep[] = round($v, 4);
                        }
                    }
                }

                // LAST statement wins, rather than the union of every value the
                // working ever wrote down. A solve that rejects a root states
                // both and then discards one — project 102 ends "x = 5 or
                // x = -2 … x = -2 is not allowed … Only solution is x = 5", and
                // marking the extraneous root on the answer line would teach
                // exactly the mistake the video just warned about.
                if ($fromThisStep !== []) {
                    $values = $fromThisStep;
                }
            }
        }

        $values = array_values(array_unique($values));
        sort($values);
        $values = array_slice($values, 0, 3);
        if ($values === []) {
            return null;
        }

        // Frame the line so the marks sit inside it with room to breathe.
        $lo = min($values);
        $hi = max($values);
        $pad = max(1.0, ($hi - $lo) * 0.35);

        return [
            'scene_id' => 'scene_answer_line_synth',
            'layout_template' => 'geometry_diagram',
            'duration_seconds' => 9,
            'narration' => ['text' => count($values) > 1
                ? 'And here they are on the line — the values we solved for, in their places.'
                : 'And there it is on the line — the value we solved for, in its place.',
            ],
            'mood' => 'neutral',
            'slots' => [
                // The key the geometry_diagram template declares — a slot the
                // template does not know is not rendered.
                'slot_geometry' => [
                    'content_type' => 'geometry',
                    'shape' => 'number_line',
                    'heading' => 'See the Answer',
                    'x_min' => floor($lo - $pad),
                    'x_max' => ceil($hi + $pad),
                    'marks' => array_map(
                        fn ($v) => ['x' => $v, 'label' => "{$var} = {$this->num($v)}"],
                        $values
                    ),
                ],
            ],
        ];
    }

    /**
     * Put a raw step into the notation the evaluator reads.
     *
     * This synthesizer runs on the composer's RAW scenes — the validator's
     * linearization has not happened yet — so a step still arrives as the model
     * typed it: "x = 3 ± √5", not "x = 3 +- sqrt{5}". Project 98's answer beat
     * went missing for exactly this reason while the same extraction worked
     * perfectly against the stored (post-validator) storyboard, which is a good
     * reminder that anything reading scenes mid-compose must normalise first.
     */
    private static function normalizeExpr(string $expr): string
    {
        $s = strtr($expr, [
            '±' => '+-', '∓' => '+-', '−' => '-', '–' => '-',
            '×' => '*', '·' => '*', '÷' => '/', '≈' => '=',
        ]);
        // √(x) and √x both mean sqrt(x); the evaluator needs the call form.
        $s = (string) preg_replace('/√\s*\(/u', 'sqrt(', $s);
        $s = (string) preg_replace('/√\s*\{/u', 'sqrt{', $s);

        return (string) preg_replace('/√\s*(\d+(?:\.\d+)?|[A-Za-z])/u', 'sqrt($1)', $s);
    }

    /**
     * True when the expression contains no free symbols — only numbers,
     * operators, and the function/constant names the evaluator knows. This is
     * what separates an ANSWER ("3 +- sqrt{5}") from a rearrangement or a
     * definition ("run = x2 - x1").
     */
    private static function isClosedNumeric(string $expr): bool
    {
        $stripped = (string) preg_replace(
            '/(?<![a-z])(?:sqrt|sin|cos|tan|asin|acos|atan|abs|log|ln|exp|pi|e)(?![a-z])/i',
            '',
            $expr
        );

        return trim($stripped) !== '' && !preg_match('/[A-Za-z]/', $stripped);
    }

    /**
     * "3 +- sqrt{5}" → ["3 + sqrt{5}", "3 - sqrt{5}"]; anything without a ±
     * comes back as itself. The ± is the one operator the evaluator cannot
     * represent, and it is exactly what a quadratic answer is written with.
     *
     * @return array<int, string>
     */
    private function plusMinusVariants(string $expr): array
    {
        if (!str_contains($expr, '+-')) {
            return [$expr];
        }

        return [
            str_replace('+-', '+', $expr),
            str_replace('+-', '-', $expr),
        ];
    }

    /**
     * Build the CHECK-the-answer card — the substitute-back habit the MATH
     * MODE directive demands and the model routinely skips. Deterministic
     * and honest: the final answer is substituted into the original
     * function and TinyExpr actually evaluates it. The card only exists
     * when the result certifies something the viewer saw — it lands near
     * zero (a root: the equation really is satisfied) or it reproduces a
     * value the working already claimed. A check that produces a brand-new
     * number certifies nothing and is not synthesized.
     *
     * @param array  $scenes RAW scenes (validator input shape).
     * @param string $extra  Additional haystack (project title etc.).
     */
    public function checkFromScenes(array $scenes, string $extra = ''): ?array
    {
        $fn = $this->extractFunction($scenes, $extra);
        if ($fn === null) {
            return null;
        }

        $answers = $this->extractAnswers($scenes, $fn['argVar']);
        if ($answers === []) {
            return null; // nothing claimed, nothing to check
        }

        $v = (float) end($answers);
        $y = ($fn['f'])($v);
        if (!is_finite($y)) {
            return null;
        }

        // "Is v a root?" must respect that the working ROUNDED it: t = 5.8
        // for a true root of 5.8206 gives f(5.8) ≈ 0.56 on a slope of ~29 —
        // still the root, displaced by the rounding. Scale the tolerance by
        // the local slope: accept when v sits within ~0.02 of a true zero.
        $h = 1e-4;
        $slope = (($fn['f'])($v + $h) - ($fn['f'])($v - $h)) / (2 * $h);
        $rootTol = max(0.06, (is_finite($slope) ? abs($slope) : 0) * 0.02);

        $vStr = $this->num($v);
        $isRoot = abs($y) < $rootTol;
        $yStr = $isRoot && abs($y) < 0.005 ? '0' : $this->num($y);

        if (!$isRoot) {
            // The computed value must match a number the working already
            // showed (h(2.86) = 42.18 appearing as a step or heading).
            $claimed = false;
            $tol = max(0.02, abs($y) * 0.01);
            foreach ($scenes as $scene) {
                if (!is_array($scene)) {
                    continue;
                }
                $hay = json_encode($scene['slots'] ?? []);
                if (preg_match_all('/-?\d+(?:\.\d+)?/', (string) $hay, $nums)) {
                    foreach ($nums[0] as $n) {
                        if (abs((float) $n - $y) <= $tol) {
                            $claimed = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$claimed) {
                return null;
            }
        }

        $lhs = "{$fn['fname']}({$vStr})";
        $substituted = (string) preg_replace(
            '/(?<![A-Za-z])' . preg_quote($fn['argVar'], '/') . '(?![A-Za-z])/',
            "({$vStr})",
            $fn['rhsSrc']
        );

        $steps = [];
        $first = "{$lhs} = {$substituted}";
        $steps[] = mb_strlen($first) <= 58
            ? ['expr' => $first, 'note' => 'substitute back']
            : ['expr' => "{$fn['argVar']} = {$vStr}", 'note' => 'substitute back'];
        $steps[] = [
            'expr' => "{$lhs} = {$yStr}",
            'note' => $isRoot ? ($yStr === '0' ? 'checks out' : '~= 0, rounding') : 'matches the working',
        ];

        $narration = $isRoot
            ? "One last habit, and it is the one that separates careful work from lucky work: check the answer. Substitute {$fn['argVar']} equals {$vStr} back into the original equation, work it through, and it comes out to zero. The answer really does satisfy the equation."
            : "One last habit, and it is the one that separates careful work from lucky work: check the answer. Substitute {$fn['argVar']} equals {$vStr} back into the original equation and out comes {$yStr} — exactly the value we found before. It checks out.";

        return [
            'scene_id' => 'scene_check_synth',
            'layout_template' => 'math_steps',
            'duration_seconds' => 11,
            'narration' => ['text' => $narration],
            'mood' => 'neutral',
            'slots' => [
                'slot_math' => [
                    'content_type' => 'math_steps',
                    'heading' => 'Check the Answer',
                    'steps' => $steps,
                ],
            ],
        ];
    }

    /**
     * Build the PRACTICE card — one problem of the same type handed to the
     * viewer, then its answer. Watching a solve is not the same as doing one,
     * and the analyzer will not reliably invent a second problem on its own,
     * so this is a focused call whose only job is that problem.
     *
     * The model invents; it does not certify. Its answer goes straight through
     * {@see PracticeCheck} — a numeric answer to a single-unknown equation is
     * substituted back and evaluated here, while the model is still on the
     * line to correct it. One corrective retry, then the card is abandoned:
     * a video without a practice beat is fine, a video that teaches a wrong
     * answer to someone who just did the work is not.
     *
     * @param array  $scenes The working so far (validator input shape).
     * @param string $extra  Additional haystack (project title etc.).
     */
    public function practiceFromScenes(array $scenes, string $extra = ''): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        // The worked problem, as the viewer saw it: the chains carry it all.
        $working = [];
        foreach ($scenes as $scene) {
            if (!is_array($scene) || ($scene['layout_template'] ?? '') !== 'math_steps') {
                continue;
            }
            foreach ((array) (($scene['slots']['slot_math'] ?? [])['steps'] ?? []) as $step) {
                $expr = trim((string) (is_array($step) ? ($step['expr'] ?? '') : ''));
                if ($expr !== '') {
                    $working[] = $expr;
                }
            }
        }
        if (count($working) < 2) {
            return null; // no solve to be similar TO
        }

        $system = <<<'PROMPT'
You write ONE practice problem for a maths explainer: the same TYPE of problem the video just solved,
with different numbers, that a viewer could do in under a minute. Return ONLY JSON:

{"prompt": "<=90 chars, the problem itself, in LINEAR MATH NOTATION (frac{a}{b}, sqrt{...}, x^2, pi) — an equation to solve, not a story",
 "answer": "<=40 chars, the correct result written the same way ('x = 7')",
 "hint": "<=70 chars, ONE nudge naming the first move — never the solution",
 "narration": "3-4 sentences: pose the problem, tell the viewer to pause and try it, then give the answer and one line on how it falls out. The answer sentence must come LAST."}

Rules:
- The problem must be SOLVABLE and your answer must be EXACTLY right — it is checked by substituting it back in, and a wrong answer throws the whole card away.
- Prefer whole-number answers. Keep the numbers small and the working short.
- Same shape as the video's working, different numbers. Do not reuse the video's own numbers.
- Never put the answer inside the prompt.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "THE VIDEO'S WORKING:\n" . implode("\n", array_slice($working, 0, 14))
                . "\n\nTOPIC: " . mb_substr(trim($extra), 0, 300)],
        ];

        $parsed = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = Http::withToken($this->apiKey)
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'max_tokens' => 320,
                    'response_format' => ['type' => 'json_object'],
                ], 'low'));

            if (!$response->successful()) {
                Log::info('MathVisualSynthesisService: practice request failed', ['status' => $response->status()]);
                return null;
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'math_practice_synth');

            $raw = (string) $response->json('choices.0.message.content');
            $candidate = json_decode($raw, true);
            $prompt = is_array($candidate) ? trim((string) ($candidate['prompt'] ?? '')) : '';
            $answer = is_array($candidate) ? trim((string) ($candidate['answer'] ?? '')) : '';
            if ($prompt === '' || $answer === '') {
                return null;
            }

            $why = null;
            if (!\Modules\Project\Support\PracticeCheck::disproves($prompt, $answer, $why)) {
                $parsed = $candidate;
                break;
            }

            Log::info('MathVisualSynthesisService: practice answer disproved', ['why' => $why]);
            $messages[] = ['role' => 'assistant', 'content' => $raw];
            $messages[] = ['role' => 'user', 'content' =>
                "That is wrong: substituting your answer into your own problem does not hold ({$why}). "
                . 'Either solve your problem correctly or write a simpler one with a whole-number answer. '
                . 'Return ONLY the JSON.',
            ];
        }

        if ($parsed === null) {
            Log::info('MathVisualSynthesisService: practice synthesis never produced a verified answer');
            return null;
        }

        $narration = trim((string) ($parsed['narration'] ?? ''));
        if ($narration === '') {
            $narration = 'Now one for you. Pause the video here and work it through on paper — everything you need is on the screen. '
                . 'Ready? Here is the answer.';
        }

        return [
            'scene_id' => 'scene_practice_synth',
            'layout_template' => 'practice_card',
            'duration_seconds' => 13,
            'narration' => ['text' => $narration],
            'mood' => 'neutral',
            'slots' => [
                'slot_practice' => array_filter([
                    'content_type' => 'practice',
                    'prompt' => trim((string) $parsed['prompt']),
                    'answer' => trim((string) $parsed['answer']),
                    'hint' => trim((string) ($parsed['hint'] ?? '')) ?: null,
                    'heading' => 'Your Turn',
                ], fn ($v) => $v !== null),
            ],
        ];
    }

    // ---- Geometry figure synthesis (iter 40) --------------------------------
    //
    // The bug this closes: a geometry_diagram scene whose narration describes a
    // rich, LABELLED figure ("draw the circle, mark the diameter A B, put a
    // third point C on it, join A C and B C") but whose slot came back as a
    // bare shape name — {"shape":"circle"} or {"shape":"triangle"} with no
    // points, no labels, no marks. The renderer then draws its generic default
    // (a lone circle + radius, an unlabelled triangle), which contradicts the
    // voice. Project 105 (Thales' theorem) is the canonical case: three
    // geometry scenes, every one bare.
    //
    // The GeometryDiagram renderer is fully capable of the labelled figure —
    // points, vertex letters, angle marks, circumcircle, inner segments, side
    // squares. The gap is upstream: neither the tree composer nor the giant
    // call reliably fills those fields. So a thin figure is REBUILT by one
    // focused call whose only job is that single drawing — the same move the
    // scenario/plot/check synthesizers make, applied to geometry.

    /**
     * The polygon/circle/angle family — the shapes drawn by GeometryDiagram's
     * general machine, which is where a bare shape degrades to an unlabelled
     * default. The coordinate-system shapes (number_line, coordinate_plane,
     * fraction_bar, unit_circle, area_model) are produced by dedicated paths
     * with their own content and are left untouched.
     */
    private const GEOMETRY_SYNTH_SHAPES = [
        'triangle', 'right_triangle', 'rectangle', 'square', 'polygon', 'circle', 'angle',
    ];

    /**
     * How much a geometry slot actually SAYS beyond naming a shape: labelled
     * vertices, side labels, angle marks, erected squares, inner segments,
     * extra points, a circumcircle, radius/centre labels, equal-side ticks and
     * parallel arrows. A figure that will render as the plain default scores 0.
     */
    private function geometryInfoScore(array $slot): int
    {
        $shape = strtolower(trim((string) (is_scalar($slot['shape'] ?? null) ? $slot['shape'] : '')));
        $nonEmpty = fn ($arr): int => count(array_filter(
            (array) $arr,
            fn ($v) => is_scalar($v) ? trim((string) $v) !== '' : !empty($v)
        ));

        if ($shape === 'circle') {
            return (trim((string) ($slot['radius_label'] ?? '')) !== '' ? 1 : 0)
                + (trim((string) ($slot['center_label'] ?? '')) !== '' ? 1 : 0);
        }

        $points = (array) ($slot['points'] ?? []);
        $labelledPoints = count(array_filter(
            $points,
            fn ($p) => is_array($p) && trim((string) ($p['label'] ?? '')) !== ''
        ));
        $realPoints = count(array_filter(
            $points,
            fn ($p) => is_array($p) && is_numeric($p['x'] ?? null) && is_numeric($p['y'] ?? null)
        ));

        return $labelledPoints
            + $nonEmpty($slot['side_labels'] ?? [])
            + count((array) ($slot['angle_marks'] ?? []))
            + $nonEmpty($slot['side_squares'] ?? [])
            + count((array) ($slot['segments'] ?? []))
            + count((array) ($slot['extra_points'] ?? []))
            + (!empty($slot['circumcircle']) ? 1 : 0)
            + (int) array_sum(array_map('intval', (array) ($slot['side_ticks'] ?? [])))
            + (int) array_sum(array_map('intval', (array) ($slot['side_arrows'] ?? [])))
            // Real placed vertices are worth keeping even when unlabelled — a
            // figure the model bothered to position is not the empty default.
            + ($realPoints >= 3 ? 2 : 0);
    }

    /**
     * True when a geometry_diagram slot would render as GeometryDiagram's plain
     * default: an eligible shape carrying no distinguishing content at all.
     * Coordinate-system shapes and figures that already say something return
     * false — they are never re-synthesized.
     */
    public function geometryIsThin(array $slot): bool
    {
        $shape = strtolower(trim((string) (is_scalar($slot['shape'] ?? null) ? $slot['shape'] : '')));
        if (!in_array($shape, self::GEOMETRY_SYNTH_SHAPES, true)) {
            return false;
        }

        return $this->geometryInfoScore($slot) === 0;
    }

    /**
     * Walk raw scenes (validator-input shape) and REBUILD every thin
     * geometry_diagram slot from its own narration. Returns the scenes with
     * thin figures enriched; untouched (byte-identical) when nothing is thin or
     * the API key is missing. Never throws — a scene whose synthesis fails
     * keeps its original bare slot and the validator degrades it as before.
     *
     * @param  array  $scenes  RAW scenes.
     * @param  string $context Topic/title, to disambiguate the figure.
     */
    public function enrichThinGeometry(array $scenes, string $context = ''): array
    {
        if (empty($this->apiKey)) {
            return $scenes;
        }

        foreach ($scenes as $i => $scene) {
            if (!is_array($scene) || ($scene['layout_template'] ?? '') !== 'geometry_diagram') {
                continue;
            }
            $slot = (array) (($scene['slots'] ?? [])['slot_geometry'] ?? []);
            if (!$this->geometryIsThin($slot)) {
                continue;
            }

            $narr = is_array($scene['narration'] ?? null)
                ? (string) ($scene['narration']['text'] ?? '')
                : (string) ($scene['narration'] ?? '');
            if (trim($narr) === '') {
                continue; // nothing describing the figure to rebuild from
            }

            $heading = trim((string) ($slot['heading'] ?? ''));
            $built = null;
            try {
                $built = $this->geometryFromNarration($narr, $heading, $context);
            } catch (\Throwable $e) {
                Log::info('MathVisualSynthesisService: geometry enrichment threw', ['error' => $e->getMessage()]);
                $built = null;
            }
            if ($built === null) {
                continue;
            }

            // Keep the scene's own heading/content_type; graft the drawn body.
            $built['content_type'] = 'geometry';
            if ($heading !== '' && trim((string) ($built['heading'] ?? '')) === '') {
                $built['heading'] = $heading;
            }
            $scenes[$i]['slots']['slot_geometry'] = $built;
            Log::info('MathVisualSynthesisService: thin geometry_diagram rebuilt from narration', [
                'scene_index' => $i,
                'shape' => $built['shape'] ?? '?',
            ]);
        }

        return array_values($scenes);
    }

    /**
     * One focused call: given the narration for a single geometry scene, return
     * a fully-labelled geometry slot the GeometryDiagram renderer can draw. The
     * result must carry real content (points with letters, marks, a circumcircle
     * — geometryInfoScore > 0) or it is rejected; a thin answer is worthless.
     * One corrective retry, then null.
     *
     * @return array|null The slot_geometry body (shape + points + labels + ...).
     */
    public function geometryFromNarration(string $narration, string $heading = '', string $context = ''): ?array
    {
        if (empty($this->apiKey) || trim($narration) === '') {
            return null;
        }

        $system = <<<'PROMPT'
You are a geometry draughtsman. You are given the NARRATION for ONE scene of a maths video that says a
figure is being drawn on screen. Your job: output the figure as JSON so a renderer can DRAW it, fully
labelled, exactly matching what the narration describes. Return ONLY JSON — the body of one figure:

{"shape": "triangle" | "right_triangle" | "square" | "rectangle" | "polygon" | "circle" | "angle",
 "points": [{"x": 0..1, "y": 0..1, "label": "A"}],   // normalized, y points UP, listed in order around the figure
 "side_labels": ["c", "a", "b"],                      // one per edge (edge i joins point i to point i+1); "" to skip an edge
 "angle_marks": [{"at": <vertex index>, "label": "90°", "right": true}],  // arc + label at a vertex; right:true draws the right-angle square
 "segments": [{"from": "A", "to": "C", "label": "h", "dashed": true}],    // an INTERNAL line between two named points (a chord, cevian, median, diagonal, altitude)
 "extra_points": [{"on_side": <edge index>, "t": 0..1, "label": "M"}],    // a named point ON an edge (a midpoint, a foot)
 "side_squares": ["a^2", "b^2", "c^2"],               // a labelled square erected OUTWARD on an edge (Pythagoras / area-on-a-side); "" for none
 "circumcircle": true,                                // draws the circle THROUGH the first 3 points — use for a triangle inscribed in a circle, "angle in a semicircle", cyclic figures
 "radius_label": "r", "center_label": "O",            // circle only
 "highlight_side": <edge index>, "fill": true}

RULES
- Label with the EXACT letters/values the narration uses ("A", "B", "C", "a", "b", side "5 cm"). If the narration names vertices, the figure MUST carry those vertex labels.
- Choose the shape the narration describes. A circle with a triangle drawn inside it is a "triangle" with circumcircle:true (NOT a bare "circle") — the three points sit ON the circle, and the first three are what the circumcircle is drawn through.
- Place points so the figure looks right: a diameter is two points on opposite sides of the circle; an inscribed point sits elsewhere on it. For a right angle at a vertex, put angle_marks {"at": that vertex, "right": true}.
- Include EVERY element the narration mentions — every named point, every labelled side, every angle, every drawn line. Do not return a bare shape.
- Omit any field you are not using. Coordinates are 0..1 with y UP (so the top of the frame is y≈0.95).

EXAMPLE — narration: "Draw a circle, mark the ends of a diameter A and B, and a third point C on the circle. Join A to C and B to C to form triangle A B C. The angle at C is a right angle."
{"shape":"triangle","points":[{"x":0.06,"y":0.5,"label":"A"},{"x":0.94,"y":0.5,"label":"B"},{"x":0.28,"y":0.93,"label":"C"}],"circumcircle":true,"angle_marks":[{"at":2,"right":true}]}

EXAMPLE — narration: "Here is a right triangle with legs a and b and hypotenuse c; the right angle sits between the two legs."
{"shape":"right_triangle","points":[{"x":0.08,"y":0.08,"label":"A"},{"x":0.08,"y":0.9,"label":"C"},{"x":0.92,"y":0.9,"label":"B"}],"side_labels":["b","a","c"],"angle_marks":[{"at":1,"right":true}]}

EXAMPLE — narration: "Mark the two equal base angles of the isosceles triangle, both labelled a."
{"shape":"triangle","points":[{"x":0.5,"y":0.92,"label":"C"},{"x":0.08,"y":0.1,"label":"A"},{"x":0.92,"y":0.1,"label":"B"}],"side_ticks":[0,1,1],"angle_marks":[{"at":1,"label":"a"},{"at":2,"label":"a"}]}
PROMPT;

        $user = "NARRATION:\n" . mb_substr(trim($narration), 0, 1200);
        if (trim($heading) !== '') {
            $user .= "\n\nSCENE HEADING: " . mb_substr(trim($heading), 0, 80);
        }
        if (trim($context) !== '') {
            $user .= "\n\nTOPIC: " . mb_substr(trim($context), 0, 200);
        }

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = Http::withToken($this->apiKey)
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'max_tokens' => 600,
                    'response_format' => ['type' => 'json_object'],
                ], 'low'));

            if (!$response->successful()) {
                Log::info('MathVisualSynthesisService: geometry request failed', ['status' => $response->status()]);
                return null;
            }

            CostTracker::recordChat($this->model, $response->json('usage'), 'geometry_synth');

            $raw = (string) $response->json('choices.0.message.content');
            $candidate = json_decode($raw, true);
            if (is_array($candidate)) {
                $shape = strtolower(trim((string) (is_scalar($candidate['shape'] ?? null) ? $candidate['shape'] : '')));
                if (in_array($shape, self::GEOMETRY_SYNTH_SHAPES, true)) {
                    $candidate['shape'] = $shape;
                    // A rebuild that is itself thin certifies nothing — reject it
                    // so the scene keeps its original slot rather than swapping
                    // one empty default for another.
                    if ($this->geometryInfoScore($candidate) > 0) {
                        return $candidate;
                    }
                }
            }

            $messages[] = ['role' => 'assistant', 'content' => $raw];
            $messages[] = ['role' => 'user', 'content' =>
                'That figure carries no labels or marks, so it would draw as a blank default. Return the JSON '
                . 'again with the actual content the narration describes: place the points with their letter labels, '
                . 'add the angle marks and side labels it names, and set circumcircle:true if a triangle sits inside '
                . 'a circle. Return ONLY the JSON.',
            ];
        }

        Log::info('MathVisualSynthesisService: geometry synthesis never produced a labelled figure');

        return null;
    }
}
