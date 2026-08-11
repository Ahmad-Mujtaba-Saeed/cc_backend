<?php

/**
 * revision-check — the guarantees behind "tell the AI what to change".
 *
 * The whole point of the revision flow is that a note about scene 4 costs
 * scene 5 nothing: its uploads, its cached voiceover, its timing and its
 * coordinates on the canvas all survive because its ROW is never rewritten.
 * That promise lives entirely in StoryboardRevision, which is pure — so it
 * can be checked here, without a model, a queue or a database.
 *
 *   docker compose exec app php scratchpad/revision-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ShotListValidator;
use Modules\Project\Support\StoryboardDiff;
use Modules\Project\Support\StoryboardRevision;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

/** A realistic board: built by the real validator, exactly like a live one. */
function board(): array
{
    $raw = ['scenes' => [
        ['scene_id' => 'scene_1', 'layout_template' => 'stat_spotlight', 'duration_seconds' => 5,
            'narration' => ['text' => 'Streaming killed the video store in under a decade.'],
            'slots' => ['slot_stat' => ['content_type' => 'text_block', 'heading' => '9,000 stores', 'bullets' => ['gone by 2013']]]],
        ['scene_id' => 'scene_2', 'layout_template' => 'split_side_by_side', 'duration_seconds' => 8,
            'narration' => ['text' => 'The rental counter was the whole business and it was busy every Friday night.'],
            'slots' => [
                'slot_left' => ['content_type' => 'image', 'asset_request' => ['description' => 'a busy video rental counter in 1998']],
                'slot_right' => ['content_type' => 'text_block', 'heading' => 'The counter', 'bullets' => ['late fees paid the rent', 'one copy per customer']],
            ]],
        ['scene_id' => 'scene_3', 'layout_template' => 'animated_chart', 'duration_seconds' => 9,
            'narration' => ['text' => 'Revenue collapsed from six billion to almost nothing.'],
            'slots' => ['slot_chart' => ['content_type' => 'chart', 'chart_type' => 'bar', 'values' => [6, 3, 1],
                'labels' => ['2004', '2008', '2012'], 'unit' => 'B', 'source' => 'annual reports']]],
        ['scene_id' => 'scene_4', 'layout_template' => 'checklist_card', 'duration_seconds' => 7,
            'narration' => ['text' => 'Three things had to be true at once for the model to work.'],
            'slots' => ['slot_checklist' => ['content_type' => 'proscons', 'heading' => 'What it needed',
                'pros' => ['local stores', 'new releases', 'late fees'], 'cons' => ['no mail', 'no streaming']]]],
        ['scene_id' => 'scene_5', 'layout_template' => 'single_focus', 'duration_seconds' => 6,
            'narration' => ['text' => 'By the time the decision was made the audience had already left.'],
            'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Too late', 'bullets' => ['the habit had moved']]]],
    ]];

    return (new ShotListValidator())->validate($raw, ['hook_enabled' => false, 'outro_enabled' => true])['scenes'];
}

$scenes = board();
$ids = array_map(fn ($s) => (string) $s['scene_id'], $scenes);
$outroId = null;
foreach ($scenes as $s) {
    if ($s['layout_template'] === 'outro_card') {
        $outroId = (string) $s['scene_id'];
    }
}

echo "\n=== the board under test ===\n";
foreach ($scenes as $s) {
    printf("  %-14s %-22s %.1fs\n", $s['scene_id'], $s['layout_template'], $s['duration_seconds']);
}

echo "\n=== digest ===\n";
$digest = StoryboardRevision::digest($scenes, ['scene_2::slot_left' => 'rental-counter.jpg']);
check('digest lists every scene', substr_count($digest, 'scene_') >= count($scenes));
check('digest marks the auto-inserted end card SYSTEM', str_contains($digest, 'SYSTEM'));
check('digest shouts about the user\'s upload', str_contains($digest, 'THE USER UPLOADED A FILE HERE'));
check('digest carries the chart\'s real numbers', str_contains($digest, '"values"'));

