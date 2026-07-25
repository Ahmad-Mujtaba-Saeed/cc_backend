<?php

/**
 * Iter 19 — receipt_card.
 *
 * Run in-container:  docker compose exec -T app php scratchpad/receipt-check.php
 *
 * The card's whole promise is that the figures ADD UP. Most of this file is
 * about that one guarantee: the rows are the evidence, the total is recomputed
 * from them, and a model's disagreeing total is never printed.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Validate one receipt scene; returns [scene, warnings]. */
function one(array $slot): array
{
    $out = (new ShotListValidator())->validate(['scenes' => [[
        'narration' => ['text' => 'Here is where the money actually goes once every line item is added up properly.'],
        'layout_template' => 'receipt_card',
        'slots' => ['slot_receipt' => $slot],
    ]]], ['hook_enabled' => false, 'outro_enabled' => false]);

    return [$out['scenes'][0], $out['warnings']];
}

echo "\n== registry\n";
check('version bumped to 28', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 28,
    (string) (ExplainerRegistry::all()['version'] ?? '?'));
check('receipt_card template exists', in_array('receipt_card', ExplainerRegistry::templateNames(), true));
check('receipt_card caps at 1 per video', ExplainerRegistry::maxPerVideo('receipt_card') === 1);

echo "\n== THE GUARANTEE: the rows are the truth\n";

[$computed] = one(['content_type' => 'receipt', 'rows' => [
    ['label' => 'Hosting', 'value' => 4200],
    ['label' => 'Salaries', 'value' => 38500],
]]);
check('total computed when absent', ($computed['slots']['slot_receipt']['total'] ?? null) === 42700.0,
    var_export($computed['slots']['slot_receipt']['total'] ?? null, true));

[$corrected, $warns] = one(['content_type' => 'receipt', 'total' => 99999, 'rows' => [
    ['label' => 'Hosting', 'value' => 4200],
    ['label' => 'Salaries', 'value' => 38500],
]]);
check('a WRONG total is overwritten by the row sum',
    ($corrected['slots']['slot_receipt']['total'] ?? null) === 42700.0,
    var_export($corrected['slots']['slot_receipt']['total'] ?? null, true));
check('the correction is reported to the operator',
    count(array_filter($warns, fn ($w) => str_contains((string) $w, 'did not match its rows'))) === 1,
    implode(' | ', $warns));

[$agreed, $quietWarns] = one(['content_type' => 'receipt', 'total' => 42700, 'rows' => [
    ['label' => 'Hosting', 'value' => 4200],
    ['label' => 'Salaries', 'value' => 38500],
]]);
check('a CORRECT total raises no warning',
    count(array_filter($quietWarns, fn ($w) => str_contains((string) $w, 'did not match'))) === 0,
    implode(' | ', $quietWarns));

[$negatives] = one(['content_type' => 'receipt', 'rows' => [
    ['label' => 'Subtotal', 'value' => 5000],
    ['label' => 'Discount', 'value' => -1200],
]]);
check('negative rows subtract', ($negatives['slots']['slot_receipt']['total'] ?? null) === 3800.0,
    var_export($negatives['slots']['slot_receipt']['total'] ?? null, true));

[$decimals] = one(['content_type' => 'receipt', 'rows' => [
    ['label' => 'Meetings', 'value' => 11.5],
    ['label' => 'Deep work', 'value' => 14.25],
]]);
check('decimals sum without float drift', ($decimals['slots']['slot_receipt']['total'] ?? null) === 25.75,
    var_export($decimals['slots']['slot_receipt']['total'] ?? null, true));

echo "\n== the model sends dirty numbers anyway\n";

[$dirty] = one(['content_type' => 'receipt', 'rows' => [
    ['label' => 'Hosting', 'value' => '$1,200'],
    ['label' => 'Salaries', 'value' => '2 300'],
    ['label' => 'Refund', 'value' => '(150)'],
]]);
$r = $dirty['slots']['slot_receipt'] ?? [];
check('currency symbols and separators stripped', ($r['rows'][0]['value'] ?? null) === 1200.0,
    var_export($r['rows'][0]['value'] ?? null, true));
check('spaced thousands parsed', ($r['rows'][1]['value'] ?? null) === 2300.0);
check('parenthesised figure is an accounting negative', ($r['rows'][2]['value'] ?? null) === -150.0,
    var_export($r['rows'][2]['value'] ?? null, true));
check('dirty rows still total correctly', ($r['total'] ?? null) === 3350.0, var_export($r['total'] ?? null, true));

