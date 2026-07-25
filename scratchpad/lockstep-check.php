<?php

/**
 * lockstep-check — narration↔step lockstep audit + units lint (loop iter 10).
 * Pure static linter, runs anywhere:
 *   php scratchpad/lockstep-check.php
 *   docker exec viralforgebackend-app-1 php scratchpad/lockstep-check.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Modules\Project\Support\SceneBudgetLinter;

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

function chain(string $id, array $exprs, string $narr, ?array $lastNote = null): array
{
    $steps = array_map(fn ($e) => ['expr' => $e], $exprs);
    if ($lastNote !== null) {
        $steps[count($steps) - 1]['note'] = $lastNote['note'];
    }
    return [
        'scene_id' => $id,
        'layout_template' => 'math_steps',
        'duration_seconds' => 12,
        'narration' => ['text' => $narr],
        'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => $steps]],
    ];
}

function scenario(string $id, array $values, string $question): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'scenario_diagram',
        'duration_seconds' => 10,
        'narration' => ['text' => 'Here is the setup, drawn before any algebra begins today.'],
        'slots' => ['slot_scenario' => [
            'content_type' => 'scenario',
            'entities' => array_map(fn ($v) => ['label' => 'Actor', 'value' => $v], $values),
            'question' => $question,
        ]],
    ];
}

$lint = fn (array $scenes, bool $math = true) => SceneBudgetLinter::lint($scenes, [
    'hook_enabled' => false,
    'outro_enabled' => false,
    'math_mode' => $math,
]);
$byCode = fn (array $report, string $code) => array_values(array_filter(
    $report['items'],
    fn ($i) => $i['code'] === $code
));

// 1. Perfect lockstep: one sentence per step, numbers in their own sentences.
$r = $lint([chain('scene_1',
    ['m = 155 - 24', 'm = 131', 'total = 131 + 262'],
    'First, 155 minus 24. That leaves 131 million. Adding 262 closes the books.'
)]);
check('perfect lockstep passes clean', $byCode($r, 'steps_narration_lockstep') === []);

// 2. Two moves per breath: fewer sentences than steps.
$r = $lint([chain('scene_1',
    ['x = 45 + 10', 'x = 55', 'y = 55 * 3', 'y = 165'],
    'We add and double things. The answer is 165.'
)]);
check('compressed narration flagged', count($byCode($r, 'steps_narration_lockstep')) === 1);

// 3. A number spoken far from its step is a sync fault.
$r = $lint([chain('scene_1',
    ['x = 45 + 10', 'x = 55', 'y = 55 * 3', 'y = 165'],
    'Keep 165 in mind as the goal today. Add ten to 45 which gives 55. Then we triple the result. The prediction held exactly.'
)]);
$hits = $byCode($r, 'steps_narration_lockstep');
check('out-of-order number flagged', count($hits) === 1 && str_contains($hits[0]['message'], '165'));

// 4. A consistent one-sentence intro shifts everything — not a fault.
$r = $lint([chain('scene_1',
    ['d = 45 * 12', 'd = 540', 'left = 540 - 15'],
    'Now the real algebra begins. Multiply 45 by twelve for the distance. That comes to 540 exactly. Take away 15 and we are done.'
)]);
check('uniform intro offset tolerated', $byCode($r, 'steps_narration_lockstep') === []);

// 5. Unspoken numbers are tolerated (the voice says "simplify").
$r = $lint([chain('scene_1',
    ['t = frac{-28}{-9.8}', 't = 2.86'],
    'Divide through and simplify. The vertex time falls out.'
)]);
check('unspoken numbers tolerated', $byCode($r, 'steps_narration_lockstep') === []);

// 6. Outside math mode nothing fires.
$r = $lint([chain('scene_1',
    ['x = 45 + 10', 'x = 55', 'y = 55 * 3', 'y = 165'],
    'Two short sentences only. The answer is 165.'
)], false);
check('non-math videos skip the audit', $byCode($r, 'steps_narration_lockstep') === []);

// 7. Units in the givens must reach the stamped answer.
$r = $lint([
    scenario('scene_1', ['v = 20 km/min', 't = 20 min'], 'd = ?'),
    chain('scene_2', ['d = 20 * 20', 'd = 400'], 'Multiply speed by time. That gives four hundred.'),
]);
$hits = $byCode($r, 'units_dropped');
check('dropped units flagged as info', count($hits) === 1 && $hits[0]['severity'] === 'info');

// 8. A unit word on the answer note satisfies it.
$r = $lint([
    scenario('scene_1', ['v = 20 km/min', 't = 20 min'], 'd = ?'),
    chain('scene_2', ['d = 20 * 20', 'd = 400'], 'Multiply speed by time. That gives four hundred.', ['note' => 'kilometres']),
]);
check('unit word on the note satisfies', $byCode($r, 'units_dropped') === []);

// 9. A unit glued to the final expression satisfies too.
$r = $lint([
    chain('scene_1', ['h = 9.8 m/s^2 * 4', 't = 2.86 s'], 'Multiply the acceleration out. The time drops to just under three.'),
]);
check('unit on the final line satisfies', $byCode($r, 'units_dropped') === []);

// 10. Pure algebra owes no unit back.
$r = $lint([chain('scene_1', ['x^2 + 5*x - 24 = 0', 'x = 3'], 'Factor the quadratic today. The positive root is three.')]);
check('unit-free working never flagged', $byCode($r, 'units_dropped') === []);

// 11. Algebra's "5s" (five times s) is not five seconds.
$r = $lint([chain('scene_1', ['y = 5s + 12', 'y = 17s'], 'Five s plus twelve to start. Collect the terms to finish.')]);
check('"5s" never reads as seconds', $byCode($r, 'units_dropped') === []);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