echo "\n=== normalizePlan: refusals ===\n";
$p = StoryboardRevision::normalizePlan([
    ['op' => 'edit', 'scene_id' => 'scene_99', 'instruction' => 'x'],
], $scenes);
check('an edit naming a scene that does not exist is refused', $p['ops'] === [] && count($p['findings']) === 1);

$p = StoryboardRevision::normalizePlan([
    ['op' => 'edit', 'scene_id' => $outroId, 'instruction' => 'reword it'],
], $scenes);
check('the generated end card cannot be rewritten', $p['ops'] === [], json_encode($p['ops']));

$p = StoryboardRevision::normalizePlan([
    ['op' => 'delete', 'scene_id' => $outroId],
], $scenes);
check('...but it CAN be deleted on request', count($p['ops']) === 1 && $p['ops'][0]['op'] === 'delete');

$p = StoryboardRevision::normalizePlan([
    ['op' => 'move', 'scene_id' => 'scene_2', 'after' => 'scene_2'],
], $scenes);
check('a scene cannot be moved after itself', $p['ops'] === []);

$p = StoryboardRevision::normalizePlan([
    ['op' => 'insert', 'after' => 'scene_2'],
], $scenes);
check('a new scene with no brief is refused', $p['ops'] === []);

$p = StoryboardRevision::normalizePlan([
    ['op' => 'insert', 'after' => 'scene_404', 'instruction' => 'x'],
], $scenes);
check('a new scene anchored to nothing is refused', $p['ops'] === []);

$p = StoryboardRevision::normalizePlan(
    array_map(fn ($id) => ['op' => 'delete', 'scene_id' => $id], ['scene_1', 'scene_2', 'scene_3', 'scene_4', 'scene_5']),
    $scenes
);
$deletes = array_values(array_filter($p['ops'], fn ($o) => $o['op'] === 'delete'));
check(
    'a revision can never empty the video',
    count($deletes) === count($ids) - 1 - StoryboardRevision::MIN_SCENES,
    count($deletes) . ' deletes survived'
);

$p = StoryboardRevision::normalizePlan([
    ['op' => 'edit', 'scene_id' => 'scene_3', 'instruction' => 'a'],
    ['op' => 'delete', 'scene_id' => 'scene_3'],
    ['op' => 'move', 'scene_id' => 'scene_3', 'after' => 'scene_1'],
], $scenes);
check(
    'a scene being removed is not also rewritten or moved',
    count($p['ops']) === 1 && $p['ops'][0]['op'] === 'delete'
);

$p = StoryboardRevision::normalizePlan([
    ['op' => 'edit', 'scene_id' => 'scene_3', 'instruction' => 'a'],
    ['op' => 'edit', 'scene_id' => 'scene_3', 'instruction' => 'b'],
], $scenes);
check('one rewrite per scene, the first wins', count($p['ops']) === 1 && $p['ops'][0]['instruction'] === 'a');

$many = [];
for ($i = 0; $i < StoryboardRevision::MAX_TOUCHED + 4; $i++) {
    $many[] = ['op' => 'insert', 'after' => 'scene_1', 'instruction' => "new {$i}"];
}
$p = StoryboardRevision::normalizePlan($many, $scenes);
check(
    'the number of new cards is capped',
    count($p['ops']) === StoryboardRevision::MAX_INSERTS,
    count($p['ops']) . ' inserts'
);

$p = StoryboardRevision::normalizePlan([['op' => 'sing', 'scene_id' => 'scene_1']], $scenes);
check('an invented operation is ignored silently', $p['ops'] === [] && $p['findings'] === []);

echo "\n=== apply: an edit touches one scene and nothing else ===\n";
$validator = new ShotListValidator();
$ops = StoryboardRevision::normalizePlan([
    ['op' => 'edit', 'scene_id' => 'scene_3', 'instruction' => 'use 2019-2021 revenue instead'],
], $scenes)['ops'];
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3',
    'layout_template' => 'animated_chart',
    'narration' => 'Revenue fell from four billion to under one.',
    'seconds' => 8,
    'slots' => ['slot_chart' => ['content_type' => 'chart', 'chart_type' => 'bar', 'values' => [4, 2, 0.6],
        'labels' => ['2019', '2020', '2021'], 'unit' => 'B', 'source' => 'annual reports']],
]], $validator);

