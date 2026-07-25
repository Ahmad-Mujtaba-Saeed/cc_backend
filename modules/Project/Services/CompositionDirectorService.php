<?php

namespace Modules\Project\Services;

use Modules\Project\Support\LlmModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Support\CanvasPlanValidator;
use Modules\Project\Support\ChapterPlanValidator;
use Modules\Project\Support\ExplainerRegistry;

/**
 * CompositionDirectorService — the editor-in-chief pass for hybrid videos.
 *
 * Where ScriptAnalysisService decides WHAT each scene contains and
 * CanvasDirectorService choreographs one canvas world, this cheap pass
 * decides the video's macro rhythm: it groups the scenes into consecutive
 * CHAPTERS and picks each chapter's presentation system — "canvas" (one
 * continuous camera journey, for connected runs of ideas) or "slides"
 * (hard cuts, for hooks, montages and big standalone statements).
 *
 * For every canvas chapter it then runs the existing CanvasDirectorService
 * on just that chapter's scenes, embedding a per-chapter world plan — so a
 * bad plan for one chapter degrades that chapter alone. Any failure of the
 * grouping pass itself falls back to one canvas chapter over everything,
 * which is exactly the pre-hybrid behaviour. This pass can improve the
 * video but can never break it.
 */
class CompositionDirectorService
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
     * @return array  A GUARANTEED-legal chapter plan with embedded canvas plans.
     */
    public function direct(array $scenes, string $aspectRatio): array
    {
        $validator = new ChapterPlanValidator();
        $sceneIds = array_map(fn ($s) => (string) $s['scene_id'], $scenes);
        $byId = [];
        foreach ($scenes as $scene) {
            $byId[(string) $scene['scene_id']] = $scene;
        }

        try {
            $raw = $this->requestChapters($scenes);
            $result = $validator->validate($raw, $sceneIds);
            if (!empty($result['warnings'])) {
                Log::info('CompositionDirectorService: validator adjustments', ['warnings' => $result['warnings']]);
            }
            $plan = $result['plan'];
        } catch (\Throwable $e) {
            Log::warning('CompositionDirectorService: grouping pass failed -> single canvas chapter', [
                'error' => $e->getMessage(),
            ]);
            $plan = $validator->fallbackPlan($sceneIds);
        }

        // Choreograph each canvas chapter as its own small world. The canvas
        // director is guaranteed-legal internally (validator + deterministic
        // fallback), and running it per-chapter isolates any weak plan.
        foreach ($plan['chapters'] as &$chapter) {
            if (($chapter['mode'] ?? '') !== 'canvas') {
                $chapter['canvas'] = null;
                continue;
            }
            $chapterScenes = array_values(array_filter(array_map(
                fn ($sid) => $byId[$sid] ?? null,
                $chapter['scene_ids']
            )));
            try {
                $chapter['canvas'] = (new CanvasDirectorService())->direct($chapterScenes, $aspectRatio);
            } catch (\Throwable $e) {
                Log::warning('CompositionDirectorService: canvas pass failed for a chapter -> fallback layout', [
                    'chapter' => $chapter['id'] ?? '?',
                    'error' => $e->getMessage(),
                ]);
                $chapter['canvas'] = (new CanvasPlanValidator())->fallbackPlan($chapter['scene_ids'], $aspectRatio);
            }
        }
        unset($chapter);

        return $plan;
    }

    // -------------------------------------------------------------------------

    private function requestChapters(array $scenes): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                    ['role' => 'user', 'content' => $this->buildScenesBrief($scenes)],
                ],
                'temperature' => 0.6,
                'max_tokens' => 1200,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            throw new \Exception("Composition director request failed (HTTP {$response->status()})");
        }

        CostTracker::recordChat($this->model, $response->json('usage'), 'composition_director');

        $parsed = json_decode((string) $response->json('choices.0.message.content'), true);
        if (!is_array($parsed)) {
            throw new \Exception('Composition director returned malformed JSON');
        }

        return $parsed;
    }

    private function buildSystemPrompt(): string
    {
        $chapters = ExplainerRegistry::chapters();
        $maxChapters = ExplainerRegistry::maxChapters();
        $minCanvas = ExplainerRegistry::minCanvasChapterScenes();
        $descriptions = $chapters['mode_descriptions'] ?? [];
        $canvasDesc = $descriptions['canvas'] ?? 'one continuous camera journey across connected ideas';
        $slidesDesc = $descriptions['slides'] ?? 'hard scene-to-scene cuts for punchy standalone beats';
        $transitions = implode(', ', array_filter(
            ExplainerRegistry::transitions(),
            fn ($t) => $t !== 'none'
        ));

        return <<<PROMPT
You are the editor-in-chief of an explainer video that can mix TWO presentation systems inside one video:
- "canvas": {$canvasDesc}
- "slides": {$slidesDesc}

Group the scenes below into 1-{$maxChapters} CONSECUTIVE chapters and pick each chapter's system.

RULES:
- Every scene id exactly once, in the given order — chapters are contiguous runs, never reordered.
- A "canvas" chapter needs at least {$minCanvas} scenes that GENUINELY connect (a chain of reasoning, cause-and-effect, a tour). If the ideas don't flow into each other, they are slides.
- Don't chapter for its own sake: a video that is one connected argument is ONE canvas chapter. Only break where the storytelling mode truly changes.
- Opening hooks and closing payoffs work well as "slides" chapters when they are punchy standalone beats.
- For each chapter after the first, pick "transition_in" from: {$transitions}. The cut INTO a new chapter should feel bigger than an ordinary scene cut — "zoom_through" or "whip_pan" for energy, "fade" for a calm act break, "zoom_out_in" for stepping back to a new topic.

Return ONLY JSON of this exact shape:
{"chapters": [
  {"id": "ch_1", "mode": "slides", "scene_ids": ["scene_1", "scene_2"], "transition_in": "none", "reason": "hook"},
  {"id": "ch_2", "mode": "canvas", "scene_ids": ["scene_3", "scene_4", "scene_5"], "transition_in": "zoom_through", "reason": "the argument"}
]}
"reason" is <= 6 words. Return valid JSON only — no markdown, no commentary.
PROMPT;
    }

    /**
     * Same compact one-line-per-scene brief the canvas director reads.
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
