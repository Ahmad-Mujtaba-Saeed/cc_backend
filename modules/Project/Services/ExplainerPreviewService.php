<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ExplainerSceneAssembler;
use Modules\Project\Support\ExplainerTimingMap;

/**
 * ExplainerPreviewService
 *
 * Freezes one frame of the REAL composition so the storyboard can show what a
 * style change looks like without paying for a render. Every look-affecting
 * setting (colour scheme, brand colour, font pack, skin, motion style,
 * composition mode, board skin, captions, logo) feeds a fingerprint; the PNG
 * is stored under that fingerprint, so re-picking a style you already looked
 * at is a cache hit and returns instantly. Flipping back and forth is free.
 *
 * The preview is deliberately NOT a second renderer: it goes through
 * ExplainerSceneAssembler + RemotionRenderService::buildRenderPayload, the
 * same two pieces the MP4 uses.
 */
class ExplainerPreviewService
{
    /** Keep the last few looks per project; older stills are pruned. */
    private const KEEP_PER_PROJECT = 12;

    /**
     * @return array{
     *   success: bool, url?: string, path?: string, fingerprint?: string,
     *   scene_id?: string, cached?: bool, render_seconds?: float, error?: string
     * }
     */
    public function preview(Project $project, ?string $sceneId = null): array
    {
        $settings = $project->settings ?? [];

        $autoVisuals = (bool) ($settings['auto_visuals'] ?? $settings['auto_visuals_auto'] ?? false);
        $assembled = ExplainerSceneAssembler::assemble($project, $autoVisuals);
        $scenes = $assembled['scenes'];

        if (empty($scenes)) {
            return ['success' => false, 'error' => 'No storyboard scenes to preview yet.'];
        }

        $sceneId = $this->resolveSceneId($scenes, $sceneId);
        $frame = $this->frameFor($project, $scenes, $sceneId);

        $fingerprint = $this->fingerprint($project, $sceneId, $scenes);
        $relativePath = "explainer/{$project->id}/previews/{$fingerprint}.png";

        // Cache hit: this exact look at this exact scene was already frozen.
        if (Storage::disk('public')->exists($relativePath)) {
            return [
                'success' => true,
                'url' => Storage::disk('public')->url($relativePath),
                'path' => $relativePath,
                'fingerprint' => $fingerprint,
                'scene_id' => $sceneId,
                'cached' => true,
            ];
        }

        $service = new RemotionRenderService();
        if (!$service->isAvailable()) {
            return ['success' => false, 'error' => 'Preview service is not running.'];
        }

        $result = $service->previewStill($project, $scenes, $relativePath, $frame);

        if (!($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'Preview failed'];
        }

        $this->prune($project, $relativePath);

        return [
            'success' => true,
            'url' => Storage::disk('public')->url($relativePath),
            'path' => $relativePath,
            'fingerprint' => $fingerprint,
            'scene_id' => $sceneId,
            'cached' => false,
            'render_seconds' => (float) ($result['render_seconds'] ?? 0),
        ];
    }

    /**
     * Hash of every knob that changes how the video LOOKS — nothing else.
     * Music, SFX and narration voice are deliberately absent: they cannot
     * change a frame, so they must neither bust the preview cache nor mark a
     * finished render as stale.
     *
     * The processor stamps this at render time (settings['rendered_look']);
     * the storyboard compares it to the live value to decide whether the MP4
     * on screen still reflects the current settings.
     */
    public static function lookHash(Project $project): string
    {
        $settings = $project->settings ?? [];

        $payload = [
            'v' => 1,
            'aspect' => $project->aspect_ratio,
            'color_scheme' => $settings['color_scheme'] ?? null,
            'brand_color' => $settings['brand']['color'] ?? null,
            'brand_logo' => $settings['brand']['logo_path'] ?? null,
            'font_pack' => $settings['font_pack'] ?? null,
            'skin' => $settings['skin'] ?? null,
            'skin_auto' => $settings['skin_auto'] ?? null,
            'motion_style' => $settings['motion_style'] ?? null,
            'motion_style_auto' => $settings['motion_style_auto'] ?? null,
            'composition_mode' => $settings['composition_mode'] ?? null,
            'board_style' => $settings['board_style'] ?? null,
            'board_style_auto' => $settings['board_style_auto'] ?? null,
            'captions' => $settings['captions_enabled'] ?? null,
            'chapter_chip' => $settings['chapter_chip'] ?? null,
            'accent_shift' => $settings['accent_shift'] ?? null,
            'backdrop' => $settings['backdrop_enabled'] ?? null,
        ];

        // An AI revision rewrites CONTENT, not a style knob — but it changes
        // frames, so both things this hash drives must react to it: the
        // preview cache has to miss, and a finished MP4 has to read as stale.
        //
        // Added conditionally on purpose. Putting the key in unconditionally
        // would change the hash of every project that has never been revised,
        // and every completed video in the system would suddenly claim its
        // render no longer matches its settings.
        if (!empty($settings['storyboard_rev'])) {
            $payload['storyboard_rev'] = (int) $settings['storyboard_rev'];
        }

        // Smoothness knobs, added on the same conditional terms and for the
        // same reason: a project that never touched them must keep the hash it
        // has, or every finished video in the system reads as stale the day
        // these shipped. A project that DID touch them has a genuinely
        // different render — 60fps is a different clock, and the blur changes
        // every flight frame.
        if (!empty($settings['render_fps'])) {
            $payload['render_fps'] = (int) $settings['render_fps'];
        }
        if (array_key_exists('motion_blur_enabled', $settings)) {
            $payload['motion_blur'] = (bool) $settings['motion_blur_enabled'];
        }

        return substr(md5(json_encode($payload, JSON_UNESCAPED_UNICODE)), 0, 20);
    }

