<?php

/**
 * proj34-fix-check — the exact failure shapes of project 34, verified against
 * the new validator passes + routing rule.
 *
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/proj34-fix-check.php
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

$mkText = function (string $id, string $heading, array $bullets, string $narr, string $tpl = 'single_focus') {
    return [
        'scene_id' => $id,
        'layout_template' => $tpl,
        'duration_seconds' => 11,
        'narration' => ['text' => $narr],
        'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => $heading, 'bullets' => $bullets]],
    ];
};

// ---------------------------------------------------------------- validate()
// A miniature project 34: working on math cards, answers exiled to counters,
// a text card describing the plot, mangled superscript in a bullet.
$shotList = ['scenes' => [
    $mkText('scene_1', 'Projectile Launch', ['How high does it go?'], 'A projectile follows h of t equals negative four point nine t squared plus twenty eight t plus three.'),
    $mkText('scene_3', 'Quadratic Equation', ["h(t) = at\x1B2 + bt + c", 'a = -4.9, b = 28, c = 3'], 'First we identify the standard form.'),
    [
        'scene_id' => 'scene_5',
        'layout_template' => 'math_steps',
        'duration_seconds' => 14,
        'narration' => ['text' => 'To find the time of maximum height we use the vertex formula.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [
            ['expr' => 't = -b / (2a)', 'note' => 'Use formula'],
            ['expr' => 't = -28 / (2 * -4.9)', 'note' => 'Plug in values'],
            ['expr' => 't = -28 / -9.8'],
        ]]],
    ],
    [
        'scene_id' => 'scene_6',
        'layout_template' => 'big_counter',
        'duration_seconds' => 10,
        'narration' => ['text' => 'Calculating this gives us a time of approximately two point eight six seconds.'],
        'slots' => ['slot_counter' => ['content_type' => 'text_block', 'heading' => '2.86 seconds',
            'bullets' => ['Calculating this gives us a time of approximately two point eight six seconds.']]],
    ],
    [
        'scene_id' => 'scene_14',
        'layout_template' => 'function_plot',
        'duration_seconds' => 12,
        'narration' => ['text' => 'Let us visualize the height over time.'],
        'slots' => ['slot_plot' => ['content_type' => 'function_plot', 'expression' => '-4.9*x^2 + 28*x + 3',
            'x_min' => 0, 'x_max' => 6, 'marks' => [['x' => 2.86, 'label' => 'Max Height']]]],
    ],
    $mkText('scene_15', 'Height Over Time', ['Visualizing height vs time', 'Key points marked'], 'Here is the function plot showing the height over time.'),
]];

$res = (new ShotListValidator())->validate($shotList, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$scenes = $res['scenes'];
$byId = [];
foreach ($scenes as $s) {
    $byId[$s['scene_id']] = $s;
}

// 1. The big_counter answer echo is gone, folded into scene_5's chain.
check('answer echo scene dropped', !isset($byId['scene_6']));
$steps5 = $byId['scene_5']['slots']['slot_math']['steps'] ?? [];
$last5 = end($steps5) ?: [];
check('answer landed as final step "t = 2.86"', ($last5['expr'] ?? '') === 't = 2.86');
check('unit kept as the note', ($last5['note'] ?? '') === 'seconds');
check('echo narration folded in', str_contains($byId['scene_5']['narration']['text'] ?? '', 'two point eight six'));
check('echo time folded in', (float) ($byId['scene_5']['duration_seconds'] ?? 0) >= 24.0);

// 2. The plot-describing text card is gone, its words on the plot scene.
check('figure echo scene dropped', !isset($byId['scene_15']));
check('figure echo narration folded into plot', str_contains($byId['scene_14']['narration']['text'] ?? '', 'Here is the function plot'));

// 3. The mangled superscript is repaired, not scrubbed — and the equation
// slide itself was absorbed as scene_5's rule panel (absorbRuleLeadIns),
// which is where the repaired formula must now live.
check('equation lead-in absorbed into the math card', !isset($byId['scene_3']));
$rule5 = $byId['scene_5']['slots']['slot_math']['rule'] ?? [];
check('rule panel carries the lead-in name', ($rule5['name'] ?? '') === 'Quadratic Equation');
$formula = (string) ($rule5['formula'] ?? '');
check('control char gone from formula', !preg_match('/[\x00-\x1F\x7F]/', $formula));
check('superscript repaired to a real power', str_contains($formula, 'at²') || str_contains($formula, 'at^2'));

// 4. Regression: outside math mode nothing folds.
$resPlain = (new ShotListValidator())->validate($shotList, ['math_mode' => false, 'hook_enabled' => false, 'outro_enabled' => false]);
$plainIds = array_column($resPlain['scenes'], 'scene_id');
check('non-math video untouched (counter kept)', in_array('scene_6', $plainIds, true));
check('non-math video untouched (text card kept)', in_array('scene_15', $plainIds, true));

// 5. A stat card NOT after working stays (it is a real statistic).
$standalone = ['scenes' => [
    $mkText('s1', 'The Setup', ['A rocket problem'], 'Some context first.'),
    [
        'scene_id' => 's2',
        'layout_template' => 'big_counter',
        'duration_seconds' => 8,
        'narration' => ['text' => 'Rockets reach speeds of eight kilometers per second.'],
        'slots' => ['slot_counter' => ['content_type' => 'text_block', 'heading' => '8 km/s', 'bullets' => ['Orbital velocity']]],
    ],
]];
$resStandalone = (new ShotListValidator())->validate($standalone, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
check('counter not after math_steps kept', in_array('s2', array_column($resStandalone['scenes'], 'scene_id'), true));

// 6. A number-led title card after working does NOT fold (no calc language).
$title = ['scenes' => [
    [
        'scene_id' => 't1',
        'layout_template' => 'math_steps',
        'duration_seconds' => 12,
        'narration' => ['text' => 'We solve it.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [
            ['expr' => 'x + 1 = 3'], ['expr' => 'x = 2'],
        ]]],
    ],
    [
        'scene_id' => 't2',
        'layout_template' => 'big_counter',
        'duration_seconds' => 8,
        'narration' => ['text' => 'Here are three tips to remember.'],
        'slots' => ['slot_counter' => ['content_type' => 'text_block', 'heading' => '3 Golden Tips', 'bullets' => ['Remember these']]],
    ],
]];
$resTitle = (new ShotListValidator())->validate($title, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
check('number-led title card not treated as answer', in_array('t2', array_column($resTitle['scenes'], 'scene_id'), true));

// 7. Chain already ending on the value: echo folds without duplicate step.
$dup = ['scenes' => [
    [
        'scene_id' => 'd1',
        'layout_template' => 'math_steps',
        'duration_seconds' => 12,
        'narration' => ['text' => 'So t equals two point eight six.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [
            ['expr' => 't = -28 / -9.8'], ['expr' => 't = 2.86'],
        ]]],
    ],
    [
        'scene_id' => 'd2',
        'layout_template' => 'big_counter',
        'duration_seconds' => 8,
        'narration' => ['text' => 'The calculation gives approximately 2.86 seconds.'],
        'slots' => ['slot_counter' => ['content_type' => 'text_block', 'heading' => '2.86 seconds', 'bullets' => ['The result']]],
    ],
]];
$resDup = (new ShotListValidator())->validate($dup, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$d1 = null;
foreach ($resDup['scenes'] as $s) {
    if ($s['scene_id'] === 'd1') {
        $d1 = $s;
    }
}
check('echo after value-final chain still dropped', !in_array('d2', array_column($resDup['scenes'], 'scene_id'), true));
check('no duplicate answer step appended', count($d1['slots']['slot_math']['steps'] ?? []) === 2);

// 8. Figure lead-in BEFORE the figure folds forward.
$lead = ['scenes' => [
    $mkText('l1', 'The Graph', ['Let us plot the curve'], 'Now let us look at the graph of the function.'),
    [
        'scene_id' => 'l2',
        'layout_template' => 'function_plot',
        'duration_seconds' => 10,
        'narration' => ['text' => 'The parabola opens downward.'],
        'slots' => ['slot_plot' => ['content_type' => 'function_plot', 'expression' => '-x^2 + 4', 'x_min' => -3, 'x_max' => 3]],
    ],
]];
$resLead = (new ShotListValidator())->validate($lead, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$l2 = null;
foreach ($resLead['scenes'] as $s) {
    if ($s['scene_id'] === 'l2') {
        $l2 = $s;
    }
}
check('figure lead-in dropped', !in_array('l1', array_column($resLead['scenes'], 'scene_id'), true));
check('lead-in narration precedes figure narration', str_starts_with((string) ($l2['narration']['text'] ?? ''), 'Now let us look'));

// 9. Proj-34-run-2 shape: "42.18 meters / This is the maximum height" —
// no calculational verb, but a plain lowercase unit after working folds.
$run2 = ['scenes' => [
    [
        'scene_id' => 'r1',
        'layout_template' => 'math_steps',
        'duration_seconds' => 12,
        'narration' => ['text' => 'Substitute the time back into the height equation.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [
            ['expr' => 'h(2.86) = -4.9(2.86)^2 + 28(2.86) + 3'],
            ['expr' => 'h = -40.08 + 80.08 + 3'],
        ]]],
    ],
    [
        'scene_id' => 'r2',
        'layout_template' => 'big_counter',
        'duration_seconds' => 11,
        'narration' => ['text' => 'This is the maximum height of the projectile.'],
        'slots' => ['slot_counter' => ['content_type' => 'text_block', 'heading' => '42.18 meters',
            'bullets' => ['This is the maximum height of the projectile.']]],
    ],
]];
$resRun2 = (new ShotListValidator())->validate($run2, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$r1 = null;
foreach ($resRun2['scenes'] as $s) {
    if ($s['scene_id'] === 'r1') {
        $r1 = $s;
    }
}
check('lowercase-unit echo folded (run-2 shape)', !in_array('r2', array_column($resRun2['scenes'], 'scene_id'), true));
$lastR1 = end($r1['slots']['slot_math']['steps']) ?: [];
check('height answer appended as "h = 42.18"', ($lastR1['expr'] ?? '') === 'h = 42.18');

// 10. Echo separated from its chain by the SEE-the-answer plot still folds.
$across = ['scenes' => [
    [
        'scene_id' => 'a1',
        'layout_template' => 'math_steps',
        'duration_seconds' => 12,
        'narration' => ['text' => 'Solving the quadratic gives the flight time.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [
            ['expr' => 't = (-28 - 29.03) / -9.8'],
        ]]],
    ],
    [
        'scene_id' => 'a2',
        'layout_template' => 'function_plot',
        'duration_seconds' => 10,
        'narration' => ['text' => 'See the whole flight on one picture.'],
        'slots' => ['slot_plot' => ['content_type' => 'function_plot', 'expression' => '-4.9*x^2 + 28*x + 3', 'x_min' => 0, 'x_max' => 7]],
    ],
    [
        'scene_id' => 'a3',
        'layout_template' => 'big_counter',
        'duration_seconds' => 11,
        'narration' => ['text' => 'The total flight time of the projectile.'],
        'slots' => ['slot_counter' => ['content_type' => 'text_block', 'heading' => '5.8 seconds', 'bullets' => ['Total flight time']]],
    ],
]];
$resAcross = (new ShotListValidator())->validate($across, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$a1 = null;
foreach ($resAcross['scenes'] as $s) {
    if ($s['scene_id'] === 'a1') {
        $a1 = $s;
    }
}
check('echo across the plot folded', !in_array('a3', array_column($resAcross['scenes'], 'scene_id'), true));
$lastA1 = end($a1['slots']['slot_math']['steps']) ?: [];
check('flight time appended to the working card', ($lastA1['expr'] ?? '') === 't = 5.8');

// -------------------------------------------------- plot synthesis (no LLM)
$synth = new \Modules\Project\Services\MathVisualSynthesisService();
$plotScenes = [
    [
        'scene_id' => 'p1',
        'layout_template' => 'math_steps',
        'slots' => ['slot_math' => ['steps' => [
            ['expr' => 'h(t) = -4.9t^2 + 28t + 3'],
            ['expr' => 'a = -4.9, b = 28, c = 3'],
        ]]],
    ],
    [
        'scene_id' => 'p2',
        'layout_template' => 'math_steps',
        'slots' => ['slot_math' => ['steps' => [
            ['expr' => 't = -28 / -9.8'],
            ['expr' => 't = 2.86'],
        ]]],
    ],
    [
        'scene_id' => 'p3',
        'layout_template' => 'math_steps',
        'slots' => ['slot_math' => ['steps' => [
            ['expr' => 't = (-28 - 29.03) / -9.8'],
            ['expr' => 't = 5.82 s'],
        ]]],
    ],
];
$plot = $synth->plotFromScenes($plotScenes);
check('plot synthesized from the working', $plot !== null);
check('plot expression rewritten to x', ($plot['slots']['slot_plot']['expression'] ?? '') === '-4.9x^2 + 28x + 3');
$plotMarks = array_column($plot['slots']['slot_plot']['marks'] ?? [], 'x');
check('marks are the computed answers', $plotMarks === [2.86, 5.82]);
check('domain hugs the answers', ($plot['slots']['slot_plot']['x_min'] ?? null) == 0 && ($plot['slots']['slot_plot']['x_max'] ?? null) >= 7);

// Symbolic working (no numbers) must synthesize nothing.
$symbolic = [[
    'scene_id' => 's1',
    'layout_template' => 'math_steps',
    'slots' => ['slot_math' => ['steps' => [['expr' => 'f(x) = ax^2 + bx + c']]]],
]];
check('symbolic coefficients -> no plot', $synth->plotFromScenes($symbolic) === null);

// No function statement at all (pure equation solve) -> nothing.
$noFn = [[
    'scene_id' => 'n1',
    'layout_template' => 'math_steps',
    'slots' => ['slot_math' => ['steps' => [['expr' => 'x^2 + 5x - 24 = 0'], ['expr' => 'x = 3']]]],
]];
check('no f(x)= statement -> no plot', $synth->plotFromScenes($noFn) === null);

// TinyExpr: the evaluator core.
$fq = \Modules\Project\Support\TinyExpr::compile('-4.9x^2 + 28x + 3');
check('TinyExpr compiles the projectile curve', $fq !== null);
check('TinyExpr evaluates correctly', $fq !== null && abs($fq(2.0) - (-4.9 * 4 + 56 + 3)) < 1e-9);
$zq = $fq !== null ? \Modules\Project\Support\TinyExpr::zeros($fq) : [];
check('TinyExpr finds both roots', count($zq) === 2 && abs($zq[0] - -0.11) < 0.05 && abs($zq[1] - 5.82) < 0.05);
check('TinyExpr rejects symbols outside the grammar', \Modules\Project\Support\TinyExpr::compile('ax^2 + bx + c') === null);
check('TinyExpr implicit multiplication', ($g = \Modules\Project\Support\TinyExpr::compile('2(x+1)')) !== null && abs($g(2.0) - 6.0) < 1e-9);
check('TinyExpr sqrt domain -> NAN', ($h = \Modules\Project\Support\TinyExpr::compile('sqrt(x)')) !== null && is_nan($h(-4.0)));

// Title fallback: no f()= step anywhere, function only in the title (with
// the user's literal "t.t" notation) -> plot still synthesized, marks = the
// curve's own zeros, domain hugs them.
$prose = [[
    'scene_id' => 'q1',
    'layout_template' => 'math_steps',
    'slots' => ['slot_math' => ['steps' => [['expr' => 't = -28 / -9.8']]]],
]];
$plotTitle = $synth->plotFromScenes($prose, 'A projectile follows h(t)=−4.9t.t+28t+3. Find Maximum height Time of maximum height Total flight time');
check('plot synthesized from the TITLE', $plotTitle !== null);
check('title t.t normalized and rewritten to x', ($plotTitle['slots']['slot_plot']['expression'] ?? '') === '-4.9x*x+28x+3');
$tm = array_column($plotTitle['slots']['slot_plot']['marks'] ?? [], 'x');
check('zeros used as marks when steps carry none', count($tm) === 2 && abs($tm[1] - 5.82) < 0.05);
check('domain hugs the flight', ($plotTitle['slots']['slot_plot']['x_max'] ?? 0) >= 7 && ($plotTitle['slots']['slot_plot']['x_max'] ?? 99) <= 9);

// Echo with the number in a BULLET and a title heading still folds.
$inv = ['scenes' => [
    [
        'scene_id' => 'i1',
        'layout_template' => 'math_steps',
        'duration_seconds' => 12,
        'narration' => ['text' => 'We use the vertex formula.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [['expr' => 't = -28 / -9.8']]]],
    ],
    [
        'scene_id' => 'i2',
        'layout_template' => 'stat_spotlight',
        'duration_seconds' => 11,
        'narration' => ['text' => 'Calculating this gives us the time.'],
        'slots' => ['slot_stat' => ['content_type' => 'text_block', 'heading' => 'Maximum Height Time', 'bullets' => ['2.86 seconds']]],
    ],
]];
$resInv = (new ShotListValidator())->validate($inv, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$i1 = null;
foreach ($resInv['scenes'] as $s) {
    if ($s['scene_id'] === 'i1') {
        $i1 = $s;
    }
}
check('bullet-number echo folded', !in_array('i2', array_column($resInv['scenes'], 'scene_id'), true));
$lastI1 = end($i1['slots']['slot_math']['steps']) ?: [];
check('bullet value appended as answer step', ($lastI1['expr'] ?? '') === 't = 2.86');

// And the synthesized plot must survive the validator's own clamp.
$plotValidated = (new ShotListValidator())->validate(['scenes' => [$plot]], ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$pv = $plotValidated['scenes'][0] ?? [];
check('synth plot survives validation as function_plot', ($pv['layout_template'] ?? '') === 'function_plot');

// ------------------------------------------------ rule injection (library)
use Modules\Project\Support\MathRuleLibrary;

$d = fn (array $exprs) => (MathRuleLibrary::detect($exprs)['name'] ?? null);
check('quadratic formula (+- sqrt)', $d(['x = (-2 +- sqrt(48)) / 2']) === 'Quadratic Formula');
check('quadratic formula (applied discriminant)', $d(['t = (-28 - sqrt(28^2 - 4 * -4.9 * 3)) / -9.8']) === 'Quadratic Formula');
check('vertex formula (general)', $d(['t = -b / (2a)']) === 'Vertex of a Parabola');
check('vertex formula (applied)', $d(['t = -28 / (2 * -4.9)', 't = 2.86']) === 'Vertex of a Parabola');
check('zero product', $d(['(x + 8)(x - 3) = 0']) === 'Zero-Product Property');
check('log product rule', $d(['log_2(x - 1) + log_2(x + 3) = 3']) === 'Product Rule of Logarithms');
check('pythagoras', $d(['c^2 = a^2 + b^2']) === 'Pythagorean Theorem');
check('slope formula', $d(['m = (y2 - y1) / (x2 - x1)']) === 'Slope Formula');
check('d = vt', $d(['d = v * t', 'd = 20 * 20']) === 'Distance Formula');
check('plain arithmetic -> no rule', $d(['x + 5 = 12', 'x = 7']) === null);
check('plain division -> not vertex', $d(['x = 40 / 8', 'x = 5']) === null);

// The validator injects the panel when the model left it empty…
$inj = ['scenes' => [[
    'scene_id' => 'j1',
    'layout_template' => 'math_steps',
    'duration_seconds' => 12,
    'narration' => ['text' => 'We use the vertex formula to find the time.'],
    'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [
        ['expr' => 't = -b / (2a)'], ['expr' => 't = -28 / (2 * -4.9)'],
    ]]],
]]];
$resInj = (new ShotListValidator())->validate($inj, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
check('rule panel injected', ($resInj['scenes'][0]['slots']['slot_math']['rule']['name'] ?? '') === 'Vertex of a Parabola');

// …never overrides the model's own rule…
$own = $inj;
$own['scenes'][0]['slots']['slot_math']['rule'] = ['name' => 'My Own Rule', 'formula' => 'a = b'];
$resOwn = (new ShotListValidator())->validate($own, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
check('model rule kept', ($resOwn['scenes'][0]['slots']['slot_math']['rule']['name'] ?? '') === 'My Own Rule');

// …and never injects the same rule twice.
$twice = ['scenes' => [$inj['scenes'][0], array_merge($inj['scenes'][0], ['scene_id' => 'j2'])]];
$resTwice = (new ShotListValidator())->validate($twice, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$panels = 0;
foreach ($resTwice['scenes'] as $s) {
    if (($s['slots']['slot_math']['rule']['name'] ?? '') === 'Vertex of a Parabola') {
        $panels++;
    }
}
check('same rule injected once per video', $panels === 1);

// ------------------------------------------------- check-the-answer synth
$checkScenes = [
    [
        'scene_id' => 'k1',
        'layout_template' => 'math_steps',
        'slots' => ['slot_math' => ['steps' => [
            ['expr' => 'h(t) = -4.9t^2 + 28t + 3'],
        ]]],
    ],
    [
        'scene_id' => 'k2',
        'layout_template' => 'math_steps',
        'slots' => ['slot_math' => ['steps' => [
            ['expr' => 't = (-28 - 29.03) / -9.8'], ['expr' => 't = 5.82'],
        ]]],
    ],
];
$checkCard = $synth->checkFromScenes($checkScenes, '');
check('check card synthesized (root case)', $checkCard !== null);
$ck = $checkCard['slots']['slot_math']['steps'] ?? [];
check('check substitutes back', str_contains($ck[0]['expr'] ?? '', 'h(5.82) = -4.9(5.82)^2 + 28(5.82) + 3'));
check('check verifies ~zero', str_contains(($ck[1]['expr'] ?? ''), 'h(5.82) = '));
check('check verdict honest', in_array($ck[1]['note'] ?? '', ['checks out', '~= 0, rounding'], true));

// Non-root answer matching a claimed value: h(2.86) = 42.98? Working claims 42.98.
$claimScenes = [
    $checkScenes[0],
    [
        'scene_id' => 'k3',
        'layout_template' => 'math_steps',
        'slots' => ['slot_math' => ['steps' => [
            ['expr' => 'h(2.86) = 42.98'], ['expr' => 't = 2.86'],
        ]]],
    ],
];
$claimCard = $synth->checkFromScenes($claimScenes, '');
check('claimed-value check synthesized', $claimCard !== null && str_contains(($claimCard['slots']['slot_math']['steps'][1]['note'] ?? ''), 'matches'));

// A value the working never claimed -> no card (honesty guard).
$unclaimed = [
    $checkScenes[0],
    [
        'scene_id' => 'k4',
        'layout_template' => 'math_steps',
        'slots' => ['slot_math' => ['steps' => [['expr' => 't = 2.5']]]],
    ],
];
check('unverifiable check not synthesized', $synth->checkFromScenes($unclaimed, '') === null);

// Plot marks now carry meaning.
$plotLbl = $synth->plotFromScenes($checkScenes, '');
$lbls = array_column($plotLbl['slots']['slot_plot']['marks'] ?? [], 'label');
check('plot marks labelled with the variable', ($lbls[0] ?? '') === 't = 5.82');

// --------------------------------------------------- L1 skeleton (repair)
use Modules\Project\Services\ScriptSkeletonService;

$sk = new ScriptSkeletonService();
$rep = $sk->repair([
    ['intent' => 'work', 'brief' => 'vertex formula gives time of max height'],
    ['intent' => 'work', 'brief' => 'substitute back for the height'],
    ['intent' => 'work', 'brief' => 'solve h(t) = 0 for flight time'],
    ['intent' => 'hook', 'brief' => 'a rocket problem with three questions'],
], 'worked_problem', true);
$intents = array_column($rep, 'intent');
check('skeleton starts with hook', $intents[0] === 'hook');
check('scenario phase forced in', $intents[1] === 'scenario_setup');
check('given_find present', in_array('given_find', $intents, true));
check('all three work phases kept in order', array_values(array_filter($intents, fn ($i) => $i === 'work')) === ['work', 'work', 'work']
    && ($rep[array_search('work', $intents, true)]['brief'] ?? '') === 'vertex formula gives time of max height');
// The practice beat joined the canonical tail in iter 21; the composer drops
// it silently when it cannot verify an answer, so planning it is free.
check('see/check/practice/payoff appended in order', array_slice($intents, -4) === ['see_answer', 'check', 'practice', 'payoff']);

// Garbage in -> canonical skeleton out. `concept` joined the unconditional
// tail in iter 34: it used to appear only when the planner happened to return
// a concept brief, which made the formula/idea beat come and go between runs
// of the same script. Structure is law; the composer still decides the card.
$fallback = $sk->repair([['intent' => 'dance_break', 'brief' => 'nope'], 'junk'], 'worked_problem', false);
$fbIntents = array_column($fallback, 'intent');
check('garbage yields canonical shape', $fbIntents === ['hook', 'given_find', 'concept', 'work', 'see_answer', 'check', 'practice', 'payoff'], implode(' ', $fbIntents));

$proof = $sk->repair([], 'proof_concept', false);
check('proof skeleton canonical', array_column($proof, 'intent') === ['hook', 'claim', 'build_object', 'argument_step', 'argument_step', 'close', 'payoff']);

// --------------------------------------------- L2/L3 composer + L1 generic
$composer = new \Modules\Project\Services\MathStoryboardComposerService();
check('composer refuses a skeleton without work phases', $composer->compose('some script', [
    ['intent' => 'hook', 'brief' => 'x'], ['intent' => 'payoff', 'brief' => 'y'],
], ['kind' => 'worked_problem'], '') === null);
check('composer refuses an empty skeleton', $composer->compose('some script', [], ['kind' => 'worked_problem'], '') === null);

$g = $sk->repairGeneric('argument', [
    ['intent' => 'point', 'brief' => 'streaming killed ownership'],
    ['intent' => 'hook', 'brief' => 'why nobody owns music anymore'],
    ['intent' => 'point', 'brief' => 'vinyl is a counterexample'],
    ['intent' => 'counter', 'brief' => 'but convenience won'],
]);
$gi = array_column($g, 'intent');
check('argument shape canonical order', $gi === ['hook', 'context', 'point', 'point', 'counter', 'resolution', 'payoff']);
check('argument briefs preserved in order', $g[2]['brief'] === 'streaming killed ownership' && $g[3]['brief'] === 'vinyl is a counterexample');

$g2 = $sk->repairGeneric('journey', [['intent' => 'era', 'brief' => 'the 90s boom']]);
check('journey optional turning_point dropped when unused', !in_array('turning_point', array_column($g2, 'intent'), true));
check('unknown shape falls to generic', array_column($sk->repairGeneric('sonnet', [['intent' => 'aspect', 'brief' => 'a facet']]), 'intent') === ['hook', 'context', 'aspect', 'payoff']);
check('nothing usable -> empty (no directive)', $sk->repairGeneric('argument', [['intent' => 'dance', 'brief' => 'x']]) === []);

// ------------------------------------------- proof + generic composers
check('proof composer refuses no argument steps', $composer->composeProof('script', [
    ['intent' => 'hook', 'brief' => 'x'], ['intent' => 'payoff', 'brief' => 'y'],
], ['kind' => 'proof_concept'], '') === null);

$gc = new \Modules\Project\Services\GenericStoryboardComposerService();
check('generic composer refuses a tiny skeleton', $gc->compose('script', [['intent' => 'hook', 'brief' => 'x']]) === null);
check('menuFor known intent', in_array('list_ranking', \Modules\Project\Services\GenericStoryboardComposerService::menuFor('ranking_reveal'), true));
check('menuFor unknown intent degrades to text', \Modules\Project\Services\GenericStoryboardComposerService::menuFor('dance') === ['single_focus']);

// ------------------------------------------------------ retention/evidence
use Modules\Project\Support\SceneBudgetLinter;

$mkFlat = fn (string $id, float $dur) => [
    'scene_id' => $id, 'layout_template' => 'single_focus', 'duration_seconds' => $dur,
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Words about the thing', 'bullets' => ['a point', 'b point']]],
];
$flatReport = SceneBudgetLinter::lint([
    $mkFlat('f1', 11), $mkFlat('f2', 11), $mkFlat('f3', 11),
], ['hook_enabled' => false, 'outro_enabled' => false]);
$codes = array_column($flatReport['items'], 'code');
check('flat 33s span warned', in_array('flat_pacing', $codes, true));

$mixedReport = SceneBudgetLinter::lint([
    $mkFlat('m1', 11),
    ['scene_id' => 'm2', 'layout_template' => 'animated_chart', 'duration_seconds' => 11,
        'slots' => ['slot_chart' => ['content_type' => 'chart', 'chart_type' => 'bar', 'values' => [1, 2], 'labels' => ['a', 'b'], 'source' => 'IMF 2024']]],
    $mkFlat('m3', 11),
], ['hook_enabled' => false, 'outro_enabled' => false]);
check('mixed pacing not warned', !in_array('flat_pacing', array_column($mixedReport['items'], 'code'), true));
check('sourced chart not flagged', !in_array('no_source', array_column($mixedReport['items'], 'code'), true));

$noSrcReport = SceneBudgetLinter::lint([
    ['scene_id' => 'c1', 'layout_template' => 'animated_chart', 'duration_seconds' => 11,
        'slots' => ['slot_chart' => ['content_type' => 'chart', 'chart_type' => 'bar', 'values' => [1, 2], 'labels' => ['a', 'b']]]],
], ['hook_enabled' => false, 'outro_enabled' => false]);
check('unsourced chart flagged info', in_array('no_source', array_column($noSrcReport['items'], 'code'), true));

// ------------------------------------------- scenario layout (shape) rules
use Modules\Project\Support\ScenarioLayout;

check('explicit layout wins', ScenarioLayout::resolve('fall', 'a projectile thrown up') === 'fall');
check('projectile infers arc', ScenarioLayout::infer('A projectile follows h(t), find maximum height and flight time') === 'arc');
check('peak/ground labels infer arc', ScenarioLayout::infer('Launch h0 = 3 m Peak h max = ? Ground rises falls back') === 'arc');
check('dropped stone infers fall', ScenarioLayout::infer('A stone is dropped from a cliff, how long until it hits the water') === 'fall');
check('balloon rising infers climb', ScenarioLayout::infer('A balloon rises at 3 m/s, when does it reach 120 m') === 'climb');
check('car journey stays line', ScenarioLayout::infer('A car drives at 20 km/min and reaches its destination in 20 minutes') === 'line');
check('junk explicit falls back to inference', ScenarioLayout::resolve('spiral', 'thrown ball') === 'arc');

// The clamp stamps the resolved layout onto the slot.
$scn = ['scenes' => [[
    'scene_id' => 'sc1',
    'layout_template' => 'scenario_diagram',
    'duration_seconds' => 12,
    'narration' => ['text' => 'A projectile rises to its peak and falls back to the ground.'],
    'slots' => ['slot_scenario' => ['content_type' => 'scenario',
        'entities' => [
            ['label' => 'Launch', 'icon' => 'rocket', 'value' => 'h0 = 3 m'],
            ['label' => 'Peak', 'value' => 'h max = ?'],
            ['label' => 'Ground', 'value' => 'h = 0'],
        ],
        'connectors' => [['label' => 'rises'], ['label' => 'falls back']],
        'question' => 'h max = ?',
    ]],
]]];
$resScn = (new ShotListValidator())->validate($scn, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
$sc1 = $resScn['scenes'][0] ?? [];
check('clamp keeps scenario card', ($sc1['layout_template'] ?? '') === 'scenario_diagram');
check('clamp stamps inferred arc layout', ($sc1['slots']['slot_scenario']['layout'] ?? '') === 'arc');

// ------------------------------------------------------------- routing rule
// The job's $isMathBoard expression, extracted verbatim shape: classifier says
// worked_problem -> board with just 2 math scenes even under the 40% ratio.
$routing = function (array $mathTopic, int $mathScenes, int $contentScenes): bool {
    return $mathScenes >= 2 && (
        ($mathTopic['kind'] ?? null) === 'worked_problem'
        || $mathScenes >= (int) ceil($contentScenes * 0.4)
    );
};
check('proj34 shape now routes to board (4/15 worked_problem)', $routing(['kind' => 'worked_problem'], 4, 15) === true);
check('proof_concept still needs the ratio (2/12 stays off board)', $routing(['kind' => 'proof_concept'], 2, 12) === false);
check('proof_concept with ratio routes (6/10)', $routing(['kind' => 'proof_concept'], 6, 10) === true);
check('unclassified keeps old rule (5/12 -> board)', $routing([], 5, 12) === true);
check('worked_problem with only 1 math scene stays off board', $routing(['kind' => 'worked_problem'], 1, 10) === false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
