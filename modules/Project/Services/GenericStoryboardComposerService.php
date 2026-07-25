<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GenericStoryboardComposerService — L2/L3 for NON-math videos.
 *
 * The skeleton (argument / journey / compare / countdown / generic) already
 * decided the acts; here each phase casts from a MENU its intent allows and
 * one focused call fills the details. The prompt carries only the offered
 * cards' docs — a fraction of the giant call's registry dump — and the
 * structure is not the model's to change: a template outside the phase's
 * menu is degraded to a text scene deterministically.
 *
 * Null on anything thin; the caller falls back to the giant call, so this
 * path can only improve a video, never strand one.
 */
class GenericStoryboardComposerService
{
    /** Per-intent template menus — L2 as data. First entry = degrade target hint. */
    private const MENUS = [
        'hook' => ['single_focus', 'stat_spotlight', 'quote_card', 'full_bleed_with_banner'],
        'context' => ['single_focus', 'full_bleed_with_side_panel', 'labeled_diagram', 'cycle_diagram', 'formula_anatomy', 'term_card', 'layer_stack', 'hierarchy_card'],
        'point' => ['single_focus', 'animated_chart', 'stat_spotlight', 'quote_card', 'myth_fact', 'pictogram_percent', 'cycle_diagram', 'function_plot', 'formula_anatomy', 'spectrum_card', 'quadrant_map', 'venn_card', 'receipt_card', 'proportion_flow', 'scale_comparison', 'evidence_card', 'layer_stack'],
        'counter' => ['single_focus', 'quote_card', 'myth_fact', 'common_mistake'],
        'resolution' => ['single_focus', 'animated_chart', 'pictogram_percent', 'receipt_card', 'proportion_flow', 'evidence_card', 'practice_card'],
        'origin' => ['single_focus', 'map_card', 'full_bleed_with_banner'],
        'era' => ['single_focus', 'timeline_card', 'map_card', 'full_bleed_with_side_panel'],
        'turning_point' => ['single_focus', 'stat_spotlight', 'quote_card', 'before_after', 'myth_fact'],
        'legacy' => ['single_focus', 'big_counter'],
        'contenders' => ['split_side_by_side', 'versus_card'],
        'round' => ['single_focus', 'versus_card', 'before_after', 'split_side_by_side', 'spectrum_card', 'scale_comparison'],
        'verdict' => ['single_focus', 'versus_card', 'checklist_card', 'spectrum_card', 'quadrant_map', 'venn_card', 'decision_tree'],
        'setup' => ['single_focus', 'term_card'],
        'ranking_reveal' => ['list_ranking'],
        'number_one' => ['single_focus', 'full_bleed_with_banner', 'stat_spotlight'],
        'aspect' => ['single_focus', 'split_side_by_side', 'animated_chart', 'icon_grid', 'phone_mockup', 'labeled_diagram', 'cycle_diagram', 'function_plot', 'formula_anatomy', 'math_steps', 'venn_card', 'common_mistake', 'proportion_flow', 'scale_comparison', 'evidence_card', 'layer_stack', 'hierarchy_card'],
        'payoff' => ['single_focus', 'practice_card'],
    ];