    /**
     * The still's cache key: the look, plus the scene being previewed and that
     * scene's own content — an edited slot or a re-analysis must invalidate
     * the PNG even when no style knob moved.
     */
    public function fingerprint(Project $project, string $sceneId, ?array $scenes = null): string
    {
        $settings = $project->settings ?? [];

        if ($scenes === null) {
            $autoVisuals = (bool) ($settings['auto_visuals'] ?? $settings['auto_visuals_auto'] ?? false);
            $scenes = ExplainerSceneAssembler::assemble($project, $autoVisuals)['scenes'];
        }

        $scene = null;
        foreach ($scenes as $s) {
            if ((string) ($s['scene_id'] ?? '') === $sceneId) {
                $scene = $s;
                break;
            }
        }

        return substr(md5(json_encode([
            'look' => self::lookHash($project),
            'scene_id' => $sceneId,
            'scene' => $scene,
        ], JSON_UNESCAPED_UNICODE)), 0, 20);
    }

    /**
     * The whole shot list, ready for @remotion/player to PLAY in the browser.
     *
     * The frozen still above answers "what does this look like"; this answers
     * "how does it move" — the storyboard's play tab runs the real
     * `ExplainerVideo` component on this payload, so timing, transitions,
     * reveals and camera are the renderer's own, not an approximation.
     *
     * It is the same {@see RemotionRenderService::buildRenderPayload} the MP4
     * uses, with exactly two deliberate differences:
     *
     *  - `output_path` goes: it is a filesystem path on the render host and has
     *    no business being sent to a browser.
     *  - `narration_audio_url` goes on every scene: the preview is silent of
     *    voice by design (music and SFX still play). That also means the music
     *    bed does not duck, because the renderer derives its duck windows from
     *    the presence of narration audio — a preview plays its music a little
     *    louder than the export does.
     *
     * `narration_words` is deliberately KEPT: word-synced reveals and captions
     * then run on the same clock as the export.
     *
     * TIMING HONESTY. Narration is synthesised at render time, and the
     * processor rewrites every scene's duration to fit the wav it got back. So
     * before a project's first render these durations are the planner's
     * estimates and the preview's clock is approximate; afterwards it is the
     * exported clock exactly. `timing` says which, so the UI can, too.
     *
     * @return array{
     *   success: bool, error?: string, payload?: array<string, mixed>,
     *   timing?: string, duration_seconds?: float, scenes?: array<int, array<string, mixed>>
     * }
     */
    public function playerPayload(Project $project): array
    {
        $settings = $project->settings ?? [];
        $autoVisuals = (bool) ($settings['auto_visuals'] ?? $settings['auto_visuals_auto'] ?? false);
        $assembled = ExplainerSceneAssembler::assemble($project, $autoVisuals);
        $scenes = $assembled['scenes'];

        if (empty($scenes)) {
            return ['success' => false, 'error' => 'No storyboard scenes to play yet.'];
        }

        $aspect = $project->aspect_ratio ?? '16:9';
        [$width, $height] = RemotionRenderService::dimensionsForAspect($aspect);

        // The output path is required by the signature and dropped below; the
        // payload builder never touches the filesystem.
        $payload = (new RemotionRenderService())
            ->buildRenderPayload($project, $scenes, '', $aspect, $width, $height);
        unset($payload['output_path']);

        $voiced = 0;
        $speaking = 0;
        foreach ($payload['shot_list']['scenes'] as $i => $scene) {
            // A scene with nothing to say is not evidence of a missing render,
            // so only scenes that CARRY narration count toward the verdict.
            if (trim((string) ($scene['narration']['text'] ?? '')) !== '') {
                $speaking++;
            }
            if (!empty($scene['narration_audio_url'])) {
                $voiced++;
                unset($payload['shot_list']['scenes'][$i]['narration_audio_url']);
            }
        }
        $payload['shot_list']['scenes'] = array_values($payload['shot_list']['scenes']);

        // Every speaking scene having audio means a render has already paced
        // this storyboard against real speech, so the clock is the export's.
        $timing = ($speaking > 0 && $voiced >= $speaking) ? 'exact' : 'estimated';

        return [
            'success' => true,
            'payload' => $this->rebaseForBrowser($payload),
            'timing' => $timing,
            'duration_seconds' => round(array_sum(array_map(
                fn ($s) => (float) ($s['duration_seconds'] ?? 0),
                $scenes
            )), 2),
            // The scene ruler under the player: what to label each marker and
            // where it sits. Overlapping transitions are the renderer's
            // business, so these are cumulative scene lengths, not frames.
            'scenes' => array_map(fn ($s) => [
                'scene_id' => (string) ($s['scene_id'] ?? ''),
                'duration_seconds' => (float) ($s['duration_seconds'] ?? 0),
            ], $scenes),
        ];
    }

