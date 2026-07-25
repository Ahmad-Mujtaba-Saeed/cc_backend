<?php

/**
 * Iter 21 — practice_card.
 *
 * Run in-container:  docker compose exec -T app php scratchpad/practice-check.php
 *
 * The card hands the viewer a problem and then answers it, so most of this
 * file is about the one thing that must never happen: a WRONG answer on
 * screen. PracticeCheck substitutes the answer back where it can, and a
 * disproved answer takes the whole card down rather than being printed.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\PracticeCheck;
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

/** Validate one practice scene; returns [scene, warnings]. */
function one(array $slot): array
{
    $out = (new ShotListValidator())->validate(['scenes' => [[
        'narration' => ['text' => 'Now one for you. Pause the video and work it through before I give you the answer.'],
        'layout_template' => 'practice_card',
        'slots' => ['slot_practice' => $slot],
    ]]], ['hook_enabled' => false, 'outro_enabled' => false]);

    return [$out['scenes'][0], $out['warnings']];
}

echo "\n== registry\n";
check('version bumped to 30', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 30,
    (string) (ExplainerRegistry::all()['version'] ?? '?'));
check('practice_card template exists', in_array('practice_card', ExplainerRegistry::templateNames(), true));
check('practice_card caps at 1 per video', ExplainerRegistry::maxPerVideo('practice_card') === 1);
check('practice content type declared',
    isset(ExplainerRegistry::all()['content_types']['practice']));
check('slot_practice allows exactly the practice type',
    (ExplainerRegistry::all()['templates']['practice_card']['slots']['slot_practice']['allowed'] ?? []) === ['practice']);

echo "\n== THE GUARANTEE: an answer that can be disproved is never printed\n";

check('a correct linear answer verifies', !PracticeCheck::disproves('3x + 5 = 26', 'x = 7'));
check('a wrong linear answer is caught', PracticeCheck::disproves('3x + 5 = 26', 'x = 8'));
check('a correct quadratic root verifies', !PracticeCheck::disproves('x^2 - 9 = 0', 'x = 3'));
check('a wrong quadratic root is caught', PracticeCheck::disproves('x^2 - 9 = 0', 'x = 4'));
check('a stacked fraction is evaluated', !PracticeCheck::disproves('frac{x^2 - 9}{x - 3} = 8', 'x = 5'));
check('a wrong fraction answer is caught', PracticeCheck::disproves('frac{x^2 - 9}{x - 3} = 8', 'x = 6'));
check('a radical is evaluated', !PracticeCheck::disproves('sqrt{x} = 4', 'x = 16'));
check('a prose lead-in is stripped before the equation', !PracticeCheck::disproves('Solve for t: 4t - 6 = 10', 't = 4'));
check('a wrong answer behind a prose lead-in is still caught', PracticeCheck::disproves('Solve for t: 4t - 6 = 10', 't = 5'));
check('a bare numeric answer picks up the single unknown', PracticeCheck::disproves('2y = 10', '4'));
check('a bare numeric answer that is right passes', !PracticeCheck::disproves('2y = 10', '5'));

echo "\n== ...and it NEVER guesses outside what it can settle\n";

check('a word problem is not judged', PracticeCheck::residual('A train runs 90 km/h for 40 min. How far?', '60 km') === null);
check('a symbolic answer is not judged', PracticeCheck::residual('a*x + b = c', 'x = (c-b)/a') === null);
check('free coefficients are not judged', PracticeCheck::residual('a*x = 10', 'x = 2') === null);
check('an answer naming a different unknown is not judged',
    PracticeCheck::residual('3x + 5 = 26', 'y = 7') === null);
check('a non-equation prompt is not judged', PracticeCheck::residual('What is 12 times 5?', '60') === null);
check('a rounded answer is tolerated (slope-scaled)', !PracticeCheck::disproves('x^2 = 10', 'x = 3.16'));
check('but a genuinely wrong nearby value is not', PracticeCheck::disproves('x^2 = 10', 'x = 3.6'));

