<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Support\ExplainerRegistry;

/**
 * ScriptAnalysisService
 *
 * The "brain" of the explainer template. Takes a free-form script and asks the
 * LLM (OpenAI, reusing the project's existing key) to break it into scenes and
 * assign content to the named slots of a registry layout template.
 *
 * The model is constrained by {@see ExplainerRegistry::promptReference()} and
 * forced into JSON mode; the raw output is then handed to
 * {@see \Modules\Project\Support\ShotListValidator} which guarantees a legal
 * shot list. This service therefore never has to fully trust the response.
 */
class ScriptAnalysisService
{
    private string $apiKey;
    private string $model;
    private string $title = '';
    private float $temperature = 0.5;
    private int $attempts = 0;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        if (empty($this->apiKey)) {
            throw new \Exception('OPENAI_API_KEY is not set');
        }
        $this->model = LlmModels::for('explainer');
    }

    /**
     * @param  string  $script  The user's raw script / topic.
     * @param  array   $options ['aspect_ratio' => '16:9', 'target_seconds' => 60,
     *                           'math_topic' => ['kind' => 'worked_problem'|'proof_concept', 'subject' => '...']]
     * @return array   Raw (un-validated) shot list: ['scenes' => [...], 'summary' => ...]
     */
    public function analyze(string $script, array $options = []): array
    {
        $aspectRatio = $options['aspect_ratio'] ?? '16:9';
        $targetSeconds = (int) ($options['target_seconds'] ?? 60);
        $mathTopic = is_array($options['math_topic'] ?? null) ? $options['math_topic'] : [];
        $skeleton = is_array($options['skeleton'] ?? null) ? $options['skeleton'] : [];
        $guide = trim((string) ($options['guide'] ?? ''));
        $this->title = (string) ($options['title'] ?? '');
        // A maths storyboard is structure-prescribed — variance only means
        // some runs forget the scenario and shatter the working (project 34
        // rolled a different failure every run at 0.5). Ordinary explainers
        // keep the creative temperature.
        $this->temperature = $mathTopic !== [] ? 0.2 : 0.5;

        // Escalation valve (roadmap 3a): maths analysis can run on a
        // stronger model via OPENAI_EXPLAINER_MODEL_MATH. Unset = no change.
        if ($mathTopic !== []) {
            $this->model = LlmModels::for('math');
        }

        $systemPrompt = $this->buildSystemPrompt($aspectRatio, $targetSeconds, $mathTopic, $skeleton, $guide);
        $userPrompt = "SCRIPT / TOPIC:\n" . trim($script);

        Log::info('ScriptAnalysisService: requesting shot list', [
            'model' => $this->model,
            'aspect_ratio' => $aspectRatio,
            'target_seconds' => $targetSeconds,
            'script_length' => strlen($script),
            'math_kind' => $mathTopic['kind'] ?? null,
        ]);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
        $parsed = $this->requestShotList($messages);

        // A maths video that never draws anything is the failure this mode
        // exists to prevent — the viewer watches symbols move and never SEES
        // what they mean. The prompt requires a figure or a graph and the model
        // mostly complies, but "mostly" is not a feature. One corrective retry
        // (the same shape as the script writer's length retry): show it what it
        // returned, name the omission, ask again. A classified word problem is
        // held to the stricter bar — ANY visual is not enough, the scenario
        // schematic itself must exist (project 34 had a lovely function_plot
        // banked at the very end and still no drawing of the projectile, so
        // the generic check passed while the video failed the viewer).
        $needsScenario = ($mathTopic['scenario'] ?? false) === true;
        $missingVisual = $mathTopic !== [] && !$this->hasVisual($parsed);
        $missingScenario = $needsScenario && !$this->hasScenario($parsed);
        if ($missingVisual || $missingScenario) {
            Log::info('ScriptAnalysisService: maths storyboard incomplete, retrying once', [
                'missing_visual' => $missingVisual,
                'missing_scenario' => $missingScenario,
            ]);
            $complaint = $missingScenario
                ? 'This is a WORD PROBLEM — a physical situation with actors and given quantities — and the storyboard '
                . 'never DRAWS that situation. It must OPEN with a scenario_diagram (the FIRST content scene after the '
                . 'hook): one entity box per actor with its given value, connectors between them, and the question chip '
                . 'stating what we are looking for. The viewer needs the drawing BEFORE the first equation or the '
                . 'algebra is symbols about nothing. '
                : 'That storyboard never DRAWS the maths — not one scenario_diagram, geometry_diagram or function_plot, '
                . 'so the viewer only ever sees symbols being rewritten. That is not acceptable for this video. ';
            $messages[] = ['role' => 'assistant', 'content' => json_encode($parsed)];
            $messages[] = ['role' => 'user', 'content' =>
                $complaint
                . 'Return the same storyboard again, corrected: '
                . 'a scenario_diagram FIRST when this is a word problem with actors and given quantities; '
                . 'a unit_circle when the subject is trigonometry, complex numbers, polar form or rotation; '
                . 'a function_plot of the actual expression when there is a function, a curve, a log or an exponential — '
                . 'mark the roots, or plot both sides of the equation and mark where they cross; '
                . 'a geometry_diagram when there is a shape; a number_line to place the final answer. '
                . 'Place each visual AT the beat it illuminates — the setup drawing before the first equation, the plot '
                . 'at the beat that uses it — never banked together at the end. '
                . 'Keep everything else as it is — same beats, same order, same working. Return ONLY the JSON.',
            ];
            $retry = $this->requestShotList($messages);
            // Keep whichever attempt fixes more of what was missing.
            $score = fn (array $p): int => (int) $this->hasVisual($p)
                + (int) (!$needsScenario || $this->hasScenario($p));
            if ($score($retry) > $score($parsed)) {
                $parsed = $retry;
            } elseif ($score($retry) < $score($parsed)) {
                Log::info('ScriptAnalysisService: retry was worse, keeping the first attempt');
            } else {
                // Tie: prefer the retry only when it actually gained the thing
                // we asked for; otherwise the first attempt stands.
                Log::info('ScriptAnalysisService: retry did not improve, keeping the first attempt');
            }
        }

        // Past two attempts, asking harder is not a strategy — BUILD what is
        // still missing (project 34: both attempts of a scenario:true word
        // problem came back with zero drawings). The scenario comes from one
        // focused single-purpose call; the SEE-the-answer plot is extracted
        // mechanically from the working itself. Never fatal: null inserts
        // nothing and the lint report still tells the user.
        if ($mathTopic !== []) {
            $parsed = $this->synthesizeMissingVisuals($parsed, $mathTopic, $script);
        }

        Log::info('ScriptAnalysisService: shot list received', [
            'scene_count' => count($parsed['scenes'] ?? []),
        ]);

        return $parsed;
    }

    /** Insert the scenario / answer-plot scenes the model refused to draw. */
    private function synthesizeMissingVisuals(array $parsed, array $mathTopic, string $script): array
    {
        $scenes = array_values((array) ($parsed['scenes'] ?? []));
        if ($scenes === []) {
            return $parsed;
        }

        $mathTemplates = ['math_steps', 'geometry_diagram', 'function_plot', 'scenario_diagram', 'formula_anatomy'];
        $synth = null;

        if (($mathTopic['scenario'] ?? false) === true && !$this->hasScenario($parsed)) {
            try {
                $synth = new MathVisualSynthesisService();
                $scenario = $synth->scenario($script);
            } catch (\Throwable $e) {
                Log::info('ScriptAnalysisService: scenario synthesis unavailable', ['error' => $e->getMessage()]);
                $scenario = null;
            }
            if ($scenario !== null) {
                // Before the first maths beat — the drawing must precede the
                // working (hoistScenarioSetup would agree, but land it right
                // the first time). Never index 0: the opener is the hook (a
                // thin model scenario often IS scene 1 — it degrades to the
                // problem-statement text and the real drawing follows it).
                $at = 1;
                foreach ($scenes as $i => $s) {
                    if (in_array((string) (is_array($s) ? ($s['layout_template'] ?? '') : ''), $mathTemplates, true)) {
                        $at = max(1, $i);
                        break;
                    }
                }
                array_splice($scenes, min($at, count($scenes)), 0, [$scenario]);
                Log::info('ScriptAnalysisService: scenario_diagram synthesized', ['position' => $at]);
            }
        }

        $hasPlot = (bool) array_filter(
            $scenes,
            fn ($s) => is_array($s) && ($s['layout_template'] ?? '') === 'function_plot'
        );
        if (($mathTopic['kind'] ?? '') === 'worked_problem' && !$hasPlot) {
            $plot = ($synth ?? new MathVisualSynthesisService())->plotFromScenes($scenes, $this->title);
            if ($plot !== null) {
                // After the last chain of working — the payoff picture.
                $at = count($scenes);
                foreach (array_reverse(array_keys($scenes)) as $i) {
                    if ((($scenes[$i]['layout_template'] ?? '')) === 'math_steps') {
                        $at = $i + 1;
                        break;
                    }
                }
                array_splice($scenes, $at, 0, [$plot]);
                Log::info('ScriptAnalysisService: function_plot synthesized from the working', ['position' => $at]);
            }
        }

        // CHECK the answer (directive step 7) — demanded of the model, and
        // routinely skipped. When no beat substitutes back, build one and
        // let TinyExpr actually verify it (the card is only born when the
        // arithmetic certifies something the viewer saw).
        if (($mathTopic['kind'] ?? '') === 'worked_problem' && !$this->hasCheckBeat($scenes)) {
            $check = ($synth ?? new MathVisualSynthesisService())->checkFromScenes($scenes, $this->title);
            if ($check !== null) {
                // After the last maths beat (working or the SEE plot) — the
                // closing habit, right before the payoff.
                $at = count($scenes);
                foreach (array_reverse(array_keys($scenes)) as $i) {
                    if (in_array($scenes[$i]['layout_template'] ?? '', ['math_steps', 'function_plot', 'geometry_diagram'], true)) {
                        $at = $i + 1;
                        break;
                    }
                }
                array_splice($scenes, $at, 0, [$check]);
                Log::info('ScriptAnalysisService: check-the-answer card synthesized and verified', ['position' => $at]);
            }
        }

        $parsed['scenes'] = array_values($scenes);

        return $parsed;
    }

    /**
     * True when the storyboard already closes with a verification beat.
     * Only the LAST math card counts: "substitute back" mid-video is
     * ordinary computation (finding the height from the time — project 34
     * says it every run), while the checking HABIT is always the closer.
     */
    private function hasCheckBeat(array $scenes): bool
    {
        $last = null;
        foreach ($scenes as $scene) {
            if (is_array($scene) && ($scene['layout_template'] ?? '') === 'math_steps') {
                $last = $scene;
            }
        }
        if ($last === null) {
            return false;
        }

        $slot = $last['slots']['slot_math'] ?? [];
        $text = ((string) ($slot['heading'] ?? '')) . ' '
            . implode(' ', array_map(fn ($s) => (string) (is_array($s) ? ($s['note'] ?? '') : ''), (array) ($slot['steps'] ?? [])))
            . ' ' . (string) ($last['narration']['text'] ?? '');

        return (bool) preg_match('/\b(check|verify|verif\w*|substitut\w*\s+(it\s+)?back|plug\w*\s+(it\s+)?back|confirm)\b/i', $text);
    }

    /**
     * True when the shot list draws the maths at least once. A scenario only
     * counts when it would survive validation (>=2 named entities) — a husk
     * that will degrade to text must not satisfy "the video draws something".
     */
    private function hasVisual(array $parsed): bool
    {
        foreach ((array) ($parsed['scenes'] ?? []) as $scene) {
            $tpl = (string) (is_array($scene) ? ($scene['layout_template'] ?? '') : '');
            if (in_array($tpl, ['geometry_diagram', 'function_plot'], true)) {
                return true;
            }
        }

        return $this->hasScenario($parsed);
    }

    /**
     * True when the shot list draws the word problem's situation — for real.
     * The validator degrades a scenario with fewer than two NAMED entities
     * ("one box is not a scenario"), and the model's opening scenario is
     * routinely that thin (every project-34 run logged "scenario needs at
     * least 2 named entities -> single_focus"). Counting a husk as a
     * scenario here would skip the retry and the synthesis that replace it,
     * so this mirrors the clamp's bar.
     */
    private function hasScenario(array $parsed): bool
    {
        foreach ((array) ($parsed['scenes'] ?? []) as $scene) {
            if (!is_array($scene) || ($scene['layout_template'] ?? '') !== 'scenario_diagram') {
                continue;
            }
            $named = 0;
            foreach ((array) ((($scene['slots'] ?? [])['slot_scenario'] ?? [])['entities'] ?? []) as $e) {
                if (is_array($e) && trim((string) (is_scalar($e['label'] ?? null) ? $e['label'] : '')) !== '') {
                    $named++;
                }
            }
            if ($named >= 2) {
                return true;
            }
        }

        return false;
    }

    /** How many shot-list round trips this analysis took (cost signal). */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /** One shot-list request/parse round trip. */
    private function requestShotList(array $messages): array
    {
        $this->attempts++;
        $response = Http::withToken($this->apiKey)
            ->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', LlmModels::tune([
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => $this->temperature,
                // Long scripts (~25 scenes) overflow 4000 output tokens and the
                // model silently drops optional fields (transition/mood/camera)
                // to fit — every scene then validates to the same defaults.
                'max_tokens' => 8000,
                'response_format' => ['type' => 'json_object'],
            ], 'low'));

        if (!$response->successful()) {
            Log::error('ScriptAnalysisService: OpenAI request failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            throw new \Exception("Script analysis failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'script_analysis');

        $content = $response->json('choices.0.message.content');

        // The transport-mangling hunt (roadmap 3c): ± √ ² sometimes arrive
        // as control characters. The scrubs downstream make it survivable —
        // this pins WHERE it happens. If these bytes are already broken
        // here, the mangling is in the model output / HTTP layer, not ours.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', (string) $content, $m, PREG_OFFSET_CAPTURE)) {
            $off = (int) $m[0][1];
            Log::warning('ScriptAnalysisService: control characters in raw model output (transport mangling)', [
                'first_hex' => bin2hex($m[0][0]),
                'context_hex' => bin2hex(substr((string) $content, max(0, $off - 12), 24)),
            ]);
        }

        $parsed = json_decode((string) $content, true);

        if (!is_array($parsed)) {
            Log::error('ScriptAnalysisService: could not parse model JSON', [
                'preview' => substr((string) $content, 0, 300),
            ]);
            throw new \Exception('Script analysis returned malformed JSON');
        }

        return $parsed;
    }

    /**
     * The REQUIRED SHAPE directive — the L1 skeleton rendered as a hard,
     * numbered outline the analyzer must follow. Empty when no skeleton
     * (every non-math video today). This is the tree's first level doing
     * its job: the giant call stops deciding structure and only fills it.
     */
    /**
     * The user's own brief for this video, as a hard directive.
     *
     * The guide used to reach only the script WRITER and the math composers,
     * so a user who supplied their own script and a guide saying "here are the
     * seven screenshots I want placed" had that guide read out of settings and
     * silently discarded by every path that builds a storyboard. Empty when
     * the user wrote no guide.
     */
    private function buildGuideDirective(string $guide): string
    {
        $guide = trim($guide);
        if ($guide === '') {
            return '';
        }
        $guide = mb_substr($guide, 0, 1500);

        return <<<GUIDE


=== THE USER'S GUIDE (HIGHEST PRIORITY) ===
This is the brief from the person who commissioned the video. Where it conflicts with the generic
advice below, THE GUIDE WINS. Cover everything it asks for, in the order it asks.
When the guide names something to SHOW, that beat MUST be a scene with an image or video slot, and
its asset_request.description must describe THAT EXACT SHOT in the user's own terms so they
recognise it and upload the file they already have. Never swap a visual the guide asked for for a
text card, and never ask for an AI illustration of a screen the user clearly owns a screenshot of —
use phone_mockup (frame "browser" for a web UI, "phone" for an app).

{$guide}
=== END OF GUIDE ===

GUIDE;
    }

    private function buildSkeletonDirective(array $skeleton): string
    {
        if ($skeleton === []) {
            return '';
        }

        // What each phase intent is allowed to cast — the menu filter that
        // makes "a work phase can't be a big_counter" true by construction.
        $menus = [
            'hook' => 'a text scene (single_focus / stat_spotlight): the problem stated so the viewer must know the answer.',
            'scenario_setup' => 'scenario_diagram ONLY — the drawing of the situation.',
            'given_find' => 'a short text scene ("Given: ... Find: ...") — or fold it into the scenario\'s values when one exists.',
            'concept' => 'a text scene or explanation_box naming the idea that unlocks the solve — or formula_anatomy (the driving formula with each piece labelled) when ONE equation is the idea.',
            'work' => 'math_steps ONLY — one card, a 2-4 step chain of THIS phase\'s working, rule panel filled.',
            'see_answer' => 'function_plot / geometry_diagram (number_line) of the ACTUAL maths, answers marked.',
            'check' => 'math_steps that substitutes the answer back and shows it holds.',
            'payoff' => 'one text scene: the trick worth remembering.',
            'claim' => 'a text scene or math_steps carrying the identity being proven — or formula_anatomy labelling its parts when the names matter.',
            'build_object' => 'geometry_diagram / function_plot — draw the object the argument talks about.',
            'argument_step' => 'geometry_diagram / function_plot / math_steps GROWING the same figure or equality — never cut away for variety.',
            'close' => 'the beat where the two sides meet — math_steps or the finished figure.',
            'context' => 'a text or image scene giving the background the viewer needs — layer_stack when the background IS a layered structure, hierarchy_card when it is an org chart / parts-of-parts structure.',
            'point' => 'the claim\'s strongest card: animated_chart (with source) for numbers, stat_spotlight for one figure, quote_card for words, cycle_diagram when the point IS a repeating loop, function_plot/formula_anatomy when it pivots on a real curve or equation — plus an image beat when it needs one.',
            'counter' => 'the strongest objection, honestly stated — text or quote_card.',
            'resolution' => 'what the points add up to — text, the chart that settles it, proportion_flow when the answer is how ONE whole divides, or evidence_card when a specific named study settles it.',
            'origin' => 'where the story starts — image scene or map_card when place matters.',
            'era' => 'timeline_card for the dates, or an image scene per chapter — map_card when the story MOVES.',
            'turning_point' => 'the pivot beat — stat_spotlight / quote_card / before_after.',
            'legacy' => 'what it left behind — text or big_counter for the number that survived.',
            'contenders' => 'introduce both sides — versus_card or two image scenes.',
            'round' => 'one dimension compared — versus_card / before_after / split layouts / spectrum_card when the contenders sit on one axis / quadrant_map when TWO judgements sort them at once / scale_comparison when the dimension is sheer SIZE.',
            'verdict' => 'the call — versus_card verdict, checklist_card, or venn_card when the answer is the overlap ("pick two").',
            'setup' => 'the criteria, briefly — text scene.',
            'ranking_reveal' => 'list_ranking ONLY — the countdown is one card.',
            'number_one' => 'dwell on the winner — image scene / stat_spotlight.',
            'aspect' => 'the facet\'s best card — image, chart, icon_grid, cycle_diagram for a repeating process, layer_stack when the facet is a layered structure, hierarchy_card when the facet is a branching org chart / taxonomy, proportion_flow when the facet is one whole divided into parts, scale_comparison when the facet is sheer size, evidence_card when the facet rests on a specific named study, function_plot/formula_anatomy/math_steps when the facet is a real curve, equation or calculation, whatever the content earns.',
            // The `demo` shape (product walkthroughs / tutorials). A demo is a
            // SCREEN video: its beats are actions performed on a UI, so they
            // cast to the device mockup and the splits, never to bullets.
            'problem' => 'the pain the product removes — text, stat_spotlight, myth_fact or icon_grid.',
            'product_intro' => 'name the product — full_bleed_with_banner over a hero shot, phone_mockup of its home screen, or a text scene.',
            'demo_step' => 'phone_mockup ("browser" for a web UI, "phone" for an app) of the ACTUAL screen this action happens on, or split_side_by_side with that screenshot beside the instruction. One card per action.',
            'result' => 'what the viewer ends up with — photo_stack of the outputs, before_after, phone_mockup of the finished result, or checklist_card.',
            'second_feature' => 'the other capability — its own phone_mockup / split, never a bullet list.',
        ];

        $lines = [];
        foreach (array_values($skeleton) as $i => $phase) {
            $intent = (string) ($phase['intent'] ?? '');
            $brief = trim((string) ($phase['brief'] ?? ''));
            $menu = $menus[$intent] ?? 'the card that fits.';
            $n = $i + 1;
            $label = strtoupper($intent);
            $lines[] = "  {$n}. {$label} — {$brief}. Cast: {$menu}";
        }
        $outline = implode("\n", $lines);

        return <<<SKEL

=== REQUIRED SHAPE — THIS VIDEO'S PHASES, IN ORDER ===
The storyboard MUST follow these phases exactly, in this order. One phase = one scene (two only when a phase genuinely needs a beat of prose beside its card). Do not add phases, do not reorder them, do not skip one, and never use a card type a phase does not allow.

{$outline}
=== END REQUIRED SHAPE ===

SKEL;
    }

    /**
     * The MATH MODE directive — empty for every ordinary explainer.
     *
     * The math cards used to be reachable only by the model noticing, scene by
     * scene, that a beat looked mathematical, and every rule protecting a maths
     * video was phrased for scripts that SOLVE something. A conceptual maths
     * video ("Why does a^2 + b^2 = c^2?") therefore read as a normal explainer
     * and asked the viewer to upload photos of the figures we draw natively.
     * So the subject is now decided up front (MathTopicService) and stated here
     * as a directive instead of being left to emerge.
     */
    private function buildMathDirective(array $mathTopic): string
    {
        $kind = (string) ($mathTopic['kind'] ?? '');
        if (!in_array($kind, MathTopicService::KINDS, true)) {
            return '';
        }

        $subject = trim((string) ($mathTopic['subject'] ?? ''));
        $subjectLine = $subject !== '' ? "\nSUBJECT: {$subject}" : '';
        if (($mathTopic['scenario'] ?? false) === true) {
            // Decided up front, stated as fact — not left for the model to
            // re-derive from the phrasing (it kept deciding "no" — project 34
            // solved a projectile flight with no drawing of the projectile).
            $subjectLine .= "\nTHIS IS A WORD PROBLEM: the statement describes a physical situation. "
                . 'The FIRST content scene after the hook MUST be the scenario_diagram that draws it '
                . '— actors as entity boxes with their given values, connectors between them, the '
                . 'question chip naming what we want. This is not optional and nothing may precede it '
                . 'except the hook.';
        }

        $shape = $kind === 'worked_problem'
            ? <<<'SHAPE'
This script SOLVES a problem, so the solution IS the video. Structure it:
  1. SHOW the problem — math_steps with the starting equation, or geometry_diagram/function_plot when the problem is a shape or a curve. A word problem with actors and quantities OPENS with the scenario_diagram instead — and it comes FIRST, before ANY working; a scenario drawn after the algebra is worthless (the viewer needed it to understand the algebra).
  2. State GIVEN and FIND explicitly before the first move — the viewer must be able to say "we know X and Y, we want Z" before a single symbol moves. On a scenario_diagram the entity values ARE the givens and the question chip IS the find; otherwise a geometry_diagram labelled with the GIVEN values or a short text scene ("Given: ... Find: ...").
  3. The concept or formula that unlocks it. When ONE formula drives the whole solve, make this beat a formula_anatomy card — the equation typeset large with each piece labelled ("the -4.9 is half of gravity") — so the viewer knows every part BEFORE the algebra starts moving them.
  4. ONE math_steps scene per PHASE of the working, 2-4 steps each, in order — and each of those cards fills its "rule" panel with the law it is using. A phase without a named rule is rare; if you cannot name what makes the move legal, you have not understood the phase.
  5. SEE the answer — a function_plot of the function with its root marked, or a number_line with the answer on it. This scene is REQUIRED. A solve that never shows a picture is exactly what this mode exists to prevent: the viewer has watched symbols move and still cannot see what the answer MEANS.
  6. The answer, as the LAST step of the last math_steps scene (it is stamped automatically) — or a big_counter when the answer is one dramatic number.
  7. CHECK the answer — a short math_steps card that substitutes it back into the original equation (or checks the units/domain) and shows both sides agree. REQUIRED: a solve that never verifies teaches the mechanics but not the habit.
  8. HAND ONE OVER — a "practice_card" posing ONE problem of the same type for the viewer (slot_practice {prompt, answer, hint?}), narrated so it poses the problem, pauses, and only then gives the answer. Optional but strongly encouraged: watching a solve is not the same as doing one. Only include it if you can state an answer you are certain of — a numeric answer is substituted back into the prompt and the card is discarded if it does not hold.
  9. The payoff: the trick worth remembering.
SHAPE
            : <<<'SHAPE'
This script EXPLAINS or PROVES why something is true — nothing is being computed, so do NOT invent a march to a numeric answer. The ARGUMENT is the video. Structure it:
  1. State the claim — a text scene, math_steps carrying the identity itself, or formula_anatomy labelling the identity's parts when the names matter to the argument.
  2. Build the object it talks about — geometry_diagram / function_plot, labelled with the names the narration uses ("a", "b", "c").
  3. ONE scene per step of the argument, each adding exactly ONE new element to that same figure, or ONE new equality. Consecutive geometry_diagram scenes are CORRECT here — repeat the figure and grow it, do not cut away to something else for the sake of variety. Point at whatever the narration is naming that beat: highlight_side for the side being discussed, fill to shade the area being counted, side_squares to erect the square on a side.
  4. The beat where the argument closes — the two things shown to be equal.
  5. The payoff: what the result lets you do.
SHAPE;

        return <<<MATH

=== MATH MODE — THIS VIDEO'S SUBJECT IS MATHEMATICAL ==={$subjectLine}

The math cards are the SPINE of this video, not a garnish. Non-negotiable:
- Every beat that mentions an equation, a shape, or a function MUST be a math_steps, geometry_diagram or function_plot scene. Not a bullet list about it, not a photo of it. NEVER put an expression in a text_block bullet — linear notation like "frac{-2 pm sqrt{48}}{2}" is typeset ONLY on the math cards; in a bullet it renders as that literal gibberish.
- EVERY math_steps card carries 2-4 steps that form a CHAIN — consecutive lines of the SAME working, where each line is the previous line after one move. A card holding ONE expression is always wrong: the card exists to show a line TURNING INTO the next line (it accents exactly what changed, and the last step is stamped as the result), and one line has nothing to turn into. If a beat only has one line in it, it belongs on the neighbouring card with the lines around it. Roughly one card per PHASE of the working, not one per keystroke — a solve that takes 12 moves is about 4 cards of 3 steps, never 12 cards of 1.
- EVERY STEP IS A COMPLETE STATEMENT — an equation or inequality with both sides ("Delta x = 5 - 1 = 4", "m = 4/4 = 1"). A bare number or naked expression is NEVER a step: "5 - 1" then "4" as two steps wastes a whole line (and the answer stamp) on a fragment and shows no relationship. Name the quantity, show the computation, show the result — one line.
- A note names the MOVE, never the result ("subtract 1", "divide both sides by 2" — NEVER "change in x is 4": the line already shows the 4, and the narration already says it).
- The narration of a math_steps scene speaks ONE sentence per step, in step order — the lines then land exactly as the voice reaches them. Never narrate two steps in one breath or pad a step with a second sentence.
- A figure EVOLVES instead of repeating: when the working returns to a curve to add its tangent or shade an area, that is ONE function_plot scene carrying expression + tangent_at + shade together (the card reveals them in stages) — never a second scene re-drawing the same curve.
- NAME THE RULE. Whenever a card applies a named rule, law, formula or identity, fill its "rule" (see slot_math below) with the rule's name, the rule STATED as a general formula, and one plain-language line saying what it does. That panel is where the viewer learns WHY the move is legal — the steps show what happened, the rule says why it was allowed.
- The video must EXPLAIN, not just execute. Mechanically correct steps with no reason attached teach nothing. If the script gives a reason for a move, it belongs in the rule panel or the step note — never drop it.
- MATHS SCENES ARE LONGER. A math_steps card with a 3-line chain and a rule panel needs 10-14 seconds to land, a figure 7-10. Do NOT split one beat into a prose scene plus a one-line card to reach a scene count — that is the single worst thing you can do to a maths video. Where the script explains a move and then makes it, that is ONE scene: the card shows the lines while the narration explains them, and the reason goes in the rule panel. Give this video FEWER, FULLER scenes; a text scene should only exist for a beat that is genuinely not about an equation, a shape or a curve.
- You may NOT emit a single image/video slot describing an equation, a triangle, a square, a diagram, a graph or a plot. Those are geometry_diagram/function_plot scenes. "An illustration of a right triangle labelled a, b, c" and "a square drawn on side a" are figures the renderer draws — request them as geometry_diagram, never as uploads.
- Do NOT use animated_chart to stand in for maths. A chart is for real measured data that is genuinely comparable. Areas, terms of an equation and steps of an argument are NOT chart data — inventing bar values to represent "a squared vs b squared vs c squared" is always wrong.
- A value the working COMPUTES is never its own big_counter or stat_spotlight scene. The final line of the math_steps card IS the answer — it lands in an accent chip with a stamp. A separate counter card restating "2.86 seconds" right after the card that derived it says the same fact twice and cuts the working in half. big_counter/stat_spotlight are for real-world statistics that are NOT derived on screen (a country's population, a price) — in this video they are almost always wrong.
- THIS VIDEO MUST SHOW A PICTURE OF THE MATHS — at least one scenario_diagram, geometry_diagram or function_plot, and more when the topic earns it. A wall of equations is not an explanation; the point of a visual is that the viewer SEES why the algebra is true. Reach for the one the subject asks for:
  · a WORD PROBLEM with actors and quantities — motion/velocity/distance, work rates, mixtures, tanks filling, two things approaching → OPEN with "scenario_diagram" (slot_scenario {layout: the SHAPE of the sketch — "arc" when the thing goes UP and comes back DOWN (projectiles, thrown balls — a projectile drawn as a flat chain is a WRONG drawing), "climb" when it rises (ascent, filling, growth), "fall" when it drops, "line" for flat left-to-right stories (two things approaching, A to B distance); entities: [{label, icon?, sprite?: a drawable OBJECT <=8 words ("a red hatchback car, side view") that the system draws as a cut-out image sitting ON the diagram — always give the MOVING actor a sprite, value?: the given value, e.g. "v = 20 km/min"}] ordered ALONG the motion (arc: launch → peak → landing), connectors: [{label?, sub?, style?: arrow|line|both}] one per gap, question?: "d = ?"}). Draw the situation FIRST — the car, the road, the destination — so every later equation points back at something the viewer has seen. The setup is a FULL beat, never a transition: its narration walks the viewer through the drawing — name each actor, each given value, and the thing being asked, in 3-4 sentences (~10-12s of speech). A one-line "here's our setup" flashes the diagram and wastes it.
  · trigonometry, complex numbers, polar form, rotations, angles, De Moivre, Euler → geometry_diagram shape "unit_circle" (angle_deg, and angle2_deg for a second angle when the beat compares them — this is what "raising to the n multiplies the angle" LOOKS like).
  · any named function, curve, growth or decay — logs, exponentials, parabolas, sine waves, 1/x → function_plot of the ACTUAL expression (expression2 to compare two curves, marks for roots/intersections, tangent_at for slope, shade for area).
  · shapes, areas, lengths, triangles, circles → geometry_diagram.
  · ONE formula the video pivots on (the model equation of a word problem, the quadratic formula, compound interest) → "formula_anatomy" (slot_formula {formula: the equation in the same linear notation <=80 chars, parts: 2-4 of {match: an EXACT substring of formula ("-4.9", "h_0", "b^2 - 4*a*c"), label: what that piece means in THIS problem, <=48 chars}}) — the intro beat BEFORE the working starts, never a substitute for it. Every match must appear character-for-character inside formula or its label is dropped. At most one per video.
  · a value on a line, an interval, an inequality → geometry_diagram "number_line". A fraction or proportion → "fraction_bar".
  Even a pure algebra solve usually has one: plot the function whose root you just found, or put the answer on a number line, and mark it.
- VISUALS RIDE WITH THE WORKING, never after it. A figure sits AT the beat it illuminates — the scenario before the first equation, the unit circle AT the rotation beat, the plot AT the beat that finds the root. A video that does all the algebra first and then shows the pictures at the end has failed: by then there is nothing left to explain. Alternate: draw, then work, then point back at the drawing.
- SHOW WHERE THE PIECES GO. When a step MOVES a term to the other side, or DISTRIBUTES a product over a bracket, add "arrows" to that step: [{from: the token in the PREVIOUS line, to: the token it becomes in THIS line}] — e.g. moving the 5: {from: "5", to: "-5"}; distributing x over (x - 3): {from: "x", to: "x^2"}. Up to 3 per step. The renderer draws the pen strokes so the viewer literally watches the term travel.
- full_bleed_*, split_* and single_focus must never be used to present a figure or an equation.
- Everything spoken as an equation must appear as an on-screen expr in the SAME scene.
- The math cards may repeat freely — their per-video caps are raised for this video. A figure that grows across four consecutive scenes is the argument, not a rhythm problem.
- The viewer is NEVER asked to upload anything. Any image slot you do include (a hook, a real-world context beat) is AI-illustrated from your asset_request.description, so write it as a concrete drawable SUBJECT ("a student at a desk staring at a whiteboard of symbols"), never a photo/screenshot request — and use image slots sparingly. The math cards ARE the visuals.

{$shape}
=== END MATH MODE ===

MATH;
    }

    private function buildSystemPrompt(string $aspectRatio, int $targetSeconds, array $mathTopic = [], array $skeleton = [], string $guide = ''): string
    {
        $reference = ExplainerRegistry::promptReference();
        $mathDirective = $this->buildMathDirective($mathTopic)
            . $this->buildSkeletonDirective($skeleton)
            . $this->buildGuideDirective($guide);

        // A maths scene is a SLOWER scene. A math_steps card carrying a 3-line
        // chain plus a rule panel needs 10-14s of narration to land; asked for
        // a 6s average, the model hits the scene count by splitting every beat
        // into a prose scene plus a one-line card — which is exactly the
        // "just worked math, no explanation" failure. Give maths room and it
        // builds whole cards instead.
        $sceneSeconds = $mathDirective !== '' ? 11.0 : ExplainerRegistry::defaultSceneSeconds();
        $approxScenes = max(2, (int) round($targetSeconds / $sceneSeconds));

        $aspectGuidance = $aspectRatio === '9:16'
            ? '- PORTRAIT VIDEO (9:16): the frame is TALL and narrow. Prefer "split_top_bottom" over "split_side_by_side" (side-by-side columns get too thin), prefer "full_bleed_with_banner" over "full_bleed_with_side_panel", and describe requested images as vertical/portrait shots where possible. Keep headings extra short (~3 words) so they fit the narrow frame.'
            : '- LANDSCAPE VIDEO: all layouts work; side-by-side splits and side panels look great.';

        return <<<PROMPT
You are a video director that turns a script into a structured, slot-based "shot list" for an automated explainer-video renderer. You do NOT place anything by pixel coordinates. You only choose, for each scene: one layout template, and what content goes in each of that template's named slots. Every choice you make is from the fixed registry below — never invent a template name, slot name, content type, camera move, or transition that is not listed.
{$mathDirective}
{$reference}

YOUR JOB:
- Break the script into a sequence of roughly {$approxScenes} scenes (aim for ~{$sceneSeconds}s each; total near {$targetSeconds}s). Each scene covers one beat of the narration.
- For each scene, write the narration text the voice-over would say.
- Pick the layout_template that best fits that beat. Use "split_side_by_side" for comparisons or for putting an image on one side and bullet points on the other. Use "single_focus" for a single hero image or headline. A scene whose ENTIRE point is one number/statistic -> "stat_spotlight" (the heading IS the stat, e.g. "\$4.2 Billion"). A verbatim quotation or memorable one-liner -> "quote_card". Use stat_spotlight and quote_card at most twice each per video — they are exclamation marks, not prose.
- DATA CARDS (the strongest scenes — reach for them whenever the content qualifies, respecting each card's per-video cap):
  · "versus_card" when the script pits two NAMED things against each other — fill slot_left/slot_right with an image request for each side and slot_versus with {left: {label, stats: [<=3 short lines]}, right: {...}, verdict?: "one line"}.
  · "animated_chart" whenever the narration lists 3+ comparable numbers — emit REAL data in slot_chart {chart_type: bar|line|area|donut|pie|scatter|radar, values, labels, unit, highlight_index, source}. Pick the shape by meaning: bar = categories compared, line = change over time, area = accumulation over time, donut = ONE share of a whole, pie = the whole composition (3-6 shares), scatter = correlation across a sequence, radar = 3-8 dimensions of one thing. NEVER put numbers like that in prose bullets, and NEVER request a screenshot/photo of a chart, graph or table — charts are always rendered natively from your data.
  · "big_counter" when one record-breaking number deserves maximum drama (slot_counter: a text_block whose heading IS the figure, one support bullet).
  · "checklist_card" for requirements / do's and don'ts / advantages vs disadvantages (slot_checklist {pros, cons?, pros_label?, cons_label?, heading?}).
  · "icon_grid" when enumerating 3-9 parts/features/categories (slot_icons {items: [{icon: from the ICON LIBRARY only, label: 1-2 words}], highlight_index?, heading?}).
  · "timeline_card" for a history/roadmap/sequence of dated moments (slot_timeline {nodes: [{date, label}], heading?} — 3-6 nodes).
  · "step_flow" for how-it-works processes (slot_steps {items: [{label: <=4 words, icon?: from the ICON LIBRARY}], heading?} — 3-5 steps).
  · "before_after" for transformations with an image of each state (slot_before + slot_after image requests).
  · "list_ranking" for top-N countdowns (slot_ranking {items: [strings, BEST LAST], heading?} — 3-6 items; the reveal counts down to #1).
  · "progress_meter" when one percentage IS the scene (slot_meter {value_pct, label, unit?}).
  · "pictogram_percent" when the statistic is a share OF PEOPLE — "7 in 10 gamers", "1 in 4 adults", "64% of users" — slot_pictogram {filled: the numerator (decimals fine, 6.4), of: the denominator (usually 10), label: who the share is ("of gamers never finish the story"), unit?: "%" to display the clean percentage}. Person icons fill in one by one — always prefer it over progress_meter when the stat is about people.
  · "quote_portrait" for a quote from a specific person whose face matters (slot_portrait image request + slot_quote like quote_card).
  · "phone_mockup" whenever the visual is SCREEN content — an app, website, post, chat, dashboard (slot_screen image/video request with "frame": "phone" or "browser").
  · "photo_stack" for a run of 2-4 related photos flipped through like prints (slot_photo_1..slot_photo_4 image requests; 3 and 4 are optional).
  · "map_card" when WHERE matters — a place, a spread, a journey between two places (slot_map {pins: [{label, lat, lon}] with real approximate coordinates, region?: world|europe|asia|africa|north_america|south_america|oceania, route?: true to arc between 2 pins, heading?}).
  · "headline_ticker" for press/community reaction beats (slot_headlines {items: [{text: short headline, source: outlet}], heading?} — 2-3 chips).
  · "labeled_diagram" for "how X works" / parts-of-X beats — one hero illustration with 2-4 leader-line part labels drawn natively over it (slot_diagram: an image whose asset_request.description is ONE clean centered subject on a plain background containing no text, plus heading?: <=40 chars and callout_suggestions: [2-4 short part names] — the system positions the labels on the image automatically). Never for shapes/equations/charts (those have their own cards).
  · "myth_fact" when the script DEBUNKS a widespread belief ("everyone thinks…", "you've been told…", "actually…") — slot_myth_fact {myth: the belief exactly as people repeat it (<=140 chars), fact: what is actually true, with the receipts (<=140 chars), heading?}. The myth is struck through as the fact stamps in, timed to the narration's pivot. Never for mere comparisons — that is versus_card.
  · "quadrant_map" when the beat sorts things by TWO independent judgements at once — effort vs impact, urgency vs importance, risk vs reward — slot_quadrant {x_axis: {left_label, right_label}, y_axis: {bottom_label, top_label} (<=18 chars each, "Low effort"/"High effort"), quadrant_items: [3-6 of {label <=20, x: 0..1 from the LEFT pole, y: 0..1 from the BOTTOM pole — commit to real judgements and spread them across the square}], zones?: {top_left, top_right, bottom_left, bottom_right} names for the four boxes (<=16 chars, "Quick wins"), highlight_index?: the item the beat is ABOUT, heading?, caption?}. Never for ONE dimension (spectrum_card) or a chronology (timeline_card).
  · "scale_comparison" when the beat is HOW BIG something is next to something the viewer already knows — a blue whale beside a bus, Jupiter beside Earth, a gigabyte beside a terabyte — slot_scale {scale_items: [2-3 of {label: <=24 chars, value: a plain NUMBER in ONE shared unit — the real measurement, never pre-scaled or normalised, note?: <=40 chars}], unit?: <=8 printed after the numbers ("m", "kg", "years"), shape?: "square" (default) or "circle", highlight_index?: the one the beat is ABOUT, heading?, caption?: <=80}. The shapes are drawn at TRUE relative size computed from your values, growing into place as the narration names them. Past a 40x spread the smallest cannot be drawn honestly in one frame, so the card stops drawing to scale and states the ratio instead ("1,200x smaller") — which is why the figures must be real. Parts of ONE whole are proportion_flow; a measured series is animated_chart; two things compared feature by feature is versus_card.
  · "evidence_card" when the beat's authority rests on a SPECIFIC named source — "according to a 2021 Lancet study…", "Stanford researchers found…", "Pew Research reports…" — slot_evidence {source: the study, institution or publication behind the claim (<=48 chars — it MUST NAME someone; "The Lancet", "Stanford University", never "studies" or "experts"), finding: the one thing it found, in plain words (<=160), year?: <=12 ("2021", "2019–2023"), sample?: what it looked at (<=40, "12,000 adults", "n = 500"), heading?, caption?: a caveat (<=80)}. The finding lands as the headline and the provenance is cited beneath it. This is the constructive counterpart of the no_source warning — real provenance earns the card, and if you have no nameable source the card REFUSES and the beat becomes plain text, so never invent one. A number that stands on its own is big_counter; a myth being corrected is myth_fact; a list of findings is checklist_card.
  · "proportion_flow" when the beat splits ONE whole into its parts — where every tax dollar goes, how a 24-hour day is spent, what the atmosphere is made of — slot_proportion {source_label: the whole being divided (<=24 chars, "Every tax dollar"), branches: [2-5 of {label: <=24 chars, value: a plain NUMBER giving that part's SIZE — never a percentage, note?: <=40 chars}], unit?: <=6 ("$" prints before the number, "hrs" after it), total?: ONLY when the branches genuinely do not cover the whole, in which case the leftover is drawn as its own "Other" branch, highlight_index?: the branch the beat is ABOUT (it takes the accent), heading?, caption?: <=80}. The source bar lands whole, then splits into proportional segments as the narration names each part. The percentages are COMPUTED from your values and any you send are overwritten, so give real sizes and let the card do the arithmetic. A running list of figures that merely adds up to a total is receipt_card; unrelated quantities side by side are animated_chart; one headline share is pictogram_percent.
  · "spectrum_card" when the beat asks WHERE something sits relative to others — pricing, risk, difficulty, political lean, sweetness — slot_spectrum {axis: {left_label, right_label} the two poles (<=18 chars each, "Cheap"/"Expensive"), spectrum_items: [2-5 of {label <=20, position: 0..1 from the LEFT pole — commit to a real judgement and spread them out}], highlight_index?: the item the question is ABOUT (it takes the accent), heading?, caption?}. Never for time (timeline_card) or measured data series (animated_chart).
  · "decision_tree" when the beat is genuinely a CHOICE the viewer makes by answering questions ("which should you use?", "do you need X?") — slot_decision {question: the FIRST thing you ask (<=64), branches: [EXACTLY 2 of {label: the answer that takes this path (<=14, "Yes"/"No"/"Under 10k"), then EITHER outcome: what you do if it ends here (<=40) OR question: one more question (<=48) plus its own branches: [exactly 2 of {label, outcome (<=36)}]}], heading?, caption?}. At most TWO levels deep, so at most four endings, and every path must END somewhere. A list of options that do not branch is icon_grid or checklist_card; a linear process is step_flow.
  · "hierarchy_card" when the beat lays out HOW SOMETHING IS STRUCTURED — how an organisation is organised, the parts of a system and their parts, a taxonomy ("a company splits into three divisions, and sales has two teams") — slot_hierarchy {root: the one thing at the top (<=28 chars, "The UN", "A cell"), children: [2-4 of {label: a branch under the root (<=22), caption?: <=40 what it is or does, children?: [2-4 of {label <=18}] for a SECOND level of sub-parts under that branch}], highlight_index?: the top-level branch this beat is ABOUT (it takes the accent), heading?, caption?}. At most two levels below the root, drawn as an org chart that builds as the narration names each branch. A yes/no path the viewer walks by answering is decision_tree; things stacked ON TOP of each other are layer_stack; a flat list that does not branch is icon_grid or checklist_card.
  · "receipt_card" when the beat BREAKS A TOTAL DOWN into named parts that sum — a cost breakdown, where the hours went, what a tax funds, a price after discounts — slot_receipt {rows: [2-8 of {label: what the line is (<=28 chars), value: a NUMBER, negative for a discount}], total?: LEAVE IT OUT, the card adds the rows up itself and a total that disagrees with them is overwritten, total_label?: <=20 (default "Total"), unit?: <=6 ("$" prints before the number, "hrs" after it), heading?, caption?: <=80 ("per month")}. Values are plain numbers — no symbols or commas inside them. Unrelated figures are animated_chart; one headline number is big_counter.
  · "common_mistake" when there is ONE error a viewer is very likely to make with what was just taught — a sign flipped, a root split over a sum, a rule used where it does not hold — slot_mistake {wrong: the line people actually write (<=60 chars, LINEAR MATH NOTATION when it is maths), correct: the SAME move done properly (<=60 — it must be the same step, not a different topic, so the viewer sees exactly what changed), why?: one plain line naming the error (<=100, "a square root does not split over a sum"), heading?, caption?: <=80}. The wrong line lands first and is struck through as the narration turns; the correct line stamps in below. BOTH lines are checked — pure arithmetic is evaluated, and a card whose "wrong" line is actually true, or whose "correct" line is false, is discarded. A false belief about the WORLD is myth_fact, not this; a list of tips is checklist_card.
  · "practice_card" when the video hands the viewer a problem to solve THEMSELVES — almost always the last teaching beat, right before the outro — slot_practice {prompt: the problem itself (<=90 chars, in the same LINEAR MATH NOTATION as math_steps when it is maths), answer: the correct result (<=40, "x = 7", "38 km"), hint?: one nudge that is not the solution (<=70), heading?, caption?: <=80 tying it back ("same move as step 2")}. The problem lands, an accent pause bar runs while the viewer works, and the answer stamps in afterwards — so write the narration to POSE the problem, leave a real beat of silence or encouragement, and only then say the answer. The answer is VERIFIED: a numeric answer is substituted back into the prompt and the entire card is thrown away if it does not check out, so do not guess one. A problem the narrator works through himself is math_steps; a rhetorical question is a plain text scene.
  · "term_card" when the script STOPS to define a word the viewer needs before the explanation can continue — jargon, a loanword, a technical term — slot_term {term: the word itself (<=28 chars), definition: ONE plain sentence a beginner would understand (<=120 chars — never define jargon with more jargon), phonetic?: how it sounds (<=32, IPA "/ˈen.trə.prə.nɜːr/" or respelling "on-truh-pruh-NUR"), part_of_speech?: noun/verb/adjective, caption?: origin or literal meaning (<=80), heading?}. The word lands large with an accent underline, then the pronunciation, then the definition. Defining SEVERAL words in one beat is icon_grid or checklist_card; a term plus its equation is formula_anatomy.
  · "venn_card" when the point is what two or three things have IN COMMON, or what sits at the intersection of requirements ("cheap, fast, good — pick two") — slot_venn {sets: [2-3 of {label: <=20 chars naming the group, caption?: <=32 chars for what is unique to it}], overlap_label?: <=28 chars naming what sits in the shared middle ("Both", "Whales", "The sweet spot") — this is the punchline, so give it one, heading?, caption?}. The circles overlap and the shared middle tints in accent, the overlap label stamping in last. Two things merely COMPARED side by side is versus_card; a flat list of shared traits with no groups is checklist_card.
  · "cycle_diagram" for a process that REPEATS back to its start (water cycle, habit loop, supply chain, product lifecycle) — slot_cycle {items: [{label: <=4 words, icon?: from the ICON LIBRARY}] IN LOOP ORDER (3-6), heading?, caption?: <=60 chars shown in the ring's centre}. The stages sit on a ring and the arrows close the loop back to stage 1. A one-way process that ENDS is step_flow, not a cycle.
  · "layer_stack" when things genuinely sit ON TOP of each other — atmosphere layers, a network/OSI stack, soil horizons, what's inside a battery or a road — slot_layers {layers: [3-6 of {label: <=24 chars, caption?: <=44 chars saying what that layer does}] listed TOP FIRST in their REAL stacking order (the order is the content and is never rearranged; repeated labels are fine — real stacks alternate materials), highlight_index?: the one layer this beat is ABOUT (it takes the accent), heading?, caption?}. The slabs land one by one as the narration names them and deeper layers shade darker. A list with no vertical structure is checklist_card or icon_grid; a repeating loop is cycle_diagram.
  · "formula_anatomy" when a beat introduces ONE driving equation — "E = m*c^2", the compound-interest formula, the model behind the story — slot_formula {formula: the equation in the same linear notation (<=80 chars), parts: 2-4 of {match: an EXACT substring of formula ("-4.9", "m*c^2"), label: what that piece means in THIS story (<=48 chars)}, heading?}. The card typesets the equation large and hangs each label off its own piece with a leader line; every match must appear character-for-character inside formula or its label is dropped. Introduce the equation, never derive it (a derivation is math_steps). Works in ANY subject — physics, finance, chemistry — whenever one formula is the star.
  · "math_steps" for a worked equation/derivation beat — slot_math {steps: [{expr, note?}], rule?: {name, formula?, why?}, heading?}. Write expr in LINEAR MATH NOTATION: frac{a}{b} for stacked fractions, sqrt{...}, x^2 or x^{n+1} powers, x_1 subscripts; write pi, theta, deg, +-, <=, >=, !=, ->, * as words/ASCII — they render as real symbols (π θ ° ± ≤ ≥ ≠ → ×). NO LaTeX backslashes, NO $ delimiters. ALWAYS 2-4 steps forming a CHAIN (consecutive lines of the same working, each the previous line after one move) — a card with ONE step is always wrong. note is a <=6-word move name ("subtract 5 from both sides"). rule = the named law/identity this card applies, shown in a panel beside the working: {name: "Product rule of logarithms", formula: "log_b(m) + log_b(n) = log_b(mn)", why: "adding logs multiplies what is inside"} — fill it whenever a named rule is used. The LAST step is stamped as the answer — end on the result.
  · "geometry_diagram" when the beat concerns a SHAPE — slot_geometry {shape, ...}. shape is one of: triangle/right_triangle/rectangle/square/polygon/circle/angle (points?: [{x, y, label}] normalized 0..1 with y UP; side_labels?: one per edge; angle_marks?: [{at, label:"60°", right?:true}]; side_ticks?: equal-side hashes per edge e.g. [1,1,0]; side_arrows?: parallel chevrons per edge; side_squares?: a labelled square erected OUTWARD on an edge, positional per edge with "" for none — ["a^2","b^2","c^2"] stands a named square on every side of a right triangle, which is how an AREA argument is drawn (the figure reframes to fit them); extra_points?: [{on_side, t:0..1, label}] for midpoints; segments?: [{from, to, label?, dashed?}] draws an INTERNAL line between two of the figure's points (each a vertex label/index or an extra_point label) — a cevian, median, diagonal, or the parallel cut of a similar-triangle/intercept proof; circumcircle?: true draws the circle through the first 3 vertices, for an "angle in a semicircle" or a cyclic figure; radius_label/center_label for circles; fill?, highlight_side?); OR area_model (terms: ["a","b"], col_terms?: ["c","d"]) — the subdivided box that PROVES an area identity: ["a","b"] carves a square of side (a+b) into a^2/ab/ab/b^2, the picture of (a+b)^2 = a^2 + 2ab + b^2 (col_terms differing gives a rectangle product (a+b)(c+d)); OR number_line (marks:[{x,label}], segment?:{from,to} for an interval/inequality); OR coordinate_plane (coords:[{x,y,label}] REAL values, line_through?:[i,j] for slope/distance, rise_run?: true draws the dashed slope triangle with labelled Δx/Δy legs — ALWAYS set it on a slope problem); OR fraction_bar (numerator, denominator); OR unit_circle for trigonometry/complex numbers/polar form/rotations (angle_deg in DEGREES swung counter-clockwise from the positive x-axis; angle2_deg = a second angle drawn after it so the beat can SHOW one angle becoming another — n*theta, a rotation, an angle sum — with the first kept in ink to compare against; angle_label/angle2_label like "theta"/"n theta"; point_label names the point, e.g. "cos(theta) + i sin(theta)", or show_coords labels its real (cos, sin) values). Label with the GIVEN values from the problem.
  · "function_plot" when a FUNCTION or curve is discussed (parabola, slope, growth, decay, trig wave) — slot_plot {expression: calculator syntax in x ("x^2 - 4", "sin(x)", "2^x", "1/x"; functions sin cos tan sqrt abs log ln exp, constants pi e), x_min?, x_max?, marks?: [{x, label}] for roots/vertex/intersections, expression2?: a second curve to compare/intersect, tangent_at?: an x to draw the tangent (slope beats), shade?: {from, to} to shade the area under the curve (integrals), heading?, caption?}. Plot the ACTUAL expression from the script.
- NEVER request an uploaded image of an equation, a geometric figure, a diagram, a graph, a chart or a table. The renderer DRAWS all of those natively — math_steps for an equation, geometry_diagram for a figure, function_plot for a curve, animated_chart for data. An asset_request reading "an illustration of a right triangle labelled a, b, c" is always a bug: that is a geometry_diagram. Upload requests are for things a camera photographs — people, places, products, screens, real-world scenes.
- Fill every slot of the chosen template. A slot is either an "image" (describe what to upload in asset_request.description, and pick a camera_move) or a "text_block" (a short heading + 1-5 punchy bullets).
- "needs an asset" = any image/video slot. Always write a clear, specific asset_request.description (e.g. "GTA V map screenshot, top-down") so the user knows exactly what to upload.
- STOCK B-ROLL: for GENERIC atmosphere footage (city skylines, crowds, nature, traffic, hands typing) you may set a video-capable slot to content_type "stock_video" with "query" (2-4 search words) — the system fetches free stock footage automatically, max 3 per video. Specific or named subjects (a particular game, product, person, place) must stay ordinary image/video asset requests.
- PACING: set duration_seconds to match the narration — roughly the spoken length at ~2.5 words/second (a 15-word line ≈ 6s). Give scenes with more bullets a little more time. Keep most scenes between 4 and 10 seconds.
- VARIETY: never use the same layout_template more than twice in a row — alternate to keep a visual rhythm (e.g. split → full_bleed → single_focus). Reach for full_bleed_with_side_panel when explaining part of one image, and full_bleed_with_banner for a hero shot with a title/stat.
- RELATION: give every scene AFTER the first a "relation" saying how it relates to the PREVIOUS scene: "continues" (next step of the same thread — the default), "elaborates" (digs into a detail of it), "consequence" (its direct result), "contrast" (its opposite/counterpart), "callback" (returns to an earlier idea), "new_chapter" (a topic shift, a new act). Scene 1 is "opening". This is the single most important editorial choice you make — the relation decides the cut's motion and sound.
- TRANSITIONS carry meaning — every relation has a SIGNATURE transition that is applied automatically when you leave "transition" out, and leaving it out is usually right: continues→stack_push, elaborates→mask_wipe_circle, consequence→whip_pan, contrast→split_slide, callback→match_dissolve, new_chapter→line_sweep. Only set "transition" yourself when you want to override the signature: "fade" calm beat · "push_left/right/up/down" directional momentum · "wipe"/"wipe_up" list progression · "zoom_through" diving INTO a detail · "zoom_out_in" exhaling OUT · "mask_wipe_diagonal" crisp neutral cut · "column_reveal" analytical/data beat. NEVER the same transition twice in a row; never more than two pushes in a row.
- CAMERA MOVES match the subject: "pan_up_zoom_in" for tall subjects (and most portrait-video shots), "zoom_in_snap" for a reveal the narration lands on, "hover" for diagrams/maps/UI that must stay readable, "ken_burns"/"ken_burns_reverse" alternated across photo sequences, "push_in" for drama. Never repeat one move on consecutive image scenes.
- MOOD: give every scene a "mood" from this list (drives background music): neutral, upbeat, calm, dramatic, tense, inspirational, suspense.
- CALLOUTS: for an image slot where it helps to point at specific parts, optionally add "callout_suggestions": an array of 1-3 very short labels (max ~4 words each, e.g. "Old map border"). You only write the label text — the user will position the pins themselves. Do NOT output coordinates.
- Keep bullets short (max ~6 words each). Keep headings to ~4 words; explanation_box body to 1-2 sentences.
{$aspectGuidance}

OUTPUT — return ONLY a JSON object of this exact shape:
{
  "summary": "one sentence describing the video",
  "pronunciations": [{"term": "a word in the narration a text-to-speech voice would get WRONG — a surname, a place, a brand, a loanword or a piece of jargon", "say": "how to say it, written as plain syllables a reader would follow (\"Nguyen\" -> \"nwin\", \"Xiaomi\" -> \"shao-mee\")"}],
  "scenes": [
    {
      "scene_id": "scene_1",
      "duration_seconds": {$sceneSeconds},
      "narration": { "text": "what the voice says" },
      "layout_template": "split_side_by_side",
      "relation": "continues",
      "slots": {
        "slot_left": {
          "content_type": "image",
          "label": "GTA V",
          "camera_move": "slow_zoom_in",
          "asset_request": { "description": "GTA V world map screenshot" }
        },
        "slot_right": {
          "content_type": "text_block",
          "heading": "Map Facts",
          "bullets": ["~49 square miles", "Single city: Los Santos"]
        }
      }
    }
  ]
}
Target aspect ratio: {$aspectRatio}. Return valid JSON only — no markdown, no commentary.
PROMPT;
    }
}
