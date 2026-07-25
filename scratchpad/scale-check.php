<?php

/**
 * scale-check — true relative size, the 40x refusal, clamp/degrade/cap and
 * casting of scale_comparison (loop iter 37, card queue #2 item 10).
 *   docker exec viralforgebackend-app-1 php scratchpad/scale-check.php
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
        echo "  OK  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($got !== '' ? "  -> {$got}" : '') . "\n";
    }
}

$ITEMS = [
    ['label' => 'Blue whale', 'value' => 30, 'note' => 'Longest animal alive'],
    ['label' => 'Double decker bus', 'value' => 15],
    ['label' => 'An adult', 'value' => 1.7],
];

function scaleScene(string $id, array $slot): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'scale_comparison',
        'duration_seconds' => 13,
        'narration' => ['text' => 'A blue whale is thirty metres long, which is two buses and then some.'],
        'slots' => ['slot_scale' => array_merge(['content_type' => 'scale'], $slot)],
    ];
}

$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false]
)['scenes'];
$one = fn (array $slot) => $run([scaleScene('scene_1', $slot)])[0] ?? [];

echo "--- registry ---\n";
$templates = ExplainerRegistry::templates();
check('scale_comparison template registered', isset($templates['scale_comparison']));
check('its slot is slot_scale', isset($templates['scale_comparison']['slots']['slot_scale']));
check('the slot allows the scale type',
    ($templates['scale_comparison']['slots']['slot_scale']['allowed'] ?? []) === ['scale']);
check('capped at 1 per video', ($templates['scale_comparison']['max_per_video'] ?? null) === 1);
$reg = json_decode(file_get_contents(__DIR__ . '/../modules/Project/Resources/explainer_registry.json'), true);
check('scale content type registered', isset($reg['content_types']['scale']));
check('registry version bumped past the proportion', (int) ($reg['version'] ?? 0) >= 36, (string) ($reg['version'] ?? ''));

echo "--- the sizes are DERIVED, and they are linear ---\n";
$out = $one(['unit' => 'm', 'scale_items' => $ITEMS, 'highlight_index' => 0]);
check('template survives', ($out['layout_template'] ?? '') === 'scale_comparison', $out['layout_template'] ?? '');
$slot = $out['slots']['slot_scale'] ?? [];
$its = $slot['scale_items'] ?? [];
check('all three kept', count($its) === 3, json_encode(array_column($its, 'label')));
check('the biggest is the unit of measure', ($its[0]['scale'] ?? null) === 1.0, var_export($its[0]['scale'] ?? null, true));
// 15 of 30 is HALF as long, and a viewer reads a square's side: 0.5, not the
// 0.707 an area-proportional card would draw.
check('half the value is half the SIDE, not half the area',
    abs(($its[1]['scale'] ?? 0) - 0.5) < 0.0001, var_export($its[1]['scale'] ?? null, true));
check('the smallest gets its true fraction',
    abs(($its[2]['scale'] ?? 0) - (1.7 / 30)) < 0.0001, var_export($its[2]['scale'] ?? null, true));
check('the ratio is how many times bigger the biggest is',
    ($its[1]['ratio'] ?? null) === 2.0 && ($its[0]['ratio'] ?? null) === 1.0,
    json_encode(array_column($its, 'ratio')));
check('a large ratio is rounded the way a person says it',
    ($its[2]['ratio'] ?? null) === 18.0, var_export($its[2]['ratio'] ?? null, true));
check('unit, note and highlight kept',
    ($slot['unit'] ?? '') === 'm' && ($its[0]['note'] ?? '') === 'Longest animal alive'
    && ($slot['highlight_index'] ?? null) === 0);

echo "--- the 40x refusal ---\n";
$out = $one(['scale_items' => [['label' => 'Big', 'value' => 40], ['label' => 'Small', 'value' => 1]]]);
check('a 40x spread is still drawable', ($out['slots']['slot_scale']['to_scale'] ?? null) === true);
$out = $one(['scale_items' => [['label' => 'Big', 'value' => 41], ['label' => 'Small', 'value' => 1]]]);
check('past 40x the card stops drawing to scale',
    ($out['slots']['slot_scale']['to_scale'] ?? null) === false);
check('but it still carries the real ratio to state',
    ($out['slots']['slot_scale']['scale_items'][1]['ratio'] ?? null) === 41.0,
    var_export($out['slots']['slot_scale']['scale_items'][1]['ratio'] ?? null, true));
$out = $one(['scale_items' => [
    ['label' => 'Sun', 'value' => 1392700],
    ['label' => 'Jupiter', 'value' => 139820],
    ['label' => 'Earth', 'value' => 12742],
]]);
$its = $out['slots']['slot_scale']['scale_items'] ?? [];
check('a solar-system spread reads as not-to-scale',
    ($out['slots']['slot_scale']['to_scale'] ?? null) === false);
check('and Earth is stated at 109x smaller', ($its[2]['ratio'] ?? null) === 109.0,
    var_export($its[2]['ratio'] ?? null, true));

echo "--- what an item must be ---\n";
$out = $one(['scale_items' => [
    ['label' => 'Kept', 'value' => 30],
    ['label' => '', 'value' => 10],
    ['label' => 'No number', 'value' => 'big'],
    ['label' => 'Negative', 'value' => -4],
    ['label' => 'Zero', 'value' => 0],
    ['label' => 'Also kept', 'value' => 10],
]]);
$its = $out['slots']['slot_scale']['scale_items'] ?? [];
check('unlabelled, unnumbered, negative and zero items are dropped',
    array_column($its, 'label') === ['Kept', 'Also kept'], json_encode(array_column($its, 'label')));

$out = $one(['scale_items' => [
    ['label' => 'Same', 'value' => 30],
    ['label' => 'SAME', 'value' => 10],
    ['label' => 'Other', 'value' => 20],
]]);
$its = $out['slots']['slot_scale']['scale_items'] ?? [];
check('duplicate labels collapse to the first',
    count($its) === 2 && ($its[0]['value'] ?? null) === 30.0, json_encode($its));

$out = $one(['scale_items' => [
    ['label' => 'One', 'value' => 5], ['label' => 'Two', 'value' => 4],
    ['label' => 'Three', 'value' => 3], ['label' => 'Four', 'value' => 2],
], 'highlight_index' => 3]);
$slot = $out['slots']['slot_scale'] ?? [];
check('capped at 3 things — more is a chart, not a comparison',
    count($slot['scale_items'] ?? []) === 3, (string) count($slot['scale_items'] ?? []));
check('a highlight past the cap is dropped, not guessed', !isset($slot['highlight_index']),
    json_encode($slot['highlight_index'] ?? 'absent'));

$out = $one(['heading' => 'Only one thing', 'scale_items' => [['label' => 'Alone', 'value' => 5]]]);
check('one thing is not a comparison — it degrades to text',
    ($out['layout_template'] ?? '') === 'single_focus', $out['layout_template'] ?? '');
check('and the degrade keeps what there was',
    (bool) array_filter($out['slots']['slot_main']['bullets'] ?? [], fn ($b) => str_contains($b, 'Alone')),
    json_encode($out['slots']['slot_main']['bullets'] ?? []));

echo "--- shape, unit, aliases ---\n";
check('the shape defaults to a square',
    ($one(['scale_items' => $ITEMS])['slots']['slot_scale']['shape'] ?? '') === 'square');
check('circle is honoured',
    ($one(['shape' => 'Circle', 'scale_items' => $ITEMS])['slots']['slot_scale']['shape'] ?? '') === 'circle');
check('anything else falls back to a square rather than drawing nothing',
    ($one(['shape' => 'hexagon', 'scale_items' => $ITEMS])['slots']['slot_scale']['shape'] ?? '') === 'square');
$out = $one(['items' => [
    ['label' => 'Alpha', 'size' => 8, 'caption' => 'a note by another name'],
    ['label' => 'Beta', 'value' => 2],
]]);
$its = $out['slots']['slot_scale']['scale_items'] ?? [];
check('items/size/caption are accepted as the model writes them',
    count($its) === 2 && ($its[0]['note'] ?? '') === 'a note by another name', json_encode($its));

echo "--- the over-cap degrade keeps the SIZE and the RATIO ---\n";
$scenes = $run([
    scaleScene('scene_1', ['unit' => 'm', 'scale_items' => $ITEMS]),
    scaleScene('scene_2', ['unit' => 'm', 'scale_items' => $ITEMS]),
]);
$cards = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'scale_comparison'));
check('only one card survives the cap', count($cards) === 1, (string) count($cards));
$degraded = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'single_focus'));
$bullets = $degraded[0]['slots']['slot_main']['bullets'] ?? [];
check('the degrade prints the measurement',
    (bool) array_filter($bullets, fn ($b) => str_contains($b, '30 m')), json_encode($bullets));
check('and the ratio for everything that is not the biggest',
    (bool) array_filter($bullets, fn ($b) => str_contains($b, '2x smaller')), json_encode($bullets));
check('the biggest is never called smaller than itself',
    !str_contains((string) ($bullets[0] ?? ''), 'smaller'), (string) ($bullets[0] ?? ''));

echo "--- the linter treats it as a drawn card ---\n";
$lint = (new SceneBudgetLinter())->lint($run([
    scaleScene('scene_1', ['unit' => 'm', 'scale_items' => $ITEMS, 'heading' => 'How big is a whale']),
]), []);
$codes = array_column($lint['items'] ?? [], 'code');
check('not flagged as a sparse text scene', !in_array('sparse_scene', $codes, true), implode(',', $codes));
check('not flagged as dead air', !in_array('dead_air', $codes, true), implode(',', $codes));

$scene = $run([scaleScene('scene_1', ['unit' => 'm', 'scale_items' => $ITEMS])])[0];
$m = (new ReflectionClass(SceneBudgetLinter::class))->getMethod('sceneScreenText');
$m->setAccessible(true);
$screen = strtolower((string) $m->invoke(null, $scene));
check('the sync audit reads the labels', str_contains($screen, 'blue whale'), mb_substr($screen, 0, 120));
check('and the figures printed beside them', str_contains($screen, '30'), mb_substr($screen, 0, 120));

echo "--- casting knows about it ---\n";
$docs = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('CARD_DOCS');
check('the composer documents the card', isset($docs['scale_comparison']));
check('the doc names the real slot key', str_contains($docs['scale_comparison'] ?? '', 'slot_scale'));
check('the doc warns that the figures must be real',
    str_contains($docs['scale_comparison'] ?? '', 'never pre-scaled'));
$menus = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('MENUS');
$offered = array_keys(array_filter($menus, fn ($m) => in_array('scale_comparison', $m, true)));
check('offered on at least one intent', $offered !== [], implode(',', $offered));
check('every menu entry is a real template',
    !array_diff(array_unique(array_merge(...array_values($menus))), array_keys($templates)));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
