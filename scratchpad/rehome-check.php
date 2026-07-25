<?php

/**
 * rehome-check — StoryboardDiff (idempotent re-analysis, loop iter 4).
 *
 * The planner is pure PHP, so this runs anywhere:
 *   php scratchpad/rehome-check.php
 *   docker exec viralforgebackend-app-1 php scratchpad/rehome-check.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Modules\Project\Support\StoryboardDiff;

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

/** Old rows carry narration as a plain string. */
function oldScene(string $id, int $order, string $tpl, string $narr, array $slots): array
{
    return ['scene_id' => $id, 'order' => $order, 'layout_template' => $tpl, 'narration' => $narr, 'slots' => $slots];
}

/** New scenes are validator-shaped: narration.text. */
function newScene(string $id, int $order, string $tpl, string $narr, array $slots): array
{
    return ['scene_id' => $id, 'order' => $order, 'layout_template' => $tpl, 'narration' => ['text' => $narr], 'slots' => $slots];
}

function actionFor(array $plan, int $assetId): ?array
{
    foreach ($plan['assets'] as $a) {
        if ($a['id'] === $assetId) {
            return $a;
        }
    }
    return null;
}

function keptAt(array $plan, int $assetId, string $sceneId, string $slotKey): bool
{
    $a = actionFor($plan, $assetId);
    return $a !== null && $a['action'] === 'keep'
        && ($a['scene_id'] ?? '') === $sceneId && ($a['slot_key'] ?? '') === $slotKey;
}

function dropped(array $plan, int $assetId): bool
{
    $a = actionFor($plan, $assetId);
    return $a !== null && $a['action'] === 'drop';
}

// --------------------------------------------------------------- the fixture
// A mini "Console Wars" storyboard: hook, versus w/ two uploads, a chart-ish
// scene w/ an AI slot fill, a stock-footage quote card, a scenario w/ sprite,
// and a Dreamcast scene that the re-analysis DELETES entirely.

$STOCK_Q = 'city night timelapse';

$oldScenes = [
    oldScene('scene_1', 1, 'single_focus', "PlayStation didn't just beat Xbox, it crushed it — here's how.", [
        'slot_main' => ['content_type' => 'text_block', 'heading' => 'The Console War'],
    ]),
    oldScene('scene_2', 2, 'versus_card', 'The PS2 sold over 155 million units while the original Xbox managed just 24 million.', [
        'slot_left' => ['content_type' => 'image', 'label' => 'PlayStation 2', 'asset_request' => ['description' => 'photo of a PlayStation 2 console']],
        'slot_right' => ['content_type' => 'image', 'label' => 'Xbox', 'asset_request' => ['description' => 'photo of an original Xbox console']],
    ]),
    oldScene('scene_3', 3, 'single_focus', 'Sales climbed year after year from 2000 to 2006.', [
        'slot_media' => ['content_type' => 'image', 'label' => 'PS2 sales growth', 'asset_request' => ['description' => 'a rising sales chart illustration']],
    ]),
    oldScene('scene_4', 4, 'quote_card', 'Analysts called it the fastest selling console in history.', [
        'slot_media' => ['content_type' => 'video', 'stock_query' => $STOCK_Q],
    ]),
    oldScene('scene_5', 5, 'scenario_diagram', 'Picture the living room battle: one console arrives, the other answers.', [
        'slot_scenario' => ['content_type' => 'scenario', 'heading' => 'The Living Room'],
    ]),
    oldScene('scene_6', 6, 'single_focus', 'Sega had already left the hardware race years earlier.', [
        'slot_photo' => ['content_type' => 'image', 'label' => 'Dreamcast', 'asset_request' => ['description' => 'photo of a Sega Dreamcast console']],
    ]),
];

