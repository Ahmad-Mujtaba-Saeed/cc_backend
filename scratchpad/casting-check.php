<?php

/**
 * casting-check — the two deterministic guarantees that stop the SAME script
 * casting different cards on different runs (loop iter 34).
 *
 *   (1) whether a video opens on a drawn situation must not rest on one
 *       classifier call, and
 *   (2) the see-the-answer beat must be a ladder, so a solve with no curve
 *       still shows its answer instead of showing nothing.
 *
 *   docker exec viralforgebackend-app-1 php scratchpad/casting-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\MathTopicService;
use Modules\Project\Services\MathVisualSynthesisService;

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

echo "--- a physical situation is recognised without asking the model ---\n";
$physical = [
    'A ball is thrown at 20 m/s from a 25 m roof - when does it land?',
    'A car travels 150 km in 2 hours. Find its average speed.',
    'A tank fills at 4 litres per minute. How long to fill 200 litres?',
    'A ladder leans against a wall, its foot 3 metres out.',
    'A jacket costs $80 after a 20% discount - what was the original price?',
    'You invest 5000 dollars at 6% interest compounded annually.',
    'Two cyclists leave town and meet 40 minutes later.',
];
foreach ($physical as $t) {
    check('physical: ' . mb_substr($t, 0, 46), MathTopicService::looksPhysical($t));
}

echo "--- pure symbol manipulation is NOT a scenario ---\n";
$symbolic = [
    'Solve x^2 - 6x + 4 = 0 with the quadratic formula',
    'Differentiate ln(x) with respect to x',
    'Solve log(x) + log(x - 3) = 1',
    'Why does a^2 + b^2 = c^2 work for right triangles?',
    'Simplify the expression 5s + 3s - 2s',        // "5s" is algebra, not seconds
    'Factor the polynomial x^3 - 8',
];
foreach ($symbolic as $t) {
    check('symbolic: ' . mb_substr($t, 0, 46), !MathTopicService::looksPhysical($t), 'flagged physical');
}

echo "--- the worked-problem skeleton has the SAME shape every run ---\n";
// The planner contributes briefs and work segmentation; the phase list is law.
// Anything conditional on what the planner happened to return is a card that
// appears on one run and not the next.
$skel = new Modules\Project\Services\ScriptSkeletonService();
$intents = fn (array $raw, bool $scenario): array => array_column(
    $skel->repair($raw, 'worked_problem', $scenario), 'intent'
);

// The planner returned almost nothing usable.
$bare = $intents([['intent' => 'work', 'brief' => 'solve it']], false);
check('concept beat present even when unplanned', in_array('concept', $bare, true), implode(' ', $bare));
foreach (['hook', 'given_find', 'concept', 'work', 'see_answer', 'check', 'practice', 'payoff'] as $must) {
    check("canonical phase: {$must}", in_array($must, $bare, true), implode(' ', $bare));
}
check('no scenario phase without a scenario', !in_array('scenario_setup', $bare, true), implode(' ', $bare));

// A rich planner response must produce the SAME phase list as the bare one,
// plus the scenario beat — that is what "no variance" means here.
$rich = $intents([
    ['intent' => 'payoff', 'brief' => 'the trick'],
    ['intent' => 'work', 'brief' => 'substitute'],
    ['intent' => 'concept', 'brief' => 'the formula'],
    ['intent' => 'hook', 'brief' => 'the problem'],
    ['intent' => 'given_find', 'brief' => 'knowns'],
], true);
check('scenario beat added when physical', in_array('scenario_setup', $rich, true), implode(' ', $rich));
check(
    'rich and bare plans differ ONLY by the scenario beat',
    array_values(array_diff($rich, ['scenario_setup'])) === array_values($bare),
    implode(' ', $rich) . '  vs  ' . implode(' ', $bare)
);

echo "--- the answer beat: a solve with no curve still gets a picture ---\n";
$synth = new MathVisualSynthesisService();

/** One math_steps scene built from a list of step expressions. */
function chain(array $exprs): array
{
    return [
        'layout_template' => 'math_steps',
        'slots' => ['slot_math' => ['content_type' => 'math_steps',
            'steps' => array_map(fn ($e) => ['expr' => $e], $exprs)]],
    ];
}

