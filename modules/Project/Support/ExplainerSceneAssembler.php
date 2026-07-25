<?php

namespace Modules\Project\Support;

use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Models\Project;
use Illuminate\Support\Facades\Storage;

/**
 * ExplainerSceneAssembler
 *
 * Loads the stored storyboard and folds every produced asset back onto it —
 * uploads and AI slot fills, scenario sprites, ambient/illustration backdrops
 * and narration audio — producing the scene array the render service consumes.
 *
 * It exists so the MP4 render and the storyboard's live style preview assemble
 * scenes THE SAME WAY. The only difference between them is tolerance:
 * a render must refuse to start with a required upload missing (STRICT), while
 * a preview happily draws the reserved-slot placeholder instead (TOLERANT) —
 * previewing a colour scheme should never be blocked on an unrelated upload.
 */
class ExplainerSceneAssembler
{
    /**
     * @param  bool  $autoVisuals  auto-illustration is on for this project, so
     *         an unfilled image slot is expected rather than missing
     * @return array{scenes: array<int, array>, missing: array<int, string>}
     */
    public static function assemble(Project $project, bool $autoVisuals): array
    {
        $scenes = $project->explainerScenes()->get();
        $assets = ExplainerAsset::where('project_id', $project->id)->get()
            ->keyBy(fn ($a) => $a->scene_id . '::' . $a->slot_key);

        $missing = [];
        $payload = [];

        foreach ($scenes as $scene) {
            $slots = $scene->slots ?? [];
            foreach ($slots as $slotKey => &$slot) {
                if (!in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                    continue;
                }
                $asset = $assets->get($scene->scene_id . '::' . $slotKey);
                if (!$asset) {
                    // A stock slot whose fetch failed — or an auto-visuals slot
                    // whose AI fill failed — renders its reserved-slot
                    // placeholder rather than blocking the whole video.
                    if (trim((string) ($slot['stock_query'] ?? '')) === '' && !$autoVisuals) {
                        $missing[] = "{$scene->scene_id} / {$slotKey}";
                    }
                    continue;
                }
                $slot['asset_ref'] = ['path' => $asset->path, 'type' => $asset->type];
            }
            unset($slot);

            // Scenario sprites: cut-out objects generated per entity — attach
            // each alpha PNG to its entity so the renderer can draw the car
            // itself instead of an icon in a box. Missing sprite = icon.
            foreach ($slots as $slotKey => &$slot) {
                if (($slot['content_type'] ?? '') !== 'scenario') {
                    continue;
                }
                foreach ((array) ($slot['entities'] ?? []) as $i => $entity) {
                    $spriteAsset = $assets->get("{$scene->scene_id}::{$slotKey}#sprite{$i}");
                    if ($spriteAsset && Storage::disk('public')->exists($spriteAsset->path)) {
                        $slot['entities'][$i]['sprite_path'] = $spriteAsset->path;
                    }
                }
            }
            unset($slot);

            $scenePayload = [
                'scene_id' => $scene->scene_id,
                'order' => $scene->order,
                'duration_seconds' => $scene->duration_seconds,
                'narration' => ['text' => (string) $scene->narration],
                'layout_template' => $scene->layout_template,
                'transition' => $scene->transition,
                'relation' => $scene->relation,
                'mood' => $scene->mood ?? 'neutral',
                'slots' => $slots,
            ];

            // Per-scene ambient (legacy) or the video-wide shared backdrop;
            // only text-only scenes render it, media scenes cover the frame.
            $ambient = $assets->get($scene->scene_id . '::__ambient__')
                ?? (empty($scene->requiredAssetSlots()) ? $assets->get('__project__::__ambient__') : null);
            if ($ambient) {
                $scenePayload['ambient_image_path'] = $ambient->path;
            }

            $illustration = $assets->get($scene->scene_id . '::__illustration__');
            if ($illustration) {
                $scenePayload['illustration_image_path'] = $illustration->path;
            }

            $narration = $assets->get($scene->scene_id . '::__narration__');
            if ($narration) {
                $scenePayload['narration_audio_path'] = $narration->path;
            }

            $payload[] = $scenePayload;
        }

        return ['scenes' => $payload, 'missing' => $missing];
    }
}
