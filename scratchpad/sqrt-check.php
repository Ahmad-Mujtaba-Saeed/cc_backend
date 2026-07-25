<?php

/**
 * sqrt-check — radicals must reach the typesetter in the ONE form it can draw.
 *
 * Project 91 ("the distance formula") rendered the literal letters
 * "d = sqrt(run^2 + rise^2)" on screen: the renderer's parser only ever
 * accepted `sqrt{...}`, while the models write `sqrt(...)` and the unicode
 * transliteration turns "√" into "sqrt " + radicand. Run in the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/sqrt-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\MathPlain;
use Modules\Project\Support\MathTruth;
use Modules\Project\Support\ShotListValidator;
use Modules\Project\Support\TinyExpr;

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
        echo "FAIL  {$name}" . ($got !== '' ? "  -> got: {$got}" : '') . "\n";
    }
}

/** linearizeMathSymbols is private — exercise it the way the validator does.
 *  $expr mirrors the caller's field: notation (a step expr) or prose (a note). */
function linearize(string $s, bool $expr = true): string
{
    $m = new ReflectionMethod(ShotListValidator::class, 'linearizeMathSymbols');
    $m->setAccessible(true);

    return $m->invoke(new ShotListValidator(), $s, $expr);
}

echo "--- validator canonicalisation ---\n";

$cases = [
    // [input, expected]
    ['d = sqrt(run^2 + rise^2)', 'd = sqrt{run^2 + rise^2}'],          // project 91, verbatim
    ['d = sqrt{ (x2-x1)^2 }', 'd = sqrt{ (x2-x1)^2 }'],                 // already canonical, untouched
    ['x = sqrt(b^2 - 4*a*c)', 'x = sqrt{b^2 - 4*a*c}'],                 // nested ops survive
    ['c = sqrt(a^2 + sqrt(b))', 'c = sqrt{a^2 + sqrt{b}}'],             // nested radical
    ['r = √(x^2 + y^2)', 'r = sqrt{x^2 + y^2}'],                        // unicode → paren → braces
    ['√2', 'sqrt{2}'],                                                  // unicode bare number
    ['sqrt 2', 'sqrt{2}'],                                              // bare number
    ['sqrt x^2', 'sqrt{x^2}'],                                          // bare variable with exponent
    ['x = (-b +- sqrt(b^2-4ac)) / (2a)', 'x = (-b +- sqrt{b^2-4ac}) / (2a)'], // quadratic formula
];
foreach ($cases as [$in, $want]) {
    $got = linearize($in);
    check("linearize: {$in}", $got === $want, $got);
}

echo "--- a named radicand only counts inside an EXPRESSION ---\n";
// "sigma = sqrt var" is a step; "take the sqrt of both sides" is a sentence.
check('expr: named radicand braced', linearize('sigma = sqrt var') === 'sigma = sqrt{var}', linearize('sigma = sqrt var'));
check('expr: sqrt20 (no space, no parens)', linearize('x = sqrt20') === 'x = sqrt{20}', linearize('x = sqrt20'));

echo "--- prose says the words instead of the source ---\n";
$prose = [
    // The word survives as ENGLISH — never as a radical over the next word,
    // and never as the bare token "sqrt" on screen.
    ['take the sqrt of both sides', 'take the square root of both sides'],
    ['sqrt simplification', 'square root simplification'],
    ['apply sqrt to each side', 'apply square root to each side'],
];
foreach ($prose as [$in, $want]) {
    $got = linearize($in, false);
    check("prose: {$in}", $got === $want, $got);
}
// Real notation inside prose still typesets.
check(
    'prose keeps a braced radical',
    linearize('the answer is sqrt(20) exactly', false) === 'the answer is sqrt{20} exactly',
    linearize('the answer is sqrt(20) exactly', false)
);

echo "--- an unbalanced radical never crashes or eats the line ---\n";
$got = linearize('d = sqrt(a + b');
check('unbalanced paren degrades legibly', str_contains($got, 'sqrt{a + b'), $got);