// Project 98's working: no function anywhere, answer written as a surd pair.
$quadratic = [chain([
    'x^2 - 6x + 4 = 0',
    'x = (6 +- sqrt{20}) / 2',
    'x = 3 +- sqrt{5}',
])];
check('plot refuses (no function stated)', $synth->plotFromScenes($quadratic, '') === null);

$line = $synth->answerLineFromScenes($quadratic, '');
check('number line is synthesized instead', is_array($line));
$marks = $line['slots']['slot_geometry']['marks'] ?? [];
$xs = array_map(fn ($m) => $m['x'], $marks);
check('both roots of the +- pair are marked', count($xs) === 2, json_encode($xs));
check('roots are 0.76 and 5.24', abs($xs[0] - 0.7639) < 0.01 && abs($xs[1] - 5.2361) < 0.01, json_encode($xs));
check('marks are labelled with the variable', ($marks[0]['label'] ?? '') === 'x = 0.76', $marks[0]['label'] ?? '');
check('drawn as a number line', ($line['slots']['slot_geometry']['shape'] ?? '') === 'number_line');
check('the line frames its marks', ($line['slots']['slot_geometry']['x_min'] ?? 0) <= 0
    && ($line['slots']['slot_geometry']['x_max'] ?? 0) >= 6);

// Real model output labels its steps in prose (project 98, run B).
$labelled = [chain([
    'x^2 - 6x + 4 = 0',
    'Plug in a = 1, b = -6, c = 4: x = (6 +- sqrt{36 - 16}) / 2',
    'Divide by 2: x = 3 +- sqrt{5}',
])];
$lineLabelled = $labelled ? $synth->answerLineFromScenes($labelled, '') : null;
$xs2 = array_map(fn ($m) => $m['x'], $lineLabelled['slots']['slot_geometry']['marks'] ?? []);
check('a prose-labelled step still yields the answer', count($xs2) === 2, json_encode($xs2));
check('and the values are right', $xs2 && abs($xs2[0] - 0.7639) < 0.01 && abs($xs2[1] - 5.2361) < 0.01, json_encode($xs2));

// An alternation states both answers on one line.
$alt = [chain(['x^2 - 4x + 3 = 0', 'x = 1 or x = 3'])];
$xs3 = array_map(fn ($m) => $m['x'], $synth->answerLineFromScenes($alt, '')['slots']['slot_geometry']['marks'] ?? []);
check('an "or" alternation marks both roots', $xs3 === [1.0, 3.0], json_encode($xs3));

// THE RAW FORM. The synth runs mid-compose, before the validator linearises,
// so a step arrives as the model typed it (project 98's answer beat went
// missing for a whole round because of exactly this).
$raw = [chain([
    'x^2 - 6x + 4 = 0',
    'Divide by 2 to obtain x = 3 ± √5',
])];
$xsRaw = array_map(fn ($m) => $m['x'], $synth->answerLineFromScenes($raw, '')['slots']['slot_geometry']['marks'] ?? []);
check('unicode ± and √ still yield the answers', count($xsRaw) === 2
    && abs($xsRaw[0] - 0.7639) < 0.01 && abs($xsRaw[1] - 5.2361) < 0.01, json_encode($xsRaw));
$rawParen = [chain(['c^2 = 225', 'c = √(225)'])];
$xsParen = array_map(fn ($m) => $m['x'], $synth->answerLineFromScenes($rawParen, '')['slots']['slot_geometry']['marks'] ?? []);
check('unicode √(...) too', $xsParen === [15.0], json_encode($xsParen));

