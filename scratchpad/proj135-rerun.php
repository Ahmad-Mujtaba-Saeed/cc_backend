<?php

/**
 * proj135-rerun — run project 135's REAL script + guide through the fixed
 * planner/composer/validator and print the storyboard, without touching the
 * project. Costs a few LLM calls.
 *   docker exec viralforgebackend-app-1 php scratchpad/proj135-rerun.php [id]
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\Project;
use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptSkeletonService;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

$project = Project::findOrFail((int) ($argv[1] ?? 135));
$settings = $project->settings ?? [];
$script = (string) ($settings['script'] ?? '');
$guide = (string) ($settings['guide'] ?? '');
$target = (int) ($settings['target_seconds'] ?? 60);

echo "PROJECT {$project->id}: {$project->title}  (target {$target}s)\n";
echo 'GUIDE: ' . ($guide === '' ? '(none)' : mb_substr($guide, 0, 160) . '…') . "\n\n";

$skeleton = (new ScriptSkeletonService())->planGeneric($script, $guide, $target);
echo '--- SKELETON (' . count($skeleton) . " phases) ---\n";
foreach ($skeleton as $i => $p) {
    printf("  %2d. %-16s %s\n", $i + 1, $p['intent'], $p['brief']);
}

$composer = (new GenericStoryboardComposerService())->setGuide($guide);
$raw = $composer->compose($script, $skeleton, $target);
if ($raw === null) {
    echo "\nCOMPOSER RETURNED NULL (would fall back to the giant call)\n";
    exit(1);
}

$result = (new ShotListValidator())->validate($raw, [
    'hook_enabled' => false,
    'outro_enabled' => true,
    'outro_title' => (string) $project->title,
]);
$scenes = $result['scenes'];

echo "\n--- STORYBOARD (" . count($scenes) . ' scenes, ' . $composer->attempts() . " compose attempt(s)) ---\n";
$total = 0.0;
$media = 0;
$tplCount = [];
foreach ($scenes as $s) {
    $d = (float) $s['duration_seconds'];
    $total += $d;
    $tpl = (string) $s['layout_template'];
    $tplCount[$tpl] = ($tplCount[$tpl] ?? 0) + 1;

    $uploads = [];
    foreach ((array) $s['slots'] as $key => $slot) {
        if (in_array((string) ($slot['content_type'] ?? ''), ['image', 'video'], true)) {
            $uploads[] = $key . ': ' . mb_substr((string) ($slot['asset_request']['description'] ?? '?'), 0, 78);
        }
    }
    if ($uploads !== []) {
        $media++;
    }

    printf("\n  #%-2d %5.1fs  %-26s\n", $s['order'], $d, $tpl);
    echo '        say: ' . mb_substr((string) ($s['narration']['text'] ?? ''), 0, 110) . "\n";
    foreach ($uploads as $u) {
        echo "        UPLOAD  {$u}\n";
    }
}

$secs = array_map(fn ($s) => (float) $s['duration_seconds'], $scenes);
arsort($tplCount);
echo "\n--- SUMMARY ---\n";
printf("  total          %.1fs (target %ds)\n", $total, $target);
printf("  durations      %.1fs – %.1fs (spread %.1fs)\n", min($secs), max($secs), max($secs) - min($secs));
printf("  upload slots   %d of %d scenes carry media\n", $media, count($scenes));
printf("  single_focus   %d of %d scenes\n", $tplCount['single_focus'] ?? 0, count($scenes));
echo '  templates      ' . implode(', ', array_map(
    fn ($t, $c) => "{$t}×{$c}",
    array_keys($tplCount),
    array_values($tplCount)
)) . "\n";

$lint = SceneBudgetLinter::lint($scenes, ['hook_enabled' => false, 'outro_enabled' => true]);
echo "\n--- LINT (" . json_encode($lint['counts']) . ") ---\n";
foreach ($lint['items'] as $item) {
    printf("  %-5s %-16s %s\n", $item['severity'], $item['code'], $item['message']);
}
