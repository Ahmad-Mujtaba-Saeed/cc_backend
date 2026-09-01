<?php

/**
 * flight-budget-check — flights are punctuation, not grammar.
 *
 *   docker compose exec app php scratchpad/flight-budget-check.php
 *
 * Until now `canvas_hop` was the treatment for four of the seven story
 * relations, so nearly every cut in every video was a camera flight across the
 * canvas. The journey read as constant travel, and — worse — nothing the CARDS
 * did could compete with a world sliding past behind them.
 *
 * A flight now has to be funded. This file is the guarantee: whatever the
 * director returns, the plan that comes out spends at most the budget, and
 * every other scene holds the frame.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\CanvasPlanValidator;
use Modules\Project\Support\ExplainerRegistry;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

/** N scenes with the given narrations. */
function scenes(int $n, array $narrations = []): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = [
            'scene_id' => "scene_{$i}",
            'narration' => ['text' => $narrations[$i - 1] ?? "This is the narration of scene {$i}, which continues the same thread."],
            'mood' => 'neutral',
            'duration_seconds' => 6,
        ];
    }

    return $out;
}

/** Treatments after validation. */
function treatments(array $plan): array
{
    return array_map(fn ($it) => (string) ($it['treatment'] ?? '?'), $plan['items']);
}

/** How many items actually fly. */
function flights(array $plan): int
{
    return count(array_filter(
        $plan['items'],
        fn ($it, $i) => $i > 0 && in_array($it['treatment'] ?? '', ['canvas_hop', 'pull_reveal', 'kinetic_break'], true),
        ARRAY_FILTER_USE_BOTH
    ));
}

echo "\n== 1. the budget itself ==\n";

check('a 4-scene video gets one flight', ExplainerRegistry::maxFlights(4) === 1, (string) ExplainerRegistry::maxFlights(4));
check('an 8-scene video gets two', ExplainerRegistry::maxFlights(8) === 2, (string) ExplainerRegistry::maxFlights(8));
check('a 20-scene video gets five', ExplainerRegistry::maxFlights(20) === 5, (string) ExplainerRegistry::maxFlights(20));
check('same_frame is the default treatment', ExplainerRegistry::defaultTreatment() === 'same_frame', ExplainerRegistry::defaultTreatment());
check('same_frame is a legal treatment', in_array('same_frame', ExplainerRegistry::treatmentNames(), true));

echo "\n== 2. a director that wants to fly everywhere ==\n";

// The old behaviour, exactly: every scene a hop, every scene its own station.
$sceneList = scenes(9);
$greedy = [
    'journey_pattern' => 'zigzag',
    'world' => ['width' => 20000, 'height' => 8000],
    'items' => array_map(fn ($s, $i) => [
        'scene_id' => $s['scene_id'],
        'treatment' => $i === 0 ? 'hero_open' : 'canvas_hop',
        'relation' => $i === 0 ? 'opening' : 'continues',
        'x' => 1500 + $i * 3400,
        'y' => 2500 + ($i % 2 ? 1400 : -1400),
        'w' => 1560,
        'h' => 1000,
        'hold_move' => 'breathe',
    ], $sceneList, array_keys($sceneList)),
    'connectors' => [],
];

$result = (new CanvasPlanValidator())->validate($greedy, $sceneList, '16:9');
$plan = $result['plan'];
$budget = ExplainerRegistry::maxFlights(9);

check('the plan still covers every scene', count($plan['items']) === 9, (string) count($plan['items']));
check("flights are cut to the budget of {$budget}", flights($plan) <= $budget, flights($plan) . ' flights: ' . implode(',', treatments($plan)));
check('the rest hold the frame', count(array_filter(treatments($plan), fn ($t) => $t === 'same_frame')) >= 6);

// Holding the frame MEANS holding the frame: the boxes have to coincide, or
// the camera would have to travel to find the next card.
$stacked = 0;
for ($i = 1; $i < count($plan['items']); $i++) {
    if (($plan['items'][$i]['treatment'] ?? '') !== 'same_frame') {
        continue;
    }
    $a = $plan['items'][$i];
    $b = $plan['items'][$i - 1];
    if (abs($a['x'] - $b['x']) < 0.01 && abs($a['y'] - $b['y']) < 0.01
        && abs($a['w'] - $b['w']) < 0.01 && abs($a['h'] - $b['h']) < 0.01) {
        $stacked++;
    }
}
$holds = count(array_filter(treatments($plan), fn ($t) => $t === 'same_frame'));
check('every held scene sits exactly where the last one sat', $stacked === $holds, "{$stacked}/{$holds}");

// A connector is a drawn journey; there is none between two coincident boxes.
$badConnectors = 0;
foreach ($plan['connectors'] as $conn) {
    $toIndex = array_search($conn['to'], array_column($plan['items'], 'scene_id'), true);
    if ($toIndex !== false && ($plan['items'][$toIndex]['treatment'] ?? '') === 'same_frame' && ($conn['style'] ?? 'none') !== 'none') {
        $badConnectors++;
    }
}
check('no connector is drawn into a held frame', $badConnectors === 0, (string) $badConnectors);

echo "\n== 3. the budget is spent on what MATTERS ==\n";