// The relation spelled as a WORD, with a unit and a full stop (project 100).
$approx = [chain([
    'Set -4.9t^2 + 20t + 25 = 0.',
    't = (-20 + 29.83) / -9.8.',
    't approx 5.08 seconds.',
])];
$xsApprox = array_map(fn ($m) => $m['x'], $synth->answerLineFromScenes($approx, '')['slots']['slot_geometry']['marks'] ?? []);
check('"t approx 5.08 seconds." yields the answer', $xsApprox === [5.08], json_encode($xsApprox));

// A chained equality states the answer in one line (project 101, verbatim).
$chained = [chain(['9^2 + 12^2 = c^2', 'c^2 = 225', 'c = sqrt{225} = 15'])];
$xsChain = array_map(fn ($m) => $m['x'], $synth->answerLineFromScenes($chained, '')['slots']['slot_geometry']['marks'] ?? []);
check('a chained equality yields the answer', $xsChain === [15.0], json_encode($xsChain));

// An answer wearing its unit still lands on the line.
$united = [chain(['a^2 + b^2 = c^2', 'c^2 = 225', 'c = 15 cm'])];
$xsUnit = array_map(fn ($m) => $m['x'], $synth->answerLineFromScenes($united, '')['slots']['slot_geometry']['marks'] ?? []);
check('a unit does not disqualify the answer', $xsUnit === [15.0], json_encode($xsUnit));
$secs = [chain(['h(t) = 0', 't = 5.08 seconds'])];
$xsSecs = array_map(fn ($m) => $m['x'], $synth->answerLineFromScenes($secs, '')['slots']['slot_geometry']['marks'] ?? []);
check('"5.08 seconds" too', $xsSecs === [5.08], json_encode($xsSecs));

// A rejected root must NOT reach the answer line (project 102, verbatim).
$extraneous = [chain([
    'log x + log (x-3) = 1',
    'x^2 - 3x - 10 = 0',
    'x = 5 or x = -2',
    'x = -2 is not allowed because it is not > 3',
    'Only solution is x = 5',
])];
$xs4 = array_map(fn ($m) => $m['x'], $synth->answerLineFromScenes($extraneous, '')['slots']['slot_geometry']['marks'] ?? []);
check('the extraneous root is not marked', $xs4 === [5.0], json_encode($xs4));

// A radical that lands on a whole number (project 101).
$geom = [chain(['9^2 + 12^2 = c^2', 'c^2 = 225', 'c = sqrt{225}'])];
$line2 = $synth->answerLineFromScenes($geom, '');
check('surd answer evaluated to 15', ($line2['slots']['slot_geometry']['marks'][0]['x'] ?? null) === 15.0,
    json_encode($line2['slots']['slot_geometry']['marks'] ?? []));

echo "--- nothing is invented ---\n";
// A rearrangement is not an answer: the rhs still mentions the unknown.
check('rearrangement is not an answer', $synth->answerLineFromScenes(
    [chain(['x = x + 1', 'x = 2x - 3'])], ''
) === null);
// Working that never lands on a number gets no beat at all.
check('symbolic-only working gets no beat', $synth->answerLineFromScenes(
    [chain(['d = sqrt{run^2 + rise^2}', 'run = x2 - x1'])], ''
) === null);
check('no math_steps at all gets no beat', $synth->answerLineFromScenes(
    [['layout_template' => 'single_focus', 'slots' => []]], ''
) === null);

echo "--- a merged working keeps the tree instead of changing the whole shape ---\n";
// The single largest shape-changer: the working call answering 3 briefs with
// 2 fuller chains used to hand the entire video to the giant call, so the same
// script came back as a completely different deck.
$composer = new Modules\Project\Services\MathStoryboardComposerService();
$trim = new ReflectionMethod($composer, 'trimWorkPhases');
$trim->setAccessible(true);

