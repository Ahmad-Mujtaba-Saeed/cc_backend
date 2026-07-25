<?php

/**
 * quadrant-check — clamp/degrade/cap/casting behaviour of quadrant_map
 * (loop iter 35, card queue #2 item 8).
 *   docker exec viralforgebackend-app-1 php scratchpad/quadrant-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($got !== '' ? "  -> {$got}" : '') . "\n";
    }
}

$AXES = [
    'x_axis' => ['left_label' => 'Low effort', 'right_label' => 'High effort'],
    'y_axis' => ['bottom_label' => 'Low impact', 'top_label' => 'High impact'],
];
$ITEMS = [
    ['label' => 'Fix checkout', 'x' => 0.1, 'y' => 0.9],
    ['label' => 'Rewrite backend', 'x' => 0.9, 'y' => 0.8],
    ['label' => 'Redesign logo', 'x' => 0.8, 'y' => 0.1],
];

function quadScene(string $id, array $slot): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'quadrant_map',
        'duration_seconds' => 11,
        'narration' => ['text' => 'Sorted by two judgements at once, the priorities stop being a matter of opinion.'],
        'slots' => ['slot_quadrant' => array_merge(['content_type' => 'quadrant'], $slot)],
    ];
}

$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false]
)['scenes'];
$first = fn (array $scenes) => $scenes[0] ?? [];

echo "--- registry ---\n";
$templates = ExplainerRegistry::templates();
check('quadrant_map template registered', isset($templates['quadrant_map']));
check('its slot is slot_quadrant', isset($templates['quadrant_map']['slots']['slot_quadrant']));
check('the slot allows the quadrant type',
    ($templates['quadrant_map']['slots']['slot_quadrant']['allowed'] ?? []) === ['quadrant']);
check('capped at 1 per video', ($templates['quadrant_map']['max_per_video'] ?? null) === 1);
$reg = json_decode(file_get_contents(__DIR__ . '/../modules/Project/Resources/explainer_registry.json'), true);
check('quadrant content type registered', isset($reg['content_types']['quadrant']));
check('registry version bumped past spectrum\'s', (int) ($reg['version'] ?? 0) >= 34, (string) ($reg['version'] ?? ''));

echo "--- the clamp keeps a good payload ---\n";
$out = $first($run([quadScene('scene_1', $AXES + ['quadrant_items' => $ITEMS, 'highlight_index' => 0])]));
check('template survives', ($out['layout_template'] ?? '') === 'quadrant_map', $out['layout_template'] ?? '');
$slot = $out['slots']['slot_quadrant'] ?? [];
check('all three items kept', count($slot['quadrant_items'] ?? []) === 3, json_encode($slot['quadrant_items'] ?? []));
check('both axes kept', ($slot['x_axis']['left_label'] ?? '') === 'Low effort'
    && ($slot['y_axis']['top_label'] ?? '') === 'High impact');
check('highlight kept', ($slot['highlight_index'] ?? null) === 0);

echo "--- coordinates are judgements, but bounded ---\n";
$out = $first($run([quadScene('scene_1', $AXES + ['quadrant_items' => [
    ['label' => 'Way off left', 'x' => -3, 'y' => 0.5],
    ['label' => 'Way off top', 'x' => 0.5, 'y' => 99],
    ['label' => 'Fine', 'x' => 0.42, 'y' => 0.58],
]])]));
$its = $out['slots']['slot_quadrant']['quadrant_items'] ?? [];
check('x clamps into 0..1', ($its[0]['x'] ?? null) === 0.0, json_encode($its[0] ?? []));
check('y clamps into 0..1', ($its[1]['y'] ?? null) === 1.0, json_encode($its[1] ?? []));
check('a real coordinate is untouched', ($its[2]['x'] ?? null) === 0.42 && ($its[2]['y'] ?? null) === 0.58);

echo "--- what the clamp refuses ---\n";
// A missing pole: the matrix cannot be read, so it degrades to text.
$out = $first($run([quadScene('scene_1', [
    'x_axis' => ['left_label' => 'Low effort', 'right_label' => 'High effort'],
    'y_axis' => ['bottom_label' => 'Low impact'], // no top pole
    'quadrant_items' => $ITEMS,
])]));
check('a missing pole degrades to text', ($out['layout_template'] ?? '') === 'single_focus', $out['layout_template'] ?? '');
check('the degrade keeps the labels',
    count($out['slots']['slot_main']['bullets'] ?? []) >= 3, json_encode($out['slots']['slot_main'] ?? []));

// Two items is a comparison — versus_card draws that better.
$out = $first($run([quadScene('scene_1', $AXES + ['quadrant_items' => array_slice($ITEMS, 0, 2)])]));
check('two items is not a matrix', ($out['layout_template'] ?? '') === 'single_focus', $out['layout_template'] ?? '');

// Junk items are dropped individually; the card survives on the rest.
$out = $first($run([quadScene('scene_1', $AXES + ['quadrant_items' => array_merge($ITEMS, [
    ['label' => '', 'x' => 0.5, 'y' => 0.5],
    ['label' => 'No coords'],
    'not even an array',
])])]));
check('junk items dropped, card survives', ($out['layout_template'] ?? '') === 'quadrant_map'
    && count($out['slots']['slot_quadrant']['quadrant_items'] ?? []) === 3);

echo "--- dedupe, cap, stale highlight ---\n";
$out = $first($run([quadScene('scene_1', $AXES + ['quadrant_items' => [
    ['label' => 'Same', 'x' => 0.1, 'y' => 0.1],
    ['label' => 'SAME', 'x' => 0.9, 'y' => 0.9],
    ['label' => 'Other', 'x' => 0.5, 'y' => 0.5],
    ['label' => 'Third', 'x' => 0.3, 'y' => 0.7],
]])]));
$its = $out['slots']['slot_quadrant']['quadrant_items'] ?? [];
check('duplicate labels collapse to the first', count($its) === 3 && ($its[0]['x'] ?? null) === 0.1, json_encode($its));

$many = [];
for ($i = 0; $i < 9; $i++) {
    $many[] = ['label' => "Item {$i}", 'x' => $i / 10, 'y' => 1 - $i / 10];
}
$out = $first($run([quadScene('scene_1', $AXES + ['quadrant_items' => $many, 'highlight_index' => 8])]));
check('capped at 6 items', count($out['slots']['slot_quadrant']['quadrant_items'] ?? []) === 6);
check('a highlight past the cap is dropped, not guessed',
    !isset($out['slots']['slot_quadrant']['highlight_index']),
    json_encode($out['slots']['slot_quadrant']['highlight_index'] ?? 'absent'));

echo "--- zones are optional and independently droppable ---\n";
$out = $first($run([quadScene('scene_1', $AXES + [
    'quadrant_items' => $ITEMS,
    'zones' => ['top_left' => 'Quick wins', 'top_right' => '', 'bottom_right' => 'Money pit', 'junk_corner' => 'nope'],
])]));
$zones = $out['slots']['slot_quadrant']['zones'] ?? [];
check('named zones survive', ($zones['top_left'] ?? '') === 'Quick wins' && ($zones['bottom_right'] ?? '') === 'Money pit');
check('empty and unknown corners dropped', !isset($zones['top_right']) && !isset($zones['junk_corner']), json_encode($zones));

$out = $first($run([quadScene('scene_1', $AXES + ['quadrant_items' => $ITEMS])]));
check('no zones at all is still a valid matrix', ($out['layout_template'] ?? '') === 'quadrant_map');

echo "--- the over-cap degrade keeps the FINDING ---\n";
// Two quadrant_maps: the second is over the per-video cap and must degrade to
// text that still says which box each item sat in.
$scenes = $run([
    quadScene('scene_1', $AXES + ['quadrant_items' => $ITEMS, 'zones' => ['top_left' => 'Quick wins']]),
    quadScene('scene_2', $AXES + ['quadrant_items' => $ITEMS, 'zones' => ['top_left' => 'Quick wins']]),
]);
$maps = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'quadrant_map'));
check('only one map survives the cap', count($maps) === 1, (string) count($maps));
$degraded = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'single_focus'));
$bullets = $degraded[0]['slots']['slot_main']['bullets'] ?? [];
check('the degrade names the zone it was in',
    (bool) array_filter($bullets, fn ($b) => str_contains($b, 'Quick wins')), json_encode($bullets));
check('and falls back to the poles when unnamed',
    (bool) array_filter($bullets, fn ($b) => str_contains($b, 'High effort')), json_encode($bullets));

echo "--- the linter treats it as a drawn card ---\n";
$lint = (new SceneBudgetLinter())->lint($run([
    quadScene('scene_1', $AXES + ['quadrant_items' => $ITEMS, 'heading' => 'What to build first']),
]), []);
$codes = array_column($lint['items'] ?? [], 'code');
check('not flagged as a sparse text scene', !in_array('sparse_scene', $codes, true), implode(',', $codes));
check('not flagged as dead air', !in_array('dead_air', $codes, true), implode(',', $codes));

echo "--- casting knows about it ---\n";
$docs = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('CARD_DOCS');
check('the composer documents the card', isset($docs['quadrant_map']));
check('the doc names the real slot key', str_contains($docs['quadrant_map'] ?? '', 'slot_quadrant'));
check('the doc names both axes',
    str_contains($docs['quadrant_map'] ?? '', 'x_axis') && str_contains($docs['quadrant_map'] ?? '', 'y_axis'));
$menus = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('MENUS');
$offered = array_keys(array_filter($menus, fn ($m) => in_array('quadrant_map', $m, true)));
check('offered on at least one intent', $offered !== [], implode(',', $offered));
check('every menu entry is a real template',
    !array_diff(array_unique(array_merge(...array_values($menus))), array_keys($templates)));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