check('exactly one card is reported changed', $r['changed'] === ['scene_3'], json_encode($r['changed']));
check('nothing was added or removed', $r['added'] === [] && $r['removed'] === []);
check('the scene count is unchanged', count($r['scenes']) === count($scenes));
check('the edited scene KEEPS its id (so its assets stay attached)', $r['scenes'][2]['scene_id'] === 'scene_3');
check('the new numbers landed', ($r['scenes'][2]['slots']['slot_chart']['values'] ?? []) === [4.0, 2.0, 0.6],
    json_encode($r['scenes'][2]['slots']['slot_chart']['values'] ?? null));

$untouchedBefore = array_values(array_filter($scenes, fn ($s) => $s['scene_id'] !== 'scene_3'));
$untouchedAfter = array_values(array_filter($r['scenes'], fn ($s) => $s['scene_id'] !== 'scene_3'));
check(
    'every other scene is byte-identical',
    json_encode($untouchedBefore) === json_encode($untouchedAfter)
);

echo "\n=== apply: refusals keep the old card ===\n";
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'layout_template' => 'outro_card', 'narration' => 'the end', 'slots' => ['slot_outro' => []],
]], $validator);
check('a rewrite may not choose an auto-inserted card', $r['changed'] === [] && count($r['findings']) === 1);
check('...and the old card is still there', $r['scenes'][2]['layout_template'] === 'animated_chart');

$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'layout_template' => 'hologram_card', 'narration' => 'x', 'slots' => [],
]], $validator);
check('a rewrite may not invent a card type', $r['changed'] === [] && count($r['findings']) === 1);
check('...and an invented card type is worth one retry', array_key_exists('scene_3', $r['rejected']));

// The voice must not survive the card it was describing.
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'stat_spotlight',
    'slots' => ['slot_stat' => ['content_type' => 'text_block', 'heading' => '$0', 'bullets' => ['by 2013']]],
]], $validator);
check('a new card with no narration of its own is refused',
    $r['changed'] === [] && str_contains($r['rejected']['scene_3'] ?? '', 'no narration'),
    json_encode($r['rejected']));
// ...but only on the first ask. Having asked twice, refusing again would mean
// the user's request silently did nothing — the card lands with a warning.
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'stat_spotlight',
    'slots' => ['slot_stat' => ['content_type' => 'text_block', 'heading' => '$0', 'bullets' => ['by 2013']]],
]], $validator, ['final' => true]);
check('on the final pass the card change lands anyway', $r['changed'] === ['scene_3']
    && $r['scenes'][2]['layout_template'] === 'stat_spotlight');
check('...flagged as a warning, not buried',
    (bool) array_filter($r['findings'], fn ($f) => $f['severity'] === 'warn' && $f['code'] === 'revision_narration_stale'));
check('...but a card kept AS IT WAS may reuse its narration', (function () use ($scenes, $ops, $validator) {
    $out = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
        'key' => 'scene_3', 'layout_template' => 'animated_chart',
        'slots' => ['slot_chart' => ['content_type' => 'chart', 'chart_type' => 'line', 'values' => [6, 3, 1],
            'labels' => ['2004', '2008', '2012'], 'unit' => 'B', 'source' => 'annual reports']],
    ]], $validator);

    return $out['changed'] === ['scene_3']
        && ($out['scenes'][2]['slots']['slot_chart']['chart_type'] ?? '') === 'line';
})());
check('a "changed nothing" rewrite is not retried', (function () use ($scenes, $ops, $validator) {
    $out = StoryboardRevision::apply($scenes, $ops, ['scene_3' => ['key' => 'scene_3']], $validator);

    return $out['changed'] === [] && $out['rejected'] === [];
})());

$r = StoryboardRevision::apply($scenes, $ops, [], $validator);
check('an empty draft leaves the storyboard alone', $r['changed'] === [] && $r['scenes'] === $scenes);

