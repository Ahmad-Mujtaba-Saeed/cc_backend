<?php

/**
 * image-grid-check — the multi-image card.
 *
 *   docker compose exec app php scratchpad/image-grid-check.php
 *
 * image_grid puts 3-6 pictures on screen AT ONCE. Everything checked here
 * protects that claim: too few cells is a different card, cells that say the
 * same thing are not a comparison, and every cell must still be an ordinary
 * image slot so it inherits uploads, AI generation, the free media library and
 * asset re-homing for free.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

/** One image cell as a model would write it. */
function cell(string $description, string $label = '', array $extra = []): array
{
    return array_merge([
        'content_type' => 'image',
        'label' => $label,
        'asset_request' => ['description' => $description],
    ], $extra);
}

/** Run one image_grid scene through the validator. */
function grid(array $cells, string $aspect = '16:9', string $narration = 'Four ways the same idea shows up.'): array
{
    $slots = [];
    foreach ($cells as $i => $c) {
        $slots['slot_image_' . ($i + 1)] = $c;
    }

    $result = (new ShotListValidator())->validate([
        'aspect_ratio' => $aspect,
        'scenes' => [[
            'scene_id' => 'scene_1',
            'duration_seconds' => 8,
            'narration' => ['text' => $narration],
            'layout_template' => 'image_grid',
            'slots' => $slots,
        ]],
    ]);

    return $result['scenes'][0] ?? [];
}

echo "\n== 1. the registry entry ==\n";

check('image_grid is a template', ExplainerRegistry::hasTemplate('image_grid'));
check('it declares six cells', count(ExplainerRegistry::slotKeys('image_grid')) === 6, implode(', ', ExplainerRegistry::slotKeys('image_grid')));
check('three cells are required', array_reduce(
    ['slot_image_1', 'slot_image_2', 'slot_image_3'],
    fn ($c, $k) => $c && empty(ExplainerRegistry::slotMeta('image_grid', $k)['optional']),
    true
));
check('the last three are optional', array_reduce(
    ['slot_image_4', 'slot_image_5', 'slot_image_6'],
    fn ($c, $k) => $c && !empty(ExplainerRegistry::slotMeta('image_grid', $k)['optional']),
    true
));
check('every cell takes an image', array_reduce(
    ExplainerRegistry::slotKeys('image_grid'),
    fn ($c, $k) => $c && ExplainerRegistry::allowedContentTypes('image_grid', $k) === ['image'],
    true
));
check('capped at one per video', ExplainerRegistry::maxPerVideo('image_grid') === 1, (string) ExplainerRegistry::maxPerVideo('image_grid'));

// A six-cell grid would have eaten three quarters of the old fill budget of 8,
// and a grid whose last cells never filled renders as holes beside pictures.
check('the AI fill budget can cover a full grid plus the rest of a video', ExplainerRegistry::maxSlotFills() >= 12, (string) ExplainerRegistry::maxSlotFills());

echo "\n== 2. a grid the model got right ==\n";

$scene = grid([
    cell('a hand-painted shop sign', 'Hand-painted', ['heading' => 'Four ways a sign speaks']),
    cell('a neon bar sign glowing at night', 'Neon'),
    cell('a carved wooden signpost on a trail', 'Carved'),
    cell('an enamel railway station sign', 'Enamel'),
]);

check('the template survives', ($scene['layout_template'] ?? '') === 'image_grid', $scene['layout_template'] ?? '?');
check('all four cells survive', count($scene['slots'] ?? []) === 4, (string) count($scene['slots'] ?? []));
check('cells are numbered contiguously', array_keys($scene['slots'] ?? []) === ['slot_image_1', 'slot_image_2', 'slot_image_3', 'slot_image_4'], implode(',', array_keys($scene['slots'] ?? [])));
check('captions survive as labels', ($scene['slots']['slot_image_2']['label'] ?? '') === 'Neon', $scene['slots']['slot_image_2']['label'] ?? '(none)');
check('the heading survives on the first cell', ($scene['slots']['slot_image_1']['heading'] ?? '') === 'Four ways a sign speaks');

