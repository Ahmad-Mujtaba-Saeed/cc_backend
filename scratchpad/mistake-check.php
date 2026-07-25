<?php

/**
 * Iter 22 — common_mistake.
 *
 * Run in-container:  docker compose exec -T app php scratchpad/mistake-check.php
 *
 * The card prints two lines and LABELS them. Most of this file is about the
 * labels being right: a true line called wrong, or a false line called
 * correct, is worse than never running the beat — and the guard must not fire
 * on an honest conditional equation.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\MathTruth;
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

/** Validate one mistake scene; returns [scene, warnings]. */
function one(array $slot): array
{
    $out = (new ShotListValidator())->validate(['scenes' => [[
        'narration' => ['text' => 'Here is the one that catches everyone. But that move is not allowed — here is what to write instead.'],
        'layout_template' => 'common_mistake',
        'slots' => ['slot_mistake' => $slot],
    ]]], ['hook_enabled' => false, 'outro_enabled' => false]);

    return [$out['scenes'][0], $out['warnings']];
}

echo "\n== registry\n";
check('version bumped to 31', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 31,
    (string) (ExplainerRegistry::all()['version'] ?? '?'));
check('common_mistake template exists', in_array('common_mistake', ExplainerRegistry::templateNames(), true));
check('caps at 2 per video', ExplainerRegistry::maxPerVideo('common_mistake') === 2);
check('mistake content type declared', isset(ExplainerRegistry::all()['content_types']['mistake']));
check('slot_mistake allows exactly the mistake type',
    (ExplainerRegistry::all()['templates']['common_mistake']['slots']['slot_mistake']['allowed'] ?? []) === ['mistake']);

echo "\n== MathTruth: arithmetic that can be settled, IS\n";

check('a false arithmetic claim is refuted', MathTruth::holds('sqrt{9+16} = 3+4') === false);
check('a true arithmetic claim holds', MathTruth::holds('sqrt{9+16} = 5') === true);
check('a false algebraic identity is refuted', MathTruth::holds('(x+3)^2 = x^2 + 9') === false);
check('a true algebraic identity holds', MathTruth::holds('(x+3)^2 = x^2 + 6*x + 9') === true);
check('a fraction sum is settled', MathTruth::holds('frac{1}{2} + frac{1}{3} = frac{2}{5}') === false);
check('...and its correction holds', MathTruth::holds('frac{1}{2} + frac{1}{3} = frac{5}{6}') === true);
check('a trig identity holds', MathTruth::holds('sin(x)^2 + cos(x)^2 = 1') === true);
check('a cancelled fraction holds', MathTruth::holds('frac{x^2-9}{x-3} = x+3') === true);
check('two unknowns is unknown', MathTruth::holds('a + b = b + a') === null);
check('an inequality is not judged', MathTruth::holds('x^2 >= 0') === null);
check('prose is not judged', MathTruth::holds('Cancel the x on the top and bottom') === null);
check('unknowns() counts none in pure arithmetic', MathTruth::unknowns('sqrt{9+16} = 5') === 0);
check('unknowns() counts the one in an identity', MathTruth::unknowns('(x+3)^2 = x^2 + 9') === 1);

echo "\n== the labels must be the right way round\n";

[$swapped, $warns] = one(['content_type' => 'mistake',
    'wrong' => 'sqrt{9+16} = 5', 'correct' => 'sqrt{9+16} = 3+4',
    'why' => 'A square root does not split over a sum.']);
check('a TRUE line labelled wrong takes the card down',
    $swapped['layout_template'] !== 'common_mistake', $swapped['layout_template']);
check('neither line is printed after a refusal',
    !str_contains(json_encode($swapped), '3+4') && !str_contains(json_encode($swapped), 'sqrt{9+16}'),
    json_encode($swapped));
check('the explanation survives', str_contains(json_encode($swapped), 'does not split'));
check('the operator is told why',
    count(array_filter($warns, fn ($w) => str_contains((string) $w, 'labels a TRUE line'))) === 1,
    implode(' | ', $warns));