[$junk] = one(['content_type' => 'receipt', 'rows' => [
    ['label' => 'Real', 'value' => 100],
    ['label' => 'Unpriceable', 'value' => 'lots'],
    ['label' => 'Also real', 'value' => 50],
]]);
check('non-numeric rows are dropped, not zeroed',
    count($junk['slots']['slot_receipt']['rows'] ?? []) === 2 && ($junk['slots']['slot_receipt']['total'] ?? null) === 150.0,
    json_encode($junk['slots']['slot_receipt'] ?? []));

echo "\n== shape\n";

[$thin] = one(['content_type' => 'receipt', 'rows' => [['label' => 'Only one', 'value' => 5]], 'heading' => 'Thin']);
check('a single row degrades away from receipt_card', $thin['layout_template'] !== 'receipt_card', $thin['layout_template']);
check('the row survives the degrade', str_contains(json_encode($thin), 'Only one'));

$nine = [];
for ($i = 1; $i <= 10; $i++) {
    $nine[] = ['label' => "Row {$i}", 'value' => 10];
}
[$capped9] = one(['content_type' => 'receipt', 'rows' => $nine]);
check('rows capped at 8', count($capped9['slots']['slot_receipt']['rows'] ?? []) === 8,
    (string) count($capped9['slots']['slot_receipt']['rows'] ?? []));
check('total reflects only the kept rows', ($capped9['slots']['slot_receipt']['total'] ?? null) === 80.0,
    var_export($capped9['slots']['slot_receipt']['total'] ?? null, true));

[$long] = one([
    'content_type' => 'receipt',
    'rows' => [['label' => str_repeat('x', 50), 'value' => 1], ['label' => 'Two', 'value' => 2]],
    'total_label' => str_repeat('t', 40),
    'unit' => str_repeat('u', 20),
    'heading' => str_repeat('h', 90),
    'caption' => str_repeat('c', 150),
]);
$L = $long['slots']['slot_receipt'];
check('row label clamped to 28', mb_strlen($L['rows'][0]['label']) === 28);
check('total_label clamped to 20', mb_strlen($L['total_label']) === 20);
check('unit clamped to 6', mb_strlen($L['unit']) === 6);
check('heading clamped to 60', mb_strlen($L['heading']) === 60);
check('caption clamped to 80', mb_strlen($L['caption']) === 80);

echo "\n== cap degrade (max 1 per video)\n";

$mk = fn (string $h) => [
    'narration' => ['text' => "The {$h} breakdown adds up line by line and this sentence carries the beat."],
    'layout_template' => 'receipt_card',
    'slots' => ['slot_receipt' => ['content_type' => 'receipt', 'heading' => $h, 'unit' => '$',
        'rows' => [['label' => 'Alpha', 'value' => 100], ['label' => 'Rebate', 'value' => -25]]]],
];
$two = (new ShotListValidator())->validate(['scenes' => [$mk('First'), $mk('Second')]],
    ['hook_enabled' => false, 'outro_enabled' => false]);
$tpls = array_map(fn ($s) => $s['layout_template'], $two['scenes']);
check('first receipt kept', $tpls[0] === 'receipt_card', implode(',', $tpls));
check('second degraded', $tpls[1] !== 'receipt_card', implode(',', $tpls));
$flat = json_encode($two['scenes'][1]);
check('degrade keeps rows and the total', str_contains($flat, 'Alpha') && str_contains($flat, '75'), $flat);
check('degrade puts the sign OUTSIDE the currency symbol',
    str_contains($flat, '-$25') && !str_contains($flat, '$-25'), $flat);

echo "\n== linter\n";
$lint = SceneBudgetLinter::lint([array_merge($two['scenes'][0], ['duration_seconds' => 11.0])], []);
$noise = array_filter($lint['items'], fn ($i) => in_array($i['code'] ?? '', ['min_text', 'static_hold'], true));
check('no min_text/static_hold on a long receipt scene', count($noise) === 0, json_encode(array_values($noise)));

$m = new ReflectionMethod(SceneBudgetLinter::class, 'sceneScreenText');
$m->setAccessible(true);
$txt = $m->invoke(null, ['slots' => ['slot_receipt' => [
    'content_type' => 'receipt', 'rows' => [['label' => 'Hosting', 'value' => 4200]], 'total' => 4200,
]]]);
check('receipt rows visible to the sync audit', str_contains($txt, 'Hosting') && str_contains($txt, '4200'), $txt);

echo "\n== casting\n";
$composer = file_get_contents(__DIR__ . '/../modules/Project/Services/GenericStoryboardComposerService.php');
check('receipt_card in a composer menu', str_contains($composer, "'receipt_card'"));
check('receipt_card has CARD_DOCS', str_contains($composer, "'receipt_card' => 'slot_receipt:"));
$analysis = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php');
check('receipt_card in the giant-call card list', str_contains($analysis, '"receipt_card"'));

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