// Renumbered AND reordered (chart now BEFORE versus) with paraphrased
// narration — exactly what a fresh LLM pass produces from the same script.
$newScenes = [
    newScene('scene_1', 1, 'single_focus', "Here's how PlayStation crushed Xbox in the console war.", [
        'slot_main' => ['content_type' => 'text_block', 'heading' => 'The Console War'],
    ]),
    newScene('scene_2', 2, 'single_focus', 'From 2000 through 2006, sales kept climbing every single year.', [
        'slot_media' => ['content_type' => 'image', 'label' => 'PS2 sales growth', 'asset_request' => ['description' => 'an illustration of a rising sales chart']],
    ]),
    newScene('scene_3', 3, 'versus_card', 'PS2 moved more than 155 million consoles; Xbox sold only 24 million.', [
        'slot_left' => ['content_type' => 'image', 'label' => 'PlayStation 2', 'asset_request' => ['description' => 'a photo of the PlayStation 2 console']],
        'slot_right' => ['content_type' => 'image', 'label' => 'Xbox', 'asset_request' => ['description' => 'a photo of the original Xbox console']],
    ]),
    newScene('scene_4', 4, 'quote_card', 'Reviewers called it the fastest selling console in history.', [
        'slot_media' => ['content_type' => 'video', 'stock_query' => $STOCK_Q],
    ]),
    newScene('scene_5', 5, 'scenario_diagram', 'Picture the living room battle between the two consoles.', [
        'slot_scenario' => ['content_type' => 'scenario', 'heading' => 'The Living Room'],
    ]),
    newScene('scene_7', 6, 'labeled_diagram', 'Every machine on this diagram shaped the living room.', [
        'slot_diagram' => ['content_type' => 'image', 'label' => 'Dreamcast', 'asset_request' => ['description' => 'a photo of the Sega Dreamcast console']],
    ]),
];

$oldAssets = [
    ['id' => 1, 'scene_id' => 'scene_2', 'slot_key' => 'slot_left', 'type' => 'image', 'path' => 'p/left.png', 'original_name' => 'my-ps2.png'],
    ['id' => 2, 'scene_id' => 'scene_2', 'slot_key' => 'slot_right', 'type' => 'image', 'path' => 'p/right.png', 'original_name' => 'my-xbox.png'],
    ['id' => 3, 'scene_id' => 'scene_3', 'slot_key' => 'slot_media', 'type' => 'image', 'path' => 'p/fill.png', 'original_name' => 'slot-fill:abc123'],
    ['id' => 4, 'scene_id' => 'scene_4', 'slot_key' => 'slot_media', 'type' => 'video', 'path' => 'p/stock.mp4', 'original_name' => 'stock:' . md5($STOCK_Q)],
    ['id' => 5, 'scene_id' => 'scene_5', 'slot_key' => 'slot_scenario#sprite0', 'type' => 'image', 'path' => 'p/sprite.png', 'original_name' => 'sprite:def456'],
    ['id' => 6, 'scene_id' => 'scene_6', 'slot_key' => 'slot_photo', 'type' => 'image', 'path' => 'p/dc.png', 'original_name' => 'dreamcast.jpg'],
    ['id' => 7, 'scene_id' => 'scene_2', 'slot_key' => '__narration__', 'type' => 'audio', 'path' => 'p/narration_scene_2.wav', 'original_name' => 'hash-n2'],
    ['id' => 8, 'scene_id' => 'scene_1', 'slot_key' => '__illustration__', 'type' => 'image', 'path' => 'p/ill.png', 'original_name' => 'hash-i1'],
    ['id' => 9, 'scene_id' => '__project__', 'slot_key' => '__ambient__', 'type' => 'image', 'path' => 'p/amb.png', 'original_name' => 'hash-amb'],
];

// ------------------------------------------------------- reordered + renamed
$plan = StoryboardDiff::plan($newScenes, $oldScenes, $oldAssets);
$map = $plan['scene_map'];

