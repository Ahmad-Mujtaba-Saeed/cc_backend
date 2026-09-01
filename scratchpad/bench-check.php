<?php

/**
 * bench-check — the bench's own test suite. No LLM, no network, no cost.
 *
 *   docker compose exec -T app php scratchpad/bench-check.php
 *
 * A scorer nobody checks is a number generator. Every assertion here feeds
 * Scorer::score a HAND-BUILT storyboard whose defect is known, and asserts the
 * dimension that should notice it does, and that the others do not.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/bench/Scorer.php';

use Bench\Scorer;

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   {$what}\n";
    } else {
        $fail++;
        echo "  FAIL {$what}\n";
    }
}

// ------------------------------------------------------------- fixtures ----

/** A scene carrying one text card. */
function textScene(int $i, string $tpl, float $secs, string $narration = 'A line of narration that runs for a while.'): array
{
    return [
        'scene_id' => "scene_{$i}",
        'order' => $i,
        'layout_template' => $tpl,
        'duration_seconds' => $secs,
        'narration' => ['text' => $narration],
        'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Heading', 'bullets' => ['One', 'Two']]],
    ];
}

/** A scene carrying one image slot with a full media brief. */
function mediaScene(int $i, float $secs, array $brief = []): array
{
    return [
        'scene_id' => "scene_{$i}",
        'order' => $i,
        'layout_template' => 'full_bleed_with_banner',
        'duration_seconds' => $secs,
        'narration' => ['text' => 'A line of narration about the picture on screen.'],
        'slots' => [
            'slot_background' => [
                'content_type' => 'image',
                'asset_request' => array_merge([
                    'description' => 'A wide daylight shot of a real newsroom',
                    'search_query' => 'busy newsroom desks',
                    'media_kind' => 'image',
                    'guidance' => 'A wide daylight shot of a real newsroom with people at desks, not a posed stock handshake.',
                ], $brief),
            ],
            'slot_banner' => ['content_type' => 'text_block', 'heading' => 'Heading'],
        ],
    ];
}

/** A minimal corpus case with a rubric we control. */
function makeCase(array $rubric = [], array $over = []): array
{
    return array_merge([
        'id' => 'fixture',
        'title' => 'Fixture',
        'target_seconds' => 60,
        'script' => 'Some script.',
        'rubric' => array_merge([
            'shape' => ['argument'],
            'scenes' => [4, 10],
            'math_mode' => false,
            'must' => [['cards' => ['animated_chart'], 'why' => 'the numbers']],
            'should' => [],
            'never' => ['timeline_card' => 'no chronology'],
            'media_min' => 0.33,
        ], $rubric),
    ], $over);
}

/** A run payload with sensible defaults, overridable per assertion. */
function makeRun(array $scenes, array $over = []): array
{
    return array_merge([
        'shape' => 'argument',
        'math_mode' => false,
        'skeleton' => array_fill(0, count($scenes), ['intent' => 'point', 'brief' => 'b']),
        'raw_scenes' => $scenes,
        'scenes' => $scenes,
        'validator_warnings' => [],
        'plan_items' => [],
        'lint' => ['items' => [], 'counts' => ['error' => 0, 'warn' => 0, 'info' => 0]],
        'attempts' => 1,
        'composed_by_tree' => true,
    ], $over);
}

function codes(array $scored): array
{
    return array_column($scored['findings'], 'code');
}

// A storyboard with nothing wrong with it: varied cards, half of them media,
// uneven pacing, the required chart, no forbidden card.
$good = [
    textScene(1, 'stat_spotlight', 4.0),
    mediaScene(2, 9.0),
    textScene(3, 'animated_chart', 6.0),
    mediaScene(4, 12.0),
    textScene(5, 'myth_fact', 5.0),
    mediaScene(6, 11.0),
    textScene(7, 'checklist_card', 8.0),
];

echo "\nbaseline\n";
$base = Scorer::score(makeCase(), makeRun($good));
ok('a clean storyboard scores >= 95', $base['total'] >= 95);
ok('no error findings on a clean storyboard',
    array_filter($base['findings'], fn ($f) => $f['severity'] === 'error') === []);

