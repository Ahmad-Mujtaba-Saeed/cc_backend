<?php

/**
 * hierarchy-check — the "how is X structured" org-chart card: clamp (root +
 * 2-4 branches, an optional second level of grandchildren), the depth cap, the
 * degrade/cap paths, linter and casting of hierarchy_card
 * (loop iter 39, card queue #2 item 12 — the last of the queue).
 *   docker exec viralforgebackend-app-1 php scratchpad/hierarchy-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptAnalysisService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

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

function hierarchyScene(string $id, array $slot): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'hierarchy_card',
        'duration_seconds' => 12,
        'narration' => ['text' => 'The organisation splits into its main branches, and each of those branches has its own parts.'],
        'slots' => ['slot_hierarchy' => array_merge(['content_type' => 'hierarchy'], $slot)],
    ];
}

$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false]
)['scenes'];
$one = fn (array $slot) => $run([hierarchyScene('scene_1', $slot)])[0] ?? [];
$isText = fn (array $out) => ($out['layout_template'] ?? '') === 'single_focus';
$slotOf = fn (array $out) => $out['slots']['slot_hierarchy'] ?? [];

$GOOD = [
    'root' => 'The US Government',
    'children' => [
        ['label' => 'Legislative', 'caption' => 'Writes the laws', 'children' => [['label' => 'Senate'], ['label' => 'House']]],
        ['label' => 'Executive', 'caption' => 'Enforces the laws'],
        ['label' => 'Judicial', 'children' => [['label' => 'Supreme Court'], ['label' => 'Lower Courts']]],
    ],
];

echo "--- registry ---\n";
$templates = ExplainerRegistry::templates();
check('hierarchy_card template registered', isset($templates['hierarchy_card']));
check('its slot is slot_hierarchy', isset($templates['hierarchy_card']['slots']['slot_hierarchy']));
check('the slot allows the hierarchy type',
    ($templates['hierarchy_card']['slots']['slot_hierarchy']['allowed'] ?? []) === ['hierarchy']);
check('capped at 1 per video', ($templates['hierarchy_card']['max_per_video'] ?? null) === 1);
$reg = json_decode(file_get_contents(__DIR__ . '/../modules/Project/Resources/explainer_registry.json'), true);
check('hierarchy content type registered', isset($reg['content_types']['hierarchy']));
check('it requires root and children',
    ($reg['content_types']['hierarchy']['required'] ?? []) === ['root', 'children']);
check('registry version bumped past the evidence card', (int) ($reg['version'] ?? 0) >= 38, (string) ($reg['version'] ?? ''));

echo "--- a real structure renders ---\n";
$out = $one($GOOD + ['heading' => 'Three branches', 'caption' => 'Checks and balances', 'highlight_index' => 1]);
check('the card survives with a root and branches', ($out['layout_template'] ?? '') === 'hierarchy_card', $out['layout_template'] ?? '');
$slot = $slotOf($out);
check('root kept', ($slot['root'] ?? '') === 'The US Government', $slot['root'] ?? '');
check('three branches kept', count($slot['children'] ?? []) === 3, (string) count($slot['children'] ?? []));
check('branch label kept', ($slot['children'][0]['label'] ?? '') === 'Legislative', $slot['children'][0]['label'] ?? '');
check('branch caption kept', ($slot['children'][0]['caption'] ?? '') === 'Writes the laws', $slot['children'][0]['caption'] ?? '');
check('grandchildren kept', ($slot['children'][0]['children'][0]['label'] ?? '') === 'Senate', json_encode($slot['children'][0]['children'] ?? []));
check('heading, caption, highlight kept',
    ($slot['heading'] ?? '') === 'Three branches' && ($slot['caption'] ?? '') === 'Checks and balances'
    && ($slot['highlight_index'] ?? null) === 1, json_encode(['h' => $slot['heading'] ?? null, 'c' => $slot['caption'] ?? null, 'hi' => $slot['highlight_index'] ?? null]));

echo "--- THE REFUSALS: not a structure -> plain text ---\n";
check('no root degrades to text', $isText($one(['children' => $GOOD['children']])));
check('one branch degrades to text', $isText($one(['root' => 'X', 'children' => [['label' => 'Only one']]])));
check('no branches degrades to text', $isText($one(['root' => 'X', 'children' => []])));
check('nothing at all degrades to text', $isText($one([])));
$out = $one(['children' => $GOOD['children']]);
check('the refused structure survives as text bullets',
    (bool) array_filter($out['slots']['slot_main']['bullets'] ?? [], fn ($b) => str_contains($b, 'Legislative')),
    json_encode($out['slots']['slot_main']['bullets'] ?? []));

echo "--- the depth cap: at most two levels below the root ---\n";
$out = $one([
    'root' => 'Root',
    'children' => [
        ['label' => 'A', 'children' => [['label' => 'a1'], ['label' => 'a2'], ['label' => 'a3'], ['label' => 'a4'], ['label' => 'a5']]],
        ['label' => 'B'],
    ],
]);
$slot = $slotOf($out);
check('grandchildren capped at 4', count($slot['children'][0]['children'] ?? []) === 4, (string) count($slot['children'][0]['children'] ?? []));
check('a deeper level is never emitted', !isset($slot['children'][0]['children'][0]['children']));

echo "--- a lone grandchild is dropped, not drawn as a spine of one ---\n";
$out = $one(['root' => 'Root', 'children' => [['label' => 'A', 'children' => [['label' => 'only']]], ['label' => 'B']]]);
$slot = $slotOf($out);
check('a branch with one grandchild keeps no sub-level', !isset($slot['children'][0]['children']),
    json_encode($slot['children'][0] ?? []));

echo "--- branches capped at 4 ---\n";
$out = $one(['root' => 'Root', 'children' => array_map(fn ($i) => ['label' => "B{$i}"], range(1, 7))]);
check('no more than four branches', count($slotOf($out)['children'] ?? []) === 4, (string) count($slotOf($out)['children'] ?? []));

echo "--- an empty-label branch is skipped, the rest survive ---\n";
$out = $one(['root' => 'Root', 'children' => [['label' => ''], ['label' => 'Real one'], ['label' => 'Real two']]]);
$slot = $slotOf($out);
check('the empty branch is gone but the card survives',
    ($out['layout_template'] ?? '') === 'hierarchy_card' && count($slot['children'] ?? []) === 2, json_encode($slot['children'] ?? []));

echo "--- stale highlight dropped, not guessed ---\n";
$out = $one($GOOD + ['highlight_index' => 9]);
check('an out-of-range highlight is discarded', !isset($slotOf($out)['highlight_index']), json_encode($slotOf($out)['highlight_index'] ?? 'unset'));

echo "--- caps and aliases ---\n";
$out = $one([
    'root' => str_repeat('R', 60),
    'children' => [
        ['label' => str_repeat('L', 60), 'caption' => str_repeat('C', 90), 'children' => [['label' => str_repeat('g', 40)], ['label' => 'g2']]],
        ['label' => 'B'],
    ],
]);
$slot = $slotOf($out);
check('root capped at 28', mb_strlen($slot['root'] ?? '') <= 28, (string) mb_strlen($slot['root'] ?? ''));
check('branch label capped at 22', mb_strlen($slot['children'][0]['label'] ?? '') <= 22, (string) mb_strlen($slot['children'][0]['label'] ?? ''));
check('branch caption capped at 40', mb_strlen($slot['children'][0]['caption'] ?? '') <= 40, (string) mb_strlen($slot['children'][0]['caption'] ?? ''));
check('grandchild label capped at 18', mb_strlen($slot['children'][0]['children'][0]['label'] ?? '') <= 18, (string) mb_strlen($slot['children'][0]['children'][0]['label'] ?? ''));

$out = $one([
    'title' => 'A Cell',
    'branches' => [
        ['name' => 'Nucleus', 'note' => 'Holds DNA', 'items' => [['name' => 'Nucleolus'], 'Chromatin']],
        'Cytoplasm',
    ],
]);
$slot = $slotOf($out);
check('title -> root, branches -> children, name -> label, note -> caption',
    ($slot['root'] ?? '') === 'A Cell' && ($slot['children'][0]['label'] ?? '') === 'Nucleus'
    && ($slot['children'][0]['caption'] ?? '') === 'Holds DNA', json_encode($slot));
check('a string branch and a string grandchild are accepted',
    ($slot['children'][1]['label'] ?? '') === 'Cytoplasm' && ($slot['children'][0]['children'][1]['label'] ?? '') === 'Chromatin',
    json_encode($slot['children'] ?? []));

echo "--- the over-cap degrade keeps the root AND its branches ---\n";
$scenes = $run([
    hierarchyScene('scene_1', $GOOD),
    hierarchyScene('scene_2', $GOOD),
]);
$cards = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'hierarchy_card'));
check('only one card survives the cap', count($cards) === 1, (string) count($cards));
$degraded = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'single_focus'));
$deg = $degraded[0] ?? [];
check('the degrade heading is the root', ($deg['slots']['slot_main']['heading'] ?? '') === 'The US Government', $deg['slots']['slot_main']['heading'] ?? '');
$bullets = $deg['slots']['slot_main']['bullets'] ?? [];
check('a branch survives as a bullet', (bool) array_filter($bullets, fn ($b) => str_contains($b, 'Legislative')), json_encode($bullets));
check('and folds its sub-parts in after a dash',
    (bool) array_filter($bullets, fn ($b) => str_contains($b, 'Legislative') && str_contains($b, 'Senate')), json_encode($bullets));

echo "--- the linter treats it as a drawn card ---\n";
$lint = (new SceneBudgetLinter())->lint($run([hierarchyScene('scene_1', $GOOD)]), []);
$codes = array_column($lint['items'] ?? [], 'code');
check('not flagged as a sparse text scene', !in_array('sparse_scene', $codes, true), implode(',', $codes));
check('not flagged as dead air', !in_array('dead_air', $codes, true), implode(',', $codes));
check('not flagged as a static hold', !in_array('static_hold', $codes, true), implode(',', $codes));

$scene = $run([hierarchyScene('scene_1', $GOOD)])[0];
$m = (new ReflectionClass(SceneBudgetLinter::class))->getMethod('sceneScreenText');
$m->setAccessible(true);
$screen = strtolower((string) $m->invoke(null, $scene));
check('the sync audit reads the root', str_contains($screen, 'government'), mb_substr($screen, 0, 160));
check('and a branch label', str_contains($screen, 'legislative'), mb_substr($screen, 0, 160));
check('and a grandchild label', str_contains($screen, 'senate'), mb_substr($screen, 0, 160));

echo "--- casting knows about it ---\n";
$docs = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('CARD_DOCS');
check('the composer documents the card', isset($docs['hierarchy_card']));
check('the doc names the real slot key', str_contains($docs['hierarchy_card'] ?? '', 'slot_hierarchy'));
check('the doc explains the two-level cap', str_contains($docs['hierarchy_card'] ?? '', 'two levels'));
$menus = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('MENUS');
$offered = array_keys(array_filter($menus, fn ($m) => in_array('hierarchy_card', $m, true)));
check('offered on at least one intent', $offered !== [], implode(',', $offered));
check('every menu entry is a real template',
    !array_diff(array_unique(array_merge(...array_values($menus))), array_keys($templates)));

$src = file_get_contents((new ReflectionClass(ScriptAnalysisService::class))->getFileName());
check('the giant analyzer call lists the card', str_contains($src, '"hierarchy_card"'));
check('and contrasts it against decision_tree and layer_stack',
    str_contains($src, 'decision_tree') && str_contains($src, 'layer_stack'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