    /**
     * Media URLs are built for the RENDER SERVICE, which may reach Laravel on
     * an internal hostname (services.remotion.asset_base_url). A browser
     * cannot. Where the two bases differ, rewrite to the public one — the
     * alternative is a preview of broken images on exactly the deployments
     * that bothered to configure a private render network.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rebaseForBrowser(array $payload): array
    {
        $public = rtrim((string) config('app.url'), '/');
        $node = rtrim((string) (config('services.remotion.asset_base_url') ?: config('app.url')), '/');
        if ($node === '' || $public === '' || $node === $public) {
            return $payload;
        }

        array_walk_recursive($payload, function (&$value) use ($node, $public) {
            if (is_string($value) && str_starts_with($value, $node . '/storage/')) {
                $value = $public . substr($value, strlen($node));
            }
        });

        return $payload;
    }

    /**
     * Which scene to freeze. Caller's pick wins when it exists; otherwise the
     * first scene that actually shows the design off — a content scene with
     * slots, skipping a bare title/hook card when there's something richer.
     */
    private function resolveSceneId(array $scenes, ?string $requested): string
    {
        $ids = array_map(fn ($s) => (string) ($s['scene_id'] ?? ''), $scenes);
        if ($requested !== null && in_array($requested, $ids, true)) {
            return $requested;
        }

        foreach ($scenes as $i => $scene) {
            if ($i === 0 && count($scenes) > 1) {
                continue; // the hook is usually the least representative frame
            }
            if (!empty($scene['slots'])) {
                return (string) $scene['scene_id'];
            }
        }

        return (string) ($scenes[0]['scene_id'] ?? '');
    }

    /**
     * Map the scene onto the composition clock and land ~65% into it, where
     * entrances have finished and the scene is showing its settled design.
     * Uses the SAME timing map as the SRT and YouTube chapters.
     */
    private function frameFor(Project $project, array $scenes, string $sceneId): int
    {
        $settings = $project->settings ?? [];
        $mode = (string) ($settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode());

        try {
            $windows = ExplainerTimingMap::sceneWindows($scenes, $mode, $settings['chapter_plan'] ?? null);
        } catch (\Throwable $e) {
            Log::warning('ExplainerPreviewService: timing map failed, previewing frame 0: ' . $e->getMessage());

            return 0;
        }

        $window = $windows[$sceneId] ?? null;
        if (!$window) {
            return 0;
        }

        $start = (float) $window['start'];
        $end = (float) $window['end'];
        $t = $start + max(0.0, ($end - $start)) * 0.65;

        // The still is captured by the SAME composition the render uses, so the
        // frame index has to be counted on the project's own clock — a 60fps
        // project previewed at 30fps frames lands halfway through the video.
        return (int) round($t * ExplainerRegistry::resolveFps($project->settings ?? []));
    }

    /** Keep the preview directory from growing without bound. */
    private function prune(Project $project, string $keepPath): void
    {
        try {
            $dir = "explainer/{$project->id}/previews";
            $files = Storage::disk('public')->files($dir);
            if (count($files) <= self::KEEP_PER_PROJECT) {
                return;
            }

            // Oldest first, never touching the file just written.
            usort($files, fn ($a, $b) => Storage::disk('public')->lastModified($a) <=> Storage::disk('public')->lastModified($b));
            foreach (array_slice($files, 0, count($files) - self::KEEP_PER_PROJECT) as $old) {
                if ($old !== $keepPath) {
                    Storage::disk('public')->delete($old);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ExplainerPreviewService: prune failed (non-fatal): ' . $e->getMessage());
        }
    }
}