echo "\n== the validator refuses, and prints nothing\n";

[$wrong, $warns] = one(['content_type' => 'practice', 'prompt' => '3x + 5 = 26', 'answer' => 'x = 9',
    'hint' => 'Take the 5 off both sides first']);
check('a disproved answer degrades the card', $wrong['layout_template'] !== 'practice_card', $wrong['layout_template']);
check('the wrong answer is printed NOWHERE', !str_contains(json_encode($wrong), 'x = 9'), json_encode($wrong));
check('the problem survives the degrade', str_contains(json_encode($wrong), '3x + 5 = 26'));
check('the hint survives the degrade', str_contains(json_encode($wrong), 'Take the 5 off'));
check('the operator is told why',
    count(array_filter($warns, fn ($w) => str_contains((string) $w, 'does not satisfy'))) === 1,
    implode(' | ', $warns));

[$ok] = one(['content_type' => 'practice', 'prompt' => '3x + 5 = 26', 'answer' => 'x = 7']);
check('a correct answer keeps the card', $ok['layout_template'] === 'practice_card', $ok['layout_template']);
check('the answer reaches the renderer', ($ok['slots']['slot_practice']['answer'] ?? null) === 'x = 7');

[$unverifiable] = one(['content_type' => 'practice',
    'prompt' => 'A train runs at 90 km/h for 40 minutes. How far does it go?', 'answer' => '60 km']);
check('an unverifiable answer is still allowed through',
    $unverifiable['layout_template'] === 'practice_card', $unverifiable['layout_template']);

echo "\n== the other invariants\n";

[$noAnswer] = one(['content_type' => 'practice', 'prompt' => '3x + 5 = 26']);
check('a problem with no answer is not a practice card', $noAnswer['layout_template'] !== 'practice_card',
    $noAnswer['layout_template']);
check('the problem still teaches as text', str_contains(json_encode($noAnswer), '3x + 5 = 26'));

[$noPrompt] = one(['content_type' => 'practice', 'answer' => 'x = 7']);
check('an answer with no problem is refused', $noPrompt['layout_template'] !== 'practice_card',
    $noPrompt['layout_template']);

[$spoiled, $spoilWarns] = one(['content_type' => 'practice', 'prompt' => 'Solve  X = 7', 'answer' => 'x = 7']);
check('a prompt that shows its own answer is refused', $spoiled['layout_template'] !== 'practice_card',
    $spoiled['layout_template']);
check('and says so', count(array_filter($spoilWarns, fn ($w) => str_contains((string) $w, 'already showed'))) === 1,
    implode(' | ', $spoilWarns));

echo "\n== field handling\n";

[$aliased] = one(['content_type' => 'practice', 'question' => '2x = 8', 'answer' => 'x = 4']);
check('`question` is accepted as an alias for prompt',
    ($aliased['slots']['slot_practice']['prompt'] ?? null) === '2x = 8',
    json_encode($aliased['slots']['slot_practice'] ?? []));

[$long] = one(['content_type' => 'practice',
    'prompt' => str_repeat('p', 200), 'answer' => str_repeat('a', 90),
    'hint' => str_repeat('h', 200), 'heading' => str_repeat('e', 200), 'caption' => str_repeat('c', 200)]);
$P = $long['slots']['slot_practice'] ?? [];
check('prompt clamped to 90', mb_strlen($P['prompt'] ?? '') === 90, (string) mb_strlen($P['prompt'] ?? ''));
check('answer clamped to 40', mb_strlen($P['answer'] ?? '') === 40);
check('hint clamped to 70', mb_strlen($P['hint'] ?? '') === 70);
check('heading clamped to 60', mb_strlen($P['heading'] ?? '') === 60);
check('caption clamped to 80', mb_strlen($P['caption'] ?? '') === 80);

