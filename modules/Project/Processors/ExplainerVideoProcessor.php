<?php

namespace Modules\Project\Processors;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Services\ImageGenerationService;
use Modules\Project\Services\PropsLibraryService;
use Modules\Project\Services\PunchlineService;
use Modules\Project\Services\RemotionRenderService;
use Modules\Project\Services\SceneStyleService;
use Modules\Project\Services\TTSGenerationService;
use Modules\Project\Services\VlmLabelPlacementService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ExplainerSceneAssembler;
use Modules\Project\Support\TtsVoices;

/**
 * ExplainerVideoProcessor
 *
 * Render phase of the ai_explainer_video template. By the time this runs the
 * storyboard already exists (created by {@see \Modules\Project\Jobs\AnalyzeExplainerScriptJob})
 * and the user has uploaded every required slot asset. This processor simply:
 *   1. assembles the validated scenes with their uploaded asset references,
 *   2. hands them to the Node/Remotion service to produce the MP4,
 *   3. extracts a thumbnail and marks the project complete.
 *
 * It plugs into the existing ProcessVideoJob via TemplateProcessorFactory, so
 * it inherits resume/progress/Pusher behaviour from AbstractVideoProcessor.
 */
class ExplainerVideoProcessor extends AbstractVideoProcessor
{
    public function process(): bool
    {
        try {
            // Best-effort quality gate (§12.1): re-lint the storyboard as it
            // will actually render (the user may have edited scenes since
            // analyze) and refresh settings['lint_report'].
            $this->runProcessingStep(
                'lint_storyboard',
                fn () => $this->lintStoryboard(),
                8,
                'Checking storyboard quality',
                'Storyboard checked',
                'Storyboard check skipped'
            );

            // Best-effort: generate narration (fal Chatterbox TTS) and pace each
            // scene to its real audio length. Never fails the render.
            $this->runProcessingStep(
                'generate_narration',
                fn () => $this->generateNarration(),
                10,
                'Generating narration',
                'Narration ready',
                'Narration skipped'
            );

            // Best-effort: the scene stylist — every scene gets a presentation
            // personality (card variant, kicker, heading highlights) and a
            // content-true illustration brief. Never fails the render.
            $this->runProcessingStep(
                'style_scenes',
                fn () => $this->generateSceneStyles(),
                12,
                'Styling scenes',
                'Scene styles ready',
                'Scene styles skipped'
            );

            // Best-effort: pick narration punchlines (short verbatim phrases
            // that pop on screen word-synced to the voice). Never fails.
            $this->runProcessingStep(
                'generate_punchlines',
                fn () => $this->generatePunchlines(),
                13,
                'Picking punchlines',
                'Punchlines ready',
                'Punchlines skipped'
            );

            // Best-effort: give media-less scenes an AI visual. Canvas mode
            // gets crisp illustrations composed NEXT to the copy (ambient
            // blurs are never shown there); slides mode keeps its soft
            // ambient backgrounds. Never fails the render.
            $this->runProcessingStep(
                'generate_ambient',
                fn () => $this->generateSceneVisuals(),
                15,
                'Generating scene visuals',
                'Scene visuals ready',
                'Scene visuals skipped'
            );

            // Best-effort: auto visuals (math videos default on) — every image
            // slot still missing an upload gets a flat-vector AI illustration
            // in the video's own three colours, stored under the REAL slot key
            // so assembly treats it exactly like an upload. Never fails.
            $this->runProcessingStep(
                'fill_missing_media',
                fn () => $this->fillMissingMediaSlots(),
                17,
                'Illustrating image slots',
                'Slot illustrations ready',
                'Slot illustrations skipped'
            );

            // Best-effort: labeled_diagram pins — one vision look at each
            // diagram image places the planner's part labels as real (x, y)
            // callouts. No placement = the card's legend fallback. Never
            // fails the render.
            $this->runProcessingStep(
                'place_diagram_labels',
                fn () => $this->placeDiagramLabels(),
                18,
                'Placing diagram labels',
                'Diagram labels placed',
                'Diagram labels skipped'
            );

            // Best-effort: scenario-diagram sprites — a cut-out car instead of
            // an icon in a box. Generated flat on white, then BiRefNet strips
            // the background to a true alpha PNG so the object sits ON the
            // diagram. Icon fallback on any failure. Never fails the render.
            $this->runProcessingStep(
                'generate_scenario_sprites',
                fn () => $this->generateScenarioSprites(),
                18,
                'Drawing scenario objects',
                'Scenario objects ready',
                'Scenario objects skipped'
            );

            // Best-effort: resolve the canvas plan's decorative props from the
            // shared library (generating any that don't exist yet).
            $this->runProcessingStep(
                'generate_props',
                fn () => $this->resolveCanvasProps(),
                18,
                'Preparing canvas props',
                'Canvas props ready',
                'Canvas props skipped'
            );

            // Best-effort: fetch free stock b-roll (§8) for slots the planner
            // marked stock_video. Never fails the render — an unfetched slot
            // just shows its reserved-slot placeholder.
            $this->runProcessingStep(
                'fetch_stock',
                fn () => $this->fetchStockFootage(),
                19,
                'Fetching stock b-roll',
                'Stock b-roll ready',
                'Stock b-roll skipped'
            );

            $scenes = $this->runProcessingStep(
                'assemble_storyboard',
                fn () => $this->assembleScenes(),
                20,
                'Assembling storyboard',
                'Storyboard assembled',
                'Failed to assemble storyboard'
            );
            if ($scenes === false) {
                return false;
            }

            $rendered = $this->runProcessingStep(
                'render_video',
                fn () => $this->renderVideo($scenes),
                85,
                'Rendering video',
                'Video rendered',
                'Video rendering failed'
            );
            if ($rendered === false) {
                return false;
            }

            // Best-effort: normalise the master to -14 LUFS / -1.5 dBTP so the
            // video holds its own on platforms that loudness-normalise. Never
            // fails the render — a quiet mix still ships.
            $this->runProcessingStep(
                'master_audio',
                fn () => $this->masterAudio(),
                92,
                'Mastering audio',
                'Audio mastered',
                'Audio mastering skipped'
            );

            // Best-effort quality gate (§12.4): one vision look at 6 sampled
            // frames; a flagged AI asset is regenerated ONCE and the video
            // re-rendered. Never fails the pipeline.
            $this->runProcessingStep(
                'vlm_review',
                fn () => $this->vlmReview(),
                94,
                'Reviewing frames',
                'Frames reviewed',
                'Frame review skipped'
            );

            // Best-effort: extra aspect renders (§10.6, default off) — the
            // same storyboard at the two other frame shapes, shipped via the
            // output_videos manifest.
            $this->runProcessingStep(
                'aspect_variants',
                fn () => $this->renderAspectVariants(),
                96,
                'Rendering aspect variants',
                'Aspect variants ready',
                'Aspect variants skipped'
            );

            // Best-effort: SRT caption export from the narration word timings
            // (§10.7), written next to the MP4.
            $this->runProcessingStep(
                'export_captions',
                fn () => $this->exportCaptions(),
                97,
                'Exporting captions',
                'Captions exported',
                'Caption export skipped'
            );

            // Best-effort: the paste-ready YouTube kit (description, chapter
            // timestamps, hashtags) written next to the MP4.
            $this->runProcessingStep(
                'export_packaging',
                fn () => $this->exportPackaging(),
                98,
                'Writing the YouTube kit',
                'YouTube kit written',
                'YouTube kit skipped'
            );

            // Best-effort: the designed thumbnail still (§10.5); the ffmpeg
            // frame grab stays as the fallback inside.
            $this->runProcessingStep(
                'generate_thumbnail',
                fn () => $this->generateDesignedThumbnail(),
                98,
                'Generating thumbnail',
                'Thumbnail ready',
                'Thumbnail skipped'
            );

            $this->handleSuccess();
            return true;
        } catch (\Throwable $e) {
            Log::error('ExplainerVideoProcessor failed: ' . $e->getMessage(), [
                'project_id' => $this->project->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->handleFailure($e->getMessage());
            return false;
        }
    }

    /**
     * Best-effort pre-render lint pass (§12.1): the storyboard the user is
     * about to pay a render for gets one more audit, so the report on the
     * project page always reflects what actually rendered. Non-fatal.
     */
    protected function lintStoryboard(): bool
    {
        try {
            $settings = $this->project->settings ?? [];
            $scenes = $this->project->explainerScenes()->orderBy('order')->get()
                ->map(fn ($s) => [
                    'scene_id' => (string) $s->scene_id,
                    'duration_seconds' => (float) $s->duration_seconds,
                    'narration' => ['text' => (string) $s->narration],
                    'layout_template' => (string) $s->layout_template,
                    'slots' => $s->slots ?? [],
                ])->all();
            if (empty($scenes)) {
                return true;
            }

            $settings['lint_report'] = \Modules\Project\Support\SceneBudgetLinter::lint($scenes, [
                'theme' => ExplainerRegistry::colorScheme($settings['color_scheme'] ?? null),
                'hook_enabled' => $settings['hook_enabled'] ?? true,
                'outro_enabled' => $settings['outro_enabled'] ?? true,
            ]);
            $this->project->update(['settings' => $settings]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: lint pass failed (non-fatal): ' . $e->getMessage());

            return true;
        }
    }

    /**
     * Load scenes from the storyboard and inject uploaded asset references.
     * Fails loudly if any required image slot is still missing its upload.
     *
     * @return array<int, array>|false
     */
    protected function assembleScenes(): array|false
    {
        if ($this->project->explainerScenes()->count() === 0) {
            $this->project->update(['error_message' => 'No storyboard scenes to render. Run analysis first.']);
            return false;
        }

        // Shared with the storyboard's live preview (ExplainerPreviewService)
        // so both draw the same scenes; the render is the STRICT reader of the
        // result — a missing required upload stops it here.
        $assembled = ExplainerSceneAssembler::assemble($this->project, $this->autoVisualsEnabled());

        if (!empty($assembled['missing'])) {
            $this->project->update([
                'error_message' => 'Missing uploads for: ' . implode(', ', $assembled['missing']),
            ]);
            return false;
        }

        return $assembled['scenes'];
    }

    /**
     * @param  array<int, array>  $scenes
     */
    protected function renderVideo(array $scenes): bool
    {
        $service = new RemotionRenderService();

        if (!$service->isAvailable()) {
            $this->project->update(['error_message' => 'Remotion render service is not reachable.']);
            return false;
        }

        $result = $service->render($this->project, $scenes, $this->outputPath);

        if (!($result['success'] ?? false)) {
            $this->project->update(['error_message' => $result['error'] ?? 'Render failed']);
            return false;
        }

        // Stamp the look this MP4 was rendered with, so the storyboard can
        // tell whether the video on screen still matches the current settings
        // (a later scheme/font/skin change makes it stale and brings the live
        // preview back to the top).
        $settings = $this->project->settings ?? [];
        $settings['rendered_look'] = \Modules\Project\Services\ExplainerPreviewService::lookHash($this->project);

        $this->project->update([
            'output_path' => $result['output_path'] ?? $this->outputPath,
            'settings' => $settings,
        ]);

        return true;
    }

    /**
     * Best-effort: synthesize narration for each scene with fal Chatterbox TTS,
     * cache it (keyed by a hash of the narration text), and pace the scene to
     * the real audio length so the video matches the voiceover. Any failure is
     * non-fatal — the scene simply renders without audio at its paced length.
     */
    protected function generateNarration(): bool
    {
        $settings = $this->project->settings ?? [];
        if (($settings['narration_enabled'] ?? true) === false) {
            return true;
        }

        // Narration engine is admin-switchable: self-hosted Kokoro (default,
        // free) or OpenAI gpt-4o-mini-tts (styled delivery). Routed centrally.
        $tts = new TTSGenerationService();
        $provider = TtsVoices::activeProvider();
        $voice = TtsVoices::resolve(
            (string) ($settings['tts_voice'] ?? ''),
            $provider,
            $this->project->template_type
        );
        $ttsContext = [
            'template_type' => $this->project->template_type,
            'settings' => $settings,
        ];

        // Worked-math videos only: spell stray notation ("x^2", "sqrt(9)")
        // into speakable words before synthesis. Normal explainers are never
        // touched — the stored narration keeps its symbols either way.
        $mathSpeech = (bool) ($settings['auto_visuals_auto'] ?? false);
        // Every video: units, abbreviations and the analyzer's per-project
        // pronunciations become the words a narrator would actually say.
        // The stored narration and the captions keep the written form.
        $speechHints = is_array($settings['speech_hints'] ?? null) ? $settings['speech_hints'] : [];

        foreach ($this->project->explainerScenes()->get() as $scene) {
            $text = trim((string) $scene->narration);
            if ($text === '') {
                continue;
            }
            $spoken = $mathSpeech ? \Modules\Project\Support\MathSpeech::forSpeech($text) : $text;
            $spoken = \Modules\Project\Support\SpeechDictionary::forSpeech($spoken, $speechHints);

            // Namespace the cache key by engine+voice so switching provider or
            // voice (or from the old fal Chatterbox audio) forces a re-synth.
            // The spoken text (post math-speech) drives the hash so an edited
            // notation line re-synthesizes.
            $hash = md5($provider . ':' . $voice . '|' . $spoken);
            $relPath = "projects/{$this->project->id}/explainer/narration_{$scene->scene_id}.wav";
            // Word-timing sidecar: lives and dies with the wav so cached audio
            // keeps its sync data across renders.
            $wordsRel = "projects/{$this->project->id}/explainer/narration_{$scene->scene_id}.words.json";
            $existing = ExplainerAsset::where('project_id', $this->project->id)
                ->where('scene_id', $scene->scene_id)->where('slot_key', '__narration__')->first();

            $duration = 0.0;

            $cached = $existing && $existing->original_name === $hash
                && Storage::disk('public')->exists($existing->path)
                && Storage::disk('public')->exists($wordsRel);

            if ($cached) {
                // Unchanged narration — reuse cached audio + timings.
                $duration = $this->probeAudioDuration(Storage::disk('public')->path($existing->path));
            } else {
                $result = $tts->synthesize($spoken, $relPath, ['voice' => $voice, 'word_timings' => true], $ttsContext);
                if (!($result['success'] ?? false)) {
                    Log::warning("ExplainerVideoProcessor: narration failed for {$scene->scene_id}: " . ($result['error'] ?? 'unknown'));
                    continue;
                }
                $duration = (float) ($result['duration'] ?? 0);
                Storage::disk('public')->put(
                    $wordsRel,
                    json_encode(array_values($result['word_timings'] ?? []))
                );
                ExplainerAsset::updateOrCreate(
                    ['project_id' => $this->project->id, 'scene_id' => $scene->scene_id, 'slot_key' => '__narration__'],
                    ['type' => 'audio', 'path' => $result['audio_path'], 'original_name' => $hash]
                );
            }

            // Pace the scene to its narration (plus a short tail) so speech is
            // never clipped and the video length tracks the voiceover. Figure
            // cards (the scenario schematic, geometry, plots) keep a minimum
            // hold even when the LLM narrated them with one short line — a
            // setup drawing that flashes for 3 seconds explains nothing; the
            // staged reveal needs time to play and the viewer time to read it.
            // The ceiling is 90s, NOT 30: a consolidated math-board phase
            // legitimately carries ~60s of narration (project 33's 55.7s wav
            // was chopped mid-sentence at the old 30s cap).
            if ($duration > 0) {
                $isFigure = in_array($scene->layout_template, ['scenario_diagram', 'geometry_diagram', 'function_plot', 'formula_anatomy'], true);
                // The practice card takes the longest floor of all: its whole
                // design is problem -> PAUSE -> answer, and a beat that does
                // not outlive the pause bar asks the viewer to solve something
                // in three seconds.
                $floor = $scene->layout_template === 'practice_card' ? 9.0 : ($isFigure ? 7.0 : 2.0);
                $scene->update(['duration_seconds' => round(min(90.0, max($floor, $duration + 0.6)), 2)]);
            }
        }

        return true;
    }

    private function probeAudioDuration(string $absolutePath): float
    {
        try {
            return (float) $this->ffprobe->format($absolutePath)->get('duration');
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Best-effort: choose the video's punchlines — short verbatim narration
     * phrases that pop on screen in sync with the voice. Cached in project
     * settings keyed by a hash of all narrations, so unchanged storyboards
     * cost nothing on re-render. Non-fatal.
     */
    protected function generatePunchlines(): bool
    {
        try {
            $settings = $this->project->settings ?? [];
            if (($settings['punchlines_enabled'] ?? true) === false) {
                return true;
            }

            $scenes = $this->project->explainerScenes()->get()
                ->map(fn ($s) => ['scene_id' => (string) $s->scene_id, 'narration' => trim((string) $s->narration)])
                ->filter(fn ($s) => $s['narration'] !== '')
                ->values()
                ->all();

            if (empty($scenes)) {
                return true;
            }

            $cacheKey = md5('punchlines:v1:' . json_encode(array_column($scenes, 'narration')));
            if (($settings['punchlines_cache_key'] ?? '') === $cacheKey && !empty($settings['punchlines'])) {
                return true;
            }

            $punchlines = (new PunchlineService())->extract($scenes);

            $settings['punchlines'] = $punchlines;
            $settings['punchlines_cache_key'] = $cacheKey;
            $this->project->update(['settings' => $settings]);

            Log::info('ExplainerVideoProcessor: punchlines selected', [
                'project_id' => $this->project->id,
                'count' => count($punchlines),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: punchline pass failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Best-effort: run the scene stylist — one LLM pass assigning every scene
     * a presentation personality + a concrete illustration brief. Cached in
     * settings keyed by a hash of the storyboard content, so unchanged
     * storyboards cost nothing on re-render. Non-fatal.
     */
    protected function generateSceneStyles(): bool
    {
        try {
            $settings = $this->project->settings ?? [];
            if (($settings['scene_styles_enabled'] ?? true) === false) {
                return true;
            }

            // Scenes the camera director marked kinetic_break arrive like a
            // smash cut — the stylist should set them as big "statement"
            // typography, not a quiet editorial card.
            $smashIds = $this->kineticBreakSceneIds();

            $descriptors = $this->project->explainerScenes()->get()
                ->map(function ($s) use ($smashIds) {
                    $d = $this->sceneDescriptor($s);
                    $d['smash_beat'] = in_array((string) $s->scene_id, $smashIds, true);
                    return $d;
                })
                ->values()
                ->all();

            if (empty($descriptors)) {
                return true;
            }

            $cacheKey = md5('scene_styles:v2:' . json_encode($descriptors));
            if (($settings['scene_styles_cache_key'] ?? '') === $cacheKey && !empty($settings['scene_styles'])) {
                return true;
            }

            $styles = (new SceneStyleService())->style($descriptors);

            $settings['scene_styles'] = $styles;
            $settings['scene_styles_cache_key'] = $cacheKey;
            $this->project->update(['settings' => $settings]);

            Log::info('ExplainerVideoProcessor: scene styles assigned', [
                'project_id' => $this->project->id,
                'count' => count($styles),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: scene stylist failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * What the stylist needs to know about a scene: its visible copy and
     * whether it carries user media.
     */
    private function sceneDescriptor($scene): array
    {
        $heading = '';
        $bullets = [];
        $body = '';
        foreach (($scene->slots ?? []) as $slot) {
            $type = $slot['content_type'] ?? '';
            if ($type === 'text_block' && $heading === '' && empty($bullets)) {
                $heading = trim((string) ($slot['heading'] ?? ''));
                $bullets = array_values(array_filter(array_map('trim', (array) ($slot['bullets'] ?? []))));
            }
            if ($type === 'explanation_box' && $body === '') {
                $body = trim((string) ($slot['body'] ?? ''));
                if ($heading === '') {
                    $heading = trim((string) ($slot['heading'] ?? ''));
                }
            }
        }

        return [
            'scene_id' => (string) $scene->scene_id,
            'heading' => $heading,
            'bullets' => $bullets,
            'body' => $body,
            'narration' => trim((string) $scene->narration),
            'has_media' => !empty($scene->requiredAssetSlots()),
            // The typewriter variant (§4.5) is only offered on tense beats
            // that actually carry an explanation body to type out.
            'mood' => (string) ($scene->mood ?? 'neutral'),
            'has_box' => $this->sceneHasExplanationBox($scene),
        ];
    }

    /** Does any slot render as an explanation_box (the typewriter's canvas)? */
    private function sceneHasExplanationBox($scene): bool
    {
        foreach ($scene->slots ?? [] as $slot) {
            if (($slot['content_type'] ?? null) === 'explanation_box') {
                return true;
            }
        }

        return false;
    }

    /**
     * Route media-less scenes to the right kind of AI visual for the active
     * composition mode: canvas journeys pair the copy with a crisp
     * ILLUSTRATION (the blurred ambient never renders there), classic slides
     * keep their soft ambient backgrounds.
     */
    protected function generateSceneVisuals(): bool
    {
        $mode = ($this->project->settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode());

        // The math board draws every visual itself — written equations and
        // native diagrams — and never composes an AI illustration or ambient,
        // so there is nothing to generate here (and no budget to spend).
        if ($mode === 'math_board') {
            return true;
        }

        // Creative flat-vector illustrations carry the AI-image budget in
        // EVERY mode — they compose beside the copy wherever a text-only
        // scene renders. The blurred ambient exists for exactly one job
        // (a backdrop behind slides), so ONE shared image covers it; pure
        // canvas journeys never render ambients at all.
        $ok = $this->generateSceneIllustrations();

        $chapterModes = array_column(
            $this->project->settings['chapter_plan']['chapters'] ?? [],
            'mode'
        );
        $needsAmbient = $mode === 'slides'
            || ($mode === 'hybrid' && in_array('slides', $chapterModes, true));
        if ($needsAmbient) {
            $ok = $this->ensureSharedAmbient() && $ok;
        }

        return $ok;
    }

    /**
     * Scene ids whose canvas treatment is kinetic_break, gathered from the
     * whole-video canvas plan and/or every chapter's embedded plan.
     *
     * @return string[]
     */
    private function kineticBreakSceneIds(): array
    {
        $settings = $this->project->settings ?? [];
        $plans = [];
        if (!empty($settings['canvas_plan']['items'])) {
            $plans[] = $settings['canvas_plan'];
        }
        foreach (($settings['chapter_plan']['chapters'] ?? []) as $chapter) {
            if (!empty($chapter['canvas']['items'])) {
                $plans[] = $chapter['canvas'];
            }
        }

        $ids = [];
        foreach ($plans as $plan) {
            foreach ($plan['items'] as $item) {
                if (($item['treatment'] ?? '') === 'kinetic_break') {
                    $ids[] = (string) ($item['scene_id'] ?? '');
                }
            }
        }
        return array_values(array_filter($ids));
    }

    /**
     * Best-effort: text-only scenes whose layout can actually DISPLAY an
     * illustration (single_focus composes it beside the copy in both modes)
     * get a themed AI illustration, capped by the registry's image budget.
     * Generating for layouts that never show the picture — split text/text,
     * stat_spotlight, quote_card — is money down the drain, so they are
     * skipped. Cached per prompt hash; non-fatal.
     */
    protected function generateSceneIllustrations(bool $dedupe = true): bool
    {
        try {
            $textOnly = $this->project->explainerScenes()->get()
                ->filter(fn ($s) => empty($s->requiredAssetSlots()) && $s->layout_template === 'single_focus')
                ->take(ExplainerRegistry::maxAiIllustrations())
                ->values();

            if ($textOnly->isEmpty()) {
                return true;
            }

            $settings = $this->project->settings ?? [];
            $styles = is_array($settings['scene_styles'] ?? null) ? $settings['scene_styles'] : [];

            $existing = ExplainerAsset::where('project_id', $this->project->id)
                ->where('slot_key', '__illustration__')->get()->keyBy('scene_id');

            $pending = [];
            foreach ($textOnly as $scene) {
                // Prefer the stylist's scene-specific brief (a concrete subject
                // or metaphor drawn from the card's content); the generic topic
                // heuristic is only the no-stylist fallback.
                $brief = trim((string) ($styles[(string) $scene->scene_id]['image_prompt'] ?? ''));
                $topic = $brief !== '' ? $brief : ('a clean visual metaphor for: ' . $this->illustrationTopic($scene));
                // VLM retry (§12.4): a flagged illustration regenerates once
                // with a composition nudge (also changes the cache hash).
                if (!empty($settings['vlm_retry_suffix'][(string) $scene->scene_id])) {
                    $topic .= ' — different composition, different angle';
                }
                $prompt = $this->flatVectorPrompt($topic);
                $hash = md5($prompt);

                $cached = $existing->get($scene->scene_id);
                if ($cached && $cached->original_name === $hash && Storage::disk('public')->exists($cached->path)) {
                    continue; // unchanged scene — reuse the library copy
                }
                $pending[] = ['scene' => $scene, 'prompt' => $prompt, 'hash' => $hash];
            }

            if (empty($pending)) {
                return true;
            }

            $images = (new ImageGenerationService())->generateImages(
                array_map(fn ($p) => $p['prompt'], $pending),
                count($pending),
                // No style keyword: 'cinematic' pulls the model straight back
                // toward lens flare and depth of field, which is the opposite
                // of a flat vector.
                '',
                [
                    'project_id' => $this->project->id,
                    'template' => 'explainer_illustration',
                    // Match the VIDEO's frame. These illustrations are
                    // full-bleed backdrops for text-only scenes, so a square
                    // 512px image in a 16:9 render was cropped to its middle
                    // (throwing away the subject, which the art direction
                    // centres) and upscaled ~3.7x to fill 1920px, which reads
                    // as soft and empty. Same expression as the slot-fill pass.
                    'aspect_ratio' => in_array($this->project->aspect_ratio, ['16:9', '9:16', '1:1'], true)
                        ? $this->project->aspect_ratio
                        : '16:9',
                    'visual_tone' => 'flat 2D vector graphic, solid colour shapes, crisp edges, limited three-colour palette, no gradients, no shading, no photorealism',
                    // "highly detailed / sharp focus / intricate details" fights
                    // a flat vector brief; and every scene must get its own
                    // composition rather than N variations of one seed.
                    'detail_boosters' => false,
                    'character_consistency' => false,
                    'vary_seed' => true,
                ]
            );

            if (empty($images) || !is_array($images)) {
                Log::info('ExplainerVideoProcessor: no illustrations generated (non-fatal)');
                return true;
            }

            foreach ($pending as $i => $entry) {
                $absolute = $images[$i] ?? null;
                if (!$absolute) {
                    continue;
                }
                ExplainerAsset::updateOrCreate(
                    ['project_id' => $this->project->id, 'scene_id' => $entry['scene']->scene_id, 'slot_key' => '__illustration__'],
                    ['type' => 'image', 'path' => $this->toRelativeStoragePath($absolute), 'original_name' => $entry['hash']]
                );
            }

            // Duplicate-look guard (§8): two illustrations that hash within
            // 6 bits of each other read as the same picture — regenerate the
            // later ones ONCE with a composition nudge.
            if ($dedupe) {
                $this->dedupIllustrations();
            }

            return true;
        } catch (\Throwable $e) {
            // Decorative only — never block the render.
            Log::warning('ExplainerVideoProcessor: illustration generation failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * The one flat-vector art direction every AI image in the video obeys —
     * locked to the theme's own three colours (named in WORDS: the image
     * model largely ignores raw hex codes but follows "dark plum background
     * with pink accents" reliably) with the hard no-text rule (§7.1).
     */
    private function flatVectorPrompt(string $topic): string
    {
        $settings = $this->project->settings ?? [];
        $theme = ExplainerRegistry::colorScheme($settings['color_scheme'] ?? null);
        $field = $theme['bg_from'] ?? '#0A0F1E';
        $ink = $theme['text'] ?? '#EDF0F8';
        $accent = $theme['accent'] ?? '#FFB020';

        return 'Bold flat 2D vector illustration of ' . $topic . '. '
            . 'Bold geometric shapes with solid colour fills, thick confident outlines, crisp clean edges, '
            // "generous negative space, centered composition" was read by the
            // image model as "one small icon adrift in an empty field" — the
            // reason so many frames came back as a near-solid background with a
            // postage-stamp motif. The subject has to OWN the frame instead.
            . 'The subject is large and fills the frame edge to edge, generously cropped, '
            . 'occupying most of the canvas — never a small icon floating in empty space. '
            . 'Modern editorial poster style. '
            . "Strictly three colours only: a {$this->colorName($field)} background, "
            . "{$this->colorName($ink)} linework, and {$this->colorName($accent)} accents. "
            . 'Not a photograph: no photorealism, no bokeh, no blur, no depth of field. '
            . 'No gradients, no shading, no glow, no drop shadows. '
            // Image models cannot spell — the hard no-text rule (copilot.md
            // §7.1) covers every way glyphs sneak in.
            . 'No text, no words, no letters, no numbers, no labels, no captions, no watermark anywhere in the image';
    }

    /** Effective auto-visuals switch: explicit user choice > math-video auto. */
    private function autoVisualsEnabled(): bool
    {
        $settings = $this->project->settings ?? [];

        return (bool) ($settings['auto_visuals'] ?? $settings['auto_visuals_auto'] ?? false);
    }

    /**
     * Auto visuals (math videos default on): every image/video slot still
     * missing an upload gets a flat-vector AI illustration generated from its
     * own asset_request description, stored under the REAL slot key so the
     * rest of the pipeline (assembly, storyboard, smart crop, thumbnail)
     * treats it exactly like an upload. An actual upload always wins — this
     * only fills holes. Cached per prompt hash; capped by the registry image
     * budget; a failed fill degrades to the reserved-slot placeholder rather
     * than blocking the render. Non-fatal.
     */
    protected function fillMissingMediaSlots(): bool
    {
        try {
            if (!$this->autoVisualsEnabled()) {
                return true;
            }

            $assets = ExplainerAsset::where('project_id', $this->project->id)->get()
                ->keyBy(fn ($a) => $a->scene_id . '::' . $a->slot_key);

            $budget = ExplainerRegistry::maxSlotFills();
            $pending = [];
            foreach ($this->project->explainerScenes()->orderBy('order')->get() as $scene) {
                foreach ($scene->slots ?? [] as $slotKey => $slot) {
                    if (!in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                        continue;
                    }
                    // Stock slots have their own fetcher; filled slots are done.
                    if (trim((string) ($slot['stock_query'] ?? '')) !== '') {
                        continue;
                    }
                    $existing = $assets->get($scene->scene_id . '::' . $slotKey);

                    $subject = trim((string) ($slot['asset_request']['description'] ?? ''));
                    $label = trim((string) ($slot['label'] ?? ''));
                    if ($subject === '') {
                        $subject = $label !== '' ? $label : 'the idea this scene narrates';
                    }
                    // VLM retry (§12.4): a flagged fill regenerates with a
                    // composition nudge — which also changes the prompt hash.
                    if (!empty(($this->project->settings['vlm_retry_suffix'] ?? [])[(string) $scene->scene_id])) {
                        $subject .= ' — different composition, different angle';
                    }
                    $prompt = $this->flatVectorPrompt($subject);
                    $hash = md5($prompt);

                    if ($existing && Storage::disk('public')->exists($existing->path)) {
                        $isFill = str_starts_with((string) $existing->original_name, 'slot-fill:');
                        // A real upload always wins; an earlier fill is only
                        // regenerated when its prompt (theme/description) changed.
                        if (!$isFill || $existing->original_name === 'slot-fill:' . $hash) {
                            continue;
                        }
                    }

                    $pending[] = [
                        'scene_id' => (string) $scene->scene_id,
                        'slot_key' => (string) $slotKey,
                        'prompt' => $prompt,
                        'hash' => $hash,
                    ];
                    if (count($pending) >= $budget) {
                        break 2;
                    }
                }
            }

            if (empty($pending)) {
                return true;
            }

            $images = (new ImageGenerationService())->generateImages(
                array_map(fn ($p) => $p['prompt'], $pending),
                count($pending),
                '',
                [
                    'project_id' => $this->project->id,
                    'template' => 'explainer_slot_fill',
                    'aspect_ratio' => in_array($this->project->aspect_ratio, ['16:9', '9:16', '1:1'], true)
                        ? $this->project->aspect_ratio
                        : '16:9',
                    'visual_tone' => 'flat 2D vector graphic, solid colour shapes, crisp edges, limited three-colour palette, no gradients, no shading, no photorealism',
                    'detail_boosters' => false,
                    'character_consistency' => false,
                    'vary_seed' => true,
                ]
            );

            if (empty($images) || !is_array($images)) {
                Log::info('ExplainerVideoProcessor: no slot fills generated (non-fatal)');
                return true;
            }

            foreach ($pending as $i => $entry) {
                $absolute = $images[$i] ?? null;
                if (!$absolute) {
                    continue;
                }
                ExplainerAsset::updateOrCreate(
                    ['project_id' => $this->project->id, 'scene_id' => $entry['scene_id'], 'slot_key' => $entry['slot_key']],
                    ['type' => 'image', 'path' => $this->toRelativeStoragePath($absolute), 'original_name' => 'slot-fill:' . $entry['hash']]
                );
            }

            Log::info('ExplainerVideoProcessor: slot fills generated', [
                'project_id' => $this->project->id,
                'count' => count($pending),
            ]);

            return true;
        } catch (\Throwable $e) {
            // Decorative fill — the missing-gate exemption keeps the render alive.
            Log::warning('ExplainerVideoProcessor: slot fill failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * labeled_diagram pins: the planner wrote part-label TEXTS
     * (callout_suggestions); now that the slot image actually exists, one
     * vision call per diagram locates each part and writes the coordinates
     * as the slot's callout pins. User-placed pins always win (a scene that
     * already has callouts is left alone), and any failure just leaves the
     * card on its legend-row fallback. Non-fatal.
     */
    protected function placeDiagramLabels(): bool
    {
        try {
            $service = new VlmLabelPlacementService();
            if (!$service->isConfigured()) {
                return true;
            }

            $assets = ExplainerAsset::where('project_id', $this->project->id)->get()
                ->keyBy(fn ($a) => $a->scene_id . '::' . $a->slot_key);

            $placedCount = 0;
            foreach ($this->project->explainerScenes()->orderBy('order')->get() as $scene) {
                if ($scene->layout_template !== 'labeled_diagram') {
                    continue;
                }
                $slots = $scene->slots ?? [];
                $slot = $slots['slot_diagram'] ?? null;
                if (!is_array($slot)) {
                    continue;
                }
                // Pins already exist (user-placed in review, or an earlier
                // run of this step) — never second-guess them.
                if (!empty($slot['callouts']) && is_array($slot['callouts'])) {
                    continue;
                }
                $texts = array_values(array_filter(array_map(
                    fn ($s) => trim((string) $s),
                    (array) ($slot['callout_suggestions'] ?? [])
                )));
                if (count($texts) < 2) {
                    continue;
                }
                $asset = $assets->get($scene->scene_id . '::slot_diagram');
                if (!$asset || $asset->type !== 'image' || !Storage::disk('public')->exists($asset->path)) {
                    continue;
                }

                $pins = $service->place(Storage::disk('public')->path($asset->path), $texts);
                if ($pins === null) {
                    continue;
                }
                $slots['slot_diagram']['callouts'] = $pins;
                $scene->update(['slots' => $slots]);
                $placedCount++;
            }

            if ($placedCount > 0) {
                Log::info('ExplainerVideoProcessor: diagram labels placed', [
                    'project_id' => $this->project->id,
                    'scenes' => $placedCount,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            // The legend fallback keeps the card presentable without pins.
            Log::warning('ExplainerVideoProcessor: diagram label placement failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Transparent sprites for scenario diagrams: every entity that declares a
     * `sprite` subject ("a red hatchback car, side view") gets a flat vector
     * drawn on plain white, then BiRefNet cuts it out to a true alpha PNG.
     * Stored per entity under `{$slotKey}#sprite{$i}` with the same
     * prompt-hash caching as slot fills (theme/subject change ⇒ regenerate;
     * unchanged ⇒ free). Any failure leaves the entity on its icon.
     */
    protected function generateScenarioSprites(): bool
    {
        try {
            if (!$this->autoVisualsEnabled()) {
                return true;
            }

            $assets = ExplainerAsset::where('project_id', $this->project->id)->get()
                ->keyBy(fn ($a) => $a->scene_id . '::' . $a->slot_key);

            $pending = [];
            foreach ($this->project->explainerScenes()->orderBy('order')->get() as $scene) {
                if ($scene->layout_template !== 'scenario_diagram') {
                    continue;
                }
                foreach ($scene->slots ?? [] as $slotKey => $slot) {
                    if (($slot['content_type'] ?? '') !== 'scenario') {
                        continue;
                    }
                    foreach ((array) ($slot['entities'] ?? []) as $i => $entity) {
                        $subject = trim((string) ($entity['sprite'] ?? ''));
                        if ($subject === '') {
                            continue;
                        }
                        $prompt = 'Simple flat 2D vector illustration of ' . $subject
                            . '. One single object only, centered, whole object fully in frame,'
                            . ' bold clean shapes, no text, no shadow, no ground, no scenery,'
                            . ' on a plain solid white background.';
                        $hash = md5($prompt);
                        $key = "{$slotKey}#sprite{$i}";
                        $existing = $assets->get($scene->scene_id . '::' . $key);
                        if (
                            $existing
                            && $existing->original_name === 'sprite:' . $hash
                            && Storage::disk('public')->exists($existing->path)
                        ) {
                            continue;
                        }
                        $pending[] = [
                            'scene_id' => (string) $scene->scene_id,
                            'slot_key' => $key,
                            'prompt' => $prompt,
                            'hash' => $hash,
                        ];
                        if (count($pending) >= 6) {
                            break 3;
                        }
                    }
                }
            }

            if (empty($pending)) {
                return true;
            }

            $images = (new ImageGenerationService())->generateImages(
                array_map(fn ($p) => $p['prompt'], $pending),
                count($pending),
                '',
                [
                    'project_id' => $this->project->id,
                    'template' => 'explainer_scenario_sprite',
                    'aspect_ratio' => '1:1',
                    'visual_tone' => '',
                    'detail_boosters' => false,
                    'character_consistency' => false,
                    'vary_seed' => true,
                ]
            );

            if (empty($images) || !is_array($images)) {
                return true;
            }

            $python = new \App\Services\PythonAIService();
            foreach ($pending as $i => $entry) {
                $absolute = $images[$i] ?? null;
                if (!$absolute) {
                    continue;
                }
                // The cutout IS the sprite — without alpha the "car" would be
                // a white rectangle on the diagram, worse than the icon.
                $alpha = $python->removeBackground($absolute);
                if (!$alpha) {
                    continue;
                }
                ExplainerAsset::updateOrCreate(
                    ['project_id' => $this->project->id, 'scene_id' => $entry['scene_id'], 'slot_key' => $entry['slot_key']],
                    ['type' => 'image', 'path' => $this->toRelativeStoragePath($alpha), 'original_name' => 'sprite:' . $entry['hash']]
                );
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: scenario sprites failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Perceptual-hash pass over the video's illustrations: any pair closer
     * than 6 bits (dHash hamming) drops the LATER image and regenerates it
     * with the "different composition, different angle" nudge — one retry
     * per scene ever (settings['illustration_dedup_retried']). Best-effort.
     */
    private function dedupIllustrations(): void
    {
        try {
            $order = $this->project->explainerScenes()->orderBy('order')->pluck('order', 'scene_id');
            $assets = ExplainerAsset::where('project_id', $this->project->id)
                ->where('slot_key', '__illustration__')->get()
                ->filter(fn ($a) => Storage::disk('public')->exists($a->path))
                ->sortBy(fn ($a) => $order[(string) $a->scene_id] ?? PHP_INT_MAX)
                ->values();
            if ($assets->count() < 2) {
                return;
            }

            $hashes = (new \App\Services\PythonAIService())->imagePhash(
                $assets->map(fn ($a) => Storage::disk('public')->path($a->path))->all()
            );
            if ($hashes === null) {
                return;
            }

            $settings = $this->project->settings ?? [];
            $retried = (array) ($settings['illustration_dedup_retried'] ?? []);
            $dupes = [];
            for ($i = 0; $i < $assets->count(); $i++) {
                for ($j = $i + 1; $j < $assets->count(); $j++) {
                    $a = $hashes[$i] ?? null;
                    $b = $hashes[$j] ?? null;
                    if (!is_string($a) || !is_string($b) || isset($dupes[$j])) {
                        continue;
                    }
                    if ($this->hammingHex($a, $b) < 6) {
                        $sceneId = (string) $assets[$j]->scene_id;
                        if (empty($retried[$sceneId])) {
                            $dupes[$j] = $sceneId;
                        }
                    }
                }
            }
            if (empty($dupes)) {
                return;
            }

            foreach ($dupes as $j => $sceneId) {
                if ($assets[$j]->path && Storage::disk('public')->exists($assets[$j]->path)) {
                    Storage::disk('public')->delete($assets[$j]->path);
                }
                $assets[$j]->delete();
                $retried[$sceneId] = true;
                $settings['vlm_retry_suffix'][$sceneId] = true; // composition nudge
            }
            $settings['illustration_dedup_retried'] = $retried;
            $this->project->update(['settings' => $settings]);
            $this->project->refresh();

            Log::info('ExplainerVideoProcessor: near-duplicate illustrations regenerating', [
                'project_id' => $this->project->id,
                'scenes' => array_values($dupes),
            ]);

            // One retry, no further dedup recursion.
            $this->generateSceneIllustrations(false);
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: illustration dedup failed (non-fatal): ' . $e->getMessage());
        }
    }

    /** Hamming distance between two equal-length hex hashes. */
    private function hammingHex(string $a, string $b): int
    {
        $dist = 0;
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            $dist += substr_count(str_pad(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), 4, '0', STR_PAD_LEFT), '1');
        }

        return $dist;
    }

    /**
     * A human colour name for a hex — image models follow "dark plum" far
     * more reliably than "#150F1B". Hue-bucketed HSL with light/dark
     * adjectives; deliberately coarse (it steers a palette, not a paint mix).
     */
    private function colorName(string $hex): string
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) !== 6) {
            return 'neutral dark';
        }
        $r = hexdec(substr($h, 0, 2)) / 255;
        $g = hexdec(substr($h, 2, 2)) / 255;
        $b = hexdec(substr($h, 4, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $s = $d === 0.0 ? 0.0 : $d / (1 - abs(2 * $l - 1));

        if ($s < 0.12) {
            return $l < 0.12 ? 'near-black' : ($l > 0.85 ? 'near-white' : ($l > 0.6 ? 'light warm grey' : 'dark grey'));
        }

        $hue = 0.0;
        if ($d > 0) {
            $hue = match ($max) {
                $r => fmod((($g - $b) / $d) + 6, 6),
                $g => (($b - $r) / $d) + 2,
                default => (($r - $g) / $d) + 4,
            } * 60;
        }

        $name = match (true) {
            $hue < 15 || $hue >= 345 => 'red',
            $hue < 40 => 'orange',
            $hue < 65 => 'golden yellow',
            $hue < 90 => 'lime green',
            $hue < 150 => 'green',
            $hue < 185 => 'teal',
            $hue < 210 => 'cyan',
            $hue < 250 => 'blue',
            $hue < 275 => 'indigo',
            $hue < 300 => 'violet',
            $hue < 330 => 'magenta',
            default => 'pink',
        };

        if ($l < 0.16) {
            return "very dark {$name}, almost black";
        }
        if ($l < 0.35) {
            return "deep {$name}";
        }
        if ($l > 0.85) {
            return "pale {$name}, almost white";
        }
        if ($l > 0.65) {
            return "light {$name}";
        }
        return $name;
    }

    /**
     * The most concrete text the scene owns, for the illustration prompt:
     * heading first, then a bullet, then the narration.
     */
    private function illustrationTopic($scene): string
    {
        foreach (($scene->slots ?? []) as $slot) {
            if (($slot['content_type'] ?? '') === 'text_block') {
                $heading = trim((string) ($slot['heading'] ?? ''));
                $bullet = trim((string) (($slot['bullets'] ?? [])[0] ?? ''));
                if ($heading !== '' || $bullet !== '') {
                    return mb_substr(trim($heading . ($bullet !== '' ? ' — ' . $bullet : '')), 0, 140);
                }
            }
            if (($slot['content_type'] ?? '') === 'explanation_box') {
                $body = trim((string) ($slot['body'] ?? ''));
                if ($body !== '') {
                    return mb_substr($body, 0, 140);
                }
            }
        }

        $narration = trim((string) $scene->narration);

        return $narration !== '' ? mb_substr($narration, 0, 140) : 'modern technology concept';
    }

    /**
     * Best-effort: ONE soft blurred backdrop shared by every text-only slide
     * in the video (it renders blurred to 26px anyway — per-scene variations
     * were six generations nobody could tell apart). Stored under the
     * project-wide sentinel scene `__project__`; assembleScenes falls back to
     * it for any text-only scene without its own ambient. Stale per-scene
     * ambients from the old scheme are removed so they stop attaching.
     */
    protected function ensureSharedAmbient(): bool
    {
        try {
            $hasTextOnly = $this->project->explainerScenes()->get()
                ->contains(fn ($s) => empty($s->requiredAssetSlots()));
            if (!$hasTextOnly) {
                return true;
            }

            $topic = trim((string) $this->project->title) ?: 'modern technology';
            // VLM retry (§12.4): a flagged shared backdrop regenerates once
            // with a composition nudge (also changes the cache hash).
            if (!empty(($this->project->settings['vlm_retry_suffix'] ?? [])['__project__'])) {
                $topic .= ' — different composition, different angle';
            }
            $prompt = 'Abstract cinematic background, soft blurred bokeh, atmospheric depth of field, '
                . 'subtle gradient lighting, minimal, no people — themed around: '
                . mb_substr($topic, 0, 120)
                // Hard no-text rule (copilot.md §7.1): image models cannot spell.
                . '. No text, no words, no letters, no numbers, no labels, no watermark anywhere in the image';
            $hash = md5('shared_ambient:' . $prompt);

            // Drop the old per-scene blurry set — one backdrop does this job.
            ExplainerAsset::where('project_id', $this->project->id)
                ->where('slot_key', '__ambient__')
                ->where('scene_id', '!=', '__project__')
                ->delete();

            $existing = ExplainerAsset::where('project_id', $this->project->id)
                ->where('scene_id', '__project__')
                ->where('slot_key', '__ambient__')
                ->first();
            if ($existing && $existing->original_name === $hash && Storage::disk('public')->exists($existing->path)) {
                return true;
            }

            $images = (new ImageGenerationService())->generateImages(
                [$prompt],
                1,
                'cinematic',
                [
                    'project_id' => $this->project->id,
                    'template' => 'explainer_ambient',
                    'aspect_ratio' => $this->project->aspect_ratio ?? '16:9',
                    'visual_tone' => 'abstract atmospheric background, no subjects, no characters',
                    'prompt_modifiers' => 'soft focus, muted elegant palette, dark, premium, depth of field',
                    'character_consistency' => false,
                ]
            );

            $absolute = is_array($images) ? ($images[0] ?? null) : null;
            if (!$absolute) {
                Log::info('ExplainerVideoProcessor: no shared ambient generated (non-fatal)');
                return true;
            }

            ExplainerAsset::updateOrCreate(
                ['project_id' => $this->project->id, 'scene_id' => '__project__', 'slot_key' => '__ambient__'],
                ['type' => 'image', 'path' => $this->toRelativeStoragePath($absolute), 'original_name' => $hash]
            );

            return true;
        } catch (\Throwable $e) {
            // Decorative only — never block the render.
            Log::warning('ExplainerVideoProcessor: ambient generation failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Stock b-roll (§8): every video slot carrying a `stock_query` and no
     * asset yet gets a free stock clip — searched, scored, downloaded into
     * the shared cache, then COPIED into the project so a later slot delete
     * can't destroy the shared file. A user upload into the slot always wins
     * (the asset already exists, so the slot is skipped). Best-effort.
     */
    protected function fetchStockFootage(): bool
    {
        try {
            $service = new \Modules\Project\Services\StockFootageService();
            $orientation = match ($this->project->aspect_ratio ?? '16:9') {
                '9:16' => 'portrait',
                '1:1' => 'square',
                default => 'landscape',
            };

            foreach ($this->project->explainerScenes()->get() as $scene) {
                foreach (($scene->slots ?? []) as $slotKey => $slot) {
                    $query = trim((string) ($slot['stock_query'] ?? ''));
                    if ($query === '') {
                        continue;
                    }
                    $existing = ExplainerAsset::where('project_id', $this->project->id)
                        ->where('scene_id', $scene->scene_id)->where('slot_key', $slotKey)->first();
                    if ($existing && Storage::disk('public')->exists($existing->path)) {
                        continue; // user upload or an earlier fetch
                    }

                    $cached = $service->fetch($query, $orientation);
                    if ($cached === null) {
                        Log::info('ExplainerVideoProcessor: stock fetch found nothing (non-fatal)', [
                            'project_id' => $this->project->id,
                            'scene' => $scene->scene_id,
                            'query' => $query,
                        ]);
                        continue;
                    }

                    $projectRel = "projects/{$this->project->id}/explainer/stock_{$scene->scene_id}_{$slotKey}.mp4";
                    Storage::disk('public')->put(
                        $projectRel,
                        (string) Storage::disk('public')->get($cached)
                    );

                    ExplainerAsset::updateOrCreate(
                        ['project_id' => $this->project->id, 'scene_id' => $scene->scene_id, 'slot_key' => $slotKey],
                        ['type' => 'video', 'path' => $projectRel, 'original_name' => 'stock:' . md5($query)]
                    );
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: stock b-roll failed (non-fatal): ' . $e->getMessage());

            return true;
        }
    }

    /**
     * Best-effort: fill in `path` for every decorative prop in the canvas
     * plan from the shared props library (generating missing ones once).
     * Failures leave props path-less and the renderer simply skips them.
     */
    protected function resolveCanvasProps(): bool
    {
        try {
            $settings = $this->project->settings ?? [];
            $plan = $settings['canvas_plan'] ?? null;
            if (!is_array($plan) || empty($plan['items'])) {
                return true;
            }

            // Plans stored before the flat redesign still carry props, and the
            // stored plan is never re-run through CanvasPlanValidator. Strip
            // them here so an old project doesn't sprout floating cut-outs on
            // its next render — or pay Fal to generate them.
            if (ExplainerRegistry::maxPropsPerScene() < 1) {
                $had = false;
                foreach ($plan['items'] as &$item) {
                    if (!empty($item['props'])) {
                        $had = true;
                        $item['props'] = [];
                    }
                }
                unset($item);
                if ($had) {
                    $settings['canvas_plan'] = $plan;
                    $this->project->update(['settings' => $settings]);
                }

                return true;
            }

            $prompts = [];
            foreach ($plan['items'] as $item) {
                foreach (($item['props'] ?? []) as $prop) {
                    if (!empty($prop['prompt'])) {
                        $prompts[] = (string) $prop['prompt'];
                    }
                }
            }
            if (empty($prompts)) {
                return true;
            }

            $resolved = (new PropsLibraryService())->resolve($prompts, (int) $this->project->id);

            foreach ($plan['items'] as &$item) {
                if (empty($item['props'])) {
                    continue;
                }
                foreach ($item['props'] as &$prop) {
                    $prop['path'] = $resolved[$prop['prompt'] ?? ''] ?? null;
                }
                unset($prop);
            }
            unset($item);

            $settings['canvas_plan'] = $plan;
            $this->project->update(['settings' => $settings]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: prop resolution failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Aspect variants (§10.6, default off): re-render the SAME assembled
     * storyboard at the two other frame shapes. The primary keeps its path;
     * variants land next to it as `{base}_9x16.mp4` etc., and the manifest in
     * settings['output_videos'] (the gameplay template's clip-switcher shape)
     * lists all of them, primary first. Each variant gets its own loudnorm
     * pass. Best-effort — a failed variant is simply left out.
     */
    protected function renderAspectVariants(): bool
    {
        try {
            $settings = $this->project->settings ?? [];
            $primaryRel = $this->project->output_path ?: $this->outputPath;
            $primaryAspect = $this->project->aspect_ratio ?? '16:9';

            $manifest = [[
                'aspect' => $primaryAspect,
                'path' => $primaryRel,
                'label' => $primaryAspect === '9:16' ? 'Portrait' : ($primaryAspect === '1:1' ? 'Square' : 'Landscape'),
            ]];

            if (($settings['aspect_variants'] ?? false) === true) {
                $service = new RemotionRenderService();
                if ($service->isAvailable()) {
                    $scenes = $this->assembleScenes();
                    if ($scenes !== false) {
                        foreach (['16:9', '9:16', '1:1'] as $aspect) {
                            if ($aspect === $primaryAspect) {
                                continue;
                            }
                            $rel = preg_replace('/\.mp4$/i', '_' . str_replace(':', 'x', $aspect) . '.mp4', $primaryRel);
                            $result = $service->render($this->project, $scenes, $rel, $aspect);
                            if (!($result['success'] ?? false)) {
                                Log::warning('ExplainerVideoProcessor: aspect variant failed (non-fatal)', [
                                    'project_id' => $this->project->id,
                                    'aspect' => $aspect,
                                ]);
                                continue;
                            }
                            $this->masterAudio($rel);
                            $manifest[] = [
                                'aspect' => $aspect,
                                'path' => $rel,
                                'label' => $aspect === '9:16' ? 'Portrait' : ($aspect === '1:1' ? 'Square' : 'Landscape'),
                            ];
                        }
                    }
                }
            }

            // The manifest exists even with variants off, so the frontend
            // switcher has one shape to consume.
            $settings = $this->project->fresh()->settings ?? [];
            $settings['output_videos'] = $manifest;
            $this->project->update(['settings' => $settings]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: aspect variants failed (non-fatal): ' . $e->getMessage());

            return true;
        }
    }

    /**
     * SRT export (§10.7): captions from the narration word timings for every
     * video in the output manifest (cue times are identical across aspects —
     * same storyboard, same clock). Best-effort.
     */
    protected function exportCaptions(): bool
    {
        try {
            $rel = $this->project->output_path ?: $this->outputPath;
            $srt = (new SrtExportService())->export($this->project, $rel);
            if ($srt !== null) {
                $settings = $this->project->settings ?? [];
                $settings['srt_path'] = $srt;
                $this->project->update(['settings' => $settings]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: caption export failed (non-fatal): ' . $e->getMessage());

            return true;
        }
    }

    /**
     * YouTube packaging (backlog): description + chapter timestamps +
     * hashtags in one paste-ready `<video>.youtube.txt`. Best-effort.
     */
    protected function exportPackaging(): bool
    {
        try {
            $rel = $this->project->output_path ?: $this->outputPath;
            $path = (new \Modules\Project\Services\YoutubePackagingService())->export($this->project, $rel);
            if ($path !== null) {
                $settings = $this->project->settings ?? [];
                $settings['youtube_packaging_path'] = $path;
                $this->project->update(['settings' => $settings]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: packaging export failed (non-fatal): ' . $e->getMessage());

            return true;
        }
    }

    /**
     * The problem equation to feature on a math video's thumbnail, or null for
     * a normal explainer (which keeps its hero-image thumbnail). Prefers the
     * opening math_steps line, then a function_plot expression. Only fires when
     * auto visuals marked this a math video, so normal explainers are untouched.
     */
    private function thumbnailEquation(): ?string
    {
        if (empty(($this->project->settings ?? [])['auto_visuals_auto'])) {
            return null;
        }

        foreach ($this->project->explainerScenes()->orderBy('order')->get() as $scene) {
            $slots = $scene->slots ?? [];
            if ($scene->layout_template === 'math_steps') {
                $expr = trim((string) (($slots['slot_math']['steps'][0]['expr'] ?? '')));
                if ($expr !== '') {
                    return mb_substr($expr, 0, 40);
                }
            }
            if ($scene->layout_template === 'function_plot') {
                $expr = trim((string) ($slots['slot_plot']['expression'] ?? ''));
                if ($expr !== '') {
                    return 'y = ' . mb_substr($expr, 0, 34);
                }
            }
        }

        return null;
    }

    /**
     * Designed thumbnail (§10.5): renderStill the ThumbnailComp — hero image
     * + giant title + accent bars — at 1280×720 and 1080×1920 next to the
     * MP4. The project's thumbnail_path points at the orientation matching
     * its aspect. Falls back to the plain ffmpeg frame grab on any failure.
     */
    protected function generateDesignedThumbnail(): bool
    {
        try {
            // Math videos lead the thumbnail with the PROBLEM itself, typeset,
            // instead of hunting for a hero image.
            $equation = $this->thumbnailEquation();

            // Hero: the first user-uploaded image, else a scene illustration.
            // Skipped when an equation drives the thumbnail.
            $hero = $equation !== null ? null : (
                ExplainerAsset::where('project_id', $this->project->id)
                    ->where('type', 'image')
                    ->where('slot_key', 'not like', '\_\_%')
                    ->orderBy('id')
                    ->first()
                ?? ExplainerAsset::where('project_id', $this->project->id)
                    ->where('slot_key', '__illustration__')
                    ->orderBy('id')
                    ->first()
            );

            $rel = $this->project->output_path ?: $this->outputPath;
            $thumbs = (new RemotionRenderService())->renderThumbnail($this->project, $hero?->path, $rel, $equation);

            if ($thumbs === null) {
                $this->generateThumbnail('00:00:01', $this->outputPath);

                return true;
            }

            $settings = $this->project->settings ?? [];
            $settings['thumbnails'] = $thumbs;
            $this->project->update([
                'settings' => $settings,
                'thumbnail_path' => ($this->project->aspect_ratio === '9:16') ? $thumbs['portrait'] : $thumbs['landscape'],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: designed thumbnail failed (non-fatal): ' . $e->getMessage());
            $this->generateThumbnail('00:00:01', $this->outputPath);

            return true;
        }
    }

    /**
     * Loudness master (copilot.md §6.6): two-pass ffmpeg loudnorm to
     * -14 LUFS integrated / -1.5 dBTP — the level YouTube/TikTok normalise
     * to, so the mix never sounds weak next to competitors. The video stream
     * is COPIED (audio-only re-encode, seconds not minutes) and the mastered
     * file replaces the original in place so every downstream path (player
     * URL, thumbnail) keeps working. Best-effort: any failure ships the
     * original mix untouched.
     */
    protected function masterAudio(?string $relativeOverride = null): bool
    {
        try {
            $relative = $relativeOverride ?? ($this->project->output_path ?: $this->outputPath);
            $absolute = Storage::disk('public')->path($relative);
            if (!is_file($absolute)) {
                return true;
            }

            // Pass 1 — measure. loudnorm prints its JSON report to stderr.
            $measure = new \Symfony\Component\Process\Process([
                'ffmpeg', '-hide_banner', '-nostats', '-y', '-i', $absolute,
                '-af', 'loudnorm=I=-14:TP=-1.5:LRA=11:print_format=json',
                '-f', 'null', '-',
            ]);
            $measure->setTimeout(600);
            $measure->run();

            $stats = null;
            if (preg_match('/\{[^{}]*"input_i"[^{}]*\}/s', $measure->getErrorOutput(), $m)) {
                $stats = json_decode($m[0], true);
            }
            if (!is_array($stats) || !isset($stats['input_i'], $stats['input_tp'], $stats['input_lra'], $stats['input_thresh'])) {
                Log::warning('ExplainerVideoProcessor: loudnorm measure pass failed (non-fatal)', [
                    'project_id' => $this->project->id,
                ]);
                return true;
            }

            // Already on target (a re-run on a mastered file): skip the re-encode.
            if (abs((float) $stats['input_i'] + 14.0) < 0.5 && (float) $stats['input_tp'] <= -1.0) {
                return true;
            }

            // Pass 2 — apply with the measured values (linear mode keeps
            // dynamics intact). loudnorm internally resamples to 192 kHz, so
            // pin the output back to 48 kHz.
            $filter = sprintf(
                'loudnorm=I=-14:TP=-1.5:LRA=11:measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true',
                $stats['input_i'],
                $stats['input_tp'],
                $stats['input_lra'],
                $stats['input_thresh'],
                $stats['target_offset'] ?? 0
            );
            $mastered = preg_replace('/\.mp4$/i', '_mastered.mp4', $absolute) ?: ($absolute . '.mastered.mp4');
            $apply = new \Symfony\Component\Process\Process([
                'ffmpeg', '-hide_banner', '-nostats', '-y', '-i', $absolute,
                '-c:v', 'copy', '-af', $filter, '-c:a', 'aac', '-b:a', '192k', '-ar', '48000',
                '-movflags', '+faststart', $mastered,
            ]);
            $apply->setTimeout(600);
            $apply->run();

            if (!$apply->isSuccessful() || !is_file($mastered) || filesize($mastered) < 1024) {
                @unlink($mastered);
                Log::warning('ExplainerVideoProcessor: loudnorm apply pass failed — shipping unmastered mix', [
                    'project_id' => $this->project->id,
                ]);
                return true;
            }

            if (!@rename($mastered, $absolute)) {
                @unlink($mastered);
                return true;
            }

            Log::info('ExplainerVideoProcessor: audio mastered to -14 LUFS', [
                'project_id' => $this->project->id,
                'measured_i' => $stats['input_i'],
                'measured_tp' => $stats['input_tp'],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: audio mastering failed (non-fatal): ' . $e->getMessage());
            return true;
        }
    }

    /**
     * VLM frame review (copilot.md §12.4): sample 6 frames of the finished
     * render, flag gibberish AI text / empty layouts / bad crops / style
     * clashes, regenerate the single offending AI asset once, and re-render
     * only when something actually fired. Guarded so it can never loop; user
     * uploads are never touched. Best-effort throughout.
     */
    protected function vlmReview(): bool
    {
        try {
            $settings = $this->project->settings ?? [];
            $enabled = $settings['vlm_review_enabled'] ?? (bool) config('services.openai.vlm_review', true);
            if ($enabled === false || !empty($settings['vlm_review_attempted'])) {
                return true;
            }

            $relative = $this->project->output_path ?: $this->outputPath;
            $absolute = Storage::disk('public')->path($relative);
            if (!is_file($absolute)) {
                return true;
            }
            $duration = $this->probeAudioDuration($absolute); // format duration works for video too
            if ($duration <= 0) {
                return true;
            }

            $service = new VlmFrameReviewService();
            $review = $service->review($absolute, $duration);
            if ($review === null) {
                return true;
            }

            $flagged = VlmFrameReviewService::flagged($review);
            $settings['vlm_review'] = [
                'frames' => $review,
                'flagged' => count($flagged),
                'checked_at' => date('c'),
            ];

            if (empty($flagged)) {
                $this->project->update(['settings' => $settings]);

                return true;
            }

            // Map flagged times back to scenes and drop those scenes' AI
            // assets so the next generation pass rebuilds them with a
            // "different composition" nudge. Only generated assets — an
            // upload the user chose is theirs, flags or not.
            $scenes = $this->project->explainerScenes()->orderBy('order')->get()
                ->map(fn ($s) => [
                    'scene_id' => (string) $s->scene_id,
                    'duration_seconds' => (float) $s->duration_seconds,
                    'transition' => (string) $s->transition,
                ])->all();
            $windows = \Modules\Project\Support\ExplainerTimingMap::sceneWindows(
                $scenes,
                (string) ($settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode()),
                $settings['chapter_plan'] ?? null
            );

            $regenerated = [];
            $slotFillsCleared = false;
            foreach ($flagged as $frame) {
                $sceneId = \Modules\Project\Support\ExplainerTimingMap::sceneAt($windows, (float) $frame['time']);
                if ($sceneId === null || isset($regenerated[$sceneId])) {
                    continue;
                }
                // Prefer the scene's own AI asset: a slot-fill image (auto
                // visuals) or an illustration/ambient. A slot fill lives under
                // its real slot key with a 'slot-fill:' name, so it can only be
                // found by scanning the scene's generated images.
                $asset = ExplainerAsset::where('project_id', $this->project->id)
                    ->where('scene_id', $sceneId)
                    ->where('original_name', 'like', 'slot-fill:%')
                    ->first()
                    ?? ExplainerAsset::where('project_id', $this->project->id)
                        ->where('scene_id', $sceneId)
                        ->whereIn('slot_key', ['__illustration__', '__ambient__'])
                        ->first()
                    // Text-only slides lean on the shared backdrop; a flagged
                    // one regenerates for the whole video.
                    ?? ExplainerAsset::where('project_id', $this->project->id)
                        ->where('scene_id', '__project__')->where('slot_key', '__ambient__')
                        ->first();
                if (!$asset) {
                    continue;
                }
                if ($asset->path && Storage::disk('public')->exists($asset->path)) {
                    Storage::disk('public')->delete($asset->path);
                }
                // The shared backdrop is keyed by the project sentinel so its
                // regen nudge lands on ensureSharedAmbient, not a scene.
                $regenerated[$asset->scene_id === '__project__' ? '__project__' : $sceneId] = true;
                if (str_starts_with((string) $asset->original_name, 'slot-fill:')) {
                    $slotFillsCleared = true;
                    // A regenerated diagram image invalidates its label pins —
                    // clear them so placeDiagramLabels() re-places on the
                    // fresh image (or the card falls back to its legend row).
                    $diagram = $this->project->explainerScenes()
                        ->where('scene_id', $sceneId)
                        ->where('layout_template', 'labeled_diagram')
                        ->first();
                    if ($diagram && !empty(($diagram->slots ?? [])[$asset->slot_key]['callouts'])) {
                        $slots = $diagram->slots;
                        unset($slots[$asset->slot_key]['callouts']);
                        $diagram->update(['slots' => $slots]);
                    }
                }
                $asset->delete();
            }

            $settings['vlm_review']['regenerated'] = array_keys($regenerated);
            $settings['vlm_review_attempted'] = true;
            if (!empty($regenerated)) {
                // Nudge the regen prompts off the previous composition.
                foreach (array_keys($regenerated) as $sceneId) {
                    $settings['vlm_retry_suffix'][$sceneId] = true;
                }
            }
            $this->project->update(['settings' => $settings]);
            $this->project->refresh();

            if (empty($regenerated)) {
                return true; // flags fired but nothing regenerable (user media)
            }

            Log::info('ExplainerVideoProcessor: VLM review regenerating assets + re-rendering', [
                'project_id' => $this->project->id,
                'scenes' => array_keys($regenerated),
            ]);

            $this->generateSceneVisuals();
            // A flagged slot-fill was deleted above; regenerate it (the empty
            // slot triggers a fresh AI fill on the next pass), then re-pin
            // any labeled diagram whose image just changed.
            if ($slotFillsCleared) {
                $this->fillMissingMediaSlots();
                $this->placeDiagramLabels();
            }
            $scenesPayload = $this->assembleScenes();
            if ($scenesPayload === false) {
                return true;
            }
            if ($this->renderVideo($scenesPayload)) {
                $this->masterAudio();
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('ExplainerVideoProcessor: VLM review failed (non-fatal): ' . $e->getMessage());

            return true;
        }
    }

    /**
     * Convert an absolute public-storage path to a disk-relative path.
     */
    protected function toRelativeStoragePath(string $absolute): string
    {
        $root = rtrim(str_replace('\\', '/', Storage::disk('public')->path('')), '/');
        $normalized = str_replace('\\', '/', $absolute);
        if (str_starts_with($normalized, $root)) {
            return ltrim(substr($normalized, strlen($root)), '/');
        }
        return $absolute;
    }

    public function validate(): array
    {
        $errors = [];
        if ($this->project->explainerScenes()->count() === 0) {
            $errors[] = 'Storyboard has not been generated yet.';
        }
        return $errors;
    }

    protected function getTemplateConfig(): array
    {
        return static::getStaticTemplateConfigArray();
    }

    public static function getStaticTemplateConfigArray(): array
    {
        return [
            'requires_upload' => false,
            'aspect_ratio' => '16:9',
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
        ];
    }

    protected function getProcessingSteps(): array
    {
        return [
            'lint_storyboard' => 'Checking storyboard quality',
            'generate_narration' => 'Generating narration',
            'style_scenes' => 'Styling scenes',
            'generate_punchlines' => 'Picking punchlines',
            'generate_ambient' => 'Generating scene visuals',
            'generate_props' => 'Preparing canvas props',
            'fetch_stock' => 'Fetching stock b-roll',
            'assemble_storyboard' => 'Assembling storyboard',
            'render_video' => 'Rendering video',
            'master_audio' => 'Mastering audio',
            'vlm_review' => 'Reviewing frames',
            'aspect_variants' => 'Rendering aspect variants',
            'export_captions' => 'Exporting captions',
            'export_packaging' => 'Writing the YouTube kit',
            'generate_thumbnail' => 'Generating thumbnail',
        ];
    }
}
