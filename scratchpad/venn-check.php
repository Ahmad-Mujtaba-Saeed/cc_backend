<?php

/**
 * Iter 17 — venn_card.
 *
 * Run in-container:  docker compose exec -T app php scratchpad/venn-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;
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

/** Validate one venn scene and hand back its scene row. */
function one(array $slot, array $extra = []): array
{
    $out = (new ShotListValidator())->validate(['scenes' => [array_merge([
        'narration' => ['text' => 'Two groups overlap in the middle and that shared part is the whole point here.'],
        'layout_template' => 'venn_card',
        'slots' => ['slot_venn' => $slot],
    ], $extra)]], ['hook_enabled' => false, 'outro_enabled' => false]);

    return $out['scenes'][0];
}

echo "\n== registry\n";
check('version bumped to 26', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 26, (string) (ExplainerRegistry::all()['version'] ?? '?'));
check('venn_card template exists', in_array('venn_card', ExplainerRegistry::templateNames(), true));
check('venn_card caps at 1 per video', ExplainerRegistry::maxPerVideo('venn_card') === 1,
    var_export(ExplainerRegistry::maxPerVideo('venn_card'), true));

echo "\n== clamp: shape\n";

$two = one(['content_type' => 'venn', 'sets' => [['label' => 'Mammals'], ['label' => 'Sea life']], 'overlap_label' => 'Whales']);
check('2 sets survive as venn_card', $two['layout_template'] === 'venn_card', $two['layout_template']);
check('sets kept', count($two['slots']['slot_venn']['sets'] ?? []) === 2);
check('overlap label kept', ($two['slots']['slot_venn']['overlap_label'] ?? '') === 'Whales');

$three = one(['content_type' => 'venn', 'sets' => [['label' => 'Cheap'], ['label' => 'Fast'], ['label' => 'Good']]]);
check('3 sets survive', count($three['slots']['slot_venn']['sets'] ?? []) === 3);
check('overlap label is optional', !isset($three['slots']['slot_venn']['overlap_label']));

$four = one(['content_type' => 'venn', 'sets' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C'], ['label' => 'D']]]);
check('4th set dropped (geometry only arranges 2-3)', count($four['slots']['slot_venn']['sets'] ?? []) === 3);

$dupes = one(['content_type' => 'venn', 'sets' => [['label' => 'Dogs'], ['label' => 'dogs'], ['label' => 'Cats']]]);
$dupeLabels = array_column($dupes['slots']['slot_venn']['sets'] ?? [], 'label');
check('duplicate labels collapse case-insensitively', $dupeLabels === ['Dogs', 'Cats'], implode(',', $dupeLabels));

$one = one(['content_type' => 'venn', 'sets' => [['label' => 'Only one']], 'heading' => 'Not a venn']);
check('single set degrades away from venn_card', $one['layout_template'] !== 'venn_card', $one['layout_template']);
check('degrade keeps the label as text', str_contains(json_encode($one), 'Only one'));

echo "\n== clamp: field hygiene\n";

$long = one([
    'content_type' => 'venn',
    'sets' => [
        ['label' => str_repeat('x', 40), 'caption' => str_repeat('y', 60)],
        ['label' => 'Second'],
    ],
    'overlap_label' => str_repeat('z', 50),
    'heading' => str_repeat('h', 90),
    'caption' => str_repeat('c', 120),
]);
$v = $long['slots']['slot_venn'];
check('set label clamped to 20', mb_strlen($v['sets'][0]['label']) === 20, (string) mb_strlen($v['sets'][0]['label']));
check('set caption clamped to 32', mb_strlen($v['sets'][0]['caption']) === 32);
check('overlap_label clamped to 28', mb_strlen($v['overlap_label']) === 28);
check('heading clamped to 60', mb_strlen($v['heading']) === 60);
check('caption clamped to 80', mb_strlen($v['caption']) === 80);

$alias = one(['content_type' => 'venn', 'items' => ['Plain', 'Strings']]);
check('items[] alias and bare strings accepted', ($alias['slots']['slot_venn']['sets'][1]['label'] ?? '') === 'Strings',
    json_encode($alias['slots']['slot_venn'] ?? []));

$blank = one(['content_type' => 'venn', 'sets' => [['label' => '  '], ['label' => 'Real'], ['label' => 'Also real']]]);
check('blank labels dropped, not counted', count($blank['slots']['slot_venn']['sets'] ?? []) === 2);

echo "\n== cap degrade (max 1 per video)\n";

$capped = (new ShotListValidator())->validate(['scenes' => [
    ['narration' => ['text' => 'The first diagram shows where the two groups genuinely overlap in the middle.'],
     'layout_template' => 'venn_card',
     'slots' => ['slot_venn' => ['content_type' => 'venn', 'sets' => [['label' => 'A'], ['label' => 'B']], 'overlap_label' => 'Both']]],
    ['narration' => ['text' => 'A second diagram would be over the cap so it has to become something else.'],
     'layout_template' => 'venn_card',
     'slots' => ['slot_venn' => ['content_type' => 'venn',
        'sets' => [['label' => 'Cheap', 'caption' => 'costs little'], ['label' => 'Fast']],
        'overlap_label' => 'The sweet spot']]],
]], ['hook_enabled' => false, 'outro_enabled' => false]);

$tpls = array_map(fn ($s) => $s['layout_template'], $capped['scenes']);
check('first venn kept', $tpls[0] === 'venn_card', implode(',', $tpls));
check('second venn degraded', $tpls[1] !== 'venn_card', implode(',', $tpls));

$flat = json_encode($capped['scenes'][1]);
check('degrade keeps set labels', str_contains($flat, 'Cheap') && str_contains($flat, 'Fast'));
check('degrade keeps the set caption', str_contains($flat, 'costs little'));
check('degrade keeps the overlap as its own line', str_contains($flat, 'Shared: The sweet spot'), $flat);

echo "\n== linter treats venn as animated (no dead-air / sparse noise)\n";

$lint = \Modules\Project\Support\SceneBudgetLinter::lint($capped['scenes'], []);
$noise = array_filter(
    $lint['items'],
    fn ($i) => ($i['scene_id'] ?? '') === ($capped['scenes'][0]['scene_id'] ?? '')
        && in_array($i['code'] ?? '', ['static_hold', 'min_text'], true)
);
check('no static_hold/min_text flag on the venn scene', count($noise) === 0, json_encode(array_values($noise)));

echo "\n== casting menus offer it\n";
$composer = file_get_contents(__DIR__ . '/../modules/Project/Services/GenericStoryboardComposerService.php');
check('venn_card in a composer menu', str_contains($composer, "'venn_card'"));
check('venn_card has CARD_DOCS', str_contains($composer, "'venn_card' => 'slot_venn:"));
$analysis = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php');
check('venn_card in the giant-call card list', str_contains($analysis, '"venn_card"'));

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
