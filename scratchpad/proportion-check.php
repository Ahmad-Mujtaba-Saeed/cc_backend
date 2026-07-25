<?php

/**
 * proportion-check — the arithmetic guarantee, clamp, degrade, cap and casting
 * of proportion_flow (loop iter 36, card queue #2 item 9).
 *   docker exec viralforgebackend-app-1 php scratchpad/proportion-check.php
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

$BRANCHES = [
    ['label' => 'Health and pensions', 'value' => 42],
    ['label' => 'Schools', 'value' => 28],
    ['label' => 'Roads and grid', 'value' => 19],
    ['label' => 'Debt interest', 'value' => 11],
];

function propScene(string $id, array $slot): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'proportion_flow',
        'duration_seconds' => 13,
        'narration' => ['text' => 'Follow every tax dollar and the argument about spending settles itself.'],
        'slots' => ['slot_proportion' => array_merge(['content_type' => 'proportion'], $slot)],
    ];
}

/** Validate one proportion scene; returns [scene, warnings]. */
function one(array $slot): array
{
    $out = (new ShotListValidator())->validate(
        ['scenes' => [propScene('scene_1', $slot)]],
        ['hook_enabled' => false, 'outro_enabled' => false]
    );

    return [$out['scenes'][0] ?? [], $out['warnings'] ?? []];
}

$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false]
)['scenes'];

echo "--- registry ---\n";
$templates = ExplainerRegistry::templates();
check('proportion_flow template registered', isset($templates['proportion_flow']));
check('its slot is slot_proportion', isset($templates['proportion_flow']['slots']['slot_proportion']));
check('the slot allows the proportion type',
    ($templates['proportion_flow']['slots']['slot_proportion']['allowed'] ?? []) === ['proportion']);
check('capped at 1 per video', ($templates['proportion_flow']['max_per_video'] ?? null) === 1);
$reg = json_decode(file_get_contents(__DIR__ . '/../modules/Project/Resources/explainer_registry.json'), true);
check('proportion content type registered', isset($reg['content_types']['proportion']));
check('registry version bumped past the quadrant', (int) ($reg['version'] ?? 0) >= 35, (string) ($reg['version'] ?? ''));

echo "--- the clamp keeps a good payload, and DERIVES the shares ---\n";
[$out, ] = one(['source_label' => 'Every tax dollar', 'unit' => 'c', 'branches' => $BRANCHES, 'highlight_index' => 3]);
check('template survives', ($out['layout_template'] ?? '') === 'proportion_flow', $out['layout_template'] ?? '');
$slot = $out['slots']['slot_proportion'] ?? [];
check('the branches are emitted as `slices`', isset($slot['slices']) && !isset($slot['branches']),
    implode(',', array_keys($slot)));
check('all four kept', count($slot['slices'] ?? []) === 4, json_encode($slot['slices'] ?? []));
check('the total is the sum of the values', ($slot['total'] ?? null) === 100.0, var_export($slot['total'] ?? null, true));
check('every slice carries a computed share',
    count(array_filter($slot['slices'], fn ($s) => isset($s['share']))) === 4);
check('the biggest share is its own value over the sum',
    abs(($slot['slices'][0]['share'] ?? 0) - 0.42) < 0.0001, var_export($slot['slices'][0]['share'] ?? null, true));
check('the shares sum to 1',
    abs(array_sum(array_column($slot['slices'], 'share')) - 1.0) < 0.0001,
    (string) array_sum(array_column($slot['slices'], 'share')));
check('source label, unit and highlight kept',
    ($slot['source_label'] ?? '') === 'Every tax dollar' && ($slot['unit'] ?? '') === 'c'
    && ($slot['highlight_index'] ?? null) === 3);

echo "--- a percentage the model sent is OVERWRITTEN, and reported ---\n";
[$out, $warns] = one(['branches' => [
    // "60/40" claimed over values that are actually 50/50 — the exact failure
    // this card exists to make impossible.
    ['label' => 'Rent', 'value' => 50, 'share' => 0.6],
    ['label' => 'Everything else', 'value' => 50, 'percent' => 40],
]]);
$slices = $out['slots']['slot_proportion']['slices'] ?? [];
check('the drawn share follows the VALUES, not the claim',
    abs(($slices[0]['share'] ?? 0) - 0.5) < 0.0001, var_export($slices[0]['share'] ?? null, true));