// Every cell is an ORDINARY image slot, which is the whole reason this card
// needed no new asset machinery: uploads, AI generation, the free media
// library and re-homing all work on it already.
$brief = $scene['slots']['slot_image_3']['asset_request'] ?? [];
check('each cell is an image slot', ($scene['slots']['slot_image_3']['content_type'] ?? '') === 'image');
check('each cell gets its own media brief', ($brief['search_query'] ?? '') !== '' && ($brief['guidance'] ?? '') !== '', $brief['search_query'] ?? '(none)');

echo "\n== 3. a gap the card must NOT paper over ==\n";

// The failure this card invites: asked for four examples when the beat has
// one subject, a model writes the same picture four times.
$scene = grid([
    cell('a red sports car on a coastal road', 'One'),
    cell('a coastal road with a red sports car', 'Two'),
    cell('a red sports car, on a road by the coast', 'Three'),
    cell('a mountain bike leaning on a fence', 'Four'),
]);
check(
    'cells repeating one subject collapse to the distinct ones',
    ($scene['layout_template'] ?? '') === 'split_side_by_side',
    ($scene['layout_template'] ?? '?') . ' with ' . count($scene['slots'] ?? []) . ' slots'
);

// ...but genuinely different subjects that share a word are NOT duplicates.
$scene = grid([
    cell('a red sports car on a road', 'Sports'),
    cell('a yellow school bus at a stop', 'Bus'),
    cell('a black delivery van in traffic', 'Van'),
]);
check('similar-but-different subjects all survive', count($scene['slots'] ?? []) === 3, (string) count($scene['slots'] ?? []));

echo "\n== 4. too few, too many ==\n";

$two = grid([cell('a wind turbine on a hill', 'Wind'), cell('a solar farm in a desert', 'Solar')]);
check('two pictures become split_side_by_side', ($two['layout_template'] ?? '') === 'split_side_by_side', $two['layout_template'] ?? '?');
check('...carrying both pictures', array_keys($two['slots'] ?? []) === ['slot_left', 'slot_right'], implode(',', array_keys($two['slots'] ?? [])));

$one = grid([cell('a wind turbine on a hill', 'Wind')]);
check('one picture becomes single_focus', ($one['layout_template'] ?? '') === 'single_focus', $one['layout_template'] ?? '?');

$none = grid([]);
check('no pictures degrades to text', ($none['layout_template'] ?? '') === 'single_focus' && ($none['slots']['slot_main']['content_type'] ?? '') === 'text_block', $none['layout_template'] ?? '?');

// Six genuinely different subjects — the fixture has to be as honest as the
// card demands, or the duplicate guard (rightly) eats it.
$distinct = [
    'a fishmonger weighing cod on a scale' => 'Fish',
    'a florist wrapping tulips in paper' => 'Flowers',
    'a baker sliding bread out of an oven' => 'Bread',
    'a cobbler resoling a leather boot' => 'Shoes',
    'a watchmaker at a bench with a loupe' => 'Watches',
    'a barber sweeping his shop floor' => 'Barber',
    'a potter throwing a bowl on a wheel' => 'Pottery',
];

// Seven cells is more than the registry declares; the seventh is not a legal
// slot key at all and must be dropped rather than renumbered into the grid.
$slots = [];
foreach ($distinct as $description => $label) {
    $slots['slot_image_' . (count($slots) + 1)] = cell($description, $label);
}
$seven = (new ShotListValidator())->validate([
    'aspect_ratio' => '16:9',
    'scenes' => [[
        'scene_id' => 'scene_1',
        'duration_seconds' => 8,
        'narration' => ['text' => 'Seven stalls, one street.'],
        'layout_template' => 'image_grid',
        'slots' => $slots,
    ]],
])['scenes'][0] ?? [];
check('a seventh cell is dropped', count($seven['slots'] ?? []) === 6, (string) count($seven['slots'] ?? []));

