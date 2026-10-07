<?php

namespace Modules\Project\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Project\Models\Project;
use Modules\Project\Services\CostTracker;
use Modules\Project\Services\HeroSceneService;
use Modules\Project\Services\RemotionRenderService;
use Modules\Project\Support\ExplainerSceneAssembler;
use Modules\Project\Support\HeroScenes;
use Throwable;

/**
 * GenerateHeroScenesJob — write the AI hero scenes for one project.
 *
 * With no scene ids it CASTS (HeroScenes::cast: the strongest beats, never
 * adjacent, never on the maths board or over the user's own media); with ids
 * it writes exactly those (the storyboard's "Make AI scene" / "Redo").
 *
 * Each scene is written, compiled, test-rendered and reviewed by
 * HeroSceneService; the result lands in settings.hero_scenes[scene_id]. A
 * scene whose hero never passes keeps its card — nothing about the video is
 * worse for a failed attempt. Settings are re-read before every write so a
 * user editing the storyboard meanwhile is not clobbered.
 */
class GenerateHeroScenesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A retry would re-buy every model call. */
    public int $tries = 1;

    /** Up to ~3 writes + 3 test renders per scene, two scenes. */
    public int $timeout = 1800;

    /**
     * @param  string[]|null  $sceneIds
     * @param  bool  $clipsOnly  write nothing new: record the Play-tab preview
     *                           clip of every ready hero that has none yet
     */
    public function __construct(public Project $project, public ?array $sceneIds = null, public bool $clipsOnly = false)
    {
        $this->onQueue('video-processing');
    }

    public function handle(): void
    {
        CostTracker::setContext($this->project);
        $settings = $this->project->settings ?? [];
        if (($settings['composition_mode'] ?? '') === 'math_board') {
            return;
        }

        $autoVisuals = (bool) ($settings['auto_visuals'] ?? $settings['auto_visuals_auto'] ?? false);
        $scenes = ExplainerSceneAssembler::assemble($this->project, $autoVisuals)['scenes'];
        if ($scenes === []) {
            return;
        }

        if ($this->clipsOnly) {
            $ids = array_keys(array_filter((array) ($settings['hero_scenes'] ?? []), fn ($e) => is_array($e)
                && ($e['status'] ?? null) === 'ready' && empty($e['clip'])));
            $ids = $this->sceneIds !== null ? array_values(array_intersect($ids, $this->sceneIds)) : $ids;
        } else {
            $ids = $this->sceneIds ?? HeroScenes::cast(
            $scenes,
            (int) config('services.openai.hero_max_per_video', 2),
            (string) ($settings['composition_mode'] ?? '')
            );
        }
        // When casting, a hero already written for this exact scene is kept.
        if ($this->sceneIds === null && !$this->clipsOnly) {
            $ids = array_values(array_filter($ids, function ($id) use ($settings, $scenes) {
                $entry = $settings['hero_scenes'][$id] ?? null;
                if (!is_array($entry) || ($entry['status'] ?? null) !== 'ready') {
                    return true;
                }
                foreach ($scenes as $s) {
                    if ((string) $s['scene_id'] === (string) $id) {
                        return ($entry['content_hash'] ?? null) !== HeroScenes::contentHash($s);
                    }
                }

                return false;
            }));
        }
        if ($ids === []) {
            return;
        }

        $aspect = $this->project->aspect_ratio ?? '16:9';
        [$width, $height] = RemotionRenderService::dimensionsForAspect($aspect);
        $fps = 30;

        // The look the test renders in: the project's real shot list, with no
        // heroes on it yet (each test mounts only the scene being written).
        $withoutHeroes = clone $this->project;
        $withoutHeroes->settings = array_diff_key($settings, ['hero_scenes' => 1]);
        $payload = (new RemotionRenderService())->buildRenderPayload($withoutHeroes, $scenes, '', $aspect, $width, $height);
        $shotList = $payload['shot_list'];
        $byId = [];
        foreach ($shotList['scenes'] as $ps) {
            $byId[(string) $ps['scene_id']] = $ps;
        }

        foreach ($ids as $sceneId) {
            $scene = null;
            foreach ($scenes as $s) {
                if ((string) $s['scene_id'] === (string) $sceneId) {
                    $scene = $s;
                    break;
                }
            }
            if ($scene === null || !isset($byId[$sceneId])) {
                continue;
            }

            if ($this->clipsOnly) {
                $module = HeroScenes::module($settings, (string) $sceneId);
                $clip = $module === null ? null
                    : (new HeroSceneService())->renderClip($this->project, (string) $sceneId, (string) $module['js'], $byId[$sceneId], $shotList, $fps, $width, $height);
                if ($clip !== null) {
                    $this->project->refresh();
                    $entry = $this->project->settings['hero_scenes'][$sceneId] ?? null;
                    if (is_array($entry) && ($entry['status'] ?? null) === 'ready') {
                        $this->put($sceneId, array_merge($entry, $clip));
                    }
                }
                Log::info('GenerateHeroScenesJob: preview clip', ['project_id' => $this->project->id, 'scene_id' => $sceneId, 'ok' => $clip !== null]);
                continue;
            }

            $this->put($sceneId, ['status' => 'pending', 'updated_at' => now()->toIso8601String()]);
            try {
                $service = new HeroSceneService();
                $result = $service->generate($this->project, $scene, $byId[$sceneId], $shotList, $fps, $width, $height);
                $entry = $result['entry'];
                unset($entry['last_code']);
                $this->put($sceneId, $entry);
                Log::info('GenerateHeroScenesJob: scene done', [
                    'project_id' => $this->project->id,
                    'scene_id' => $sceneId,
                    'ok' => $result['ok'],
                    'model' => $service->model(),
                    'cost_usd' => $entry['cost_usd'] ?? null,
                ]);
            } catch (Throwable $e) {
                Log::warning('GenerateHeroScenesJob: scene failed', ['project_id' => $this->project->id, 'scene_id' => $sceneId, 'error' => $e->getMessage()]);
                $this->put($sceneId, ['status' => 'failed', 'error' => $e->getMessage(), 'updated_at' => now()->toIso8601String()]);
            }
        }
    }

    /** Write one scene's entry against FRESH settings (the user may be editing). */
    private function put(string $sceneId, array $entry): void
    {
        $this->project->refresh();
        $settings = $this->project->settings ?? [];
        $settings['hero_scenes'][$sceneId] = $entry;
        $this->project->update(['settings' => $settings]);
    }

    public function failed(Throwable $exception): void
    {
        // A killed job must not leave scenes stuck on "pending" forever.
        $this->project->refresh();
        $settings = $this->project->settings ?? [];
        foreach ((array) ($settings['hero_scenes'] ?? []) as $id => $entry) {
            if (($entry['status'] ?? null) === 'pending') {
                $settings['hero_scenes'][$id] = ['status' => 'failed', 'error' => 'interrupted', 'updated_at' => now()->toIso8601String()];
            }
        }
        $this->project->update(['settings' => $settings]);
    }
}