// Four candidates, a budget of one: the new chapter must win.
$sceneList = scenes(5);
$mixed = [
    'journey_pattern' => 'zigzag',
    'world' => ['width' => 20000, 'height' => 8000],
    'items' => [
        ['scene_id' => 'scene_1', 'treatment' => 'hero_open', 'relation' => 'opening', 'x' => 1500, 'y' => 2500, 'w' => 1560, 'h' => 1000, 'hold_move' => 'breathe'],
        ['scene_id' => 'scene_2', 'treatment' => 'canvas_hop', 'relation' => 'consequence', 'x' => 5000, 'y' => 2500, 'w' => 1560, 'h' => 1000, 'hold_move' => 'drift'],
        ['scene_id' => 'scene_3', 'treatment' => 'canvas_hop', 'relation' => 'contrast', 'x' => 8500, 'y' => 3900, 'w' => 1560, 'h' => 1000, 'hold_move' => 'breathe'],
        ['scene_id' => 'scene_4', 'treatment' => 'pull_reveal', 'relation' => 'new_chapter', 'x' => 13000, 'y' => 1200, 'w' => 1560, 'h' => 1000, 'hold_move' => 'settle_back'],
        ['scene_id' => 'scene_5', 'treatment' => 'canvas_hop', 'relation' => 'continues', 'x' => 16500, 'y' => 2500, 'w' => 1560, 'h' => 1000, 'hold_move' => 'orbit'],
    ],
    'connectors' => [],
];

$plan = (new CanvasPlanValidator())->validate($mixed, $sceneList, '16:9')['plan'];
$byId = array_column($plan['items'], null, 'scene_id');
check('a budget of one is spent on the new chapter', ($byId['scene_4']['treatment'] ?? '') !== 'same_frame', $byId['scene_4']['treatment'] ?? '?');
check('...and the plain "continues" does NOT fly', ($byId['scene_5']['treatment'] ?? '') === 'same_frame', $byId['scene_5']['treatment'] ?? '?');
check('the relation is kept even when the flight is not', ($byId['scene_2']['relation'] ?? '') === 'consequence', $byId['scene_2']['relation'] ?? '?');

echo "\n== 4. dives are capped too ==\n";

$sceneList = scenes(8);
$dives = [
    'journey_pattern' => 'zigzag',
    'world' => ['width' => 20000, 'height' => 8000],
    'items' => array_map(fn ($s, $i) => [
        'scene_id' => $s['scene_id'],
        'treatment' => $i === 0 ? 'hero_open' : 'zoom_nest',
        'relation' => $i === 0 ? 'opening' : 'elaborates',
        'nest' => ['fx' => 0.5, 'fy' => 0.4],
        'x' => 1500 + $i * 3400,
        'y' => 2500,
        'w' => 1560,
        'h' => 1000,
        'hold_move' => 'breathe',
    ], $sceneList, array_keys($sceneList)),
    'connectors' => [],
];

$plan = (new CanvasPlanValidator())->validate($dives, $sceneList, '16:9')['plan'];
$diveCount = count(array_filter(treatments($plan), fn ($t) => $t === 'zoom_nest'));
check('a wall of dives is cut to the cap', $diveCount <= ExplainerRegistry::maxDives(), "{$diveCount} dives");
check('the grounded dives hold the frame instead', count(array_filter(treatments($plan), fn ($t) => $t === 'same_frame')) >= 4, implode(',', treatments($plan)));

echo "\n== 5. the fallback layout obeys the same rule ==\n";

// No plan at all: the deterministic layout must not go back to hopping.
$sceneList = scenes(8);
$plan = (new CanvasPlanValidator())->validate(['items' => []], $sceneList, '16:9')['plan'];
check('a fallback plan covers every scene', count($plan['items']) === 8, (string) count($plan['items']));
check('a fallback plan is mostly quiet cuts', flights($plan) <= ExplainerRegistry::maxFlights(8), flights($plan) . ' flights: ' . implode(',', treatments($plan)));

echo "\n== 6. a short video still moves once ==\n";

// The floor matters: a 3-scene video with nothing but "continues" would be
// completely static without min_per_video, and static is its own failure.
$sceneList = scenes(3, [
    'The opening line of a very short video about one idea.',
    'Now for something completely different — a new chapter entirely.',
    'And that is where it ends.',
]);
$plan = (new CanvasPlanValidator())->validate([
    'items' => [
        ['scene_id' => 'scene_1', 'treatment' => 'hero_open', 'relation' => 'opening', 'x' => 1500, 'y' => 2500, 'w' => 1560, 'h' => 1000, 'hold_move' => 'breathe'],
        ['scene_id' => 'scene_2', 'treatment' => 'pull_reveal', 'relation' => 'new_chapter', 'x' => 6000, 'y' => 1200, 'w' => 1560, 'h' => 1000, 'hold_move' => 'settle_back'],
        ['scene_id' => 'scene_3', 'treatment' => 'canvas_hop', 'relation' => 'continues', 'x' => 9000, 'y' => 2500, 'w' => 1560, 'h' => 1000, 'hold_move' => 'drift'],
    ],
    'connectors' => [],
], $sceneList, '16:9')['plan'];
check('the one real break still flies', flights($plan) === 1, flights($plan) . ' flights: ' . implode(',', treatments($plan)));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