echo "\nstructure\n";
$s = Scorer::score(makeCase(), makeRun($good, ['shape' => 'countdown']));
ok('a wrong shape is caught', in_array('shape', codes($s), true));
ok('a wrong shape costs structure but not casting',
    $s['scores']['structure'] < $base['scores']['structure']
    && $s['scores']['casting'] === $base['scores']['casting']);

$short = array_slice($good, 0, 3);
$s = Scorer::score(makeCase(), makeRun($short));
ok('too few scenes is caught', in_array('scene_count', codes($s), true));

$s = Scorer::score(makeCase(), makeRun($good, [
    'skeleton' => array_fill(0, 10, ['intent' => 'point', 'brief' => 'b']),
]));
ok('a dropped phase is an error', in_array('dropped_phase', codes($s), true));

echo "\ncasting\n";
$noChart = $good;
$noChart[2] = textScene(3, 'quote_card', 6.0);
$s = Scorer::score(makeCase(), makeRun($noChart));
ok('a missed must-cast beat is an error', in_array('missed_beat', codes($s), true));
ok('a missed must-cast beat costs casting', $s['scores']['casting'] < $base['scores']['casting']);

$withTimeline = $good;
$withTimeline[4] = textScene(5, 'timeline_card', 5.0);
$s = Scorer::score(makeCase(), makeRun($withTimeline));
ok('a forbidden card is an error', in_array('wrong_card', codes($s), true));

$s = Scorer::score(makeCase(), makeRun($good, ['math_mode' => true]));
ok('a video routed to the maths board when it should not be is an error',
    in_array('routing', codes($s), true));

// CardSuitability, live: a timeline whose nodes carry no dates.
$unfit = $good;
$unfit[4] = [
    'scene_id' => 'scene_5', 'order' => 5, 'layout_template' => 'timeline_card',
    'duration_seconds' => 5.0, 'narration' => ['text' => 'Three criteria.'],
    'slots' => ['slot_timeline' => ['content_type' => 'timeline_nodes', 'nodes' => [
        ['date' => '', 'label' => 'page speed'],
        ['date' => '', 'label' => 'content quality'],
        ['date' => '', 'label' => 'relevance'],
    ]]],
];
$s = Scorer::score(makeCase(['never' => []]), makeRun($unfit));
ok('a timeline of non-dates is caught by CardSuitability', in_array('unfit_card', codes($s), true));

echo "\nvariety\n";
$streak = [
    textScene(1, 'single_focus', 4.0), textScene(2, 'single_focus', 9.0),
    textScene(3, 'single_focus', 6.0), textScene(4, 'single_focus', 12.0),
    textScene(5, 'animated_chart', 5.0), mediaScene(6, 11.0), mediaScene(7, 8.0),
];
$s = Scorer::score(makeCase(), makeRun($streak));
ok('a four-scene streak is caught', in_array('streak', codes($s), true));
ok('single_focus over a third is caught', in_array('single_focus', codes($s), true));
ok('a streak costs variety', $s['scores']['variety'] < 0.5);

// The registry cap: image_grid is max 1 per video.
$overCap = $good;
$overCap[0] = textScene(1, 'image_grid', 4.0);
$overCap[2] = textScene(3, 'image_grid', 6.0);
$s = Scorer::score(makeCase(['must' => []]), makeRun($overCap));
ok('a per-video cap breach is caught', in_array('over_cap', codes($s), true));

// Question 2: the model broke it, the validator fixed it — reported, not scored.
$s = Scorer::score(makeCase(), makeRun($good, ['raw_scenes' => $streak]));
ok('a rescued draft is reported as model_ignored_variety',
    in_array('model_ignored_variety', codes($s), true));
ok('a rescued draft still scores full variety', $s['scores']['variety'] === $base['scores']['variety']);

echo "\nmedia\n";
$textOnly = array_map(fn ($i) => textScene($i, ['stat_spotlight', 'myth_fact', 'quote_card', 'animated_chart', 'checklist_card'][$i % 5], 4.0 + $i), range(1, 7));
$s = Scorer::score(makeCase(), makeRun($textOnly));
ok('a slideshow of text cards is caught', in_array('thin_visuals', codes($s), true));
ok('a slideshow scores zero on media', $s['scores']['media'] === 0.0);

