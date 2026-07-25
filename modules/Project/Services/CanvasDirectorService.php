<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Support\CanvasPlanValidator;
use Modules\Project\Support\ExplainerRegistry;

/**
 * CanvasDirectorService
 *
 * The second, agentic LLM pass of the explainer pipeline. Where
 * {@see ScriptAnalysisService} decides WHAT each scene contains, this pass
 * decides WHERE everything lives: it arranges every scene as a framed
 * "station" card on one large 2D world canvas and shapes the journey the
 * virtual camera will fly (Prezi-style: zoom into station 1, pull back,
 * follow the connector arrow to station 2, push in, ...).
 *
 * The raw plan is always run through {@see CanvasPlanValidator}, and any
 * failure falls back to a deterministic layout — this pass can improve the
 * video but can never break it.
 */
class CanvasDirectorService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        if (empty($this->apiKey)) {
            throw new \Exception('OPENAI_API_KEY is not set');
        }
        $this->model = LlmModels::for('director');
    }

    /**
     * @param  array<int, array>  $scenes  Validated scenes from ShotListValidator.
     * @param  string  $aspectRatio  '16:9' | '9:16' | '1:1'
     * @return array  A GUARANTEED-legal canvas plan (validated, or fallback).
     */
    public function direct(array $scenes, string $aspectRatio): array
    {
        $validator = new CanvasPlanValidator();
        $sceneIds = array_map(fn ($s) => (string) $s['scene_id'], $scenes);

        try {
            $raw = $this->requestPlan($scenes, $aspectRatio);
            $result = $validator->validate($raw, $scenes, $aspectRatio);

            if (!empty($result['warnings'])) {
                Log::info('CanvasDirectorService: validator adjustments', ['warnings' => $result['warnings']]);
            }

            return $result['plan'];
        } catch (\Throwable $e) {
            Log::warning('CanvasDirectorService: director pass failed -> deterministic layout', [
                'error' => $e->getMessage(),
            ]);
            return $validator->fallbackPlan($sceneIds, $aspectRatio);
        }
    }

    private function requestPlan(array $scenes, string $aspectRatio): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(90)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->buildSystemPrompt($aspectRatio)],
                    ['role' => 'user', 'content' => $this->buildScenesBrief($scenes)],
                ],
                'temperature' => 0.75,
                // Long scripts (15-20 scenes) need room: items + props + connectors
                // truncate badly at 2500 and a truncated plan means fallback layout.
                'max_tokens' => 7000,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Canvas director request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'canvas_director');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        if (!is_array($parsed)) {
            throw new \Exception('Canvas director returned malformed JSON');
        }

        return $parsed;
    }

    private function buildSystemPrompt(string $aspectRatio): string
    {
        $canvas = ExplainerRegistry::canvas();
        $base = ExplainerRegistry::canvasBaseCard($aspectRatio);
        $patterns = implode(', ', $canvas['journey_patterns'] ?? ['zigzag']);
        $holdMoves = implode(', ', $canvas['hold_moves'] ?? ['breathe']);
        $minGap = (float) ($canvas['min_gap'] ?? 160);
        $orient = $aspectRatio === '9:16' ? 'PORTRAIT (tall)' : ($aspectRatio === '1:1' ? 'SQUARE' : 'LANDSCAPE (wide)');
        $propAnims = implode(', ', ExplainerRegistry::propAnimations());
        $maxProps = ExplainerRegistry::maxPropsPerScene();

        // With props capped at 0 (the flat design's default) the whole section
        // is dead weight — and worse, it used to read "0–0 per scene ... most
        // scenes should have at least one", which is a contradiction the model
        // resolves by inventing props the validator then throws away.
        $propsSection = $maxProps > 0
            ? "PROPS (small AI-generated decorations, 0–{$maxProps} per scene): generic, reusable objects — never scene-specific content. Good: \"glowing compass rose\", \"paper plane\", \"stack of gold coins\", \"magnifying glass\", \"rocket doodle\", \"chess pawn\". Bad: anything that repeats what the scene's own media shows. Each prop: prompt (a single isolated object), animation from: {$propAnims} (\"pop_spring\"/\"draw_in\" enter with energy, \"float\"/\"pulse\"/\"orbit\"/\"drift\" idle calmly), position x/y in region space (0..1 inside; slightly outside like -0.1 or 1.1 scatters it beside the region — prefer edges/corners, never the center where it covers content), size 0.08–0.3 (fraction of the region). Text-only scenes deserve 2–3 props; media-heavy scenes 0–1. Most scenes should have at least one.\n"
            : "PROPS: this video uses none. Never emit a \"props\" key.\n";
        $propsExample = $maxProps > 0
            ? ',
      "props": [ { "prompt": "glowing compass rose", "animation": "float", "x": 1.08, "y": 0.12, "size": 0.15 } ]'
            : '';

        $treatmentLines = [];
        foreach (ExplainerRegistry::treatments() as $name => $description) {
            $treatmentLines[] = "- \"{$name}\": {$description}";
        }
        $treatmentRef = implode("\n", $treatmentLines);

        $relationLines = [];
        foreach (ExplainerRegistry::relations() as $name => $description) {
            $relationLines[] = "- \"{$name}\": {$description}";
        }
        $relationRef = implode("\n", $relationLines);
        $maxArrows = ExplainerRegistry::maxArrows();

        return <<<PROMPT
You are a cinematic motion director for a "journey on a canvas" explainer video (think Prezi meets a kinetic documentary). Every scene is a frameless composition region living on ONE huge 2D world canvas — full-bleed visuals and floating text, NO cards, NO frames, NO rotation. A virtual camera flies, dives and pulls between the regions. You never write scene content — you direct the EDIT: how each scene relates to the story so far, and how the camera expresses that relation.

THINK RELATION-FIRST. For every scene, before anything else, decide its RELATION to the story so far by actually reading the narration:
{$relationRef}
The relation is the meaning of the cut. Everything else follows from it:
- "opening"      -> treatment hero_open, no connector.
- "continues"    -> treatment canvas_hop, dotted connector, placed a comfortable hop away in the journey's flow direction.
- "elaborates"   -> treatment zoom_nest (give nest.fx/fy: the exact spot in the previous visual being examined), no connector — the dive IS the link.
- "consequence"  -> treatment canvas_hop, connector style "arrow" (the camera literally rides the drawn arrow), placed FURTHER along the flow so the arrow reads. A ≤3 word label like "so", "the result", "2 years later" is welcome.
- "contrast"     -> treatment canvas_hop, placed BESIDE the previous scene (same scale, 1.4-1.8 widths away, mirrored across the flow axis) so both share the frame mid-flight. Dotted connector; a label like "vs" is welcome.
- "callback"     -> treatment canvas_hop + "callback_to": "<scene_id of the earlier scene it returns to>". Long soaring flight back across the map.
- "new_chapter"  -> treatment pull_reveal, no connector, placed FAR away (3-4 widths) with a direction change — a new act deserves a new part of the map.
You may override the derived treatment when a scene genuinely earns it — at most twice each per video:
- overlay_focus: one strong visual deserves a guided tour — the camera lands, pushes INTO the image's focal point, and eases back out. Perfect with callouts.
- kinetic_break: a punchy stat or turning point should SMASH in — the flight is short and hard, landing like a cut with an impact hit. Best on a typography-only scene.

TREATMENTS reference:
{$treatmentRef}

HARD VARIETY RULES (a monotone journey is a failed job):
- Never the same treatment more than twice in a row. Never more than three "continues" in a row — real stories turn.
- The journey must BEND: never place more than three consecutive scenes along the same straight direction (no long rows, no long columns). Curve the path, switch direction at chapter breaks, use vertical AND horizontal space.
- Vary hop distances: intimate steps (1.2 widths) for tightly-linked ideas, long expressive flights (3+) for shifts.
- At most {$maxArrows} "arrow" connectors per video — an arrow must mean causality. Dotted is the quiet default; "none" for dives and chapter breaks.
- Use at least one zoom_nest when ANY scene examines a detail of another, and at least one pull_reveal or new_chapter beat in videos over 6 scenes.

COORDINATE SYSTEM:
- World units are arbitrary pixels. x/y are the CENTER of each region. Layout is normalised afterwards.
- The viewport is {$orient} ({$aspectRatio}). Suggested region size: w={$base['w']}, h={$base['h']} (stay within ±35%; heroes up to 1.3x).
- Top-level regions must NEVER overlap: keep at least {$minGap} units of clear gap. Consecutive regions sit 1.2–2.5 widths apart (more for new_chapter).
- zoom_nest scenes need NO x/y/w/h — give "nest": {"fx": 0..1, "fy": 0..1}, the focal point INSIDE THE PREVIOUS scene's visual where this scene will live tiny until the camera dives in.
- Choose ONE journey_pattern from: {$patterns} — then let chapters bend it.

{$propsSection}
OTHER CHOICES:
- "emphasis": "hero" for the single most important scene (max 2).
- hold_move from: {$holdMoves} — push_in dramatic · drift scenic · breathe factual · orbit playful/curious · rise aspirational · sway wide/panoramic visuals · settle_back big statements that need air around them. Never the same hold_move twice in a row.

OUTPUT — return ONLY a JSON object of this exact shape (one item per scene, EVERY scene_id you were given, in order):
{
  "journey_pattern": "arc",
  "world": { "width": 9000, "height": 6000 },
  "items": [
    { "scene_id": "scene_1", "relation": "opening", "treatment": "hero_open", "x": 1200, "y": 2600, "w": {$base['w']}, "h": {$base['h']}, "emphasis": "hero", "hold_move": "push_in"{$propsExample} },
    { "scene_id": "scene_2", "relation": "elaborates", "treatment": "zoom_nest", "nest": { "fx": 0.62, "fy": 0.35 }, "hold_move": "breathe" },
    { "scene_id": "scene_3", "relation": "consequence", "treatment": "canvas_hop", "x": 4400, "y": 1800, "w": {$base['w']}, "h": {$base['h']}, "hold_move": "drift" },
    { "scene_id": "scene_4", "relation": "callback", "callback_to": "scene_1", "treatment": "canvas_hop", "x": 2300, "y": 4300, "w": {$base['w']}, "h": {$base['h']}, "hold_move": "breathe" }
  ],
  "connectors": [
    { "from": "scene_1", "to": "scene_2", "style": "none", "label": "" },
    { "from": "scene_2", "to": "scene_3", "style": "arrow", "label": "the result" },
    { "from": "scene_1", "to": "scene_4", "style": "dotted", "label": "remember?" }
  ]
}
Return valid JSON only — no markdown, no commentary.
PROMPT;
    }

    /**
     * Compact one-line-per-scene brief so the director knows what each station
     * shows without seeing full slot payloads.
     */
    private function buildScenesBrief(array $scenes): string
    {
        $lines = ['SCENES (in narrative order):'];

        foreach ($scenes as $scene) {
            $kinds = [];
            foreach (($scene['slots'] ?? []) as $slot) {
                $type = $slot['content_type'] ?? '?';
                $hint = $type === 'image' || $type === 'video'
                    ? ($slot['asset_request']['description'] ?? $slot['label'] ?? '')
                    : ($slot['heading'] ?? '');
                $kinds[] = $type . ($hint !== '' ? ' (' . mb_substr((string) $hint, 0, 60) . ')' : '');
            }

            $lines[] = sprintf(
                '- %s | mood: %s | narration: "%s" | content: %s',
                $scene['scene_id'],
                $scene['mood'] ?? 'neutral',
                mb_substr(trim((string) ($scene['narration']['text'] ?? '')), 0, 140),
                implode('; ', $kinds)
            );
        }

        return implode("\n", $lines);
    }
}
