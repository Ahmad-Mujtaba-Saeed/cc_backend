<?php

/**
 * spectrum-check — clamp/degrade/cap behavior of spectrum_card (loop iter 12).
 *   docker exec viralforgebackend-app-1 php scratchpad/spectrum-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

function spectrumScene(string $id, array $slot): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'spectrum_card',
        'duration_seconds' => 9,
        'narration' => ['text' => 'Every option lands somewhere between the two poles we drew.'],
        'slots' => ['slot_spectrum' => array_merge(['content_type' => 'spectrum'], $slot)],
    ];
}

$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false]
)['scenes'];
$byTpl = fn (array $scenes, string $t) => array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === $t));

$goodItems = [
    ['label' => 'Bonds', 'position' => 0.08],
    ['label' => 'Index funds', 'position' => 0.25],
    ['label' => 'Crypto', 'position' => 0.95],
];

// 1. A clean spectrum survives with its highlight.
$out = $run([spectrumScene('scene_1', [
    'axis' => ['left_label' => 'Safe', 'right_label' => 'Risky'],
    'spectrum_items' => $goodItems,
    'highlight_index' => 2,
])]);
$slot = ($byTpl($out, 'spectrum_card')[0] ?? [])['slots']['slot_spectrum'] ?? [];
check('clean spectrum survives', count($slot['spectrum_items'] ?? []) === 3);
check('highlight kept', ($slot['highlight_index'] ?? null) === 2);

// 2. A missing pole is not an axis.
$out = $run([spectrumScene('scene_1', [
    'axis' => ['left_label' => 'Safe'],
    'spectrum_items' => $goodItems,
])]);
check('missing pole degrades to text', count($byTpl($out, 'spectrum_card')) === 0);

// 3. One item is a caption, not a spectrum.
$out = $run([spectrumScene('scene_1', [
    'axis' => ['left_label' => 'Safe', 'right_label' => 'Risky'],
    'spectrum_items' => [['label' => 'Crypto', 'position' => 0.9]],
])]);
check('single item degrades to text', count($byTpl($out, 'spectrum_card')) === 0);

// 4. Out-of-range positions clamp; junk positions drop the item.
$out = $run([spectrumScene('scene_1', [
    'axis' => ['left_label' => 'Safe', 'right_label' => 'Risky'],
    'spectrum_items' => [
        ['label' => 'Below', 'position' => -0.4],
        ['label' => 'Above', 'position' => 1.7],
        ['label' => 'Junk', 'position' => 'very risky'],
    ],
])]);
$slot = ($byTpl($out, 'spectrum_card')[0] ?? [])['slots']['slot_spectrum'] ?? [];
$positions = array_column($slot['spectrum_items'] ?? [], 'position', 'label');
check('positions clamp to 0..1, junk item dropped',
    count($positions) === 2 && $positions['Below'] === 0.0 && $positions['Above'] === 1.0);

// 5. Duplicate labels collapse; a stale highlight is dropped, not guessed.
$out = $run([spectrumScene('scene_1', [
    'axis' => ['left_label' => 'Safe', 'right_label' => 'Risky'],
    'spectrum_items' => [
        ['label' => 'Crypto', 'position' => 0.9],
        ['label' => 'crypto', 'position' => 0.2],
        ['label' => 'Bonds', 'position' => 0.1],
    ],
    'highlight_index' => 7,
])]);
$slot = ($byTpl($out, 'spectrum_card')[0] ?? [])['slots']['slot_spectrum'] ?? [];
check('duplicate labels dedupe', count($slot['spectrum_items'] ?? []) === 2);
check('stale highlight dropped', !isset($slot['highlight_index']));

// 6. The per-video cap is 1; the capped card reads as "toward <pole>" bullets.
$out = $run([
    spectrumScene('scene_1', [
        'axis' => ['left_label' => 'Safe', 'right_label' => 'Risky'],
        'spectrum_items' => $goodItems,
    ]),
    spectrumScene('scene_2', [
        'axis' => ['left_label' => 'Cheap', 'right_label' => 'Expensive'],
        'spectrum_items' => [
            ['label' => 'Rice', 'position' => 0.1],
            ['label' => 'Saffron', 'position' => 0.97],
        ],
    ]),
]);
check('cap: one spectrum per video', count($byTpl($out, 'spectrum_card')) === 1);
$degraded = null;
foreach ($out as $s) {
    if (($s['layout_template'] ?? '') === 'single_focus') {
        $degraded = $s;
    }
}
$bullets = $degraded['slots']['slot_main']['bullets'] ?? [];
check('capped card keeps its judgements as bullets', in_array('Saffron — toward Expensive', $bullets, true));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