check('nothing the model wrote survives into the payload',
    !isset($slices[0]['sent_share']) && !isset($slices[0]['percent']), json_encode($slices[0] ?? []));
check('and the operator is warned about the disagreement',
    count(array_filter($warns, fn ($w) => str_contains((string) $w, 'disagreed with its value'))) === 1,
    implode(' | ', $warns));

[$out, $warns] = one(['branches' => [
    ['label' => 'Rent', 'value' => 50, 'share' => 0.5],
    ['label' => 'Everything else', 'value' => 50, 'share' => 0.5],
]]);
check('a percentage that AGREES raises no warning',
    count(array_filter($warns, fn ($w) => str_contains((string) $w, 'disagreed'))) === 0,
    implode(' | ', $warns));

echo "--- a whole the branches do not cover ---\n";
[$out, $warns] = one(['total' => 100, 'branches' => [
    ['label' => 'Housing', 'value' => 40],
    ['label' => 'Food', 'value' => 25],
]]);
$slices = $out['slots']['slot_proportion']['slices'] ?? [];
check('the leftover is DRAWN, not absorbed', count($slices) === 3 && ($slices[2]['label'] ?? '') === 'Other',
    json_encode(array_column($slices, 'label')));
check('and it is the real remainder', ($slices[2]['value'] ?? null) === 35.0, var_export($slices[2]['value'] ?? null, true));
check('so the parts still add to the stated whole',
    ($out['slots']['slot_proportion']['total'] ?? null) === 100.0);
check('the operator hears about it',
    count(array_filter($warns, fn ($w) => str_contains((string) $w, 'only part of the stated total'))) === 1,
    implode(' | ', $warns));

[$out, ] = one(['total' => 101, 'branches' => [
    ['label' => 'Housing', 'value' => 60],
    ['label' => 'Food', 'value' => 40],
]]);
$slices = $out['slots']['slot_proportion']['slices'] ?? [];
check('a leftover under 2% is rounding, not a part', count($slices) === 2, json_encode(array_column($slices, 'label')));

$five = [];
for ($i = 0; $i < 5; $i++) {
    $five[] = ['label' => "Part {$i}", 'value' => 10];
}
[$out, ] = one(['total' => 200, 'branches' => $five]);
$slices = $out['slots']['slot_proportion']['slices'] ?? [];
check('with no room left the branches ARE the whole', count($slices) === 5
    && ($out['slots']['slot_proportion']['total'] ?? null) === 50.0,
    json_encode(array_column($slices, 'label')));

[$out, ] = one(['total' => 60, 'branches' => [
    ['label' => 'Housing', 'value' => 60],
    ['label' => 'Food', 'value' => 40],
]]);
check('a total SMALLER than its parts never shrinks them',
    ($out['slots']['slot_proportion']['total'] ?? null) === 100.0,
    var_export($out['slots']['slot_proportion']['total'] ?? null, true));

echo "--- what a slice must be ---\n";
[$out, ] = one(['branches' => [
    ['label' => 'Kept', 'value' => 30],
    ['label' => '', 'value' => 10],
    ['label' => 'No number', 'value' => 'lots'],
    ['label' => 'Refund', 'value' => -12],
    ['label' => 'Nothing at all', 'value' => 0],
    ['label' => 'Also kept', 'value' => 70],
]]);
$slices = $out['slots']['slot_proportion']['slices'] ?? [];
check('unlabelled, unnumbered, negative and zero slices are dropped',
    array_column($slices, 'label') === ['Kept', 'Also kept'], json_encode(array_column($slices, 'label')));
check('and the survivors still make a whole',
    abs(array_sum(array_column($slices, 'share')) - 1.0) < 0.0001);

[$out, ] = one(['branches' => [
    ['label' => 'Same', 'value' => 30],
    ['label' => 'SAME', 'value' => 10],
    ['label' => 'Other', 'value' => 60],
]]);
$slices = $out['slots']['slot_proportion']['slices'] ?? [];
check('duplicate labels collapse to the first', count($slices) === 2 && ($slices[0]['value'] ?? null) === 30.0,
    json_encode($slices));

$many = [];
for ($i = 0; $i < 8; $i++) {
    $many[] = ['label' => "Part {$i}", 'value' => 10 + $i];
}
[$out, ] = one(['branches' => $many, 'highlight_index' => 7]);
$slot = $out['slots']['slot_proportion'] ?? [];
check('capped at 5 slices', count($slot['slices'] ?? []) === 5, (string) count($slot['slices'] ?? []));
check('a highlight past the cap is dropped, not guessed', !isset($slot['highlight_index']),
    json_encode($slot['highlight_index'] ?? 'absent'));