[$echoed] = one(['content_type' => 'practice', 'prompt' => '2x = 8', 'answer' => 'x = 4', 'heading' => '2x = 8']);
check('a heading identical to the prompt is dropped, not printed twice',
    !isset($echoed['slots']['slot_practice']['heading']),
    json_encode($echoed['slots']['slot_practice'] ?? []));

echo "\n== cap degrade (max 1 per video)\n";

$mk = fn (string $p, string $a) => [
    'narration' => ['text' => "Try this one yourself: pause here, work it through, and then we will compare answers."],
    'layout_template' => 'practice_card',
    'slots' => ['slot_practice' => ['content_type' => 'practice', 'prompt' => $p, 'answer' => $a,
        'hint' => 'Isolate the unknown first']],
];
$two = (new ShotListValidator())->validate(
    ['scenes' => [$mk('3x + 5 = 26', 'x = 7'), $mk('2x = 8', 'x = 4')]],
    ['hook_enabled' => false, 'outro_enabled' => false]
);
$tpls = array_map(fn ($s) => $s['layout_template'], $two['scenes']);
check('first practice kept', $tpls[0] === 'practice_card', implode(',', $tpls));
check('second degraded', $tpls[1] !== 'practice_card', implode(',', $tpls));
$flat = json_encode($two['scenes'][1]);
check('the over-cap degrade keeps the problem', str_contains($flat, '2x = 8'), $flat);
check('...and still gives the answer (it was verified)', str_contains($flat, 'x = 4'), $flat);

echo "\n== linter\n";
$lint = SceneBudgetLinter::lint([array_merge($two['scenes'][0], ['duration_seconds' => 12.0])], []);
$noise = array_filter($lint['items'], fn ($i) => in_array($i['code'] ?? '', ['min_text', 'static_hold'], true));
check('no min_text/static_hold on a long practice scene', count($noise) === 0, json_encode(array_values($noise)));

$m = new ReflectionMethod(SceneBudgetLinter::class, 'sceneScreenText');
$m->setAccessible(true);
$txt = $m->invoke(null, ['slots' => ['slot_practice' => [
    'content_type' => 'practice', 'prompt' => '3x + 5 = 26', 'answer' => 'x = 7', 'hint' => 'Subtract five',
]]]);
check('prompt, answer and hint are visible to the sync audit',
    str_contains($txt, '3x + 5 = 26') && str_contains($txt, 'x = 7') && str_contains($txt, 'Subtract five'), $txt);

echo "\n== casting\n";
$composer = file_get_contents(__DIR__ . '/../modules/Project/Services/GenericStoryboardComposerService.php');
check('practice_card in a composer menu', str_contains($composer, "'practice_card'"));
check('practice_card has CARD_DOCS', str_contains($composer, "'practice_card' => 'slot_practice:"));
$analysis = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php');
check('practice_card in the giant-call card list', str_contains($analysis, '"practice_card"'));
check('the maths shape asks for it',
    str_contains($analysis, 'HAND ONE OVER'));
check('the skeleton plans a practice phase',
    in_array('practice', Modules\Project\Services\ScriptSkeletonService::WORKED_INTENTS, true));
$mathComposer = file_get_contents(__DIR__ . '/../modules/Project/Services/MathStoryboardComposerService.php');
check('the tree composer builds the practice beat', str_contains($mathComposer, 'practiceFromScenes'));
check('the synth verifies before it ships',
    str_contains(file_get_contents(__DIR__ . '/../modules/Project/Services/MathVisualSynthesisService.php'),
        'PracticeCheck::disproves'));

echo "\n== routing is untouched\n";
check('practice_card is NOT a board-routing math template',
    !in_array('practice_card', ShotListValidator::MATH_TEMPLATES, true));
$quiz = array_map(fn ($i) => ['layout_template' => 'practice_card', 'slots' => []], range(1, 3));
check('a video of practice beats never routes to the math board',
    ShotListValidator::routesToMathBoard($quiz, 'worked_problem') === false);

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