// The live failure this rule exists for: a versus_card whose comparison does
// not parse is recast by the validator into a two-panel split holding the
// SAME sentence twice — strictly worse than the card the user already had.
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'versus_card',
    'narration' => 'Discs against downloads.',
    'slots' => [
        'slot_left' => ['content_type' => 'text_block', 'heading' => 'Discs', 'bullets' => ['a shop']],
        'slot_right' => ['content_type' => 'text_block', 'heading' => 'Streams', 'bullets' => ['a queue']],
    ],
]], $validator);
check('a card the validator had to recast is thrown away, not shipped',
    $r['changed'] === [] && $r['scenes'][2]['layout_template'] === 'animated_chart');
check('...and it is reported for a retry', array_key_exists('scene_3', $r['rejected']), json_encode($r['rejected']));
check('...and the user is told', (bool) array_filter($r['findings'], fn ($f) => str_contains($f['message'], 'not usable')));

$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'versus_card',
    'narration' => 'Discs against downloads.',
    'slots' => [
        'slot_left' => ['content_type' => 'text_block', 'heading' => 'Discs', 'bullets' => ['a shop']],
        'slot_right' => ['content_type' => 'text_block', 'heading' => 'Streams', 'bullets' => ['a queue']],
        'slot_versus' => ['content_type' => 'versus', 'left' => ['label' => 'Discs', 'stats' => ['a trip out']],
            'right' => ['label' => 'Streaming', 'stats' => ['one tap']], 'verdict' => 'Streaming won'],
    ],
]], $validator);
// ...but the same answer WITHOUT descriptions for its two picture panels is
// still refused: the validator would back-fill "Background visual for this
// scene", and that string becomes the image prompt AND an upload request.
$versusSlots = [
    'slot_versus' => ['content_type' => 'versus', 'left' => ['label' => 'Discs', 'stats' => ['a trip out']],
        'right' => ['label' => 'Streaming', 'stats' => ['one tap']], 'verdict' => 'Streaming won'],
];
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'versus_card',
    'narration' => 'Discs against downloads.', 'slots' => $versusSlots,
]], $validator);
check('a picture panel with nothing to shoot is refused',
    $r['changed'] === [] && str_contains($r['rejected']['scene_3'] ?? '', 'asset_request.description'),
    json_encode($r['rejected']));
check('...naming the panels so the retry can fix them',
    str_contains($r['rejected']['scene_3'] ?? '', 'slot_left') && str_contains($r['rejected']['scene_3'] ?? '', 'slot_right'));

// A panel that carries a LABEL is not blank: the validator already turns the
// label into the request, which is a fair description of the shot.
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'versus_card',
    'narration' => 'Discs against downloads.',
    'slots' => $versusSlots + [
        'slot_left' => ['content_type' => 'image', 'label' => 'Rental shops'],
        'slot_right' => ['content_type' => 'image', 'label' => 'Streaming'],
    ],
]], $validator);
check('a labelled panel counts as described', $r['changed'] === ['scene_3']
    && ($r['scenes'][2]['slots']['slot_left']['asset_request']['description'] ?? '') === 'Rental shops',
    json_encode($r['rejected']));

// With neither description nor label there is nothing to shoot, and the
// validator's own placeholder would become the image prompt. On the final
// pass it is replaced with the only honest thing left: what the card says.
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'versus_card',
    'narration' => 'Rental shops against streaming, ten years apart.',
    'slots' => $versusSlots,
]], $validator, ['final' => true]);
$panels = $r['scenes'][2]['slots'] ?? [];
$leftDescription = $panels['slot_left']['asset_request']['description'] ?? '';
check('on the final pass a blank panel is described from the card itself',
    str_contains($leftDescription, 'Rental shops against streaming'), $leftDescription);
check('...never left as the placeholder that becomes the image prompt',
    !str_contains(mb_strtolower(json_encode($panels)), 'visual for this scene'));
check('...and the user is told to check them',
    (bool) array_filter($r['findings'], fn ($f) => $f['code'] === 'revision_media_guessed'));