$promptQuery = $good;
$promptQuery[1] = mediaScene(2, 9.0, ['search_query' => 'a lone figure silhouetted against a cinematic sky', 'guidance' => 'Anything works here.']);
$s = Scorer::score(makeCase(), makeRun($promptQuery));
ok('prompt language in a stock query is caught', in_array('brief_query', codes($s), true));
ok('guidance that never says what to avoid is reported', in_array('brief_guidance', codes($s), true));
ok('a usable query passes', Scorer::queryUsable('busy trading floor'));
ok('an empty query fails', !Scorer::queryUsable(''));
ok('a one-word query fails', !Scorer::queryUsable('newsroom'));
ok('guidance with an avoid clause passes',
    Scorer::guidanceUsable('A wide daylight shot of a real newsroom, not a posed stock handshake.'));
ok('guidance without one fails',
    !Scorer::guidanceUsable('A wide daylight shot of a real newsroom with people at desks.'));

echo "\npacing\n";
$flat = array_map(fn ($i) => $i % 2 ? textScene($i, 'stat_spotlight', 8.5) : mediaScene($i, 8.6), range(1, 7));
$s = Scorer::score(makeCase(['must' => []]), makeRun($flat));
ok('flat pacing is caught', in_array('flat_pacing', codes($s), true));

$long = $good;
$long[3] = mediaScene(4, 40.0);
$s = Scorer::score(makeCase(), makeRun($long));
ok('a runaway runtime is caught', in_array('runtime', codes($s), true));
ok('a 40s scene is caught', in_array('scene_length', codes($s), true));

echo "\nlint\n";
$s = Scorer::score(makeCase(), makeRun($good, ['lint' => [
    'items' => [
        ['severity' => 'error', 'code' => 'dead_air', 'scene_id' => 'scene_2', 'message' => 'nothing on screen'],
        ['severity' => 'warn', 'code' => 'static_hold', 'scene_id' => 'scene_3', 'message' => 'held'],
    ],
    'counts' => ['error' => 1, 'warn' => 1, 'info' => 0],
]]));
ok('a lint error costs lint points', $s['scores']['lint'] < 1.0);
ok('lint items surface as findings', in_array('lint:dead_air', codes($s), true));

echo "\nflights\n";
$items = [];
foreach ($good as $i => $sc) {
    $items[] = ['scene_id' => $sc['scene_id'], 'treatment' => $i === 0 ? 'establish' : 'canvas_hop', 'relation' => 'continues'];
}
$s = Scorer::score(makeCase(), makeRun($good, ['plan_items' => $items]));
ok('flying on every cut breaks the budget', in_array('flight_budget', codes($s), true));

$held = [];
foreach ($good as $i => $sc) {
    $held[] = ['scene_id' => $sc['scene_id'], 'treatment' => $i === 0 ? 'establish' : 'same_frame', 'relation' => 'continues'];
}
$s = Scorer::score(makeCase(), makeRun($good, ['plan_items' => $held]));
ok('a journey that never moves is caught', in_array('no_flight', codes($s), true));

$mixed = $held;
$mixed[3]['treatment'] = 'canvas_hop';
$s = Scorer::score(makeCase(), makeRun($good, ['plan_items' => $mixed]));
ok('one flight in seven scenes is full marks', $s['scores']['flights'] === 1.0);

echo "\ncorpus\n";
$cases = require __DIR__ . '/bench/corpus.php';
ok('the corpus loads and every rubric names real templates', count($cases) >= 25);
ok('every case has a script, a title and a target', array_filter($cases, fn ($c) => trim((string) $c['script']) === ''
    || trim((string) $c['title']) === '' || (int) $c['target_seconds'] < 30) === []);
$shapes = [];
foreach ($cases as $c) {
    foreach ($c['rubric']['shape'] as $sh) {
        $shapes[$sh] = ($shapes[$sh] ?? 0) + 1;
    }
}
ok('the corpus covers every generic shape',
    count(array_intersect(array_keys($shapes), ['argument', 'journey', 'compare', 'countdown', 'demo', 'generic'])) === 6);
$mustCards = [];
foreach ($cases as $c) {
    foreach (array_merge($c['rubric']['must'], $c['rubric']['should'] ?? []) as $g) {
        foreach ($g['cards'] as $card) {
            $mustCards[$card] = true;
        }
    }
}
ok('the corpus asks for at least 30 distinct cards', count($mustCards) >= 30);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