$planned = [
    ['intent' => 'hook', 'brief' => 'a'],
    ['intent' => 'work', 'brief' => 'w1'],
    ['intent' => 'work', 'brief' => 'w2'],
    ['intent' => 'work', 'brief' => 'w3'],
    ['intent' => 'see_answer', 'brief' => 's'],
    ['intent' => 'payoff', 'brief' => 'p'],
];
$trimmed = $trim->invoke($composer, $planned, 2);
$got = array_column($trimmed, 'intent');
check('work phases trimmed to what arrived', $got === ['hook', 'work', 'work', 'see_answer', 'payoff'], implode(' ', $got));
check('trimming keeps the surrounding beats', in_array('see_answer', $got, true) && in_array('payoff', $got, true));
$same = $trim->invoke($composer, $planned, 3);
check('no trimming when the count matches', array_column($same, 'intent') === array_column($planned, 'intent'));

// The other half of "unfinished": the right NUMBER of phases carrying almost
// no working. Project 98 shipped a quadratic whose whole solve was the
// equation plus the formula — it never substituted, so there was no answer for
// the see-the-answer beat to draw either.
$total = new ReflectionMethod($composer, 'totalSteps');
$total->setAccessible(true);
$stub = [['steps' => [['expr' => 'x^2 - 6x + 4 = 0'], ['expr' => 'x = (-b +- sqrt{b^2-4ac})/(2a)']]]];
$real = [['steps' => [['expr' => 'a'], ['expr' => 'b']]], ['steps' => [['expr' => 'c'], ['expr' => 'd']]]];
check('a stub is counted in LINES, not phases', $total->invoke($composer, $stub) === 2);
check('a real solve clears the floor', $total->invoke($composer, $real) === 4);
$floor = new ReflectionClassConstant(get_class($composer), 'MIN_WORKING_STEPS');
check('the stub floor sits above project 98\'s working', $floor->getValue() > 2, (string) $floor->getValue());

echo "--- the synthesized beat survives the validator ---\n";
// A synth that produces a scene the validator then degrades to text would be
// worse than useless: the phase would look delivered while the picture is gone.
$scene = $synth->answerLineFromScenes($quadratic, '');
$scene['scene_id'] = 'scene_2';
$scene['order'] = 2;
$storyboard = ['scenes' => [
    [
        'scene_id' => 'scene_1',
        'order' => 1,
        'layout_template' => 'math_steps',
        'duration_seconds' => 14,
        'narration' => ['text' => 'We solve the quadratic by formula, and the two roots come out as three plus or minus root five.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [
            ['expr' => 'x^2 - 6x + 4 = 0'],
            ['expr' => 'x = 3 +- sqrt{5}'],
        ]]],
    ],
    $scene,
]];
$res = (new Modules\Project\Support\ShotListValidator())->validate(
    $storyboard,
    ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]
);
$out = null;
foreach ($res['scenes'] ?? [] as $s) {
    if (($s['layout_template'] ?? '') === 'geometry_diagram') {
        $out = $s;
    }
}
check('geometry_diagram survives validation', $out !== null,
    json_encode(array_column($res['scenes'] ?? [], 'layout_template')));
$fig = $out['slots']['slot_geometry'] ?? ($out['slots'] ? reset($out['slots']) : []);
check('still a number line after clamping', ($fig['shape'] ?? '') === 'number_line', json_encode($fig));
check('its marks survive clamping', count((array) ($fig['marks'] ?? [])) === 2, json_encode($fig['marks'] ?? []));

echo "--- a proof survives a half-formed response ---\n";
// Demanding BOTH the opening figure and the steps threw away answers that had
// one of them, and the giant call then cast a photo panel into a maths video.
$usable = new ReflectionMethod($composer, 'proofPartsUsable');
$usable->setAccessible(true);
$u = fn ($p) => $usable->invoke($composer, $p);

check('object alone is usable', $u(['object' => ['slot' => ['shape' => 'right_triangle']]]));
check('steps alone are usable', $u(['steps' => [['slot' => ['shape' => 'square']]]]));
check('both is obviously usable', $u([
    'object' => ['slot' => ['shape' => 'right_triangle']],
    'steps' => [['slot' => ['shape' => 'square']]],
]));
check('neither is not', !$u(['claim' => ['heading' => 'x'], 'steps' => ['junk']]));
check('a non-array is not', !$u(null) && !$u('nope'));