echo "--- MathPlain projection (degrade paths get raw slot values) ---\n";
$plain = [
    ['d = sqrt(run + rise)', 'd = √(run + rise)'],   // project 91's mistake card
    ['d = sqrt{run^2}', 'd = √(run²)'],
    ['sqrt 9', '√(9)'],
    ['take the sqrt of both sides', 'take the sqrt of both sides'],
];
foreach ($plain as [$in, $want]) {
    $got = MathPlain::toPlain($in);
    check("toPlain: {$in}", $got === $want, $got);
}

echo "--- formula_anatomy: part matches stay literal substrings ---\n";
// The clamp linearises formula and matches TOGETHER so anchors survive; a
// "sqrt" label must still be findable inside the canonicalised formula.
$formula = linearize('d = sqrt(run^2 + rise^2)');
foreach (['run^2', 'rise^2'] as $match) {
    check("anchor '{$match}' survives", str_contains($formula, linearize($match)), $formula);
}

echo "--- the evaluator reads the canonical form (braces are grouping) ---\n";
// Canonicalising radicals must NOT switch off the arithmetic guards: TinyExpr
// backs the check-the-answer synthesis, the plot's zero-finding and
// PracticeCheck, and it used to fail on every braced radical.
foreach ([['sqrt{225}', 15.0], ['sqrt(225)', 15.0], ['2*sqrt{9}', 6.0]] as [$src, $want]) {
    $f = TinyExpr::compile($src);
    check("TinyExpr compiles {$src}", $f !== null && abs($f(0.0) - $want) < 1e-9, $f ? (string) $f(0.0) : 'NULL');
}
check('MathTruth still judges a braced radical', MathTruth::holds('sqrt{9} = 4') === false);
check('MathTruth still accepts a true one', MathTruth::holds('sqrt{4} = 2') === true);

echo "--- practice / mistake cards get the notation pass ---\n";
$clamp = function (string $method, array $slot) {
    $m = new ReflectionMethod(ShotListValidator::class, $method);
    $m->setAccessible(true);
    $note = null;

    return $m->invokeArgs(new ShotListValidator(), [$slot, &$note]);
};

$mistake = $clamp('clampMistakeContent', [
    'wrong' => 'x = (6 + 20) / 2',
    'correct' => 'x = (6 +- sqrt(20)) / 2',
    'why' => 'sqrt(20) must stay under the radical.',
]);
check('mistake: correct line canonicalised', is_array($mistake) && str_contains($mistake['correct'], 'sqrt{20}'), $mistake['correct'] ?? 'null');
check('mistake: why line canonicalised', is_array($mistake) && str_contains($mistake['why'], 'sqrt{20}'), $mistake['why'] ?? 'null');

$practice = $clamp('clampPracticeContent', [
    'prompt' => 'Solve x^2 - 4x + 3 = 0',
    'answer' => 'x = 1 or x = 3',
    'hint' => 'Plug into x = (-b ± sqrt(b^2 - 4ac)) / (2a)',
]);
check('practice: hint canonicalised', is_array($practice) && str_contains($practice['hint'], 'sqrt{b^2 - 4ac}'), $practice['hint'] ?? 'null');
check('practice: unicode ± linearised in the hint', is_array($practice) && !str_contains($practice['hint'], '±'), $practice['hint'] ?? 'null');

echo "--- formula_anatomy drops a part that points at a drawn glyph ---\n";
$fa = new ReflectionMethod(ShotListValidator::class, 'clampFormulaAnatomyContent');
$fa->setAccessible(true);
$card = $fa->invoke(new ShotListValidator(), [
    'formula' => 'd = sqrt(run^2 + rise^2)',
    'parts' => [
        ['match' => 'sqrt', 'label' => 'square root'],   // project 91: unanchorable
        ['match' => 'run^2', 'label' => 'horizontal leg squared'],
        ['match' => 'rise^2', 'label' => 'vertical leg squared'],
    ],
]);
$matches = array_column($card['parts'] ?? [], 'match');
check('bare "sqrt" part dropped', !in_array('sqrt', $matches, true), implode(',', $matches));
check('the two real parts survive', count($matches) === 2, implode(',', $matches));
check('formula itself canonicalised', ($card['formula'] ?? '') === 'd = sqrt{run^2 + rise^2}', $card['formula'] ?? 'null');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