// Six reads cleanly in BOTH aspects — probed at 1920x1080 and 1080x1920.
$six = [];
foreach (array_slice($distinct, 0, 6, true) as $description => $label) {
    $six[] = cell($description, $label);
}
check('portrait keeps all six', count(grid($six, '9:16')['slots'] ?? []) === 6, (string) count(grid($six, '9:16')['slots'] ?? []));

echo "\n== 5. captions ==\n";

$long = grid([
    cell('a hand-painted shop sign', str_repeat('caption ', 8)),
    cell('a neon bar sign at night', 'Neon'),
    cell('a carved wooden signpost', 'Carved'),
]);
check('an over-long caption is clamped', mb_strlen($long['slots']['slot_image_1']['label'] ?? '') <= 24, mb_strlen($long['slots']['slot_image_1']['label'] ?? '') . ' chars');

$bare = grid([
    cell('a hand-painted shop sign'),
    cell('a neon bar sign at night'),
    cell('a carved wooden signpost'),
]);
check('captions are optional', count($bare['slots'] ?? []) === 3 && ($bare['layout_template'] ?? '') === 'image_grid');

echo "\n== 6. the cap, and the linter ==\n";

// Two grids in one video: the second is degraded, not rendered.
$twoGrids = (new ShotListValidator())->validate([
    'aspect_ratio' => '16:9',
    'scenes' => array_map(fn ($n) => [
        'scene_id' => "scene_{$n}",
        'duration_seconds' => 8,
        'narration' => ['text' => "Grid number {$n} of the examples we have collected so far."],
        'layout_template' => 'image_grid',
        'slots' => [
            'slot_image_1' => cell("a fishmonger weighing cod number {$n}", 'One'),
            'slot_image_2' => cell("a harbour crane lifting a container", 'Two'),
            'slot_image_3' => cell("a mountain hut under deep snow", 'Three'),
        ],
    ], [1, 2]),
])['scenes'];
$templates = array_column($twoGrids, 'layout_template');
check('only one grid survives the cap', array_count_values($templates)['image_grid'] === 1, implode(' + ', $templates));
check('the capped one keeps its best picture', ($twoGrids[1]['slots']['slot_main']['content_type'] ?? '') === 'image', $twoGrids[1]['layout_template'] ?? '?');

// The linter must not call a grid a static hold — its cells land one by one.
$report = (new SceneBudgetLinter())->lint([[
    'scene_id' => 'scene_1',
    'duration_seconds' => 12,
    'narration' => ['text' => 'Four examples, one after another, each one landing as it is named.'],
    'layout_template' => 'image_grid',
    'slots' => [
        'slot_image_1' => cell('a hand-painted shop sign', 'Painted'),
        'slot_image_2' => cell('a neon bar sign at night', 'Neon'),
        'slot_image_3' => cell('a carved wooden signpost', 'Carved'),
    ],
]], []);
$codes = array_column($report['items'] ?? [], 'code');
check('a 12s grid is not flagged as a static hold', !in_array('static_hold', $codes, true), implode(',', $codes) ?: '(no findings)');

echo "\n== 7. casting ==\n";

$docs = GenericStoryboardComposerService::cardDocs();
check('the composer can describe the card', isset($docs['image_grid']));
check('the doc names the real slot keys', str_contains($docs['image_grid'] ?? '', 'slot_image_1') && str_contains($docs['image_grid'] ?? '', 'slot_image_6'));
// The doc has to say what makes it different, or the model casts it wherever
// it wants several pictures and photo_stack never gets used again.
check('the doc distinguishes it from photo_stack', str_contains($docs['image_grid'] ?? '', 'photo_stack'));
check('the doc demands distinct subjects', str_contains($docs['image_grid'] ?? '', 'DIFFERENT'));

$offered = [];
foreach (['point', 'aspect', 'result'] as $intent) {
    if (in_array('image_grid', GenericStoryboardComposerService::menuFor($intent), true)) {
        $offered[] = $intent;
    }
}
check('it is on the menus where several pictures make sense', count($offered) === 3, implode(', ', $offered));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