[$falseFix, $fixWarns] = one(['content_type' => 'mistake',
    'wrong' => 'frac{1}{2} + frac{1}{3} = frac{2}{5}', 'correct' => 'frac{1}{2} + frac{1}{3} = frac{3}{5}']);
check('an arithmetically FALSE correction is refused',
    $falseFix['layout_template'] !== 'common_mistake', $falseFix['layout_template']);
check('and says so',
    count(array_filter($fixWarns, fn ($w) => str_contains((string) $w, 'arithmetically false'))) === 1,
    implode(' | ', $fixWarns));

echo "\n== ...but an honest card is left alone\n";

[$good] = one(['content_type' => 'mistake',
    'wrong' => 'sqrt{9+16} = 3+4', 'correct' => 'sqrt{9+16} = sqrt{25} = 5',
    'why' => 'A square root does not split over a sum.']);
check('the real card survives', $good['layout_template'] === 'common_mistake', $good['layout_template']);
check('both lines reach the renderer',
    ($good['slots']['slot_mistake']['wrong'] ?? null) === 'sqrt{9+16} = 3+4'
    && ($good['slots']['slot_mistake']['correct'] ?? null) === 'sqrt{9+16} = sqrt{25} = 5',
    json_encode($good['slots']['slot_mistake'] ?? []));

[$identity] = one(['content_type' => 'mistake',
    'wrong' => '(x+3)^2 = x^2 + 9', 'correct' => '(x+3)^2 = x^2 + 6*x + 9']);
check('an algebraic identity pair survives', $identity['layout_template'] === 'common_mistake',
    $identity['layout_template']);

// The false-positive that would matter most: a CONDITIONAL equation is a
// perfectly good line of working that is false at nearly every x. Judging it
// as an identity would throw away honest cards.
[$conditional] = one(['content_type' => 'mistake',
    'wrong' => 'x^2 = 2x, so x = 2', 'correct' => 'x^2 - 2x = 0, so x = 0 or x = 2']);
check('a conditional equation is NOT mistaken for a false identity',
    $conditional['layout_template'] === 'common_mistake', $conditional['layout_template']);

[$prose] = one(['content_type' => 'mistake',
    'wrong' => 'Cancel the x on the top and bottom', 'correct' => 'Factor first, then cancel the bracket']);
check('a pair of prose lines survives (nothing to evaluate)',
    $prose['layout_template'] === 'common_mistake', $prose['layout_template']);

echo "\n== shape\n";

[$same, $sameWarns] = one(['content_type' => 'mistake', 'wrong' => '2 + 2 = 5', 'correct' => '2+2  =  5']);
check('the same line twice is refused', $same['layout_template'] !== 'common_mistake', $same['layout_template']);
check('and says nothing is being corrected',
    count(array_filter($sameWarns, fn ($w) => str_contains((string) $w, 'same line twice'))) === 1,
    implode(' | ', $sameWarns));

[$half] = one(['content_type' => 'mistake', 'wrong' => 'sqrt{9+16} = 3+4', 'why' => 'Roots do not split.']);
check('one line alone is not a mistake card', $half['layout_template'] !== 'common_mistake', $half['layout_template']);

[$aliased] = one(['content_type' => 'mistake', 'wrong' => 'a wrong move here', 'right' => 'the better move here']);
check('`right` is accepted as an alias for correct',
    ($aliased['slots']['slot_mistake']['correct'] ?? null) === 'the better move here',
    json_encode($aliased['slots']['slot_mistake'] ?? []));

[$long] = one(['content_type' => 'mistake',
    'wrong' => str_repeat('w', 90), 'correct' => str_repeat('c', 90),
    'why' => str_repeat('y', 200), 'heading' => str_repeat('h', 200), 'caption' => str_repeat('p', 200)]);
