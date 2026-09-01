<?php
/**
 * flight-live — does the REAL director now direct a quiet edit?
 *
 *   docker compose exec app php scratchpad/flight-live.php
 *
 * The budget in CanvasPlanValidator is the guarantee; this measures the
 * QUALITY of what it has to work with. A director that still asks to fly
 * everywhere gets corrected, but its own choice of which beats matter is
 * thrown away in the process — so the prompt needs to be right too.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\CanvasDirectorService;

$beats = [
    'Every city has a personality, and you can read it off the front doors.',
    'In Amsterdam the houses lean forward over the canal, narrow and tall, with a hoisting beam at the top.',
    'The staircases were too tight to carry furniture up, so the beam does the lifting instead.',
    'Now somewhere completely different. In Marrakesh the street front is almost blank.',
    'A heavy studded door, no windows, and everything the family cares about facing inward onto a courtyard.',
    'Kyoto does the opposite again: a wooden lattice at the front you can slide open by a hand and a half.',
    'So none of these are decoration. Each one solves a problem the place could not avoid.',
    'That is vernacular architecture: thousands of small corrections, each made by someone who had to live with the last one.',
];
$scenes = [];
foreach ($beats as $i => $text) {
    $scenes[] = [
        'scene_id' => 'scene_' . ($i + 1),
        'order' => $i + 1,
        'duration_seconds' => 7,
        'narration' => ['text' => $text],
        'layout_template' => 'single_focus',
        'mood' => 'neutral',
        'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Beat ' . ($i + 1), 'bullets' => ['A line']]],
    ];
}

$plan = (new CanvasDirectorService())->direct($scenes, '16:9');

$flights = 0;
foreach ($plan['items'] as $i => $it) {
    $t = $it['treatment'] ?? '?';
    if ($i > 0 && in_array($t, ['canvas_hop', 'pull_reveal', 'kinetic_break'], true)) {
        $flights++;
    }
    printf("  %-9s %-12s %-12s\n", $it['scene_id'], $t, $it['relation'] ?? '?');
}
$budget = \Modules\Project\Support\ExplainerRegistry::maxFlights(count($scenes));
printf("\n%d flights out of %d cuts (budget %d)\n", $flights, count($scenes) - 1, $budget);
printf("%d scenes hold the frame\n", count(array_filter($plan['items'], fn ($it) => ($it['treatment'] ?? '') === 'same_frame')));