$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'versus_card',
    'narration' => 'Discs against downloads.',
    'slots' => $versusSlots + [
        'slot_left' => ['content_type' => 'image', 'asset_request' => ['description' => 'a wall of rental DVD cases']],
        'slot_right' => ['content_type' => 'image', 'asset_request' => ['description' => 'a laptop showing a streaming grid']],
    ],
]], $validator);
check('the same card WITH its comparison and its panels lands', $r['changed'] === ['scene_3']
    && $r['scenes'][2]['layout_template'] === 'versus_card' && $r['rejected'] === [],
    json_encode($r['rejected']));

$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'layout_template' => 'animated_chart',
    'narration' => (string) $scenes[2]['narration']['text'],
    'slots' => $scenes[2]['slots'],
]], $validator);
check('a rewrite that changes nothing is not reported as a change', $r['changed'] === []);

echo "\n=== apply: rewording keeps the card it had ===\n";
$r = StoryboardRevision::apply($scenes, $ops, ['scene_3' => [
    'narration' => 'The money simply stopped arriving.',
]], $validator);
check('a narration-only rewrite keeps the chart', $r['scenes'][2]['layout_template'] === 'animated_chart');
check('...and its data', ($r['scenes'][2]['slots']['slot_chart']['values'] ?? []) === [6.0, 3.0, 1.0]);
check('...and is reported as changed', $r['changed'] === ['scene_3']);

echo "\n=== apply: inserting ===\n";
$ops = StoryboardRevision::normalizePlan([
    ['op' => 'insert', 'after' => 'scene_2', 'instruction' => 'name the year the mail-order rival launched'],
], $scenes)['ops'];
$r = StoryboardRevision::apply($scenes, $ops, ['new_1' => [
    'key' => 'new_1',
    'layout_template' => 'stat_spotlight',
    'narration' => 'In 1997 a mail-order rival started shipping discs in red envelopes.',
    'slots' => ['slot_stat' => ['content_type' => 'text_block', 'heading' => '1997', 'bullets' => ['the red envelope arrives']]],
]], $validator, ['revision' => 2]);
check('one card was added', count($r['added']) === 1);
check('the new id cannot collide with an existing one', !in_array($r['added'][0], $ids, true), $r['added'][0]);
check('it landed directly after its anchor', $r['scenes'][2]['scene_id'] === $r['added'][0], $r['scenes'][2]['scene_id']);
check('the board grew by exactly one', count($r['scenes']) === count($scenes) + 1);
check('orders were renumbered 1..N', array_map(fn ($s) => $s['order'], $r['scenes']) === range(1, count($r['scenes'])));

$r = StoryboardRevision::apply($scenes, $ops, ['new_1' => [
    'key' => 'new_1', 'layout_template' => 'single_focus', 'narration' => '', 'slots' => [],
]], $validator);
check('a new card with nothing in it is refused', $r['added'] === [] && count($r['scenes']) === count($scenes));

$ops = StoryboardRevision::normalizePlan([
    ['op' => 'insert', 'after' => 'start', 'instruction' => 'a cold open'],
], $scenes)['ops'];
$r = StoryboardRevision::apply($scenes, $ops, ['new_1' => [
    'key' => 'new_1', 'layout_template' => 'single_focus',
    'narration' => 'Nine thousand shops closed and almost nobody noticed.',
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Gone', 'bullets' => ['in under a decade']]],
]], $validator);
check('"start" puts the new card first', $r['scenes'][0]['scene_id'] === $r['added'][0]);

echo "\n=== apply: deleting and moving ===\n";
$ops = StoryboardRevision::normalizePlan([['op' => 'delete', 'scene_id' => 'scene_4']], $scenes)['ops'];
$r = StoryboardRevision::apply($scenes, $ops, [], $validator);
check('the card is gone', !in_array('scene_4', array_map(fn ($s) => (string) $s['scene_id'], $r['scenes']), true));
check('it is reported removed', $r['removed'] === ['scene_4']);
check('the rest survives untouched', count($r['scenes']) === count($scenes) - 1);

$ops = StoryboardRevision::normalizePlan([['op' => 'move', 'scene_id' => 'scene_4', 'after' => 'scene_1']], $scenes)['ops'];
$r = StoryboardRevision::apply($scenes, $ops, [], $validator);
check('the moved card sits after its anchor', $r['scenes'][1]['scene_id'] === 'scene_4', $r['scenes'][1]['scene_id']);
check('nothing else moved', $r['scenes'][0]['scene_id'] === 'scene_1' && $r['scenes'][2]['scene_id'] === 'scene_2');
check('a move is not a rewrite', $r['changed'] === [] && $r['moved'] === ['scene_4']);

