<?php

namespace Modules\Project\Jobs;

use App\Services\PusherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Services\CanvasDirectorService;
use Modules\Project\Services\CompositionDirectorService;
use Modules\Project\Services\ScriptAnalysisService;
use Modules\Project\Support\CanvasPlanValidator;
use Modules\Project\Support\ChapterPlanValidator;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ShotListValidator;
use Throwable;

/**
 * AnalyzeExplainerScriptJob
 *
 * The human-in-the-loop "analyze" phase for the explainer template. Runs the
 * LLM, validates the result into a guaranteed-legal shot list, persists each
 * scene, and parks the project in `storyboard_ready` so the frontend can ask
 * the user to upload the per-slot assets. Rendering is a separate phase
 * (ProcessVideoJob -> ExplainerVideoProcessor) triggered after uploads.
 */
class AnalyzeExplainerScriptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(public Project $project)
    {
        $this->onQueue('video-processing');
    }

    /**
     * Terminal failure. handle()'s own catch already marks the project failed
     * for anything THROWN inside it, but that catch cannot run when the job
     * dies without an exception reaching PHP: the 300s timeout, a worker
     * restart mid-analysis, or an OOM kill. Without this the project sits on
     * 'analyzing' forever and the dashboard spins with no way back.
     *
     * Deliberately does NOT refund credits: the charge happens at render, so
     * a failed analysis has nothing to give back.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('AnalyzeExplainerScriptJob: failed permanently', [
            'project_id' => $this->project->id,
            'error' => $exception->getMessage(),
            'class' => get_class($exception),
        ]);

        // Preserve the richer message handle()'s catch may already have stored.
        $this->project->refresh();
        if ($this->project->status === 'failed' && !empty($this->project->error_message)) {
            return;
        }

        $this->project->update([
            'status' => 'failed',
            'error_message' => 'Script analysis failed: ' . $exception->getMessage(),
        ]);

        try {
            (new PusherService())->sendProgress($this->project->id, 0, 'Analysis failed.');
        } catch (Throwable $e) {
            Log::warning('AnalyzeExplainerScriptJob: failed() could not push progress: ' . $e->getMessage());
        }
    }

    public function handle(): void
    {
        $pusher = new PusherService();

        // Attribute the analysis pipeline's paid AI calls to this project.
        \Modules\Project\Services\CostTracker::setContext($this->project);

        try {
            $this->project->update([
                'status' => 'analyzing',
                'progress' => 5,
                'error_message' => null,
            ]);
            $pusher->sendProgress($this->project->id, 5, 'Analyzing script...');

            $settings = $this->project->settings ?? [];
            $script = (string) ($settings['script'] ?? '');
            if (trim($script) === '') {
                throw new \RuntimeException('No script provided for analysis.');
            }

            // Is this a maths video? Asked ONCE, before the director runs, so
            // the analyzer is TOLD the subject instead of us inferring it back
            // out of whatever templates it happened to pick. A conceptual maths
            // topic ("why does a^2+b^2=c^2") reads as an ordinary explainer to
            // a per-scene chooser, and used to ask the viewer to upload photos
            // of the very figures the renderer draws. Never fatal: any failure
            // means "not maths", which is the pre-existing behaviour.
            $mathTopic = [];
            try {
                $mathTopic = (new \Modules\Project\Services\MathTopicService())->classify(
                    (string) $this->project->title,
                    $script
                );
            } catch (Throwable $e) {
                Log::info('AnalyzeExplainerScriptJob: math-topic classification unavailable', [
                    'project_id' => $this->project->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $settings['math_topic'] = $mathTopic ?: null;

            // L1 of the planning tree (roadmap §2): decide the ACTS before
            // any scene exists. The analyzer receives the skeleton as a hard
            // outline, so structure stops being one of the 400 decisions the
            // giant call keeps fumbling. Deterministically repaired — a
            // failed call still yields the canonical shape. Math only (yet).
            $skeleton = [];
            try {
                $planner = new \Modules\Project\Services\ScriptSkeletonService();
                // Math gets the guaranteed canonical skeleton; everything
                // else gets a story shape (argument/journey/compare/
                // countdown) when the planner can see one — and NO directive
                // when it can't, which is exactly today's behavior.
                $skeleton = $mathTopic !== []
                    ? $planner->plan($script, $mathTopic, (string) ($settings['guide'] ?? ''))
                    : $planner->planGeneric($script);
            } catch (Throwable $e) {
                Log::info('AnalyzeExplainerScriptJob: skeleton planning unavailable', [
                    'project_id' => $this->project->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $settings['script_skeleton'] = $skeleton ?: null;

            // L2/L3 (roadmap M-next-2): worked problems are COMPOSED by the
            // tree — casting decided by the skeleton's intents, details from
            // two focused calls plus the deterministic synthesizers. The
            // giant call remains the fallback for proof topics, tree
            // failures, and the off switch (EXPLAINER_TREE=false).
            $raw = null;
            $settings['composed_by_tree'] = false;
            if ($skeleton !== [] && (bool) config('services.openai.explainer_tree', true)) {
                try {
                    $kind = $mathTopic['kind'] ?? null;
                    // The composer rewrites narration from the script, so the
                    // user's guide has to reach it directly — otherwise their
                    // opening ("exercise and question number first") survives
                    // in the script and dies on the board.
                    $guide = (string) ($settings['guide'] ?? '');
                    if ($kind === 'worked_problem') {
                        $composer = (new \Modules\Project\Services\MathStoryboardComposerService())->setGuide($guide);
                        $raw = $composer->compose($script, $skeleton, $mathTopic, (string) $this->project->title);
                    } elseif ($kind === 'proof_concept') {
                        $composer = (new \Modules\Project\Services\MathStoryboardComposerService())->setGuide($guide);
                        $raw = $composer->composeProof($script, $skeleton, $mathTopic, (string) $this->project->title);
                    } else {
                        $composer = new \Modules\Project\Services\GenericStoryboardComposerService();
                        $raw = $composer->compose($script, $skeleton);
                    }
                    if ($raw !== null) {
                        $settings['composed_by_tree'] = true;
                        $settings['analysis_attempts'] = $composer->attempts();
                    }
                } catch (Throwable $e) {
                    Log::info('AnalyzeExplainerScriptJob: tree composer failed, falling back to the giant call', [
                        'project_id' => $this->project->id,
                        'error' => $e->getMessage(),
                    ]);
                    $raw = null;
                }
            }

            if ($raw === null) {
                $analysis = new ScriptAnalysisService();
                $raw = $analysis->analyze($script, [
                    'aspect_ratio' => $this->project->aspect_ratio ?? '16:9',
                    'target_seconds' => (int) ($settings['target_seconds'] ?? 60),
                    'math_topic' => $mathTopic,
                    'skeleton' => $skeleton,
                    // The title often carries the literal function/equation
                    // the user typed — the plot synthesizer reads it when the
                    // storyboard's own steps never state the function.
                    'title' => (string) $this->project->title,
                ]);
                // Heavy retriers are a cost and a quality signal.
                $settings['analysis_attempts'] = $analysis->attempts();
            }

            // Geometry figure synthesis (iter 40): rebuild any geometry_diagram
            // whose slot came back thin — a bare shape name that would draw as
            // an unlabelled default while the narration describes a labelled
            // figure (the Thales bug: "circle, diameter A B, point C, triangle
            // A B C" rendering as a lone circle + radius). Runs on BOTH paths
            // (tree composer and giant call) because neither reliably fills the
            // renderer's rich geometry fields. Never fatal.
            if (is_array($raw) && !empty($raw['scenes'])
                && (bool) config('services.openai.explainer_geometry_synth', true)) {
                try {
                    $raw['scenes'] = (new \Modules\Project\Services\MathVisualSynthesisService())
                        ->enrichThinGeometry(
                            (array) $raw['scenes'],
                            trim((string) $this->project->title . ' ' . (string) ($mathTopic['subject'] ?? ''))
                        );
                } catch (Throwable $e) {
                    Log::info('AnalyzeExplainerScriptJob: geometry synthesis unavailable', [
                        'project_id' => $this->project->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $pusher->sendProgress($this->project->id, 60, 'Validating storyboard...');

            $result = (new ShotListValidator())->validate($raw, [
                // Editorial packaging (copilot.md M1): cold-open hook + end
                // card, both user-configurable through project settings.
                //
                // ensureHook lifts scene 1's opening SENTENCE into a separate
                // cold-open card. That is right for the giant call, whose
                // scene 1 warms up — but a tree-composed storyboard already
                // cast a deliberate hook phase from the skeleton, so lifting
                // again split ONE planned opening into two near-duplicate
                // cards (project 38: a stat_spotlight reading "We need to
                // solve the differential equation…" followed by "Can you
                // solve this equation?").
                'hook_enabled' => ($settings['composed_by_tree'] ?? false) === true
                    ? false
                    : ($settings['hook_enabled'] ?? true),
                'outro_enabled' => $settings['outro_enabled'] ?? true,
                'outro_title' => (string) $this->project->title,
                'outro' => is_array($settings['outro'] ?? null) ? $settings['outro'] : [],
                // A maths video may chain its math cards past the ordinary caps.
                'math_mode' => $mathTopic !== [],
            ]);
            $scenes = $result['scenes'];

            if (!empty($result['warnings'])) {
                Log::info('AnalyzeExplainerScriptJob: validator adjustments', [
                    'project_id' => $this->project->id,
                    'warnings' => $result['warnings'],
                ]);
            }

            // Text-side quality pass (roadmap 3b): a script editor's read of
            // the final narration — meta-talk, screen descriptions, repeats,
            // placeholders. The model only NOMINATES; the guarded apply()
            // decides; everything it did (or refused) lands in the lint
            // report below. Never fatal, and off via EXPLAINER_TEXT_PASS.
            $textFindings = [];
            if ((bool) config('services.openai.explainer_text_pass', true)) {
                try {
                    $reviewer = new \Modules\Project\Services\StoryboardTextReviewService();
                    $issues = $reviewer->review($scenes);
                    if (!empty($issues)) {
                        $reviewed = \Modules\Project\Services\StoryboardTextReviewService::apply($scenes, $issues);
                        $scenes = $reviewed['scenes'];
                        $textFindings = $reviewed['findings'];
                        if ($reviewed['applied'] > 0) {
                            Log::info('AnalyzeExplainerScriptJob: narration text pass applied edits', [
                                'project_id' => $this->project->id,
                                'applied' => $reviewed['applied'],
                            ]);
                        }
                    }
                } catch (Throwable $e) {
                    Log::info('AnalyzeExplainerScriptJob: text pass unavailable', [
                        'project_id' => $this->project->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Replacing the prior storyboard is deferred to persistence below
            // (after the chapter planner may have inserted act-break covers,
            // §5.5) — and it is a DIFF, not a wipe: idempotent re-analysis
            // matches old scenes to new by content and re-homes every asset
            // that survives, so the user's uploads and the cached AI artifacts
            // (narration wavs, illustrations, slot fills, sprites, stock)
            // stop vanishing on every re-analyze.

            // Auto-theme (copilot.md §11.3): one cheap LLM look at the topic
            // and the dominant mood suggests {color_scheme, font_pack,
            // motion_style, skin}; explicit user choices always win, and any
            // failure falls back to the mood map + a random scheme.
            $moodCounts = array_count_values(array_map(fn ($s) => (string) ($s['mood'] ?? 'neutral'), $scenes));
            arsort($moodCounts);
            $dominantMood = (string) (array_key_first($moodCounts) ?? 'neutral');

            $suggested = [];
            try {
                $suggested = (new \Modules\Project\Services\SceneStyleService())->suggestTheme(
                    (string) $this->project->title,
                    (string) ($raw['summary'] ?? ''),
                    $dominantMood
                );
            } catch (Throwable $e) {
                Log::info('AnalyzeExplainerScriptJob: auto-theme unavailable, using deterministic defaults', [
                    'project_id' => $this->project->id,
                    'error' => $e->getMessage(),
                ]);
            }

            /*
             * Pronunciations: the proper nouns and jargon only this topic
             * knows. The model proposes; SpeechDictionary decides which are
             * safe (length, charset, not-a-no-op) and the survivors are
             * applied to the TTS text only — never to what is on screen.
             */
            $hints = \Modules\Project\Support\SpeechDictionary::usableHints(
                is_array($raw['pronunciations'] ?? null) ? $raw['pronunciations'] : []
            );
            $settings['speech_hints'] = array_map(
                fn ($term, $say) => ['term' => $term, 'say' => $say],
                array_keys($hints),
                array_values($hints)
            );

            $settings['color_scheme'] = $suggested['color_scheme'] ?? ExplainerRegistry::randomColorSchemeName();
            $settings['motion_style_auto'] = $suggested['motion_style'] ?? ExplainerRegistry::motionStyleForMood($dominantMood);
            $settings['font_pack_auto'] = $suggested['font_pack'] ?? null;
            $settings['skin_auto'] = $suggested['skin'] ?? ExplainerRegistry::defaultSkin();

            // Maths is set in a workhorse sans + real mono, full stop. The
            // auto-theme happily picks the classic SERIF pack for "academic"
            // topics — project 26 typeset derivatives in a display serif whose
            // swashy digits actively fight legibility. An explicit user pick
            // still wins (this only touches the _auto suggestion).
            if ($mathTopic !== []) {
                $settings['font_pack_auto'] = 'tech';
            }

            // Composition choreography: agentic LLM passes shape HOW the video
            // is presented. Guaranteed-legal (validators + deterministic
            // fallbacks inside the services) and therefore never fatal here.
            // The effective mode is stamped explicitly so a later change of
            // the registry default can never silently re-mode old projects,
            // and stale plans are dropped — the scenes they described are gone.
            $pusher->sendProgress($this->project->id, 80, 'Directing the camera journey...');
            $aspect = $this->project->aspect_ratio ?? '16:9';

            // Worked-math videos are their OWN paradigm (copilot.md math board):
            // a storyboard whose spine is the native math cards must NOT reuse
            // the slide/canvas explainer choreography — it solves the problem on
            // one continuous board with a calm write-along camera.
            //
            // The classifier already decided the subject UP FRONT — so a
            // confirmed worked_problem goes to the board whenever it has any
            // real math spine at all (>=2 math cards). Counting the analyzer's
            // card mix against a ratio here repeats the exact v18 mistake one
            // level up: project 34 ("find the projectile's maximum height")
            // was a classified worked_problem, but the analyzer wrapped enough
            // of the working in prose/counter cards that 4/15 scenes missed
            // the 40% bar and the solve ran through the Prezi camera. The
            // ratio rule stays only for the cases where the classifier did
            // NOT commit to a solve: proof_concept topics (a conceptual video
            // CAN legitimately be a normal explainer with two figures) and
            // the classifier-missed safety net.
            // Since the technical cards went GLOBAL (a science video may carry
            // plots and a formula anatomy without being a maths video), the
            // decision lives in ShotListValidator::routesToMathBoard, where
            // the ratio safety net additionally demands a WORKED spine.
            $mathTemplates = ShotListValidator::MATH_TEMPLATES;
            $isMathBoard = ShotListValidator::routesToMathBoard($scenes, $mathTopic['kind'] ?? null);

            $mode = $isMathBoard
                ? 'math_board'
                : ($settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode());
            $settings['composition_mode'] = $mode;
            unset($settings['canvas_plan'], $settings['chapter_plan']);

            // On the board the teaching unit is the PHASE (4-8 lines written
            // continuously under one heading), not the 2-4 line card — merge
            // consecutive working so the camera only moves between phases.
            // Only the board: full-screen slides genuinely fit 4 lines.
            if ($mode === 'math_board') {
                // The board draws its own visuals and generateSceneVisuals()
                // early-returns for it, so an image/video slot here is filled
                // by nothing at all — an empty panel in the video, and an
                // "upload media" box in the dashboard. Strip them where the
                // mode is actually KNOWN, next to the other board-only pass.
                $scenes = (new ShotListValidator())->stripBoardMediaSlots($scenes);
                $scenes = (new ShotListValidator())->consolidateBoardPhases($scenes);

                // Board skin, picked by the TOPIC (deterministic — no prompt):
                // a proof reads as a lecture → chalkboard; a worked solve
                // reads as homework → ruled notebook; the classifier-missed
                // safety net keeps today's slate. An explicit user pick
                // (settings['board_style']) wins at render time.
                $settings['board_style_auto'] = match ($mathTopic['kind'] ?? null) {
                    'proof_concept' => 'chalk',
                    'worked_problem' => 'notebook',
                    default => 'slate',
                };
            }

            if ($mode === 'hybrid') {
                try {
                    $settings['chapter_plan'] = (new CompositionDirectorService())->direct($scenes, $aspect);
                } catch (Throwable $e) {
                    Log::warning('AnalyzeExplainerScriptJob: composition director unavailable -> single canvas chapter', [
                        'project_id' => $this->project->id,
                        'error' => $e->getMessage(),
                    ]);
                    $sceneIds = array_map(fn ($s) => (string) $s['scene_id'], $scenes);
                    $plan = (new ChapterPlanValidator())->fallbackPlan($sceneIds);
                    $plan['chapters'][0]['canvas'] = (new CanvasPlanValidator())->fallbackPlan($sceneIds, $aspect);
                    $settings['chapter_plan'] = $plan;
                }

                // Act-break covers (§5.5): long multi-chapter videos get a
                // chapter_cover mini-chapter at each boundary.
                $covered = (new ChapterPlanValidator())->insertCovers($settings['chapter_plan'], $scenes);
                $settings['chapter_plan'] = $covered['plan'];
                $scenes = $covered['scenes'];
                if ($covered['inserted'] > 0) {
                    Log::info('AnalyzeExplainerScriptJob: chapter covers inserted', [
                        'project_id' => $this->project->id,
                        'covers' => $covered['inserted'],
                    ]);
                }
            } elseif ($mode === 'canvas_journey') {
                try {
                    $settings['canvas_plan'] = (new CanvasDirectorService())->direct($scenes, $aspect);
                } catch (Throwable $e) {
                    Log::warning('AnalyzeExplainerScriptJob: canvas director unavailable -> fallback layout', [
                        'project_id' => $this->project->id,
                        'error' => $e->getMessage(),
                    ]);
                    $settings['canvas_plan'] = (new CanvasPlanValidator())->fallbackPlan(
                        array_map(fn ($s) => (string) $s['scene_id'], $scenes),
                        $aspect
                    );
                }
            }

            // Auto visuals: a maths video never asks the viewer for anything —
            // nobody has screenshots of "a student at a desk" lying around, so
            // every image slot is AI-illustrated at render time instead of
            // requested as an upload. The analyzer is TOLD this in MATH MODE,
            // so the promise must not hinge on how many math cards it happened
            // to pick: a confirmed maths topic is enough on its own. The
            // template signal stays as a safety net for the case where the
            // classifier missed but the director reached for a math card anyway.
            // Stamped as _auto so an explicit user toggle always wins.
            $settings['auto_visuals_auto'] = $mathTopic !== [] || (bool) array_filter(
                $scenes,
                fn ($s) => in_array((string) ($s['layout_template'] ?? ''), $mathTemplates, true)
            );

            // Quality gate (§12.1): lint the final storyboard — everything
            // auto-fixable was already fixed by the validator, so surviving
            // findings become the storyboard's lint report.
            $settings['lint_report'] = \Modules\Project\Support\SceneBudgetLinter::lint($scenes, [
                'theme' => ExplainerRegistry::colorScheme($settings['color_scheme'] ?? null),
                'hook_enabled' => $settings['hook_enabled'] ?? true,
                'outro_enabled' => $settings['outro_enabled'] ?? true,
                'math_mode' => $mathTopic !== [],
            ]);

            // The text pass's trail (fixed + refused) rides the same report.
            if ($textFindings !== []) {
                $settings['lint_report']['items'] = array_merge(
                    $settings['lint_report']['items'] ?? [],
                    $textFindings
                );
                foreach ($textFindings as $finding) {
                    $sev = (string) ($finding['severity'] ?? 'info');
                    $settings['lint_report']['counts'][$sev] = ($settings['lint_report']['counts'][$sev] ?? 0) + 1;
                }
            }

            // Idempotent re-analysis: plan the asset diff against the OLD
            // storyboard before it goes away. First analysis = empty plan.
            $oldSceneRows = $this->project->explainerScenes()->get();
            $oldAssetRows = $this->project->explainerAssets()->get();
            $assetPlan = \Modules\Project\Support\StoryboardDiff::plan(
                $scenes,
                $oldSceneRows->map(fn ($s) => [
                    'scene_id' => (string) $s->scene_id,
                    'order' => (int) $s->order,
                    'layout_template' => (string) $s->layout_template,
                    'narration' => (string) $s->narration,
                    'slots' => $s->slots ?? [],
                ])->all(),
                $oldAssetRows->map(fn ($a) => [
                    'id' => (int) $a->id,
                    'scene_id' => (string) $a->scene_id,
                    'slot_key' => (string) $a->slot_key,
                    'type' => (string) $a->type,
                    'path' => (string) $a->path,
                    'original_name' => (string) $a->original_name,
                ])->all()
            );

            $this->project->explainerScenes()->delete();

            foreach ($scenes as $scene) {
                ExplainerScene::create([
                    'project_id' => $this->project->id,
                    'scene_id' => $scene['scene_id'],
                    'order' => $scene['order'],
                    'duration_seconds' => $scene['duration_seconds'],
                    'narration' => $scene['narration']['text'] ?? '',
                    'layout_template' => $scene['layout_template'],
                    'slots' => $scene['slots'],
                    'transition' => $scene['transition'],
                    'relation' => $scene['relation'] ?? null,
                    'mood' => $scene['mood'] ?? 'neutral',
                ]);
            }

            try {
                $this->applyAssetPlan($assetPlan, $oldAssetRows);
                if ($oldAssetRows->isNotEmpty()) {
                    Log::info('AnalyzeExplainerScriptJob: re-analysis asset diff', array_merge(
                        ['project_id' => $this->project->id, 'scene_matches' => count($assetPlan['scene_map'])],
                        $assetPlan['stats']
                    ));
                }
            } catch (Throwable $e) {
                // A half-applied re-home could leave old-keyed rows that
                // accidentally collide with the NEW scene numbering — safer
                // to fall back to the previous wipe-everything behavior.
                Log::warning('AnalyzeExplainerScriptJob: asset re-home failed, dropping old assets', [
                    'project_id' => $this->project->id,
                    'error' => $e->getMessage(),
                ]);
                $this->project->explainerAssets()->delete();
            }

            // VLM retry nudges are keyed by scene id — re-key the survivors
            // through the scene map so an old nudge can neither poison an
            // unrelated new scene that reuses the number nor be lost for a
            // scene that survived (a stable nudge keeps prompt hashes stable).
            if (is_array($settings['vlm_retry_suffix'] ?? null)) {
                $rekeyed = \Modules\Project\Support\StoryboardDiff::rekeyByScene(
                    $settings['vlm_retry_suffix'],
                    $assetPlan['scene_map']
                );
                if ($rekeyed === []) {
                    unset($settings['vlm_retry_suffix']);
                } else {
                    $settings['vlm_retry_suffix'] = $rekeyed;
                }
            }

            $this->project->update([
                'status' => 'storyboard_ready',
                'progress' => 100,
                'processing_state' => null,
                'settings' => $settings,
            ]);

            $pusher->sendProgress($this->project->id, 100, 'Storyboard ready — upload your assets.');

            Log::info('AnalyzeExplainerScriptJob: storyboard ready', [
                'project_id' => $this->project->id,
                'scene_count' => count($scenes),
            ]);
        } catch (Throwable $e) {
            Log::error('AnalyzeExplainerScriptJob failed: ' . $e->getMessage(), [
                'project_id' => $this->project->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->project->update([
                'status' => 'failed',
                'error_message' => 'Script analysis failed: ' . $e->getMessage(),
            ]);
            $pusher->sendProgress($this->project->id, 0, 'Analysis failed.');
            $this->fail($e);
        }
    }

    /**
     * Apply a StoryboardDiff plan: drop what did not survive (rows AND their
     * files — the old blanket delete leaked every file), then re-key the
     * survivors. Re-keying is two-phase because of the unique
     * (project, scene, slot) index: a swapped pair of scene ids would collide
     * mid-update, so every kept row is parked on an id-unique key first.
     *
     * Narration is the one asset whose FILES are named after the scene id
     * (narration_<scene>.wav plus a .words.json sidecar the cache check
     * derives from the CURRENT scene id) — those are copied to their new
     * names, staged through a temp name so a swap cannot overwrite a source
     * before it is read. If the narration text survived unchanged, the
     * processor's hash check then reuses the wav instead of re-billing TTS.
     *
     * @param \Illuminate\Support\Collection $assetRows the OLD asset models
     */
    private function applyAssetPlan(array $plan, $assetRows): void
    {
        $disk = Storage::disk('public');
        $byId = $assetRows->keyBy('id');

        $keep = [];
        $keptPaths = [];
        $originalScene = [];
        foreach ($plan['assets'] as $action) {
            if (($action['action'] ?? '') === 'keep' && $byId->has($action['id'])) {
                $keep[] = $action;
                $keptPaths[(string) $byId[$action['id']]->path] = true;
                $originalScene[$action['id']] = (string) $byId[$action['id']]->scene_id;
            }
        }

        // Drops first: frees unique keys and cleans the files (best-effort,
        // never a file some kept row still points at).
        foreach ($plan['assets'] as $action) {
            if (($action['action'] ?? '') !== 'drop' || !$byId->has($action['id'])) {
                continue;
            }
            $row = $byId[$action['id']];
            $path = (string) $row->path;
            if ($path !== '' && !isset($keptPaths[$path])) {
                try {
                    $disk->delete($path);
                    if (str_ends_with($path, '.wav')) {
                        $disk->delete(substr($path, 0, -4) . '.words.json');
                    }
                } catch (Throwable) {
                    // best-effort cleanup only
                }
            }
            $row->delete();
        }

        // Park every survivor on an id-unique scene key.
        foreach ($keep as $action) {
            $byId[$action['id']]->update(['scene_id' => '__rehome__' . $action['id']]);
        }

        // Stage narration file copies for rows whose scene id changed.
        $dir = "projects/{$this->project->id}/explainer";
        $copies = [];
        foreach ($keep as $action) {
            if (($action['slot_key'] ?? '') !== '__narration__'
                || $originalScene[$action['id']] === $action['scene_id']
            ) {
                continue;
            }
            $from = (string) $byId[$action['id']]->path;
            if ($from === '' || !str_ends_with($from, '.wav') || !$disk->exists($from)) {
                continue;
            }
            $fromWords = substr($from, 0, -4) . '.words.json';
            $copies[] = [
                'id' => $action['id'],
                'from' => $from,
                'from_words' => $disk->exists($fromWords) ? $fromWords : null,
                'tmp' => "{$dir}/narration_{$action['scene_id']}.rehome.wav",
                'tmp_words' => "{$dir}/narration_{$action['scene_id']}.rehome.words.json",
                'to' => "{$dir}/narration_{$action['scene_id']}.wav",
                'to_words' => "{$dir}/narration_{$action['scene_id']}.words.json",
                'ok' => false,
            ];
        }
        foreach ($copies as &$copy) {
            try {
                $disk->delete($copy['tmp']);
                $copy['ok'] = $disk->copy($copy['from'], $copy['tmp']);
                if ($copy['ok'] && $copy['from_words'] !== null) {
                    $disk->delete($copy['tmp_words']);
                    $disk->copy($copy['from_words'], $copy['tmp_words']);
                }
            } catch (Throwable) {
                $copy['ok'] = false;
            }
        }
        unset($copy);

        $finalTargets = [];
        foreach ($copies as $copy) {
            if ($copy['ok']) {
                $finalTargets[$copy['to']] = true;
                $finalTargets[$copy['to_words']] = true;
            }
        }
        foreach ($copies as $copy) {
            if (!$copy['ok']) {
                continue;
            }
            try {
                $disk->delete($copy['to']);
                $disk->move($copy['tmp'], $copy['to']);
                if ($copy['from_words'] !== null && $disk->exists($copy['tmp_words'])) {
                    $disk->delete($copy['to_words']);
                    $disk->move($copy['tmp_words'], $copy['to_words']);
                }
                $byId[$copy['id']]->update(['path' => $copy['to']]);
                // The old-named files are orphans now — unless another moved
                // narration is landing exactly there.
                if (!isset($finalTargets[$copy['from']])) {
                    $disk->delete($copy['from']);
                }
                if ($copy['from_words'] !== null && !isset($finalTargets[$copy['from_words']])) {
                    $disk->delete($copy['from_words']);
                }
            } catch (Throwable) {
                // Copy failed mid-way: the row keeps its old path; the cache
                // check misses (sidecar name mismatch) and TTS re-synthesizes.
            }
        }

        // Land every survivor on its final scene/slot.
        foreach ($keep as $action) {
            $byId[$action['id']]->update([
                'scene_id' => $action['scene_id'],
                'slot_key' => $action['slot_key'],
            ]);
        }
    }
}