check('map: hook follows content', ($map['scene_1'] ?? '') === 'scene_1');
check('map: versus tracked through reorder (old 2 -> new 3)', ($map['scene_2'] ?? '') === 'scene_3');
check('map: chart tracked through reorder (old 3 -> new 2)', ($map['scene_3'] ?? '') === 'scene_2');
check('map: quote card holds', ($map['scene_4'] ?? '') === 'scene_4');
check('map: scenario holds', ($map['scene_5'] ?? '') === 'scene_5');
check('map: deleted scene matches nothing', !isset($map['scene_6']));

check('upload left re-homed to new versus scene', keptAt($plan, 1, 'scene_3', 'slot_left'));
check('upload right re-homed to new versus scene', keptAt($plan, 2, 'scene_3', 'slot_right'));
check('slot fill survives same-key plain media slot', keptAt($plan, 3, 'scene_2', 'slot_media'));
check('stock clip survives identical query', keptAt($plan, 4, 'scene_4', 'slot_media'));
check('sprite rides the surviving scenario slot', keptAt($plan, 5, 'scene_5', 'slot_scenario#sprite0'));
check('orphaned upload rescued by matching request text', keptAt($plan, 6, 'scene_7', 'slot_diagram'));
check('narration cache rides the scene match', keptAt($plan, 7, 'scene_3', '__narration__'));
check('illustration cache rides the scene match', keptAt($plan, 8, 'scene_1', '__illustration__'));
check('project ambient always survives', keptAt($plan, 9, '__project__', '__ambient__'));
check('stats: 3 uploads kept, 1 rescued', $plan['stats']['kept_uploads'] === 3 && $plan['stats']['rescued'] === 1);
check('stats: nothing dropped in the happy path', $plan['stats']['dropped'] === 0);

// ------------------------------------------------------------ identity rerun
$identityNew = array_map(function (array $s): array {
    $s['narration'] = ['text' => (string) $s['narration']];
    return $s;
}, $oldScenes);
$plan2 = StoryboardDiff::plan($identityNew, $oldScenes, $oldAssets);
$allSame = true;
foreach ($oldAssets as $a) {
    if (!keptAt($plan2, $a['id'], $a['scene_id'] === '__project__' ? '__project__' : $a['scene_id'], $a['slot_key'])) {
        $allSame = false;
    }
}
check('identity re-analysis keeps every asset in place', $allSame);
check('identity map is the identity', ($plan2['scene_map']['scene_6'] ?? '') === 'scene_6');

// ------------------------------------------------- guards on generated media
// Stock query changed -> the cached clip must NOT freeze in.
$changedStock = $newScenes;
$changedStock[3]['slots']['slot_media']['stock_query'] = 'desert highway drone';
$plan3 = StoryboardDiff::plan($changedStock, $oldScenes, $oldAssets);
check('stock clip dropped when the query changed', dropped($plan3, 4));

// Media slot became stock -> an AI fill must leave it empty for the fetcher.
$fillToStock = $newScenes;
$fillToStock[1]['slots']['slot_media']['stock_query'] = 'time lapse of a stock market board';
$plan4 = StoryboardDiff::plan($fillToStock, $oldScenes, $oldAssets);
check('slot fill dropped when its slot became stock', dropped($plan4, 3));

// Scenario slot renamed -> the sprite has no base slot to hang from.
$renamedScenario = $newScenes;
$renamedScenario[4]['slots'] = ['slot_stage' => ['content_type' => 'scenario', 'heading' => 'The Living Room']];
$plan5 = StoryboardDiff::plan($renamedScenario, $oldScenes, $oldAssets);
check('sprite dropped when the base slot vanished', dropped($plan5, 5));

// A generated asset is never orphan-rescued (only user labor jumps scenes).
$genOrphan = $oldAssets;
$genOrphan[5]['original_name'] = 'slot-fill:zzz';
$plan6 = StoryboardDiff::plan($newScenes, $oldScenes, $genOrphan);
check('generated fill on a deleted scene is dropped, not rescued', dropped($plan6, 6));

