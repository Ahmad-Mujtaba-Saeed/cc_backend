<?php

/**
 * fill-slots — run ONLY the auto-visuals pass for a project, the way a render
 * does, and report what each image slot ended up with.
 *
 *   docker compose exec app php scratchpad/fill-slots.php <project_id>
 *
 * ~$0.003 per picture; a slot that already has one is skipped.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Models\Project;
use Modules\Project\Processors\ExplainerVideoProcessor;

$project = Project::findOrFail((int) $argv[1]);
$processor = new ExplainerVideoProcessor($project);
$method = new ReflectionMethod($processor, 'fillMissingMediaSlots');
$method->setAccessible(true);

$t0 = microtime(true);
$ok = $method->invoke($processor);
printf("fillMissingMediaSlots -> %s in %.0fs\n\n", $ok ? 'ok' : 'FAILED', microtime(true) - $t0);

$assets = ExplainerAsset::where('project_id', $project->id)->get()->keyBy(fn ($a) => $a->scene_id . '::' . $a->slot_key);
foreach ($project->explainerScenes()->orderBy('order')->get() as $scene) {
    foreach ((array) ($scene->slots ?? []) as $key => $slot) {
        if (!is_array($slot) || !in_array($slot['content_type'] ?? '', ['image', 'video'], true)) {
            continue;
        }
        $asset = $assets->get($scene->scene_id . '::' . $key);
        printf(
            "%-12s %-16s %s\n",
            $scene->scene_id,
            $key,
            $asset
                ? (Storage::disk('public')->exists($asset->path) ? 'HAS ' : 'MISSING FILE ') . mb_substr((string) $asset->original_name, 0, 30)
                : 'EMPTY -> placeholder'
        );
    }
}

$lint = ($project->fresh()->settings['lint_report']['items'] ?? []);
$warnings = array_values(array_filter($lint, fn ($i) => ($i['code'] ?? '') === 'ai_visual_missing'));
printf("\nlint items for missing pictures: %d\n", count($warnings));
foreach ($warnings as $w) {
    echo '  - ' . $w['message'] . "\n";
}
