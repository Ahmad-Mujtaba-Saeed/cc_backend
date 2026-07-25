<?php

/**
 * formula-anatomy-check — clamp/degrade/cap behavior of the new card
 * (loop iter 5). Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/formula-anatomy-check.php
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

function faScene(string $id, string $formula, array $parts, string $narr = 'The formula, piece by piece, before we solve it properly today.'): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'formula_anatomy',
        'duration_seconds' => 12,
        'narration' => ['text' => $narr],
        'slots' => ['slot_formula' => [
            'content_type' => 'formula',
            'formula' => $formula,
            'parts' => $parts,
        ]],
    ];
}

$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false, 'math_mode' => true]
)['scenes'];

$byTpl = function (array $scenes, string $tpl): array {
    return array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === $tpl));
};

// 1. A clean card survives with its parts anchored.
$out = $run([faScene('scene_1', 'h(t) = -4.9t^2 + 28t + 3', [
    ['match' => '-4.9', 'label' => 'half of gravity, pulling down'],
    ['match' => '28', 'label' => 'launch speed'],
    ['match' => '3', 'label' => 'starting height'],
])]);
$fa = $byTpl($out, 'formula_anatomy');
$slot = $fa[0]['slots']['slot_formula'] ?? [];
check('clean card survives as formula_anatomy', count($fa) === 1);
check('content_type is formula with 3 parts', ($slot['content_type'] ?? '') === 'formula' && count($slot['parts'] ?? []) === 3);

// 2. An unanchorable match is dropped; the card lives on the two that anchor.
$out = $run([faScene('scene_1', 'E = m*c^2', [
    ['match' => 'm', 'label' => 'the mass'],
    ['match' => 'c^2', 'label' => 'light speed, squared'],
    ['match' => '9.8', 'label' => 'gravity (not in this formula)'],
])]);
$slot = ($byTpl($out, 'formula_anatomy')[0] ?? [])['slots']['slot_formula'] ?? [];
check('unanchorable match dropped, card kept on the rest', count($slot['parts'] ?? []) === 2);

// 3. One anchorable part is a caption, not an anatomy — degrade to text.
$out = $run([faScene('scene_1', 'E = m*c^2', [
    ['match' => 'm', 'label' => 'the mass'],
    ['match' => 'q', 'label' => 'not present'],
])]);
check('below 2 parts degrades to a text scene', count($byTpl($out, 'formula_anatomy')) === 0);

// 4. Unicode arrives mangled-free: ± and ² linearize in formula AND match.
$out = $run([faScene('scene_1', 'x = -b ± sqrt{b² - 4*a*c}', [
    ['match' => '±', 'label' => 'two roots'],
    ['match' => 'b² - 4*a*c', 'label' => 'the discriminant'],
])]);
$slot = ($byTpl($out, 'formula_anatomy')[0] ?? [])['slots']['slot_formula'] ?? [];
check('unicode formula + matches linearize together and anchor', count($slot['parts'] ?? []) === 2);
check('linearized formula carries +- not ±', str_contains((string) ($slot['formula'] ?? ''), '+-'));

// 5. Duplicate matches dedupe.
$out = $run([faScene('scene_1', 'y = a*x + a', [
    ['match' => 'a', 'label' => 'the slope'],
    ['match' => 'a', 'label' => 'the slope again'],
    ['match' => 'x', 'label' => 'the input'],
])]);
$slot = ($byTpl($out, 'formula_anatomy')[0] ?? [])['slots']['slot_formula'] ?? [];
check('duplicate matches dedupe to one', count($slot['parts'] ?? []) === 2);

// 6. The per-video cap is 1 — a second anatomy card degrades.
$out = $run([
    faScene('scene_1', 'h(t) = -4.9t^2 + 28t + 3', [
        ['match' => '-4.9', 'label' => 'half of gravity'],
        ['match' => '28', 'label' => 'launch speed'],
    ]),
    faScene('scene_2', 'E = m*c^2', [
        ['match' => 'm', 'label' => 'the mass'],
        ['match' => 'c^2', 'label' => 'light speed, squared'],
    ], 'A second formula card must fall back to a plain text scene here.'),
]);
check('cap: only one formula_anatomy per video', count($byTpl($out, 'formula_anatomy')) === 1);

// 7. An overlong formula cannot carry the card.
$out = $run([faScene('scene_1', str_repeat('x + ', 30) . '1', [
    ['match' => 'x', 'label' => 'the variable'],
    ['match' => '1', 'label' => 'the constant'],
])]);
check('overlong formula degrades to text', count($byTpl($out, 'formula_anatomy')) === 0);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
