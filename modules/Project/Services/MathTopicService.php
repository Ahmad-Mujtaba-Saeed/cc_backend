<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;

/**
 * MathTopicService — "is this a maths video?", asked ONCE, up front.
 *
 * The math cards (math_steps / geometry_diagram / function_plot) used to be
 * reachable only if the shot-list analyzer happened to pick them scene by
 * scene, and every prompt rule that protects a maths video — above all "never
 * ask the user to upload a figure we can draw" — was written for scripts that
 * SOLVE something step by step. So a conceptual maths video ("Why does
 * a^2 + b^2 = c^2?", "What is a derivative, visually?") fell through to the
 * generic explainer path and asked the viewer to upload photographs of the
 * very triangle the renderer draws natively.
 *
 * The fix is to stop inferring the subject from the output. One cheap call
 * classifies the topic BEFORE the analyzer runs, and the answer is handed to
 * the analyzer as a directive rather than left to emerge.
 *
 * Two kinds, because they want different scripts:
 *   - worked_problem: a question with an answer ("solve x^2+5x-24=0").
 *     The video IS the derivation.
 *   - proof_concept:  an identity, theorem, definition or intuition
 *     ("why does a^2+b^2=c^2", "what does a derivative mean").
 *     The video is the ARGUMENT — figures and expressions, not a march to a
 *     numeric answer.
 *
 * Everything degrades: no key, a refusal, a timeout or junk JSON all mean
 * "not maths", which is exactly the pre-existing behaviour.
 */
class MathTopicService
{
    public const KINDS = ['worked_problem', 'proof_concept'];

    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        // The classifier that ROUTES videos into math mode (v18) rides the
        // math valve (iter 31): a misroute here decides the whole pipeline,
        // and the valve's reasoning default is built for exactly that call.
        $this->model = LlmModels::for('math');
    }

    /**
     * Classify a topic. Returns [] when this is not a maths video (the common
     * case — a normal explainer must be untouched by any of this).
     *
     * @return array{kind?: string, subject?: string}
     */
    public function classify(string $title, string $script = ''): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $system = <<<'PROMPT'
You label the SUBJECT of a short explainer video, so the renderer knows whether to draw
equations and figures natively instead of asking the viewer to upload pictures of them.

Return ONLY JSON: {"is_math": true|false, "kind": "worked_problem"|"proof_concept"|null, "subject": "<=8 words", "scenario": true|false}

"scenario" is TRUE only when the problem statement describes a PHYSICAL SITUATION a diagram
could draw — objects moving, thrown or falling, tanks filling, people meeting or working
together, money accumulating: actors with given quantities. It is FALSE for pure symbol
manipulation ("solve x^2 + 5x - 24 = 0", "differentiate ln(x)") and for every non-math video.

is_math is TRUE when the video's spine is mathematical — its beats are equations, geometric
figures, functions/curves, or quantities being manipulated. This INCLUDES:
  - solving/finding/calculating a specific answer  -> kind "worked_problem"
  - proving, deriving, or explaining WHY an identity/theorem/formula/rule is true -> kind "proof_concept"
  - explaining what a mathematical object or operation MEANS or looks like -> kind "proof_concept"
  - geometry, trigonometry, algebra, calculus, probability and the mathematical parts of physics

Judge the SUBJECT, not the phrasing. "Why does a^2 + b^2 = c^2?", "The beauty of Euler's identity"
and "What is a derivative, visually?" are all maths (proof_concept) even though nothing is being solved.

is_math is FALSE for everything else, INCLUDING topics that merely mention numbers, statistics,
money, dates or growth. A video about company revenue, sports records, population growth or
"the maths of dating" is NOT a maths video — its numbers are evidence, not the subject.
When genuinely unsure, answer false.
PROMPT;

        $user = "TITLE: {$title}";
        if (trim($script) !== '') {
            $user .= "\n\nSCRIPT (first 1200 chars):\n" . mb_substr($script, 0, 1200);
        }

        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0.0,
                'max_tokens' => 80,
                'response_format' => ['type' => 'json_object'],
            ]));

        if (!$response->successful()) {
            throw new \Exception("Math-topic request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'math_topic');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        if (!is_array($parsed) || empty($parsed['is_math'])) {
            return [];
        }

        // An unusable kind must not silently become "not maths" — the topic
        // still IS maths, and proof_concept is the safer of the two (it never
        // promises an answer the script does not have).
        $kind = (string) ($parsed['kind'] ?? '');
        $out = ['kind' => in_array($kind, self::KINDS, true) ? $kind : 'proof_concept'];

        $subject = trim((string) ($parsed['subject'] ?? ''));
        if ($subject !== '') {
            $out['subject'] = mb_substr($subject, 0, 80);
        }

        // A word problem with actors and given quantities must OPEN on the
        // scenario schematic — the flag lets the analyzer be TOLD that, and
        // lets the retry demand the drawing when it is missing. Only a solve
        // can have one: a proof has an object, not a situation.
        //
        // The model's own answer is OR'd with a deterministic read of the
        // text: this single boolean decides whether the video opens on a
        // drawing at all, and asking one classifier call to be the only judge
        // made the opening beat flicker between runs of the SAME script
        // (project 100, "a ball thrown from a roof", drew the situation on two
        // analyses out of three). A false positive is cheap — the scenario
        // synthesizer refuses anything it cannot draw with two named entities
        // — while a false negative silently costs the video its only picture.
        if ($out['kind'] === 'worked_problem'
            && (!empty($parsed['scenario']) || self::looksPhysical($title . ' ' . $script))) {
            $out['scenario'] = true;
        }

        return $out;
    }

    /**
     * Does this text describe a physical situation a diagram could draw?
     *
     * Deliberately shallow and deliberately generous: it needs ONE actor-ish
     * noun (something that moves, holds, fills or is bought) or one measured
     * quantity with a real-world unit. Pure symbol manipulation ("solve
     * x^2 + 5x - 24 = 0", "differentiate ln(x)") contains neither.
     */
    public static function looksPhysical(string $text): bool
    {
        $t = mb_strtolower($text);

        // Things that happen to objects, and the objects they happen to.
        $subjects = '/\b(thrown|throws|throw|launched?|fired?|dropped?|drops|falls?|falling|rises?|climbs?|'
            . 'travels?|drives?|walks?|runs?|flies|flying|moves?|meets?|leaves?|'
            . 'fills?|filling|drains?|empties|pours?|leaks?|'
            . 'ball|rocket|car|train|plane|boat|cyclist|runner|ladder|rope|tank|pool|pipe|'
            . 'building|roof|tower|cliff|bridge|wall|ramp|shadow|'
            . 'invests?|deposits?|borrows?|buys?|sells?|costs?|discount|interest|salary|price)\b/';
        if (preg_match($subjects, $t)) {
            return true;
        }

        // A measured quantity: a number glued or spaced to a real-world unit.
        // Single letters are excluded — algebra's "5s" is five times s.
        $units = '/\d+\s*(m\/s|km\/h|mph|m\/s\^?2|metres?|meters?|kilometres?|kilometers?|km|cm|mm|miles?|feet|foot|inches|'
            . 'seconds?|minutes?|hours?|days?|grams?|kg|litres?|liters?|dollars?|pounds?|degrees?|%)\b/';

        return (bool) preg_match($units, $t);
    }
}