check('the shares still describe what is DRAWN, not what was sent',
    abs(array_sum(array_column($slot['slices'], 'share')) - 1.0) < 0.0001);

echo "--- aliases and notes ---\n";
[$out, ] = one(['items' => [
    ['label' => 'Alpha', 'amount' => 3, 'caption' => 'a note by another name'],
    ['label' => 'Beta', 'value' => 1],
]]);
$slices = $out['slots']['slot_proportion']['slices'] ?? [];
check('items/amount/caption are accepted as the model writes them',
    count($slices) === 2 && ($slices[0]['note'] ?? '') === 'a note by another name', json_encode($slices));

echo "--- one slice is not a split ---\n";
[$out, ] = one(['heading' => 'It all goes one place', 'branches' => [['label' => 'Only this', 'value' => 5]]]);
check('a single branch degrades to text', ($out['layout_template'] ?? '') === 'single_focus',
    $out['layout_template'] ?? '');
check('and the degrade keeps what there was',
    (bool) array_filter($out['slots']['slot_main']['bullets'] ?? [], fn ($b) => str_contains($b, 'Only this')),
    json_encode($out['slots']['slot_main']['bullets'] ?? []));

echo "--- the over-cap degrade keeps the SHARES ---\n";
$scenes = $run([
    propScene('scene_1', ['source_label' => 'Every tax dollar', 'branches' => $BRANCHES]),
    propScene('scene_2', ['source_label' => 'Every tax dollar', 'branches' => $BRANCHES]),
]);
$cards = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'proportion_flow'));
check('only one card survives the cap', count($cards) === 1, (string) count($cards));
$degraded = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'single_focus'));
$bullets = $degraded[0]['slots']['slot_main']['bullets'] ?? [];
check('the degrade prints the computed share',
    (bool) array_filter($bullets, fn ($b) => str_contains($b, 'Schools — 28%')), json_encode($bullets));
check('and titles itself from the source',
    str_contains((string) ($degraded[0]['slots']['slot_main']['heading'] ?? ''), 'every tax dollar'),
    (string) ($degraded[0]['slots']['slot_main']['heading'] ?? ''));

echo "--- the linter treats it as a drawn card ---\n";
$lint = (new SceneBudgetLinter())->lint($run([
    propScene('scene_1', ['source_label' => 'Every tax dollar', 'branches' => $BRANCHES, 'heading' => 'Where it goes']),
]), []);
$codes = array_column($lint['items'] ?? [], 'code');
check('not flagged as a sparse text scene', !in_array('sparse_scene', $codes, true), implode(',', $codes));
check('not flagged as dead air', !in_array('dead_air', $codes, true), implode(',', $codes));

// The sync audit must be able to SEE the slice labels, or every figure on the
// card reads as spoken-but-never-shown.
$narr = 'Health and pensions take the biggest share, then schools, roads and grid, and debt interest.';
$scene = $run([array_merge(propScene('scene_1', ['branches' => $BRANCHES]), ['narration' => ['text' => $narr]])])[0];
$ref = new ReflectionClass(SceneBudgetLinter::class);
$m = $ref->getMethod('sceneScreenText');
$m->setAccessible(true);
$screen = strtolower((string) $m->invoke(null, $scene));
check('the sync audit reads the slice labels', str_contains($screen, 'schools'), mb_substr($screen, 0, 120));
check('and their notes and figures too', str_contains($screen, '42'), mb_substr($screen, 0, 120));

echo "--- casting knows about it ---\n";
$docs = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('CARD_DOCS');
check('the composer documents the card', isset($docs['proportion_flow']));
check('the doc names the real slot key', str_contains($docs['proportion_flow'] ?? '', 'slot_proportion'));
check('the doc forbids sending percentages',
    str_contains($docs['proportion_flow'] ?? '', 'never send percentages')
    || str_contains($docs['proportion_flow'] ?? '', 'never a percentage'));
$menus = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('MENUS');
$offered = array_keys(array_filter($menus, fn ($m) => in_array('proportion_flow', $m, true)));
check('offered on at least one intent', $offered !== [], implode(',', $offered));
check('every menu entry is a real template',
    !array_diff(array_unique(array_merge(...array_values($menus))), array_keys($templates)));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