echo "--- the maths board never carries a slot nothing can fill ---\n";
// generateSceneVisuals early-returns for math_board, so an image slot there is
// never filled by AI or stock: it renders as an empty panel, and it is why a
// maths video showed upload boxes at all.
$boardish = ['scenes' => [
    ['scene_id' => 'scene_1', 'order' => 1, 'layout_template' => 'math_steps', 'duration_seconds' => 12,
        'narration' => ['text' => 'We square both legs and add them together to reach the hypotenuse.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps',
            'steps' => [['expr' => '9^2 + 12^2 = c^2'], ['expr' => 'c = 15']]]]],
    ['scene_id' => 'scene_2', 'order' => 2, 'layout_template' => 'math_steps', 'duration_seconds' => 12,
        'narration' => ['text' => 'Then we check the result by squaring fifteen and comparing the two sides.'],
        'slots' => ['slot_math' => ['content_type' => 'math_steps',
            'steps' => [['expr' => '15^2 = 225'], ['expr' => '81 + 144 = 225']]]]],
    ['scene_id' => 'scene_3', 'order' => 3, 'layout_template' => 'split_side_by_side', 'duration_seconds' => 10,
        'narration' => ['text' => 'The same relationship shows up everywhere you find a right angle in the world.'],
        'slots' => [
            'slot_left' => ['content_type' => 'image', 'asset_request' => ['description' => 'photo of a ladder']],
            'slot_right' => ['content_type' => 'text_block', 'heading' => 'Everywhere', 'bullets' => ['Ladders', 'Screens']],
        ]],
]];
// Called the way the JOB calls it: after validation, once the mode is known.
$v = new Modules\Project\Support\ShotListValidator();
$boardRes = $v->validate(
    $boardish,
    ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]
);
$boardRes['scenes'] = $v->stripBoardMediaSlots($boardRes['scenes']);
$wref = new ReflectionProperty($v, 'warnings');
$wref->setAccessible(true);
$boardRes['warnings'] = $wref->getValue($v);
$mediaLeft = 0;
foreach ($boardRes['scenes'] as $s) {
    foreach ((array) ($s['slots'] ?? []) as $sl) {
        if (in_array($sl['content_type'] ?? '', ['image', 'video'], true)) {
            $mediaLeft++;
        }
    }
}
check('no media slot survives on the board', $mediaLeft === 0, "{$mediaLeft} left");
check('the scene is kept, not deleted', count($boardRes['scenes']) === 3, (string) count($boardRes['scenes']));
check('and it says why', (bool) array_filter($boardRes['warnings'], fn ($w) => str_contains($w, 'maths board')),
    implode(' | ', $boardRes['warnings']));

// A maths video that does NOT board (slides/hybrid) still gets AI-filled
// images, so it never reaches the strip at all — validation alone must leave
// its media slots completely untouched.
$slidesRes = (new Modules\Project\Support\ShotListValidator())->validate(
    ['scenes' => [
        ['scene_id' => 'scene_1', 'order' => 1, 'layout_template' => 'math_steps', 'duration_seconds' => 12,
            'narration' => ['text' => 'One chain of working, which is not enough of a spine to reach the board.'],
            'slots' => ['slot_math' => ['content_type' => 'math_steps', 'steps' => [['expr' => 'x = 2 + 2'], ['expr' => 'x = 4']]]]],
        ['scene_id' => 'scene_2', 'order' => 2, 'layout_template' => 'single_focus', 'duration_seconds' => 10,
            'narration' => ['text' => 'A picture belongs here because this video renders as slides, not as a board.'],
            'slots' => ['slot_main' => ['content_type' => 'image', 'asset_request' => ['description' => 'photo of a classroom']]]],
    ]],
    ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]
);
$keptImage = false;
foreach ($slidesRes['scenes'] as $s) {
    foreach ((array) ($s['slots'] ?? []) as $sl) {
        if (($sl['content_type'] ?? '') === 'image') {
            $keptImage = true;
        }
    }
}
check('a non-board maths video keeps its images', $keptImage);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
