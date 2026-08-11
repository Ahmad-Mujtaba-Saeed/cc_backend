<?php

namespace Modules\Project\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Throwable;

/**
 * ExplainerAssetRehome — the apply half of {@see StoryboardDiff}.
 *
 * StoryboardDiff plans (arrays in, actions out); this moves the DB rows and
 * the files those actions describe. Both writers of a storyboard need it:
 * AnalyzeExplainerScriptJob (a full re-analysis) and
 * ReviseExplainerStoryboardJob (a targeted edit of a few cards), and the
 * two-phase re-key below is subtle enough that a second copy would drift.
 *
 * Apply a StoryboardDiff plan: drop what did not survive (rows AND their
 * files — the old blanket delete leaked every file), then re-key the
 * survivors. Re-keying is two-phase because of the unique
 * (project, scene, slot) index: a swapped pair of scene ids would collide
 * mid-update, so every kept row is parked on an id-unique key first.
 *
 * Narration is the one asset whose FILES are named after the scene id
 * (narration_<scene>.wav plus a .words.json sidecar the cache check derives
 * from the CURRENT scene id) — those are copied to their new names, staged
 * through a temp name so a swap cannot overwrite a source before it is read.
 * If the narration text survived unchanged, the processor's hash check then
 * reuses the wav instead of re-billing TTS.
 */
final class ExplainerAssetRehome
{
    /**
     * @param array $plan      a StoryboardDiff plan (`assets` actions)
     * @param \Illuminate\Support\Collection $assetRows the OLD asset models
     */
    public static function apply(Project $project, array $plan, $assetRows): void
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
        $dir = "projects/{$project->id}/explainer";
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