$ops = StoryboardRevision::normalizePlan([
    ['op' => 'delete', 'scene_id' => 'scene_2'],
    ['op' => 'insert', 'after' => 'scene_2', 'instruction' => 'a replacement'],
], $scenes)['ops'];
$r = StoryboardRevision::apply($scenes, $ops, ['new_1' => [
    'key' => 'new_1', 'layout_template' => 'single_focus',
    'narration' => 'Friday night at the counter was the whole business.',
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Friday night', 'bullets' => ['every week']]],
]], $validator);
check(
    'a card anchored to a scene this same revision removed still lands',
    count($r['added']) === 1 && in_array($r['added'][0], array_map(fn ($s) => (string) $s['scene_id'], $r['scenes']), true)
);

echo "\n=== apply: nothing ever lands behind the end card ===\n";
$ops = StoryboardRevision::normalizePlan([
    ['op' => 'insert', 'after' => $outroId, 'instruction' => 'one more thought'],
], $scenes)['ops'];
$r = StoryboardRevision::apply($scenes, $ops, ['new_1' => [
    'key' => 'new_1', 'layout_template' => 'single_focus',
    'narration' => 'The shops are gone but the habit they built is not.',
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'What survived', 'bullets' => ['the Friday ritual']]],
]], $validator);
$lastScene = $r['scenes'][count($r['scenes']) - 1];
check('a card anchored after the ending is pushed in front of it',
    $lastScene['layout_template'] === 'outro_card', $lastScene['layout_template']);
check('...and it is still added', count($r['added']) === 1);

echo "\n=== apply: caps are charged to the scene the user changed ===\n";
// scene_4 is already the video's one checklist_card. Turning scene_5 into a
// second one must degrade SCENE_5 — never the card nobody mentioned.
$ops = StoryboardRevision::normalizePlan([
    ['op' => 'edit', 'scene_id' => 'scene_5', 'instruction' => 'make it a checklist'],
], $scenes)['ops'];
$r = StoryboardRevision::apply($scenes, $ops, ['scene_5' => [
    'key' => 'scene_5', 'layout_template' => 'checklist_card',
    'narration' => 'Everything they would have needed to survive.',
    'slots' => ['slot_checklist' => ['content_type' => 'proscons', 'heading' => 'What was missing',
        'pros' => ['streaming rights'], 'cons' => ['stores', 'late fees']]],
]], $validator);
$byId = [];
foreach ($r['scenes'] as $s) {
    $byId[(string) $s['scene_id']] = $s;
}
check('the untouched checklist keeps its card', $byId['scene_4']['layout_template'] === 'checklist_card');
check('the changed scene is the one recast', $byId['scene_5']['layout_template'] === 'single_focus',
    $byId['scene_5']['layout_template']);
check('the recast keeps the content', str_contains(json_encode($byId['scene_5']['slots']), 'streaming rights'));
check('the user is told why', (bool) array_filter($r['findings'], fn ($f) => $f['code'] === 'revision_capped'));

echo "\n=== assets: the map is certain and rescue is off ===\n";
$edited = StoryboardRevision::apply($scenes, StoryboardRevision::normalizePlan([
    ['op' => 'edit', 'scene_id' => 'scene_3', 'instruction' => 'x'],
], $scenes)['ops'], ['scene_3' => [
    'key' => 'scene_3', 'layout_template' => 'stat_spotlight', 'narration' => 'Nothing was left.',
    'slots' => ['slot_stat' => ['content_type' => 'text_block', 'heading' => '$0', 'bullets' => ['by 2013']]],
]], $validator)['scenes'];

