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

        return substr(md5(json_encode([
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
        ], JSON_UNESCAPED_UNICODE)), 0, 20);
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

        return (int) round($t * ExplainerRegistry::fps());
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
