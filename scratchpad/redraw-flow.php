<?php

/**
 * redraw-flow — fill in any flow-card part of a project that has no picture
 * yet, and write a probe spec for the renderer.
 *
 *   docker compose exec app php scratchpad/redraw-flow.php <project_id>
 *
 * A subject already drawn is free (the model's own png is cached), so this is
 * how a keying change is applied to an existing storyboard without paying for
 * the drawings again.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rekey = in_array('--rekey', $argv, true);
$project = Modules\Project\Models\Project::findOrFail((int) $argv[1]);
$drawer = new Modules\Project\Services\FlowVisualService();
$specs = [];

foreach ($project->explainerScenes()->orderBy('order')->get() as $scene) {
    if ($scene->layout_template !== 'cinematic_card') {
        continue;
    }
    $slots = $scene->slots ?? [];
    if ($rekey) {
        // Re-key every part from the model's cached png (free), which is how a
        // change to the keying reaches drawings that were already paid for.
        foreach ((array) ($slots['slot_cinematic']['elements'] ?? []) as $i => $el) {
            unset($slots['slot_cinematic']['elements'][$i]['image_path']);
        }
    }
    $filled = $drawer->drawAll(
        ['scenes' => [['scene_id' => $scene->scene_id, 'slots' => ['slot_cinematic' => $slots['slot_cinematic'] ?? []]]]],
        (string) $project->title
    );
    $slot = $filled['scenes'][0]['slots']['slot_cinematic'];
    $slots['slot_cinematic'] = $slot;
    $scene->slots = $slots;
    $scene->save();

    $drawn = 0;
    foreach ((array) ($slot['elements'] ?? []) as $el) {
        if (($el['kind'] ?? '') === 'visual') {
            $drawn += empty($el['image_path']) ? 0 : 1;
        }
    }
    printf("scene %-10s %d/%d parts drawn  %s\n", $scene->scene_id, $drawn, count((array) ($slot['elements'] ?? [])), $slot['heading'] ?? '');

    foreach ($slot['elements'] as $i => $el) {
        if (!empty($el['image_path'])) {
            $slot['elements'][$i]['image_url'] = 'flow/' . basename($el['image_path']);
        }
    }
    $specs[] = ['scene_id' => $scene->scene_id, 'narration' => (string) $scene->narration, 'slot' => $slot];
}

$out = __DIR__ . "/flow-{$project->id}.json";
file_put_contents($out, json_encode($specs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "wrote " . count($specs) . " card(s) to {$out}\n";