$assets = [
    ['id' => 1, 'scene_id' => 'scene_2', 'slot_key' => 'slot_left', 'type' => 'image',
        'path' => 'p/1.jpg', 'original_name' => 'counter.jpg'],
    ['id' => 2, 'scene_id' => 'scene_2', 'slot_key' => '__narration__', 'type' => 'audio',
        'path' => 'p/n2.wav', 'original_name' => 'hash2'],
    ['id' => 3, 'scene_id' => 'scene_3', 'slot_key' => '__narration__', 'type' => 'audio',
        'path' => 'p/n3.wav', 'original_name' => 'hash3'],
];
$map = [];
foreach ($scenes as $s) {
    $map[(string) $s['scene_id']] = (string) $s['scene_id'];
}
$plan = StoryboardDiff::planWithMap($edited, $scenes, $assets, $map, false);
$actions = [];
foreach ($plan['assets'] as $a) {
    $actions[$a['id']] = $a;
}
check('the untouched upload stays exactly where it was',
    $actions[1]['action'] === 'keep' && $actions[1]['scene_id'] === 'scene_2' && $actions[1]['slot_key'] === 'slot_left');
check('the untouched narration is kept (its own hash decides re-synthesis)', $actions[2]['action'] === 'keep');
check('the rewritten scene keeps its narration row too', $actions[3]['action'] === 'keep');

// The user's photo sat on scene_2. They ask for scene_2 to become a text
// card, and the same revision adds a new card asking for the very same shot.
// A re-analysis would jump the file across (its only chance to survive); a
// revision must NOT, because landing a file on a card the user never
// mentioned is a stranger surprise than telling them it was released.
$recast = StoryboardRevision::apply($scenes, StoryboardRevision::normalizePlan([
    ['op' => 'edit', 'scene_id' => 'scene_2', 'instruction' => 'drop the photo, make it text'],
    ['op' => 'insert', 'after' => 'scene_2', 'instruction' => 'show the counter'],
], $scenes)['ops'], [
    'scene_2' => ['key' => 'scene_2', 'layout_template' => 'single_focus',
        'narration' => 'The rental counter was the whole business.',
        'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'The counter',
            'bullets' => ['late fees paid the rent']]]],
    'new_1' => ['key' => 'new_1', 'layout_template' => 'full_bleed_with_banner',
        'narration' => 'Every Friday night the queue went out of the door.',
        'slots' => [
            'slot_background' => ['content_type' => 'image',
                'asset_request' => ['description' => 'a busy video rental counter in 1998']],
            'slot_banner' => ['content_type' => 'text_block', 'heading' => 'Friday night'],
        ]],
], $validator)['scenes'];

$plan = StoryboardDiff::planWithMap($recast, $scenes, $assets, $map, false);
$photo = array_values(array_filter($plan['assets'], fn ($a) => $a['id'] === 1))[0];
check('an upload whose card lost its picture is dropped, not relocated', $photo['action'] === 'drop',
    json_encode($photo));
check('...whereas a re-analysis WOULD have rescued it', (function () use ($recast, $scenes, $assets, $map) {
    $with = StoryboardDiff::planWithMap($recast, $scenes, $assets, $map, true);
    $a = array_values(array_filter($with['assets'], fn ($x) => $x['id'] === 1))[0];

    return $a['action'] === 'keep' && $a['slot_key'] === 'slot_background';
})());

check('isUpload separates the user\'s files from the pipeline\'s',
    StoryboardDiff::isUpload(['slot_key' => 'slot_left', 'original_name' => 'photo.jpg'])
    && !StoryboardDiff::isUpload(['slot_key' => 'slot_left', 'original_name' => 'slot-fill:abc'])
    && !StoryboardDiff::isUpload(['slot_key' => '__narration__', 'original_name' => 'hash'])
    && !StoryboardDiff::isUpload(['slot_key' => 'slot_scenario#sprite1', 'original_name' => 'x']));

echo "\n=== summaries read like a person wrote them ===\n";
$r = StoryboardRevision::apply($scenes, StoryboardRevision::normalizePlan([
    ['op' => 'delete', 'scene_id' => 'scene_4'],
], $scenes)['ops'], [], $validator);
check('a removal is summarised', str_contains($r['summary'], 'emoved 1 card'), $r['summary']);
check('the promise is spelled out', str_contains($r['summary'], 'left untouched'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
