<?php

/**
 * revision-live — run a real AI revision against a real storyboard.
 *
 * revision-check.php proves the rules with fixtures; this proves the whole
 * job against actual model output: the planner naming scenes, the writer
 * rewriting them, the asset re-home, the plan repair and the fresh lint.
 *
 * It CLONES the project first (scenes, settings and every asset file) so a
 * destructive answer can only damage the copy. The report is the point: what
 * changed, what did NOT, and — the promise the whole feature rests on —
 * whether every untouched scene came out byte-identical with its uploads
 * still attached.
 *
 *   docker compose exec app php scratchpad/revision-live.php 133 "Scene 4's chart is wrong — use 2019-2021 revenue"
 *   docker compose exec app php scratchpad/revision-live.php 133 "..." --in-place
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use Modules\Project\Jobs\ReviseExplainerStoryboardJob;
use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;

$sourceId = (int) ($argv[1] ?? 0);
$note = (string) ($argv[2] ?? '');
$inPlace = in_array('--in-place', $argv, true);

if ($sourceId <= 0 || trim($note) === '') {
    echo "usage: php scratchpad/revision-live.php <project_id> \"<the note>\" [--in-place]\n";
    exit(1);
}

$source = Project::find($sourceId);
if (!$source || $source->template_type !== 'ai_explainer_video') {
    echo "project {$sourceId} is not an explainer project\n";
    exit(1);
}

$project = $source;
if (!$inPlace) {
    $project = Project::create([
        'user_id' => $source->user_id,
        'title' => $source->title . ' (revision test)',
        'template_type' => 'ai_explainer_video',
        'aspect_ratio' => $source->aspect_ratio,
        'status' => 'storyboard_ready',
        'progress' => 100,
        'settings' => $source->settings,
    ]);

    foreach ($source->explainerScenes()->orderBy('order')->get() as $row) {
        ExplainerScene::create([
            'project_id' => $project->id,
            'scene_id' => $row->scene_id,
            'order' => $row->order,
            'duration_seconds' => $row->duration_seconds,
            'narration' => $row->narration,
            'layout_template' => $row->layout_template,
            'slots' => $row->slots,
            'transition' => $row->transition,
            'relation' => $row->relation,
            'mood' => $row->mood,
        ]);
    }

    // Files too: the job DELETES the files of assets that do not survive, so
    // sharing paths with the source project would damage it.
    foreach ($source->explainerAssets()->get() as $asset) {
        $to = str_replace("projects/{$source->id}/", "projects/{$project->id}/", (string) $asset->path);
        if ($to === (string) $asset->path) {
            $to = "projects/{$project->id}/explainer/copy_" . basename((string) $asset->path);
        }
        try {
            if (Storage::disk('public')->exists($asset->path)) {
                Storage::disk('public')->copy($asset->path, $to);
                $words = substr((string) $asset->path, -4) === '.wav'
                    ? substr((string) $asset->path, 0, -4) . '.words.json'
                    : null;
                if ($words && Storage::disk('public')->exists($words)) {
                    Storage::disk('public')->copy($words, substr($to, 0, -4) . '.words.json');
                }
            }
        } catch (\Throwable $e) {
            echo "  (could not copy {$asset->path}: {$e->getMessage()})\n";
        }
        ExplainerAsset::create([
            'project_id' => $project->id,
            'scene_id' => $asset->scene_id,
            'slot_key' => $asset->slot_key,
            'type' => $asset->type,
            'path' => $to,
            'original_name' => $asset->original_name,
        ]);
    }

    echo "cloned project {$source->id} -> {$project->id}\n";
}

$snapshot = fn (Project $p) => $p->explainerScenes()->orderBy('order')->get()
    ->mapWithKeys(fn ($r) => [(string) $r->scene_id => [
        'order' => (int) $r->order,
        'template' => (string) $r->layout_template,
        'seconds' => (float) $r->duration_seconds,
        'narration' => (string) $r->narration,
        'slots' => json_encode($r->slots),
    ]])->all();

$assetsOf = fn (Project $p) => $p->explainerAssets()->get()
    ->mapWithKeys(fn ($a) => [$a->scene_id . '::' . $a->slot_key => (string) $a->original_name])->all();

$before = $snapshot($project);
$assetsBefore = $assetsOf($project);
$lookBefore = \Modules\Project\Services\ExplainerPreviewService::lookHash($project);

echo "\nBEFORE — {$project->explainerScenes()->count()} scenes, " . count($assetsBefore) . " assets\n";
foreach ($before as $id => $s) {
    printf("  %2d  %-16s %-24s %5.1fs  %s\n", $s['order'], $id, $s['template'], $s['seconds'],
        mb_substr($s['narration'], 0, 58));
}

echo "\nNOTE: \"{$note}\"\n\nrunning…\n";
$started = microtime(true);
(new ReviseExplainerStoryboardJob($project, $note))->handle();
$project->refresh();
$elapsed = round(microtime(true) - $started, 1);

$after = $snapshot($project);
$assetsAfter = $assetsOf($project);
$last = $project->settings['revision']['last'] ?? [];

echo "\nAFTER ({$elapsed}s) — state: " . ($last['state'] ?? '?') . "\n";
echo '  reply:   ' . ($last['reply'] ?? '') . "\n";
echo '  summary: ' . ($last['summary'] ?? $last['message'] ?? '') . "\n";
foreach (['changed', 'added', 'removed', 'moved'] as $bucket) {
    if (!empty($last[$bucket])) {
        echo '  ' . str_pad($bucket . ':', 9) . implode(', ', (array) $last[$bucket]) . "\n";
    }
}
foreach ((array) ($last['findings'] ?? []) as $finding) {
    echo '  ! ' . ($finding['message'] ?? '') . "\n";
}

echo "\nSCENES\n";
// Position is not a change: a delete or an insert shifts everything after it.
$body = function (?array $s): ?array {
    if ($s === null) {
        return null;
    }
    unset($s['order']);

    return $s;
};
foreach ($after as $id => $s) {
    $was = $before[$id] ?? null;
    $mark = $was === null ? 'NEW ' : ($body($was) === $body($s) ? '    ' : 'EDIT');
    printf("  %s %2d  %-16s %-24s %5.1fs  %s\n", $mark, $s['order'], $id, $s['template'], $s['seconds'],
        mb_substr($s['narration'], 0, 54));
}
foreach ($before as $id => $s) {
    if (!isset($after[$id])) {
        printf("  GONE    %-16s %-24s  %s\n", $id, $s['template'], mb_substr($s['narration'], 0, 54));
    }
}

// The promise, measured. "Untouched" means the row is identical apart from
// its position, which a delete or an insert legitimately shifts.
$declared = array_flip(array_merge((array) ($last['changed'] ?? []), (array) ($last['added'] ?? [])));
$drift = [];
foreach ($after as $id => $s) {
    if (isset($declared[$id]) || !isset($before[$id])) {
        continue;
    }
    $a = $s;
    $b = $before[$id];
    unset($a['order'], $b['order']);
    if ($a !== $b) {
        $drift[] = $id;
    }
}

echo "\nGUARANTEES\n";
echo '  scenes changed without being declared: ' . (empty($drift) ? 'none' : implode(', ', $drift)) . "\n";
echo '  assets before/after: ' . count($assetsBefore) . ' / ' . count($assetsAfter) . "\n";
$lostOnUntouched = [];
foreach ($assetsBefore as $key => $name) {
    [$sceneId] = explode('::', $key, 2);
    if (isset($declared[$sceneId]) || !isset($after[$sceneId])) {
        continue; // its card was rewritten or removed — losing it is expected
    }
    if (!isset($assetsAfter[$key])) {
        $lostOnUntouched[] = $key;
    }
}
echo '  assets lost on untouched scenes: ' . (empty($lostOnUntouched) ? 'none' : implode(', ', $lostOnUntouched)) . "\n";
echo '  look hash: ' . $lookBefore . ' -> '
    . \Modules\Project\Services\ExplainerPreviewService::lookHash($project)
    . ' (must differ so the preview and any finished MP4 read as stale)' . "\n";
$lint = $project->settings['lint_report']['counts'] ?? [];
echo '  lint: ' . json_encode($lint) . "\n";

exit(empty($drift) && empty($lostOnUntouched) ? 0 : 1);