    /** One-line content docs, only included when a menu offers the card. */
    private const CARD_DOCS = [
        'single_focus' => 'slot_main: {content_type:"text_block", heading<=40, bullets:[1-4 <=60]} OR {content_type:"image", asset_request:{description: a concrete photographable subject}, camera_move:"slow_zoom_in"}',
        'stat_spotlight' => 'slot_stat: {content_type:"text_block", heading: THE stat itself ("\$4.2 Billion"), bullets:[1 support line]}',
        'quote_card' => 'slot_quote: {content_type:"text_block", heading: the quotation, bullets:["— attribution"]}',
        // NB: the media slot on both full-bleed templates is `slot_background`,
        // NOT `slot_media`. Naming it wrong here is not cosmetic — the model
        // emits the wrong key, the validator drops it as illegal, and the real
        // slot is then back-filled with a generic placeholder that becomes the
        // IMAGE PROMPT verbatim ("Background visual for this scene"). Guarded
        // by scratchpad/carddocs-check.php.
        'full_bleed_with_banner' => 'slot_background: {content_type:"image", asset_request:{description: a concrete photographable subject}}, slot_banner: {content_type:"text_block", heading<=40}',
        'full_bleed_with_side_panel' => 'slot_background: {content_type:"image", asset_request:{description: a concrete photographable subject}}, slot_panel: {content_type:"text_block", heading, bullets:[1-3]}',
        'split_side_by_side' => 'slot_left: {content_type:"image", asset_request:{description}}, slot_right: {content_type:"text_block", heading, bullets:[2-4]}',
        'animated_chart' => 'slot_chart: {content_type:"chart", chart_type:"bar|line|area|donut|pie|scatter|radar", values:[numbers], labels:[same count], unit, highlight_index?, source: WHERE the numbers come from}',
        'big_counter' => 'slot_counter: {content_type:"text_block", heading: the figure ("500 Million"), bullets:[1 line]}. ONLY when the beat states an actual number — no figure in the narration means stat_spotlight or single_focus, never an empty counter',
        'map_card' => 'slot_map: {content_type:"map", pins:[{label,lat,lon} <=2 real approx coords], region?:"world|europe|asia|africa|north_america|south_america|oceania", route?:true}',
        'timeline_card' => 'slot_timeline: {content_type:"timeline_nodes", nodes:[3-6 {date,label}], heading?}. ONLY for real chronology (years, eras, release dates) — every date must be a POINT IN TIME. A list of criteria, factors or features is not a timeline: use icon_grid or checklist_card',
        'before_after' => 'slot_before + slot_after: each {content_type:"image", asset_request:{description}}',
        'versus_card' => 'slot_left + slot_right: image requests; slot_versus: {content_type:"versus", left:{label,stats:[<=3]}, right:{...}, verdict?}',
        'checklist_card' => 'slot_checklist: {content_type:"proscons", pros:[...], cons?:[...], heading?}',
        'list_ranking' => 'slot_ranking: {content_type:"ranking", items:[3-6 strings, BEST LAST], heading?}',
        'icon_grid' => 'slot_icons: {content_type:"icons", items:[3-9 {icon: a lucide name, label: 1-2 words}], heading?}',
        'phone_mockup' => 'slot_screen: {content_type:"image", asset_request:{description: the SCREEN content}, frame:"phone"|"browser"}',
        'labeled_diagram' => 'slot_diagram: {content_type:"image", asset_request:{description: ONE clean centered subject on a plain background, no text}, heading?: <=40 chars, callout_suggestions:[2-4 short part names, <=4 words each]} — use for "how X works"/parts-of-X beats; the labels are drawn natively over the image',
        'myth_fact' => 'slot_myth_fact: {content_type:"myth_fact", myth: the belief as people say it (<=140), fact: the correction with the receipts (<=140), heading?: <=40} — use when the script debunks a widespread belief; never for mere comparisons',
        'pictogram_percent' => 'slot_pictogram: {content_type:"pictogram", filled: the numerator (decimals fine, 6.4), of: the denominator (usually 10), label: who the share is ("of gamers finish the story"), unit?: "%" to display the percentage} — ONLY for shares of PEOPLE; other percentages use progress_meter',
        'cycle_diagram' => 'slot_cycle: {content_type:"cycle", items:[3-6 {label: <=4 words, icon?: a lucide name} IN LOOP ORDER], heading?, caption?: <=60 for the ring centre} — ONLY for a process that REPEATS back to its start (water cycle, habit loop, supply chain); a one-way process is step_flow',
        // The technical cards are GLOBAL: a science/economics beat that pivots
        // on a real equation or curve draws it natively, never as a bullet or
        // an uploaded picture of maths.
        'function_plot' => 'slot_plot: {content_type:"function_plot", expression: calculator syntax in x ("2^x", "exp(-x)", "sin(x)", "1/x^2"), x_min?, x_max?, marks?:[{x,label}], heading?, caption?} — ONLY when the script genuinely discusses a curve, growth, decay or wave; plot the REAL relationship it names, never invent one for decoration',
        'formula_anatomy' => 'slot_formula: {content_type:"formula", formula: the equation in linear notation <=80 chars ("E = m*c^2"), parts: 2-4 of {match: an EXACT substring of formula, label: what that piece means in THIS story <=48 chars}, heading?} — when the beat introduces ONE driving equation; every match must appear character-for-character inside formula; introduce it, never derive it (that is math_steps)',
        'math_steps' => 'slot_math: {content_type:"math_steps", steps: 2-4 of {expr: LINEAR notation <=60 chars (frac{a}{b}, sqrt{...}, x^2), note?: <=6 words naming the move}, heading?} — ONLY when the script actually walks a calculation line by line; a mere mention of a formula is formula_anatomy, not a derivation',
        'decision_tree' => 'slot_decision: {content_type:"decision", question: the FIRST thing you ask <=64, branches:[EXACTLY 2 {label: the answer taking this path <=14, then EITHER outcome: what you do <=40 OR question: one more question <=48 plus its own branches:[exactly 2 {label, outcome <=36}]}], heading?, caption?} — at most TWO levels; both branches must END somewhere. A list of options that do not branch is icon_grid or checklist_card; a linear process is step_flow',
        'receipt_card' => 'slot_receipt: {content_type:"receipt", rows:[2-8 {label <=28, value: a NUMBER, negative for a discount}], total?: OMIT IT — the card sums the rows itself and overwrites a total that disagrees, total_label?: <=20, unit?: <=6 ("$" prints before the number, "hrs" after), heading?, caption?: <=80} — ONLY when the beat breaks a total down into parts that SUM; unrelated figures are animated_chart, one headline number is big_counter',
        'term_card' => 'slot_term: {content_type:"term", term: the word itself <=28, definition: ONE plain sentence a beginner understands <=120 (no jargon inside the definition of jargon), phonetic?: how it sounds <=32 (IPA or respelling), part_of_speech?: noun/verb/adjective, caption?: origin or literal meaning <=80, heading?} — ONLY when the script STOPS to define a word the viewer needs; defining several at once is icon_grid or checklist_card',
        'common_mistake' => 'slot_mistake: {content_type:"mistake", wrong: the line people actually write <=60 (same linear notation as math_steps when it is maths), correct: the SAME move done properly <=60, why?: one plain line naming the error <=100, heading?, caption?} — ONLY for the one error a viewer is likely to make with what was just taught; the two lines must be the same step so the viewer sees exactly what changed. Both lines are CHECKED (pure arithmetic is evaluated) and a card whose labels are the wrong way round is thrown away. A false belief about the world is myth_fact; a list of tips is checklist_card',
        'practice_card' => 'slot_practice: {content_type:"practice", prompt: the problem you are handing the VIEWER <=90 (same linear notation as math_steps when it is maths), answer: the correct result <=40 ("x = 7") — REQUIRED and it is CHECKED: a numeric answer is substituted back into the prompt and the whole card is thrown away if it does not hold, hint?: one nudge that is not the solution <=70, heading?, caption?: <=80} — ONLY for a beat that asks the viewer to solve one themselves and then gives the answer; a problem the narrator works through is math_steps. Write the narration so it POSES the problem, pauses, and only then says the answer',
        'venn_card' => 'slot_venn: {content_type:"venn", sets:[2-3 {label <=20, caption?: <=32 what is unique to it}], overlap_label?: <=28 naming what sits in the middle ("Both", "The sweet spot"), heading?, caption?} — ONLY for genuine overlap (shared traits, common ground, the intersection of requirements). Two things merely compared is versus_card; a flat list of shared traits with no groups is checklist_card',
        'quadrant_map' => 'slot_quadrant: {content_type:"quadrant", x_axis:{left_label, right_label}, y_axis:{bottom_label, top_label} the four poles <=18 chars, quadrant_items:[3-6 {label <=20, x: 0..1 from the LEFT pole, y: 0..1 from the BOTTOM pole — real judgements, spread across the square}], zones?:{top_left, top_right, bottom_left, bottom_right} names for the four boxes <=16 chars ("Quick wins"), highlight_index?: the item the beat is about, heading?, caption?} — for beats that sort by TWO judgements at once (effort vs impact, urgency vs importance); one dimension only is spectrum_card',
        'scale_comparison' => 'slot_scale: {content_type:"scale", scale_items:[2-3 {label <=24, value: a plain NUMBER in ONE shared unit — never pre-scaled, note?: <=40}], unit?: <=8 printed after the numbers ("m", "kg"), shape?: "square"|"circle", highlight_index?: the one the beat is about, heading?, caption?: <=80} — for HOW BIG X is next to something the viewer knows; the shapes are drawn at true relative size from your values, and past a 40x spread the card states the ratio instead of drawing a speck. Parts of one whole are proportion_flow; a measured series is animated_chart',
        'evidence_card' => 'slot_evidence: {content_type:"evidence", source: the study, institution or publication behind the claim <=48 ("The Lancet", "Stanford University", "Pew Research") — it MUST NAME someone, finding: the one thing it found in plain words <=160, year?: <=12 ("2021"), sample?: what it looked at <=40 ("12,000 adults", "n = 500"), heading?, caption?: a caveat <=80} — ONLY when the beat leans on a SPECIFIC named source for its authority ("according to a 2021 Lancet study…"). It is the constructive counterpart of the no_source warning: with no nameable source the card refuses and becomes plain text rather than dress "studies show" up as a citation. A number that stands alone is big_counter; a myth being corrected is myth_fact',
        'proportion_flow' => 'slot_proportion: {content_type:"proportion", source_label: the whole being divided <=24 ("Every tax dollar"), branches:[2-5 {label <=24, value: a plain NUMBER — its SIZE, never a percentage, note?: <=40}], unit?: <=6 ("$" prints before, "hrs" after), total?: ONLY when the branches do not cover the whole (the leftover is drawn as "Other"), highlight_index?: the branch the beat is about, heading?, caption?: <=80} — for ONE whole splitting into its parts (where the money goes, how the day is spent); the shares are COMPUTED from the values, so never send percentages. A running list that merely sums to a total is receipt_card; unrelated quantities are animated_chart',
        'spectrum_card' => 'slot_spectrum: {content_type:"spectrum", axis:{left_label, right_label} the two poles <=18 chars, spectrum_items:[2-5 {label <=20, position: 0..1 from the LEFT pole — commit to real judgements, spread them}], highlight_index?: the item the question is about, heading?, caption?} — for WHERE-does-X-sit beats (pricing, risk, difficulty); never for time or measured series',
        'layer_stack' => 'slot_layers: {content_type:"layers", layers:[3-6 {label <=24, caption?: <=44 what that layer does} listed TOP FIRST in their REAL stacking order — the order is the content, repeated labels are fine], highlight_index?: the one layer this beat is about, heading?, caption?} — ONLY when things genuinely sit ON TOP of each other (atmosphere, network stack, soil, what\'s inside a battery); a list with no vertical structure is checklist_card or icon_grid; a repeating loop is cycle_diagram',
        'hierarchy_card' => 'slot_hierarchy: {content_type:"hierarchy", root: the one thing at the top <=28 ("The UN", "A cell"), children:[2-4 {label: a branch under the root <=22, caption?: <=40 what it is or does, children?:[2-4 {label <=18}] for a SECOND level of sub-parts under that branch}], highlight_index?: the top-level branch this beat is about, heading?, caption?} — for HOW SOMETHING IS STRUCTURED, drawn as an org chart (org structure, parts-of-parts of a system, a taxonomy); at most two levels below the root. A yes/no path the viewer walks is decision_tree; things stacked ON TOP of each other are layer_stack; a flat list that does not branch is icon_grid or checklist_card',
    ];

