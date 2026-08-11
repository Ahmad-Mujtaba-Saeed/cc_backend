<?php

namespace Modules\Project\Jobs;

use App\Services\PusherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Services\StoryboardRevisionService;
use Modules\Project\Support\CanvasPlanValidator;
use Modules\Project\Support\ChapterPlanValidator;
use Modules\Project\Support\ExplainerAssetRehome;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;
use Modules\Project\Support\StoryboardDiff;
use Modules\Project\Support\StoryboardRevision;
use Throwable;

/**
 * ReviseExplainerStoryboardJob — apply a user's note to the storyboard.
 *
 * Re-analyze rebuilds the whole video from the script; this rebuilds only the
 * cards the note is about. That distinction is the entire feature, and it is
 * enforced structurally rather than by asking a model nicely:
 *
 *  · an untouched scene's ROW is never deleted, so every asset keyed to
 *    (project, scene_id, slot_key) keeps pointing at it — uploads, cached
 *    narration wavs and their word-timing sidecars, AI slot fills, stock
 *    clips, scenario sprites;
 *  · only the changed cards go back through the validator, so the editorial
 *    passes (dead-air merges, echo folds, hook lift, variety, transitions)
 *    cannot re-cut scenes nobody complained about;
 *  · the composition plans are REPAIRED against the new scene list instead of
 *    re-directed, so an edit costs no director call and no relayout;
 *  · the project never leaves `storyboard_ready` — a failed revision leaves
 *    the storyboard exactly as it was and says so.
 *
 * @see StoryboardRevision  every rule that decides what may change
 */
class ReviseExplainerStoryboardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Deliberately 1. A retry would silently re-run both paid calls and, worse,
     * re-apply a revision on top of the one that may already have landed.
     */
    public int $tries = 1;

    public int $timeout = 300;

    /**
     * How long the storyboard stays locked before a new revision may start.
     *
     * The lock exists so two revisions cannot fight over the same board, and
     * it EXPIRES so a worker killed mid-job cannot lock a storyboard forever.
     * Generous on purpose: this job shares one queue with rendering, so it can
     * legitimately sit waiting far longer than it runs, and the ordinary
     * failure paths (an exception, the 300s timeout) release the lock through
     * failed() long before this matters.
     */
    public const LOCK_MINUTES = 30;

    public function __construct(public Project $project, public string $request)
    {
        $this->onQueue('video-processing');
    }

    /**
     * The job died without an exception PHP could see (timeout, worker
     * restart, OOM). The storyboard is untouched — say so and unlock.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ReviseExplainerStoryboardJob: failed permanently', [
            'project_id' => $this->project->id,
            'error' => $exception->getMessage(),
        ]);

        try {
            $this->project->refresh();
            $this->finish('error', 'The revision stopped unexpectedly. Your storyboard was left exactly as it was.');
        } catch (Throwable $e) {
            Log::warning('ReviseExplainerStoryboardJob: could not record the failure: ' . $e->getMessage());
        }
    }

    public function handle(): void
    {
        \Modules\Project\Services\CostTracker::setContext($this->project);
        $pusher = new PusherService();

        try {
            // Restart the lock clock now that the work is actually starting:
            // the controller stamped it at dispatch, and time spent queued
            // behind a render must not eat into the window.
            $this->project->refresh();
            $settings = $this->project->settings ?? [];
            if (is_array($settings['revision'] ?? null)) {
                $settings['revision']['started_at'] = now()->toIso8601String();
                $this->project->update(['settings' => $settings]);
            }

            $mathMode = !empty($settings['math_topic']);
            $mode = (string) ($settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode());
            $aspect = (string) ($this->project->aspect_ratio ?: '16:9');

            $rows = $this->project->explainerScenes()->orderBy('order')->get();
            if ($rows->isEmpty()) {
                $this->finish('error', 'There is no storyboard to revise yet.');

                return;
            }
            $current = $rows->map(fn ($r) => $this->toSceneArray($r))->all();

            $assetRows = $this->project->explainerAssets()->get();
            $uploads = [];
            foreach ($assetRows as $asset) {
                if (StoryboardDiff::isUpload([
                    'slot_key' => (string) $asset->slot_key,
                    'original_name' => (string) $asset->original_name,
                ])) {
                    $uploads[$asset->scene_id . '::' . $asset->slot_key] = (string) $asset->original_name;
                }
            }

            $context = [
                'title' => (string) $this->project->title,
                'aspect_ratio' => $aspect,
                'math_mode' => $mathMode,
                'composition_mode' => $mode,
                'guide' => (string) ($settings['guide'] ?? ''),
                'uploads' => $uploads,
            ];

            $pusher->sendProgress($this->project->id, 20, 'Reading your notes against the storyboard…');

            $service = new StoryboardRevisionService($mathMode);
            if (!$service->isConfigured()) {
                $this->finish('error', 'AI revisions are unavailable right now — no model is configured.');

                return;
            }

            $plan = $service->plan($current, $this->request, $context);
            if ($plan === null) {
                $this->finish('error', 'The AI could not be reached. Your storyboard was left exactly as it was.');

                return;
            }

            $normalized = StoryboardRevision::normalizePlan($plan['ops'], $current);

            if ($normalized['ops'] === []) {
                // Not a failure: the planner either had a question or judged
                // that nothing in the note maps onto a card.
                $this->finish('done', $plan['reply'] !== ''
                    ? $plan['reply']
                    : 'Nothing was changed — I could not tell which scene that note is about. Try naming the scene number.', [
                        'reply' => $plan['reply'],
                        'summary' => 'Nothing was changed.',
                        'findings' => $normalized['findings'],
                    ]);

                return;
            }

            $pusher->sendProgress($this->project->id, 55, 'Rewriting the cards you asked about…');

            $drafts = $service->draft($normalized['ops'], $current, $this->request, $context);

            $validator = new ShotListValidator();
            $applyOptions = [
                'math_mode' => $mathMode,
                'aspect_ratio' => $aspect,
                'revision' => (int) ($settings['storyboard_rev'] ?? 0) + 1,
            ];
            $result = StoryboardRevision::apply($current, $normalized['ops'], $drafts, $validator, $applyOptions);

            // A card whose content did not parse is thrown away rather than
            // shipped in the degraded form the validator falls back to (a
            // versus_card that failed to parse becomes the same sentence
            // printed twice — worse than the card the user already had), and
            // so is one that changed the card without writing new narration.
            // Both are worth exactly one more ask with the fault named.
            //
            // The second pass runs with `final`, where the repairable faults
            // are repaired instead of refused: having asked twice, doing
            // nothing at all is the one outcome the user cannot use. apply()
            // is pure, so it re-runs from the ORIGINAL board.
            if ($result['rejected'] !== []) {
                $retry = $service->draft(
                    $normalized['ops'],
                    $current,
                    $this->request,
                    $context,
                    $result['rejected']
                );
                $second = StoryboardRevision::apply(
                    $current,
                    $normalized['ops'],
                    array_merge($drafts, $retry),
                    $validator,
                    $applyOptions + ['final' => true]
                );
                if (count($second['rejected']) <= count($result['rejected'])) {
                    $result = $second;
                }
            }

            $scenes = $result['scenes'];
            $findings = array_merge($normalized['findings'], $result['findings']);
            $touched = array_merge($result['changed'], $result['added']);

            if ($touched === [] && $result['removed'] === [] && $result['moved'] === []) {
                $this->finish('done', $plan['reply'] !== ''
                    ? $plan['reply']
                    : 'Nothing was changed — the rewrite came back with nothing usable.', [
                        'reply' => $plan['reply'],
                        'summary' => 'Nothing was changed.',
                        'findings' => $findings,
                    ]);

                return;
            }

            $pusher->sendProgress($this->project->id, 80, 'Fitting the new cards into the video…');

            // Board-only invariant, applied where the mode is actually KNOWN
            // (the same reason the analyze job calls this rather than the
            // validator predicting it): the maths board draws its own visuals,
            // so a media slot there is filled by nothing at all.
            if ($mode === 'math_board' && $touched !== []) {
                $scenes = $this->stripBoardMediaOn($scenes, $touched, $validator);
            }

            // Scene 1 has no incoming cut. Normally applySignatureTransitions
            // guarantees that for the whole board; a revision only re-validates
            // the cards it changed, so the one cross-scene rule that a reorder
            // can break is re-asserted here.
            $scenes = $this->normalizeOpening($scenes, $current);

            $this->persist($scenes, $result['removed']);
            $this->rehomeAssets($scenes, $current, $result['removed'], $assetRows);

            $sceneSetChanged = $result['added'] !== [] || $result['removed'] !== [] || $result['moved'] !== [];
            $this->repairPlans($settings, $scenes, $mode, $aspect, $sceneSetChanged);

            // A rewritten card's old VLM retry nudge described an image that no
            // longer exists; keeping it would poison the new prompt hash.
            if (is_array($settings['vlm_retry_suffix'] ?? null)) {
                foreach (array_merge($touched, $result['removed']) as $sceneId) {
                    unset($settings['vlm_retry_suffix'][$sceneId]);
                }
                if ($settings['vlm_retry_suffix'] === []) {
                    unset($settings['vlm_retry_suffix']);
                }
            }

            $settings['lint_report'] = SceneBudgetLinter::lint($scenes, [
                'theme' => ExplainerRegistry::colorScheme($settings['color_scheme'] ?? null),
                'hook_enabled' => $settings['hook_enabled'] ?? true,
                'outro_enabled' => $settings['outro_enabled'] ?? true,
                'math_mode' => $mathMode,
            ]);
            if ($findings !== []) {
                $settings['lint_report']['items'] = array_merge($settings['lint_report']['items'] ?? [], $findings);
                foreach ($findings as $finding) {
                    $sev = (string) ($finding['severity'] ?? 'info');
                    $settings['lint_report']['counts'][$sev] = ($settings['lint_report']['counts'][$sev] ?? 0) + 1;
                }
            }

            // Bumping this changes the project's look hash, which is exactly
            // right: the preview still is stale and so is any finished MP4.
            $settings['storyboard_rev'] = (int) ($settings['storyboard_rev'] ?? 0) + 1;
            $this->project->update(['settings' => $settings]);

            Log::info('ReviseExplainerStoryboardJob: revision applied', [
                'project_id' => $this->project->id,
                'changed' => $result['changed'],
                'added' => $result['added'],
                'removed' => $result['removed'],
                'moved' => $result['moved'],
                'attempts' => $service->attempts(),
            ]);

            $this->finish('done', $plan['reply'] !== '' ? $plan['reply'] : $result['summary'], [
                'reply' => $plan['reply'],
                'summary' => $result['summary'],
                'changed' => $result['changed'],
                'added' => $result['added'],
                'removed' => $result['removed'],
                'moved' => $result['moved'],
                'findings' => $findings,
            ]);
        } catch (Throwable $e) {
            Log::error('ReviseExplainerStoryboardJob failed: ' . $e->getMessage(), [
                'project_id' => $this->project->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->finish('error', 'The revision could not be applied: ' . $e->getMessage());
        }
    }

    // --------------------------------------------------------------- helpers

    /** A DB row in the validator's scene shape. */
    private function toSceneArray(ExplainerScene $row): array
    {
        return [
            'scene_id' => (string) $row->scene_id,
            'order' => (int) $row->order,
            'duration_seconds' => (float) $row->duration_seconds,
            'narration' => ['text' => (string) $row->narration],
            'layout_template' => (string) $row->layout_template,
            'slots' => $row->slots ?? [],
            'transition' => (string) $row->transition,
            'relation' => $row->relation ?? null,
            'mood' => $row->mood ?? 'neutral',
        ];
    }

    /** Board media strip, restricted to the scenes this revision touched. */
    private function stripBoardMediaOn(array $scenes, array $touched, ShotListValidator $validator): array
    {
        $set = array_flip($touched);
        foreach ($scenes as $i => $scene) {
            if (!isset($set[(string) $scene['scene_id']])) {
                continue;
            }
            $stripped = $validator->stripBoardMediaSlots([$scene]);
            $scenes[$i] = $stripped[0] ?? $scene;
        }

        return $scenes;
    }

    /**
     * Scene 1 opens the video: no incoming transition, relation "opening".
     * Only ever writes to a scene that actually became (or stopped being) the
     * opener, so an untouched board is left byte-identical.
     */
    private function normalizeOpening(array $scenes, array $before): array
    {
        if ($scenes === []) {
            return $scenes;
        }

        $wasFirst = (string) ($before[0]['scene_id'] ?? '');
        $isFirst = (string) ($scenes[0]['scene_id'] ?? '');

        if ($scenes[0]['transition'] !== 'none' || ($scenes[0]['relation'] ?? null) !== 'opening') {
            $scenes[0]['transition'] = 'none';
            $scenes[0]['relation'] = 'opening';
        }

        // The old opener is now mid-video, where "opening" means nothing.
        if ($wasFirst !== '' && $wasFirst !== $isFirst) {
            foreach ($scenes as $i => $scene) {
                if ($i > 0 && (string) $scene['scene_id'] === $wasFirst && ($scene['relation'] ?? null) === 'opening') {
                    $scenes[$i]['relation'] = ExplainerRegistry::defaultRelation();
                    if (($scene['transition'] ?? 'none') === 'none') {
                        $scenes[$i]['transition'] = ExplainerRegistry::defaultTransition();
                    }
                }
            }
        }

        return $scenes;
    }

    /**
     * Write the revised board. Rows are UPDATED in place wherever the scene id
     * survived — that is what keeps the assets attached; only genuinely
     * removed scenes are deleted and only genuinely new ones inserted.
     *
     * @param string[] $removed
     */
    private function persist(array $scenes, array $removed): void
    {
        if ($removed !== []) {
            $this->project->explainerScenes()->whereIn('scene_id', $removed)->delete();
        }

        $existing = $this->project->explainerScenes()->get()->keyBy('scene_id');

        foreach ($scenes as $scene) {
            $payload = [
                'order' => (int) $scene['order'],
                'duration_seconds' => (float) $scene['duration_seconds'],
                'narration' => (string) ($scene['narration']['text'] ?? ''),
                'layout_template' => (string) $scene['layout_template'],
                'slots' => $scene['slots'],
                'transition' => (string) $scene['transition'],
                'relation' => $scene['relation'] ?? null,
                'mood' => $scene['mood'] ?? 'neutral',
            ];

            $row = $existing->get((string) $scene['scene_id']);
            if ($row) {
                $row->update($payload);
                continue;
            }
            ExplainerScene::create($payload + [
                'project_id' => $this->project->id,
                'scene_id' => (string) $scene['scene_id'],
            ]);
        }
    }

    /**
     * Re-home the assets of the cards that changed.
     *
     * The scene map is CERTAIN here (a revision edits named scenes in place),
     * so the content fingerprinting a re-analysis needs would only add a way
     * to be wrong. Orphan rescue is off for the same reason it is on there: a
     * re-analysis has no other chance to save an upload, while a revision must
     * not silently move a user's photo onto a scene they never mentioned.
     *
     * @param string[] $removed
     */
    private function rehomeAssets(array $scenes, array $before, array $removed, $assetRows): void
    {
        if ($assetRows->isEmpty()) {
            return;
        }

        $gone = array_flip($removed);
        $sceneMap = [];
        foreach ($before as $scene) {
            $id = (string) $scene['scene_id'];
            if (!isset($gone[$id])) {
                $sceneMap[$id] = $id;
            }
        }

        $plan = StoryboardDiff::planWithMap(
            $scenes,
            $before,
            $assetRows->map(fn ($a) => [
                'id' => (int) $a->id,
                'scene_id' => (string) $a->scene_id,
                'slot_key' => (string) $a->slot_key,
                'type' => (string) $a->type,
                'path' => (string) $a->path,
                'original_name' => (string) $a->original_name,
            ])->all(),
            $sceneMap,
            false
        );

        try {
            ExplainerAssetRehome::apply($this->project, $plan, $assetRows);
            Log::info('ReviseExplainerStoryboardJob: asset diff', array_merge(
                ['project_id' => $this->project->id],
                $plan['stats']
            ));
        } catch (Throwable $e) {
            // Unlike a re-analysis there is no safe wipe to fall back to: the
            // storyboard is mostly the SAME board, so dropping every asset
            // would destroy work the user never put at risk. Leave the rows as
            // they are — a stale row can only point at a slot that still
            // exists, and the content-hash checks downstream re-make anything
            // whose card actually changed.
            Log::warning('ReviseExplainerStoryboardJob: asset re-home failed, assets left as they were', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Keep the composition plans addressing the right scenes.
     *
     * Both validators already handle exactly this: unknown ids are dropped and
     * unlisted scenes are absorbed (a chapter swallows a neighbouring new
     * scene; the canvas auto-places one beside its neighbours). So a revision
     * costs no director call — and when the scene SET did not change, the
     * plans still address the same ids and are left completely alone.
     */
    private function repairPlans(array &$settings, array $scenes, string $mode, string $aspect, bool $sceneSetChanged): void
    {
        if (!$sceneSetChanged) {
            return;
        }

        $ids = array_map(fn ($s) => (string) $s['scene_id'], $scenes);
        $byId = [];
        foreach ($scenes as $scene) {
            $byId[(string) $scene['scene_id']] = $scene;
        }

        try {
            if ($mode === 'canvas_journey' && is_array($settings['canvas_plan'] ?? null)) {
                $settings['canvas_plan'] = (new CanvasPlanValidator())
                    ->validate($settings['canvas_plan'], $scenes, $aspect)['plan'];

                return;
            }

            if ($mode !== 'hybrid' || !is_array($settings['chapter_plan'] ?? null)) {
                return;
            }

            // Chapters are re-identified by the repair, so the per-chapter
            // canvas worlds are carried across on a stable anchor: the scenes
            // they contained.
            $canvasByScene = [];
            foreach ((array) ($settings['chapter_plan']['chapters'] ?? []) as $chapter) {
                if (!is_array($chapter['canvas'] ?? null)) {
                    continue;
                }
                foreach ((array) ($chapter['scene_ids'] ?? []) as $sid) {
                    $canvasByScene[(string) $sid] = $chapter['canvas'];
                }
            }

            $plan = (new ChapterPlanValidator())->validate($settings['chapter_plan'], $ids)['plan'];

            foreach ($plan['chapters'] as &$chapter) {
                if (($chapter['mode'] ?? '') !== 'canvas') {
                    $chapter['canvas'] = null;
                    continue;
                }
                $chapterScenes = array_values(array_filter(array_map(
                    fn ($sid) => $byId[(string) $sid] ?? null,
                    (array) $chapter['scene_ids']
                )));

                $prior = [];
                foreach ((array) $chapter['scene_ids'] as $sid) {
                    if (isset($canvasByScene[(string) $sid])) {
                        $prior = $canvasByScene[(string) $sid];
                        break;
                    }
                }

                $chapter['canvas'] = (new CanvasPlanValidator())
                    ->validate($prior, $chapterScenes, $aspect)['plan'];
            }
            unset($chapter);

            $settings['chapter_plan'] = $plan;
        } catch (Throwable $e) {
            // A stale plan is not fatal — the renderer validates it again and
            // falls back to a deterministic layout for anything it cannot use.
            Log::warning('ReviseExplainerStoryboardJob: plan repair failed (non-fatal)', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Unlock the project and record the outcome. Always runs, on every path,
     * so the storyboard can never be left showing a spinner forever.
     */
    private function finish(string $state, string $message, array $extra = []): void
    {
        $this->project->refresh();
        $settings = $this->project->settings ?? [];
        $revision = is_array($settings['revision'] ?? null) ? $settings['revision'] : [];

        $entry = array_merge([
            'at' => now()->toIso8601String(),
            'request' => mb_substr($this->request, 0, 1000),
            'reply' => '',
            'summary' => '',
            'changed' => [],
            'added' => [],
            'removed' => [],
            'moved' => [],
            'findings' => [],
        ], $extra);
        $entry['state'] = $state;
        $entry['message'] = $message;

        $revision['state'] = $state;
        $revision['last'] = $entry;
        $revision['count'] = (int) ($revision['count'] ?? 0) + 1;
        unset($revision['started_at']);

        $log = is_array($revision['log'] ?? null) ? $revision['log'] : [];
        array_unshift($log, [
            'at' => $entry['at'],
            'request' => $entry['request'],
            'summary' => $state === 'done' ? ($entry['summary'] ?: $message) : $message,
            'state' => $state,
        ]);
        $revision['log'] = array_slice($log, 0, 10);

        $settings['revision'] = $revision;
        $this->project->update(['settings' => $settings]);

        try {
            (new PusherService())->sendProgress(
                $this->project->id,
                100,
                $state === 'done' ? 'Storyboard updated.' : 'Revision failed.'
            );
        } catch (Throwable $e) {
            Log::warning('ReviseExplainerStoryboardJob: progress push failed: ' . $e->getMessage());
        }
    }
}
