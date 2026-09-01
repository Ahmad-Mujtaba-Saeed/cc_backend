<?php

/**
 * image-grid-live — will the planner actually CAST the multi-image card?
 *
 *   docker compose exec app php scratchpad/image-grid-live.php
 *
 * A card the validator handles perfectly and the model never chooses is dead
 * code. This runs the real composer on a script whose middle beat is plainly a
 * SET of things — the shape image_grid exists for — and reports what it cast,
 * then puts the result through the validator so the degrade paths are exercised
 * on real model output rather than on a fixture.
 *
 * One skeleton call plus one composer call.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptSkeletonService;
use Modules\Project\Support\ShotListValidator;

$script = <<<'TXT'
Every city has a personality, and you can read it off the front doors.
Walk through Amsterdam and the houses lean forward over the canal, narrow and tall, with a hoisting beam at the top because the staircases were too tight to carry furniture up.
In Marrakesh the street front is almost blank. A heavy studded door, no windows, and everything the family cares about faces inward onto a courtyard nobody outside ever sees.
Kyoto does the opposite again: a wooden lattice screen at the front, a paper door behind it, so the boundary between the street and the room is something you can slide open by a hand's width.
And in Reykjavik the houses are wrapped in corrugated iron and painted in colours you can find in a whiteout — red, ochre, teal — because for four months of the year the light is the thing in shortest supply.
Four cities, four front doors, and none of them are decoration. Each one is a solution to a problem the place could not avoid: a canal, a climate, a religion, a winter.
That is the thing about vernacular architecture. Nobody designed it in one go. It is thousands of small corrections, each made by someone who had to live with the last one.
TXT;

echo "Planning the skeleton…\n";
$skeleton = (new ScriptSkeletonService())->planGeneric($script, '', 70);
if ($skeleton === []) {
    echo "no skeleton; stopping.\n";
    exit(1);
}
$phases = $skeleton['phases'] ?? $skeleton;
echo '  ' . count($phases) . " phases: " . implode(', ', array_map(
    fn ($p) => is_array($p) ? (string) ($p['intent'] ?? '?') : (string) $p,
    $phases
)) . "\n";

echo "Composing…\n";
$raw = (new GenericStoryboardComposerService())->compose($script, $skeleton, 70);
if ($raw === null) {
    echo "composer returned null; stopping.\n";
    exit(1);
}

$scenes = $raw['scenes'] ?? [];
echo count($scenes) . " scenes cast:\n";
foreach ($scenes as $scene) {
    printf("  %-12s %-22s %s\n",
        $scene['scene_id'] ?? '?',
        $scene['layout_template'] ?? '?',
        mb_substr((string) ($scene['narration']['text'] ?? $scene['narration'] ?? ''), 0, 64)
    );
}

$castGrid = in_array('image_grid', array_column($scenes, 'layout_template'), true);
echo $castGrid
    ? "\n>> image_grid WAS cast.\n"
    : "\n>> image_grid was not cast on this run (the four-doors beat is the one it is for).\n";

// Whatever it cast, the validator must return something legal.
$validated = (new ShotListValidator())->validate([
    'aspect_ratio' => '16:9',
    'scenes' => $scenes,
]);

echo "\nAfter validation:\n";
foreach ($validated['scenes'] as $scene) {
    $slots = $scene['slots'] ?? [];
    $media = array_filter($slots, fn ($s) => in_array($s['content_type'] ?? '', ['image', 'video'], true));
    printf("  %-12s %-22s %d slot(s), %d media\n",
        $scene['scene_id'] ?? '?',
        $scene['layout_template'] ?? '?',
        count($slots),
        count($media)
    );
    if (($scene['layout_template'] ?? '') === 'image_grid') {
        foreach ($slots as $key => $slot) {
            printf("      %-14s %-16s %s\n",
                $key,
                '"' . ($slot['label'] ?? '') . '"',
                mb_substr((string) ($slot['asset_request']['description'] ?? ''), 0, 58)
            );
        }
    }
}

$warnings = $validated['warnings'] ?? [];
if ($warnings !== []) {
    echo "\nValidator adjustments:\n";
    foreach (array_slice($warnings, 0, 12) as $w) {
        echo "  - {$w}\n";
    }
}