    private ?string $apiKey;
    private string $model;
    private int $attempts = 0;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = LlmModels::for('explainer');
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /** The menu an intent casts from (used by tests + degrade). */
    public static function menuFor(string $intent): array
    {
        return self::MENUS[$intent] ?? ['single_focus'];
    }

    /** Compose a non-math storyboard from its skeleton. Null -> fallback. */
    public function compose(string $script, array $skeleton): ?array
    {
        if (empty($this->apiKey) || trim($script) === '' || count($skeleton) < 3) {
            return null;
        }

        $offered = [];
        $phaseLines = '';
        foreach (array_values($skeleton) as $i => $p) {
            $intent = (string) ($p['intent'] ?? '');
            $menu = self::menuFor($intent);
            $offered = array_merge($offered, $menu);
            $phaseLines .= '  ' . ($i + 1) . '. ' . strtoupper($intent)
                . ' — ' . (string) ($p['brief'] ?? '')
                . ' | choose layout_template from: ' . implode(' | ', $menu) . "\n";
        }
        $docs = '';
        foreach (array_unique($offered) as $tpl) {
            if (isset(self::CARD_DOCS[$tpl])) {
                $docs .= "  {$tpl}: " . self::CARD_DOCS[$tpl] . "\n";
            }
        }

        $system = <<<PROMPT
You fill a PRE-DECIDED storyboard for a short explainer video. The phases and their allowed cards are fixed — you choose within each menu and write the content. Return ONLY JSON:

{"scenes": [{"phase": <1-based phase number>, "layout_template": "<from THAT phase's menu>", "narration": "2-3 spoken sentences", "slots": {<the template's slots, shapes below>}}]}

The phases, in order (one scene per phase):
{$phaseLines}
Card content shapes:
{$docs}
Rules:
- Exactly one scene per phase, same order. Never use a template a phase does not offer.
- Every image asset_request.description is a concrete photographable subject — never text, charts, diagrams or screenshots of data.
- Chart values are REAL numbers from the script with a source; never invent statistics.
- Narration flows scene to scene like one voice telling one story.
PROMPT;

        $this->attempts++;
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "SCRIPT / TOPIC:\n" . mb_substr(trim($script), 0, 2600)],
                    ],
                    'temperature' => 0.4,
                    'max_tokens' => 2600,
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (\Throwable $e) {
            Log::info('GenericStoryboardComposer: request failed', ['error' => $e->getMessage()]);
            return null;
        }
        if (!$response->successful()) {
            return null;
        }
        CostTracker::recordChat($this->model, $response->json('usage'), 'tree_generic');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        if (!is_array($parsed) || !is_array($parsed['scenes'] ?? null)) {
            return null;
        }

        $scenes = [];
        $n = 0;
        foreach (array_values($skeleton) as $i => $p) {
            $intent = (string) ($p['intent'] ?? '');
            $menu = self::menuFor($intent);
            // Find the model's scene for this phase (by index, tolerant of
            // its own numbering).
            $cand = null;
            foreach ($parsed['scenes'] as $s) {
                if (is_array($s) && (int) ($s['phase'] ?? 0) === $i + 1) {
                    $cand = $s;
                    break;
                }
            }
            $cand = $cand ?? (is_array($parsed['scenes'][$i] ?? null) ? $parsed['scenes'][$i] : null);
            if ($cand === null) {
                continue;
            }

            $tpl = (string) ($cand['layout_template'] ?? '');
            $slots = is_array($cand['slots'] ?? null) ? $cand['slots'] : [];
            if (!in_array($tpl, $menu, true)) {
                // L2 enforcement: outside the menu -> a text scene, never
                // the wrong card.
                $tpl = 'single_focus';
                $slots = ['slot_main' => ['content_type' => 'text_block', 'heading' => '', 'bullets' => []]];
            }

            $n++;
            $scenes[] = [
                'scene_id' => "scene_{$n}",
                'order' => $n,
                'layout_template' => $tpl,
                'duration_seconds' => 9,
                'narration' => ['text' => trim((string) ($cand['narration'] ?? '')) ?: (string) ($p['brief'] ?? '')],
                'mood' => 'neutral',
                'slots' => $slots,
            ];
        }

        if (count($scenes) < max(3, (int) ceil(count($skeleton) * 0.6))) {
            return null; // the model skipped too much — giant call does better
        }

        Log::info('GenericStoryboardComposer: storyboard composed by the tree', [
            'scenes' => count($scenes),
            'attempts' => $this->attempts,
        ]);

        return ['scenes' => $scenes, 'summary' => ''];
    }
}
