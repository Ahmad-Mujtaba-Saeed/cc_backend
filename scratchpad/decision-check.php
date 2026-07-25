<?php

/**
 * Iter 20 — decision_tree.
 *
 * Run in-container:  docker compose exec -T app php scratchpad/decision-check.php
 *
 * Two things the layout depends on absolutely: the tree is at most TWO levels
 * deep, and BOTH branches end somewhere. Most of this file defends those.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;
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

function one(array $slot): array
{
    $out = (new ShotListValidator())->validate(['scenes' => [[
        'narration' => ['text' => 'Answer the first question and the chart takes you to the option you should actually use.'],
        'layout_template' => 'decision_tree',
        'slots' => ['slot_decision' => $slot],
    ]]], ['hook_enabled' => false, 'outro_enabled' => false]);

    return $out['scenes'][0];
}

$leafPair = [
    ['label' => 'Yes', 'outcome' => 'Option A'],
    ['label' => 'No', 'outcome' => 'Option B'],
];

echo "\n== registry\n";
check('version bumped to 29', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 29,
    (string) (ExplainerRegistry::all()['version'] ?? '?'));
check('decision_tree template exists', in_array('decision_tree', ExplainerRegistry::templateNames(), true));
check('decision_tree caps at 1 per video', ExplainerRegistry::maxPerVideo('decision_tree') === 1);

echo "\n== shape: both branches must END somewhere\n";

$simple = one(['content_type' => 'decision', 'question' => 'Is it reversible?', 'branches' => [
    ['label' => 'Yes', 'outcome' => 'Just try it'],
    ['label' => 'No', 'outcome' => 'Write it down'],
]]);
check('two leaves survive', $simple['layout_template'] === 'decision_tree', $simple['layout_template']);
check('root question kept', ($simple['slots']['slot_decision']['question'] ?? '') === 'Is it reversible?');

$nested = one(['content_type' => 'decision', 'question' => 'Real-time?', 'branches' => [
    ['label' => 'Yes', 'question' => 'Over 10k?', 'branches' => $leafPair],
    ['label' => 'No', 'outcome' => 'Polling'],
]]);
$b = $nested['slots']['slot_decision']['branches'] ?? [];
check('a mixed tree survives', $nested['layout_template'] === 'decision_tree', $nested['layout_template']);
check('nested branch keeps its 2 leaves', count($b[0]['branches'] ?? []) === 2, json_encode($b[0] ?? []));
check('flat branch keeps its outcome', ($b[1]['outcome'] ?? '') === 'Polling');

$dangling = one(['content_type' => 'decision', 'question' => 'Which way?', 'branches' => [
    ['label' => 'Yes', 'outcome' => 'Go left'],
    ['label' => 'No'],
]]);
check('a branch that goes NOWHERE rejects the card',
    $dangling['layout_template'] !== 'decision_tree', $dangling['layout_template']);
check('the surviving branch is kept as text', str_contains(json_encode($dangling), 'Go left'));

$oneBranch = one(['content_type' => 'decision', 'question' => 'Only one way?', 'branches' => [
    ['label' => 'Yes', 'outcome' => 'Do it'],
]]);
check('a single branch is not a tree', $oneBranch['layout_template'] !== 'decision_tree', $oneBranch['layout_template']);

$noQuestion = one(['content_type' => 'decision', 'branches' => [
    ['label' => 'Yes', 'outcome' => 'A'], ['label' => 'No', 'outcome' => 'B'],
]]);
check('no root question degrades', $noQuestion['layout_template'] !== 'decision_tree', $noQuestion['layout_template']);

echo "\n== DEPTH: never more than two levels\n";

// A third level is exactly what the layout has nowhere to put.
$tooDeep = one(['content_type' => 'decision', 'question' => 'Level 1?', 'branches' => [
    ['label' => 'Yes', 'question' => 'Level 2?', 'branches' => [
        ['label' => 'Yes', 'outcome' => 'Leaf A', 'question' => 'Level 3?', 'branches' => [
            ['label' => 'Yes', 'outcome' => 'Too deep A'],
            ['label' => 'No', 'outcome' => 'Too deep B'],
        ]],
        ['label' => 'No', 'outcome' => 'Leaf B'],
    ]],
    ['label' => 'No', 'outcome' => 'Leaf C'],
]]);
$deep = $tooDeep['slots']['slot_decision'] ?? [];
check('third level is cut off', $tooDeep['layout_template'] === 'decision_tree', $tooDeep['layout_template']);
check('the level-3 nodes are gone', !str_contains(json_encode($deep), 'Too deep'), json_encode($deep));
check('the level-2 leaf keeps its own outcome',
    ($deep['branches'][0]['branches'][0]['outcome'] ?? '') === 'Leaf A', json_encode($deep['branches'][0] ?? []));

$threeBranches = one(['content_type' => 'decision', 'question' => 'Three ways?', 'branches' => [
    ['label' => 'A', 'outcome' => 'First'],
    ['label' => 'B', 'outcome' => 'Second'],
    ['label' => 'C', 'outcome' => 'Third'],
]]);
$tb = $threeBranches['slots']['slot_decision']['branches'] ?? [];
check('a third branch is dropped (the layout is binary)', count($tb) === 2, (string) count($tb));
check('the first two branches are the ones kept',
    ($tb[0]['outcome'] ?? '') === 'First' && ($tb[1]['outcome'] ?? '') === 'Second', json_encode($tb));

$threeLeaves = one(['content_type' => 'decision', 'question' => 'Root?', 'branches' => [
    ['label' => 'Yes', 'question' => 'Sub?', 'branches' => [
        ['label' => 'a', 'outcome' => 'One'],
        ['label' => 'b', 'outcome' => 'Two'],
        ['label' => 'c', 'outcome' => 'Three'],
    ]],
    ['label' => 'No', 'outcome' => 'Done'],
]]);
check('a third leaf is dropped',
    count($threeLeaves['slots']['slot_decision']['branches'][0]['branches'] ?? []) === 2);

echo "\n== a sub-question with only one usable leaf collapses\n";

$oneLeaf = one(['content_type' => 'decision', 'question' => 'Root?', 'branches' => [
    ['label' => 'Yes', 'question' => 'Sub?', 'branches' => [['label' => 'a', 'outcome' => 'The only ending']]],
    ['label' => 'No', 'outcome' => 'Done'],
]]);
$c = $oneLeaf['slots']['slot_decision']['branches'][0] ?? [];
check('it becomes a leaf rather than a half-drawn split',
    ($c['outcome'] ?? '') === 'The only ending' && !isset($c['branches']), json_encode($c));

echo "\n== labels\n";

$noLabels = one(['content_type' => 'decision', 'question' => 'Root?', 'branches' => [
    ['outcome' => 'First'], ['outcome' => 'Second'],
]]);
$nl = $noLabels['slots']['slot_decision']['branches'] ?? [];
check('missing labels default to Yes/No', ($nl[0]['label'] ?? '') === 'Yes' && ($nl[1]['label'] ?? '') === 'No',
    json_encode(array_column($nl, 'label')));

$answerAlias = one(['content_type' => 'decision', 'question' => 'Root?', 'branches' => [
    ['answer' => 'Under 10k', 'outcome' => 'First'], ['answer' => 'Over 10k', 'outcome' => 'Second'],
]]);
check('`answer` is accepted as a label alias',
    ($answerAlias['slots']['slot_decision']['branches'][0]['label'] ?? '') === 'Under 10k');

$long = one([
    'content_type' => 'decision',
    'question' => str_repeat('q', 100),
    'heading' => str_repeat('h', 90),
    'caption' => str_repeat('c', 150),
    'branches' => [
        ['label' => str_repeat('l', 40), 'question' => str_repeat('s', 90), 'branches' => [
            ['label' => 'a', 'outcome' => str_repeat('o', 80)],
            ['label' => 'b', 'outcome' => 'Short'],
        ]],
        ['label' => 'No', 'outcome' => str_repeat('p', 90)],
    ],
]);
$L = $long['slots']['slot_decision'];
check('root question clamped to 64', mb_strlen($L['question']) === 64, (string) mb_strlen($L['question']));
check('branch label clamped to 14', mb_strlen($L['branches'][0]['label']) === 14);
check('sub-question clamped to 48', mb_strlen($L['branches'][0]['question']) === 48);
check('leaf outcome clamped to 36', mb_strlen($L['branches'][0]['branches'][0]['outcome']) === 36);
check('level-1 outcome clamped to 40', mb_strlen($L['branches'][1]['outcome']) === 40);
check('caption clamped to 80', mb_strlen($L['caption']) === 80);

echo "\n== cap degrade (max 1 per video)\n";

$mk = fn (string $q) => [
    'narration' => ['text' => "The {$q} chart walks you to the answer one question at a time, step by step."],
    'layout_template' => 'decision_tree',
    'slots' => ['slot_decision' => ['content_type' => 'decision', 'question' => $q, 'branches' => [
        ['label' => 'Yes', 'question' => 'Scale?', 'branches' => $leafPair],
        ['label' => 'No', 'outcome' => 'Option C'],
    ]]],
];
$two = (new ShotListValidator())->validate(['scenes' => [$mk('First?'), $mk('Second?')]],
    ['hook_enabled' => false, 'outro_enabled' => false]);
$tpls = array_map(fn ($s) => $s['layout_template'], $two['scenes']);
check('first tree kept', $tpls[0] === 'decision_tree', implode(',', $tpls));
check('second degraded', $tpls[1] !== 'decision_tree', implode(',', $tpls));
$flat = json_encode($two['scenes'][1]);
check('degrade flattens a nested path into one line per ending',
    str_contains($flat, 'Yes + Yes') && str_contains($flat, 'Option A'), $flat);
check('degrade keeps the level-1 ending', str_contains($flat, 'Option C'));

echo "\n== linter\n";
$lint = SceneBudgetLinter::lint([array_merge($two['scenes'][0], ['duration_seconds' => 12.0])], []);
$noise = array_filter($lint['items'], fn ($i) => in_array($i['code'] ?? '', ['min_text', 'static_hold'], true));
check('no min_text/static_hold on a long tree scene', count($noise) === 0, json_encode(array_values($noise)));

$m = new ReflectionMethod(SceneBudgetLinter::class, 'sceneScreenText');
$m->setAccessible(true);
$txt = $m->invoke(null, ['slots' => ['slot_decision' => $two['scenes'][0]['slots']['slot_decision']]]);
check('tree questions and outcomes visible to the sync audit',
    str_contains($txt, 'First?') && str_contains($txt, 'Option A') && str_contains($txt, 'Option C'), $txt);

echo "\n== casting\n";
$composer = file_get_contents(__DIR__ . '/../modules/Project/Services/GenericStoryboardComposerService.php');
check('decision_tree in a composer menu', str_contains($composer, "'decision_tree'"));
check('decision_tree has CARD_DOCS', str_contains($composer, "'decision_tree' => 'slot_decision:"));
$analysis = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php');
check('decision_tree in the giant-call card list', str_contains($analysis, '"decision_tree"'));

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
