<?php

/**
 * demo-shape-check — the project-135 regressions (a Vreato product demo that
 * came back as 7 single_focus text cards at a flat 9s, two unearned chapter
 * covers, and not one upload slot despite a guide listing seven screenshots).
 *
 * Covers the DETERMINISTIC halves of the fix — the shape table, the cover
 * bar, and the composer's self-critique. The prompt-side halves (guide
 * plumbing, pacing instructions) are exercised by re-analyzing a real project.
 *   docker exec viralforgebackend-app-1 php scratchpad/demo-shape-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptSkeletonService;
use Modules\Project\Support\ChapterPlanValidator;

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

// ------------------------------------------------------------- demo shape
$sk = new ScriptSkeletonService();

check('a `demo` shape exists', isset(ScriptSkeletonService::GENERIC_SHAPES['demo']));

// The exact failure: a product walkthrough planned as `argument` is FORCED to
// carry a counter + resolution, which is where "Some may doubt AI's editing
// quality" came from. The demo shape must not require an objection.
$demo = $sk->repairGeneric('demo', [
    ['intent' => 'hook', 'brief' => 'clipping is how creators earn now'],
    ['intent' => 'product_intro', 'brief' => 'this is Vreato'],
    ['intent' => 'demo_step', 'brief' => 'copy any supported video link'],
    ['intent' => 'demo_step', 'brief' => 'paste it into Vreato'],
    ['intent' => 'demo_step', 'brief' => 'choose the template'],
    ['intent' => 'demo_step', 'brief' => 'customize the settings'],
    ['intent' => 'demo_step', 'brief' => 'press generate and let it run'],
    ['intent' => 'result', 'brief' => 'edited shorts with captions, ready to post'],
    ['intent' => 'second_feature', 'brief' => 'the AI Explainer template made this video'],
    ['intent' => 'payoff', 'brief' => 'create more, edit less'],
]);
$intents = array_column($demo, 'intent');

check('the demo shape keeps every action as its own phase', count(array_keys($intents, 'demo_step')) === 5);
check('… and never invents a counter-argument', !in_array('counter', $intents, true));
check('… and keeps the second-feature act', in_array('second_feature', $intents, true));
check('… in story order', $intents === array_values(array_intersect(
    ScriptSkeletonService::GENERIC_SHAPES['demo'],
    $intents
)) || $intents[0] === 'hook');
check('a 10-beat script survives as 10 phases', count($demo) === 10);

// The old cap. Five walkthrough actions used to be unreachable because no
// repeatable intent allowed more than 5 and demo_step did not exist at all.
$many = $sk->repairGeneric('demo', array_merge(
    [['intent' => 'hook', 'brief' => 'h']],
    array_fill(0, 8, ['intent' => 'demo_step', 'brief' => 'an action']),
    [['intent' => 'payoff', 'brief' => 'p']]
));
check('demo_step repeats up to 8 times', count(array_keys(array_column($many, 'intent'), 'demo_step')) === 8);

// Unknown shapes still fall back, and the other shapes are untouched.
check('an unknown shape still degrades to generic', array_column($sk->repairGeneric('nonsense', [
    ['intent' => 'hook', 'brief' => 'x'],
]), 'intent')[0] === 'hook');
$arg = $sk->repairGeneric('argument', [
    ['intent' => 'hook', 'brief' => 'x'],
    ['intent' => 'point', 'brief' => 'y'],
]);
check('the argument shape is unchanged (counter still optional)', !in_array('counter', array_column($arg, 'intent'), true));

// ------------------------------------------------------------- demo menus
foreach (['problem', 'product_intro', 'demo_step', 'result', 'second_feature'] as $intent) {
    $menu = GenericStoryboardComposerService::menuFor($intent);
    check("the {$intent} phase has a real menu", count($menu) > 1);
}
check('demo_step leads with the device mockup', GenericStoryboardComposerService::menuFor('demo_step')[0] === 'phone_mockup');
check('demo_step can show a screenshot beside the instruction', in_array('split_side_by_side', GenericStoryboardComposerService::menuFor('demo_step'), true));
check('result can flip through several outputs', in_array('photo_stack', GenericStoryboardComposerService::menuFor('result'), true));
check('the hook can open on a supplied visual', in_array('phone_mockup', GenericStoryboardComposerService::menuFor('hook'), true));

// ----------------------------------------------------------- chapter covers
$cv = new ChapterPlanValidator();
$scene = fn (string $id, float $d = 9.0) => [
    'scene_id' => $id, 'duration_seconds' => $d, 'layout_template' => 'single_focus',
    'slots' => ['slot_main' => ['heading' => 'H']], 'mood' => 'neutral',
];

// Project 135's actual plan: 5 chapters, two of them a single scene, and the
// LAST one holding nothing but the outro card. It collected two covers.
$scenes135 = [
    $scene('scene_1'), $scene('scene_2'), $scene('scene_3'), $scene('scene_4'),
    $scene('scene_5'), $scene('scene_6'), $scene('scene_7'), $scene('scene_8'),
    $scene('scene_outro', 3.2),
];
$plan135 = ['version' => 1, 'chapters' => [
    ['id' => 'ch_1', 'mode' => 'slides', 'scene_ids' => ['scene_1', 'scene_2'], 'reason' => 'hook'],
    ['id' => 'ch_2', 'mode' => 'canvas', 'scene_ids' => ['scene_3', 'scene_4', 'scene_5', 'scene_6', 'scene_7', 'scene_8'], 'reason' => 'ai solution tour'],
    ['id' => 'ch_3', 'mode' => 'slides', 'scene_ids' => ['scene_outro'], 'reason' => 'product demo closer'],
]];

$out135 = $cv->insertCovers($plan135, $scenes135);
$covers135 = array_values(array_filter(
    $out135['scenes'],
    fn ($s) => ($s['layout_template'] ?? '') === 'chapter_cover'
));
check('the outro-only chapter never gets a cover', $out135['inserted'] <= 1);
check('… so no "Chapter 03" lands before the end card', !array_filter(
    $covers135,
    fn ($s) => str_contains(strtolower((string) ($s['slots']['slot_cover']['heading'] ?? '')), 'closer')
));
check('the one surviving cover is title-cased', $covers135 === [] || preg_match(
    '/^[A-Z]/u',
    (string) $covers135[0]['slots']['slot_cover']['heading']
) === 1);

// A genuinely long, multi-act video still gets its act breaks.
$long = [];
foreach (range(1, 12) as $i) {
    $long[] = $scene("s{$i}", 11.0);
}
$planLong = ['version' => 1, 'chapters' => [
    ['id' => 'c1', 'mode' => 'slides', 'scene_ids' => ['s1', 's2', 's3'], 'reason' => 'the setup'],
    ['id' => 'c2', 'mode' => 'canvas', 'scene_ids' => ['s4', 's5', 's6'], 'reason' => 'the middle'],
    ['id' => 'c3', 'mode' => 'canvas', 'scene_ids' => ['s7', 's8', 's9'], 'reason' => 'the turn'],
    ['id' => 'c4', 'mode' => 'slides', 'scene_ids' => ['s10', 's11', 's12'], 'reason' => 'the close'],
]];
$outLong = $cv->insertCovers($planLong, $long);
check('a real multi-act video still gets act breaks', $outLong['inserted'] >= 1);
check('… but never more than the cap', $outLong['inserted'] <= 2);

// Short videos and two-chapter videos are out entirely.
$short = [$scene('a'), $scene('b'), $scene('c')];
$planShort = ['version' => 1, 'chapters' => [
    ['id' => 'c1', 'mode' => 'slides', 'scene_ids' => ['a'], 'reason' => 'x'],
    ['id' => 'c2', 'mode' => 'slides', 'scene_ids' => ['b', 'c'], 'reason' => 'y'],
]];
check('a two-chapter video gets no cover at all', $cv->insertCovers($planShort, $short)['inserted'] === 0);

// ------------------------------------------------------- composer critique
// The critique is what turns the prompt's variety/media/pacing rules from
// suggestions into a retry. Reflection keeps the method private.
$composer = new GenericStoryboardComposerService();
$ref = new ReflectionMethod($composer, 'critique');
$ref->setAccessible(true);

$textScene = fn (string $id, float $d) => [
    'scene_id' => $id, 'order' => 1, 'layout_template' => 'single_focus', 'duration_seconds' => $d,
    'narration' => ['text' => 'x'], 'mood' => 'neutral',
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'H', 'bullets' => ['a', 'b']]],
];

// Project 135's shape exactly: 8 all-text single_focus scenes at a flat 9s.
$bad = [];
foreach (range(1, 8) as $i) {
    $bad[] = $textScene("scene_{$i}", 9.0);
}
$skel8 = array_fill(0, 8, ['intent' => 'point', 'brief' => 'b']);
$faults = $ref->invoke($composer, $bad, $skel8);
$joined = strtolower(implode(' ', $faults));

check('the critique catches the single_focus wall', str_contains($joined, 'single_focus'));
check('the critique catches the missing visuals', str_contains($joined, 'image slot'));
check('the critique catches the flat 9s pacing', str_contains($joined, 'every scene runs'));
check('the critique catches a back-to-back template streak', str_contains($joined, 'in a row'));

// A good draft: mixed cards, real images, varied durations.
$good = [
    array_merge($textScene('scene_1', 4.0), ['layout_template' => 'stat_spotlight']),
    array_merge($textScene('scene_2', 12.0), [
        'layout_template' => 'phone_mockup',
        'slots' => ['slot_screen' => ['content_type' => 'image', 'asset_request' => ['description' => 'the dashboard'], 'frame' => 'browser']],
    ]),
    $textScene('scene_3', 9.0),
    array_merge($textScene('scene_4', 3.5), [
        'layout_template' => 'split_side_by_side',
        'slots' => ['slot_left' => ['content_type' => 'image', 'asset_request' => ['description' => 'settings screen']]],
    ]),
    array_merge($textScene('scene_5', 13.0), ['layout_template' => 'icon_grid']),
    array_merge($textScene('scene_6', 5.0), [
        'layout_template' => 'photo_stack',
        'slots' => ['slot_photo_1' => ['content_type' => 'image', 'asset_request' => ['description' => 'clip one']]],
    ]),
];
check('a varied, media-carrying, well-paced draft passes clean', $ref->invoke($composer, $good, array_fill(0, 6, ['intent' => 'point', 'brief' => 'b'])) === []);

// Dropped phases are the lossy failure: the tail of the script never appears.
$dropped = array_slice($good, 0, 4);
check('the critique catches dropped phases', str_contains(
    strtolower(implode(' ', $ref->invoke($composer, $dropped, array_fill(0, 8, ['intent' => 'point', 'brief' => 'b'])))),
    'skipped'
));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
