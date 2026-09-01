<?php

/**
 * media-floor-check — a video with no pictures in it is a slideshow.
 *
 *   docker compose exec -T app php scratchpad/media-floor-check.php
 *
 * No API calls. The bench (iter 55) found six storyboards out of thirty with
 * not one image slot, three of them ordinary explainers — even though the
 * composer's prompt asks for a third and its critique() re-asks when they are
 * missing. ensureMediaFloor() is the guarantee behind that request.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $what . "\n";
}

/**
 * NB: the bullets are deliberately wordy. `mergeDeadAir` folds away any scene
 * carrying under six words of VISIBLE copy, so a fixture with "One line" in it
 * collapses into a single scene before this pass is ever reached — which is
 * itself worth knowing when writing any validator fixture.
 */
function textScene(int $i, float $secs, string $narration, array $bullets = [
    'The first supporting line of this card',
    'And a second line underneath it',
]): array
{
    return [
        'scene_id' => "scene_{$i}",
        'order' => $i,
        'layout_template' => 'single_focus',
        'duration_seconds' => $secs,
        'narration' => ['text' => $narration],
        'slots' => ['slot_main' => [
            'content_type' => 'text_block',
            'heading' => "Heading {$i}",
            'bullets' => $bullets,
        ]],
    ];
}

function run(array $scenes, bool $math = false): array
{
    return (new ShotListValidator())->validate(
        ['aspect_ratio' => '16:9', 'scenes' => $scenes],
        ['hook_enabled' => false, 'outro_enabled' => false, 'math_mode' => $math]
    );
}

function mediaScenes(array $scenes): int
{
    $n = 0;
    foreach ($scenes as $s) {
        foreach ((array) ($s['slots'] ?? []) as $slot) {
            if (is_array($slot) && in_array((string) ($slot['content_type'] ?? ''), ['image', 'video'], true)) {
                $n++;
                break;
            }
        }
    }

    return $n;
}

// A whole video of nothing but text cards — the failure the bench found.
$textOnly = [
    textScene(1, 6.0, 'Glass is the one packaging material that can go round forever without degrading.'),
    textScene(2, 11.0, 'A bottle is collected, sorted by colour, crushed into cullet and melted with sand and soda ash.'),
    textScene(3, 9.0, 'Mixed colour cullet can only become green glass, so one brown bottle downgrades the batch.'),
    textScene(4, 12.0, 'In Sweden over ninety percent of glass containers come back, collected separately by colour.'),
    textScene(5, 5.0, 'The material was never the problem. The bin was.'),
    textScene(6, 10.0, 'Every ten percent of cullet in the mix cuts the furnace energy by around three percent.'),
];

echo "\nthe floor\n";
$out = run($textOnly);
$n = mediaScenes($out['scenes']);
ok('a text-only storyboard gets pictures', $n >= 2);
ok('it reaches a third of the scenes', $n >= (int) ceil(count($out['scenes']) / 3));
ok('the warning says what happened', (bool) array_filter(
    $out['warnings'],
    fn ($w) => str_contains($w, 'text-only video')
));

echo "\nnothing is lost in the upgrade\n";
$upgraded = null;
foreach ($out['scenes'] as $s) {
    if ($s['layout_template'] === 'full_bleed_with_side_panel') {
        $upgraded = $s;
        break;
    }
}
ok('an upgraded scene exists', $upgraded !== null);
ok('its heading survived word for word',
    ($upgraded['slots']['slot_panel']['heading'] ?? '') !== ''
    && str_starts_with((string) $upgraded['slots']['slot_panel']['heading'], 'Heading'));
ok('its bullets survived', count((array) ($upgraded['slots']['slot_panel']['bullets'] ?? [])) >= 1);
ok('the picture is a real image slot',
    ($upgraded['slots']['slot_background']['content_type'] ?? '') === 'image');
ok('the shot is described, not left blank',
    str_word_count((string) ($upgraded['slots']['slot_background']['asset_request']['description'] ?? '')) >= 4);
ok('the user gets a searchable query with it',
    trim((string) ($upgraded['slots']['slot_background']['asset_request']['search_query'] ?? '')) !== '');
ok('the narration is untouched',
    (string) ($upgraded['narration']['text'] ?? '') !== ''
    && str_contains(implode(' ', array_column(array_column($textOnly, 'narration'), 'text')),
        (string) $upgraded['narration']['text']));

echo "\nrestraint\n";
$already = $textOnly;
$already[0]['layout_template'] = 'full_bleed_with_banner';
$already[0]['slots'] = [
    'slot_background' => ['content_type' => 'image', 'asset_request' => ['description' => 'a glass bottling line']],
    'slot_banner' => ['content_type' => 'text_block', 'heading' => 'Glass'],
];
$already[2]['layout_template'] = 'split_side_by_side';
$already[2]['slots'] = [
    'slot_left' => ['content_type' => 'image', 'asset_request' => ['description' => 'sorted cullet by colour']],
    'slot_right' => ['content_type' => 'text_block', 'heading' => 'Sorting', 'bullets' => ['One']],
];
$out2 = run($already);
$panels = count(array_filter($out2['scenes'], fn ($s) => $s['layout_template'] === 'full_bleed_with_side_panel'));
ok('a storyboard already at the floor is left alone', $panels === 0);

$out3 = run($textOnly, true);
ok('math mode is never given upload slots', mediaScenes($out3['scenes']) === 0);

$tiny = [textScene(1, 5.0, 'One short beat only.'), textScene(2, 5.0, 'And a second one.')];
ok('a two-scene storyboard is left alone', mediaScenes(run($tiny)['scenes']) === 0);

// Structured cards must never be rewritten to make room for a picture.
$structured = $textOnly;
$structured[1] = [
    'scene_id' => 'scene_2', 'order' => 2, 'layout_template' => 'animated_chart',
    'duration_seconds' => 11.0, 'narration' => ['text' => 'A third of glass containers get recycled in the United States.'],
    'slots' => ['slot_chart' => ['content_type' => 'chart', 'chart_type' => 'bar',
        'values' => [33, 90], 'labels' => ['United States', 'Sweden'], 'unit' => '%',
        'source' => 'OECD Global Plastics Outlook']],
];
$out4 = run($structured);
$chart = array_values(array_filter($out4['scenes'], fn ($s) => $s['layout_template'] === 'animated_chart'));
ok('a chart keeps its card', count($chart) === 1);
ok('and keeps its numbers',
    array_map('floatval', (array) ($chart[0]['slots']['slot_chart']['values'] ?? [])) === [33.0, 90.0]);

// No two upgraded panels back to back.
$consecutive = true;
$prev = '';
foreach ($out['scenes'] as $s) {
    if ($prev === 'full_bleed_with_side_panel' && $s['layout_template'] === 'full_bleed_with_side_panel') {
        $consecutive = false;
    }
    $prev = $s['layout_template'];
}
ok('no two upgraded panels run back to back', $consecutive);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