$M = $long['slots']['slot_mistake'] ?? [];
check('wrong clamped to 60', mb_strlen($M['wrong'] ?? '') === 60, (string) mb_strlen($M['wrong'] ?? ''));
check('correct clamped to 60', mb_strlen($M['correct'] ?? '') === 60);
check('why clamped to 100', mb_strlen($M['why'] ?? '') === 100);
check('heading clamped to 60', mb_strlen($M['heading'] ?? '') === 60);
check('caption clamped to 80', mb_strlen($M['caption'] ?? '') === 80);

echo "\n== cap degrade (max 2 per video)\n";

$mk = fn (string $w, string $c) => [
    'narration' => ['text' => 'People write this line every time, but it is not allowed — here is the move that is.'],
    'layout_template' => 'common_mistake',
    'slots' => ['slot_mistake' => ['content_type' => 'mistake', 'wrong' => $w, 'correct' => $c,
        'why' => 'Named rules only apply where they hold.']],
];
$three = (new ShotListValidator())->validate(['scenes' => [
    $mk('sqrt{9+16} = 3+4', 'sqrt{9+16} = 5'),
    $mk('(x+3)^2 = x^2 + 9', '(x+3)^2 = x^2 + 6*x + 9'),
    $mk('frac{1}{2} + frac{1}{3} = frac{2}{5}', 'frac{1}{2} + frac{1}{3} = frac{5}{6}'),
]], ['hook_enabled' => false, 'outro_enabled' => false]);
$tpls = array_map(fn ($s) => $s['layout_template'], $three['scenes']);
check('two mistake cards are allowed', $tpls[0] === 'common_mistake' && $tpls[1] === 'common_mistake',
    implode(',', $tpls));
check('the third degrades', $tpls[2] !== 'common_mistake', implode(',', $tpls));
$flat = json_encode($three['scenes'][2]);
check('the degrade keeps BOTH lines, labelled',
    str_contains($flat, 'Wrong:') && str_contains($flat, 'Right:'), $flat);

echo "\n== linter\n";
$lint = SceneBudgetLinter::lint([array_merge($three['scenes'][0], ['duration_seconds' => 11.0])], []);
$noise = array_filter($lint['items'], fn ($i) => in_array($i['code'] ?? '', ['min_text', 'static_hold'], true));
check('no min_text/static_hold on a long mistake scene', count($noise) === 0, json_encode(array_values($noise)));

$m = new ReflectionMethod(SceneBudgetLinter::class, 'sceneScreenText');
$m->setAccessible(true);
$txt = $m->invoke(null, ['slots' => ['slot_mistake' => [
    'content_type' => 'mistake', 'wrong' => 'sqrt{9+16} = 3+4', 'correct' => 'sqrt{25} = 5',
    'why' => 'Roots do not split over a sum',
]]]);
check('both lines and the reason are visible to the sync audit',
    str_contains($txt, '3+4') && str_contains($txt, 'sqrt{25}') && str_contains($txt, 'do not split'), $txt);

echo "\n== casting\n";
$composer = file_get_contents(__DIR__ . '/../modules/Project/Services/GenericStoryboardComposerService.php');
check('common_mistake in a composer menu', str_contains($composer, "'common_mistake'"));
check('common_mistake has CARD_DOCS', str_contains($composer, "'common_mistake' => 'slot_mistake:"));
$analysis = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php');
check('common_mistake in the giant-call card list', str_contains($analysis, '"common_mistake"'));
$mathComposer = file_get_contents(__DIR__ . '/../modules/Project/Services/MathStoryboardComposerService.php');
check('the tree composer can cast it from the payoff phase',
    str_contains($mathComposer, "'layout_template' => 'common_mistake'"));
check('...and the prose call is told how to fill it',
    str_contains($mathComposer, '`wrong` (the line people actually write'));

echo "\n== routing is untouched\n";
check('common_mistake is NOT a board-routing math template',
    !in_array('common_mistake', ShotListValidator::MATH_TEMPLATES, true));

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
