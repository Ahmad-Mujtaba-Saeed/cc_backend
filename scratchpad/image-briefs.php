<?php

/**
 * image-briefs — rewrite the thin image-slot descriptions of an EXISTING
 * project as real shots, the way the analyser now does for new ones.
 *
 *   docker compose exec app php scratchpad/image-briefs.php <project_id> [--all] [--dry]
 *
 * ~$0.001 per video on gpt-4o-mini. `--all` rewrites every image slot, not
 * just the thin ones; `--dry` prints without saving.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\Project;
use Modules\Project\Services\SlotImageBriefService;

$flags = array_filter($argv, fn ($a) => str_starts_with($a, '--'));
$all = in_array('--all', $flags, true);
$dry = in_array('--dry', $flags, true);
$project = Project::findOrFail((int) $argv[1]);

$scenes = $project->explainerScenes()->orderBy('order')->get();
$raw = ['scenes' => []];
foreach ($scenes as $s) {
    $raw['scenes'][$s->scene_id] = [
        'scene_id' => $s->scene_id,
        'narration' => ['text' => (string) $s->narration],
        'slots' => $s->slots ?? [],
    ];
}

$before = [];
foreach ($raw['scenes'] as $id => $scene) {
    foreach ((array) $scene['slots'] as $k => $slot) {
        if (is_array($slot) && ($slot['content_type'] ?? '') === 'image') {
            $before["{$id}::{$k}"] = (string) ($slot['asset_request']['description'] ?? '');
        }
    }
}

$service = new SlotImageBriefService();
if (!$service->available()) {
    exit("no openai key\n");
}
$t0 = microtime(true);
$out = $service->enrichAll($raw, (string) $project->title, $all);
printf("%s in %.0fs%s\n\n", $project->title, microtime(true) - $t0, $dry ? ' (dry run)' : '');

$changed = 0;
foreach ($out['scenes'] as $id => $scene) {
    foreach ((array) $scene['slots'] as $k => $slot) {
        if (!is_array($slot) || ($slot['content_type'] ?? '') !== 'image') {
            continue;
        }
        $now = (string) ($slot['asset_request']['description'] ?? '');
        $was = $before["{$id}::{$k}"] ?? '';
        if ($now === $was) {
            continue;
        }
        $changed++;
        printf("%-10s %-16s\n  was: %s\n  now: %s\n", $id, $k, $was, $now);
    }
    if (!$dry) {
        $scene_ = $project->explainerScenes()->where('scene_id', $id)->first();
        if ($scene_) {
            $scene_->update(['slots' => $scene['slots']]);
        }
    }
}
printf("\n%d slot(s) rewritten%s\n", $changed, $dry ? ' (nothing saved)' : ' and saved');
