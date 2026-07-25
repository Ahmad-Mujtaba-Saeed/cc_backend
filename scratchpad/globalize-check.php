<?php

/**
 * globalize-check — the technical cards go global (loop iter 9): a science
 * video may carry plots/equations without being dragged onto the math board,
 * and the validator carries those cards in NON-math videos too.
 *   docker exec viralforgebackend-app-1 php scratchpad/globalize-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
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

$tpl = fn (string $t) => ['layout_template' => $t];

// ---------------------------------------------------------- board routing
// A science explainer: two plots + a formula anatomy, no worked chains.
$science = [$tpl('single_focus'), $tpl('function_plot'), $tpl('formula_anatomy'), $tpl('function_plot'), $tpl('single_focus')];
check('science video (figures, no working) stays OFF the board', !ShotListValidator::routesToMathBoard($science, null));
check('… even as a proof_concept topic', !ShotListValidator::routesToMathBoard($science, 'proof_concept'));
check('a confirmed worked_problem still boards on any math spine', ShotListValidator::routesToMathBoard($science, 'worked_problem'));

// A classifier-missed solve: worked chains dominate.
$missed = [$tpl('single_focus'), $tpl('math_steps'), $tpl('math_steps'), $tpl('function_plot'), $tpl('single_focus')];
check('classifier-missed solve (2 chains, 60% math) still boards', ShotListValidator::routesToMathBoard($missed, null));

// Chains present but the video is mostly prose — ratio gate holds.
$sparse = [$tpl('single_focus'), $tpl('math_steps'), $tpl('math_steps'), $tpl('single_focus'), $tpl('single_focus'), $tpl('single_focus')];
check('two chains in a prose video (33%) do not board', !ShotListValidator::routesToMathBoard($sparse, null));

// One lone math card never boards, even confirmed.
check('a single math card never boards', !ShotListValidator::routesToMathBoard([$tpl('math_steps'), $tpl('single_focus')], 'worked_problem'));

// Covers/outros stay out of the ratio.
$withChrome = [$tpl('chapter_cover'), $tpl('math_steps'), $tpl('math_steps'), $tpl('function_plot'), $tpl('outro_card')];
check('chrome scenes excluded from the ratio', ShotListValidator::routesToMathBoard($withChrome, null));

// ------------------------------------------------------------- tree menus
check('aspect menu offers plot/formula/steps', count(array_intersect(
    ['function_plot', 'formula_anatomy', 'math_steps'],
    GenericStoryboardComposerService::menuFor('aspect')
)) === 3);
check('point menu offers plot + formula', count(array_intersect(
    ['function_plot', 'formula_anatomy'],
    GenericStoryboardComposerService::menuFor('point')
)) === 2);
check('context menu offers formula anatomy', in_array('formula_anatomy', GenericStoryboardComposerService::menuFor('context'), true));

// ------------------------------------------- validator outside math mode
$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false, 'math_mode' => false]
)['scenes'];
$byTpl = fn (array $scenes, string $t) => array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === $t));

$out = $run([[
    'scene_id' => 'scene_1',
    'layout_template' => 'formula_anatomy',
    'duration_seconds' => 12,
    'narration' => ['text' => 'Einstein tied energy to mass with one short equation about light.'],
    'slots' => ['slot_formula' => [
        'content_type' => 'formula',
        'formula' => 'E = m*c^2',
        'parts' => [
            ['match' => 'm', 'label' => 'the mass'],
            ['match' => 'c^2', 'label' => 'light speed, squared'],
        ],
    ]],
]]);
check('formula_anatomy survives in a NON-math video', count($byTpl($out, 'formula_anatomy')) === 1);

$out = $run([[
    'scene_id' => 'scene_1',
    'layout_template' => 'function_plot',
    'duration_seconds' => 10,
    'narration' => ['text' => 'Half of the sample decays in every fixed window of time, forever.'],
    'slots' => ['slot_plot' => [
        'content_type' => 'function_plot',
        'expression' => '2^(-x)',
        'x_min' => 0,
        'x_max' => 6,
        'heading' => 'Radioactive decay',
    ]],
]]);
check('function_plot survives in a NON-math video', count($byTpl($out, 'function_plot')) === 1);

$out = $run([[
    'scene_id' => 'scene_1',
    'layout_template' => 'math_steps',
    'duration_seconds' => 12,
    'narration' => ['text' => 'Multiply the mass by the speed of light squared and the joules explode.'],
    'slots' => ['slot_math' => [
        'content_type' => 'math_steps',
        'steps' => [
            ['expr' => 'E = m*c^2'],
            ['expr' => 'E = 1 * (3*10^8)^2', 'note' => 'plug in one kilogram'],
            ['expr' => 'E = 9*10^16 J'],
        ],
    ]],
]]);
check('a worked chain survives in a NON-math video', count($byTpl($out, 'math_steps')) === 1);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