// ------------------------------------------- recast scene, single-slot funnel
// Versus becomes single_focus with ONE media slot: the first upload funnels
// in, the second finds the slot claimed and drops.
$recast = $newScenes;
$recast[2] = newScene('scene_3', 3, 'single_focus', 'PS2 moved more than 155 million consoles; Xbox sold only 24 million.', [
    'slot_visual' => ['content_type' => 'image', 'label' => 'PlayStation 2', 'asset_request' => ['description' => 'a photo of the PlayStation 2 console']],
]);
$plan7 = StoryboardDiff::plan($recast, $oldScenes, $oldAssets);
check('recast scene: one upload funnels into the single media slot', keptAt($plan7, 1, 'scene_3', 'slot_visual'));
// The homeless Xbox photo must NOT ride generic request words ("photo",
// "console") into the Dreamcast slot — that slot belongs to its own upload.
check('recast scene: the displaced upload drops instead of squatting elsewhere', dropped($plan7, 2));
check('recast scene: the Dreamcast rescue still wins its own slot', keptAt($plan7, 6, 'scene_7', 'slot_diagram'));

// Upload outranks a generated fill contending for the same funnel slot.
$contention = StoryboardDiff::plan(
    [newScene('scene_1', 1, 'single_focus', 'The PS2 sold over 155 million units.', [
        'slot_visual' => ['content_type' => 'image', 'asset_request' => ['description' => 'photo of a PlayStation 2 console']],
    ])],
    [oldScene('scene_9', 1, 'single_focus', 'The PS2 sold over 155 million units.', [
        'slot_a' => ['content_type' => 'image', 'asset_request' => ['description' => 'photo of a PlayStation 2 console']],
    ])],
    [
        ['id' => 1, 'scene_id' => 'scene_9', 'slot_key' => 'slot_a', 'type' => 'image', 'path' => 'p/f.png', 'original_name' => 'slot-fill:aaa'],
        ['id' => 2, 'scene_id' => 'scene_9', 'slot_key' => 'slot_a', 'type' => 'image', 'path' => 'p/u.png', 'original_name' => 'mine.png'],
    ]
);
check('user upload beats a generated fill for the funnel slot', keptAt($contention, 2, 'scene_1', 'slot_visual') && dropped($contention, 1));

// ------------------------------------------------------------------- corners
$empty = StoryboardDiff::plan($newScenes, [], []);
check('first analysis: empty plan, empty map', $empty['assets'] === [] && $empty['scene_map'] === []);

// Content-free scenes (covers/outros) must not stealth-match each other.
$coversOnly = StoryboardDiff::matchScenes(
    [newScene('cover_1', 1, 'chapter_cover', '', ['slot_cover' => ['content_type' => 'text_block']])],
    [oldScene('cover_9', 1, 'chapter_cover', '', ['slot_cover' => ['content_type' => 'text_block']])]
);
check('empty-content scenes never match', $coversOnly === []);

// Unrelated scenes stay unmatched even at the same position + template.
$unrelated = StoryboardDiff::matchScenes(
    [newScene('scene_1', 1, 'single_focus', 'Photosynthesis turns sunlight into chemical energy inside the leaf.', [
        'slot_main' => ['content_type' => 'text_block', 'heading' => 'Photosynthesis'],
    ])],
    [$oldScenes[0]]
);
check('different content never matches on position alone', $unrelated === []);

// rekeyByScene: survivors follow the map, __project__ sticks, stale ids die.
$rekeyed = StoryboardDiff::rekeyByScene(
    ['scene_2' => true, 'scene_9' => true, '__project__' => true],
    ['scene_2' => 'scene_3']
);
check('rekeyByScene follows the map and drops stale ids', $rekeyed === ['__project__' => true, 'scene_3' => true]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
