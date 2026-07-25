<?php

/**
 * cycle-check — clamp/degrade/cap behavior of cycle_diagram (loop iter 7).
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/cycle-check.php
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

function cycleScene(string $id, array $items, ?string $caption = null): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'cycle_diagram',
        'duration_seconds' => 10,
        'narration' => ['text' => 'Around the loop we go, stage after stage, until it starts again.'],
        'slots' => ['slot_cycle' => array_filter([
            'content_type' => 'cycle',
            'heading' => 'The loop',
            'caption' => $caption,
            'items' => $items,
        ], fn ($v) => $v !== null)],
    ];
}

$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false]
)['scenes'];

$byTpl = fn (array $scenes, string $tpl) => array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === $tpl));

// 1. A clean 4-stage cycle survives with caption and icons.
$out = $run([cycleScene('scene_1', [
    ['label' => 'Evaporation', 'icon' => 'sun'],
    ['label' => 'Condensation', 'icon' => 'cloud'],
    ['label' => 'Rainfall', 'icon' => 'droplet'],
    ['label' => 'Collection'],
], '~9 days per trip')]);
$slot = ($byTpl($out, 'cycle_diagram')[0] ?? [])['slots']['slot_cycle'] ?? [];
check('clean cycle survives', ($slot['content_type'] ?? '') === 'cycle' && count($slot['items'] ?? []) === 4);
check('caption kept for the ring centre', ($slot['caption'] ?? '') === '~9 days per trip');

// 2. An invented icon is dropped; the stage survives without it.
$out = $run([cycleScene('scene_1', [
    ['label' => 'Cue', 'icon' => 'definitely-not-an-icon'],
    ['label' => 'Routine', 'icon' => 'clock'],
    ['label' => 'Reward'],
])]);
$slot = ($byTpl($out, 'cycle_diagram')[0] ?? [])['slots']['slot_cycle'] ?? [];
check('unknown icon dropped, stage kept', ($slot['items'][0]['icon'] ?? 'x') === '' && count($slot['items'] ?? []) === 3);

// 3. Two stages are not a ring — degrade to a text scene.
$out = $run([cycleScene('scene_1', [
    ['label' => 'Question'],
    ['label' => 'Answer'],
])]);
check('below 3 stages degrades to text', count($byTpl($out, 'cycle_diagram')) === 0);

// 4. Seven stages clamp to six.
$out = $run([cycleScene('scene_1', array_map(fn ($i) => ['label' => "Stage {$i}"], range(1, 7)))]);
$slot = ($byTpl($out, 'cycle_diagram')[0] ?? [])['slots']['slot_cycle'] ?? [];
check('seven stages clamp to six', count($slot['items'] ?? []) === 6);

// 5. The per-video cap is 1 — the second ring becomes a list that still
//    spells out the return step.
$out = $run([
    cycleScene('scene_1', [['label' => 'A'], ['label' => 'B'], ['label' => 'C']]),
    cycleScene('scene_2', [['label' => 'Cue'], ['label' => 'Routine'], ['label' => 'Reward']]),
]);
check('cap: only one cycle_diagram per video', count($byTpl($out, 'cycle_diagram')) === 1);
$degraded = null;
foreach ($out as $s) {
    if (($s['layout_template'] ?? '') === 'single_focus') {
        $degraded = $s;
    }
}
$bullets = $degraded['slots']['slot_main']['bullets'] ?? [];
check('capped ring degrades to a list with the return step', in_array('… and back to Cue', $bullets, true));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
