<?php

/**
 * geometry-backfill — surgically rebuild THIN geometry_diagram slots on an
 * already-analysed project, in place. Only touches geometry scenes whose slot
 * would render as GeometryDiagram's blank default; narration, audio, order and
 * every other scene are left exactly as they are.
 *
 * Dry run (prints what it WOULD change, writes nothing):
 *   docker exec viralforgebackend-app-1 php scratchpad/geometry-backfill.php 105
 * Commit the changes:
 *   docker exec viralforgebackend-app-1 php scratchpad/geometry-backfill.php 105 --write
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Services\MathVisualSynthesisService;
use Modules\Project\Support\ShotListValidator;

$projectId = (int) ($argv[1] ?? 0);
$write = in_array('--write', $argv, true);
if ($projectId <= 0) {
    fwrite(STDERR, "usage: geometry-backfill.php <project_id> [--write]\n");
    exit(1);
}

$project = Project::find($projectId);
$title = $project ? (string) $project->title : '';
$svc = new MathVisualSynthesisService();

$scenes = ExplainerScene::where('project_id', $projectId)
    ->where('layout_template', 'geometry_diagram')
    ->orderBy('order')
    ->get();

echo "project {$projectId}: {$scenes->count()} geometry_diagram scene(s)\n\n";
$changed = 0;

foreach ($scenes as $scene) {
    $slot = (array) (($scene->slots ?? [])['slot_geometry'] ?? []);
    if (!$svc->geometryIsThin($slot)) {
        echo "  scene #{$scene->order}: already has content (shape={$slot['shape']}) — skipped\n";
        continue;
    }

    $narr = is_array($scene->narration) ? (string) ($scene->narration['text'] ?? '') : (string) $scene->narration;
    echo "  scene #{$scene->order}: THIN (shape={$slot['shape']}) — rebuilding from narration\n";
    $built = $svc->geometryFromNarration($narr, (string) ($slot['heading'] ?? ''), $title);
    if ($built === null) {
        echo "    ! synthesis returned nothing — left as is\n";
        continue;
    }
    $built['content_type'] = 'geometry';
    if (trim((string) ($slot['heading'] ?? '')) !== '' && trim((string) ($built['heading'] ?? '')) === '') {
        $built['heading'] = $slot['heading'];
    }

    // Clamp through the validator so the stored slot is renderer-legal.
    $raw = ['scenes' => [[
        'scene_id' => (string) $scene->scene_id,
        'layout_template' => 'geometry_diagram',
        'duration_seconds' => (float) $scene->duration_seconds,
        'narration' => ['text' => $narr],
        'slots' => ['slot_geometry' => $built],
    ]]];
    $out = (new ShotListValidator())->validate($raw, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
    $clamped = $out['scenes'][0]['slots']['slot_geometry'] ?? null;
    if (!is_array($clamped) || ($out['scenes'][0]['layout_template'] ?? '') !== 'geometry_diagram') {
        echo "    ! rebuilt figure did not survive validation — left as is\n";
        continue;
    }

    $labels = array_values(array_filter(array_map(
        fn ($p) => (string) ($p['label'] ?? ''),
        (array) ($clamped['points'] ?? [])
    ), fn ($l) => $l !== ''));
    echo "    -> shape={$clamped['shape']} labels=[" . implode(',', $labels) . ']'
        . (empty($clamped['circumcircle']) ? '' : ' +circumcircle')
        . (empty($clamped['angle_marks']) ? '' : ' +' . count($clamped['angle_marks']) . 'angle_marks')
        . "\n";

    if ($write) {
        $slots = $scene->slots;
        $slots['slot_geometry'] = $clamped;
        $scene->slots = $slots;
        $scene->save();
        echo "    written\n";
    }
    $changed++;
}

echo "\n" . ($write ? "wrote " : "would change ") . "{$changed} scene(s)."
    . ($write ? "" : " Re-run with --write to commit.") . "\n";
