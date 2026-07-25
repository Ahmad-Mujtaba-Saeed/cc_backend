<?php

/**
 * packaging-check — YouTube kit chapter/fallback rules (loop iter 11).
 * Pure statics, runs anywhere:
 *   php scratchpad/packaging-check.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Modules\Project\Services\YoutubePackagingService as Kit;

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

function scn(string $id, string $tpl, ?string $heading, string $narr = ''): array
{
    return [
        'scene_id' => $id,
        'layout_template' => $tpl,
        'narration' => $narr,
        'slots' => $heading !== null
            ? ['slot_main' => ['content_type' => 'text_block', 'heading' => $heading]]
            : ['slot_main' => ['content_type' => 'text_block']],
    ];
}

$win = fn (array $starts) => array_map(fn ($s) => ['start' => (float) $s, 'end' => (float) $s + 9], $starts);

// 1. Headed scenes become chapters; the first is pinned to 0:00.
$scenes = [
    scn('s1', 'single_focus', 'The Hook'),
    scn('s2', 'single_focus', null),
    scn('s3', 'animated_chart', 'The Numbers'),
    scn('s4', 'single_focus', 'The Fall'),
    scn('s5', 'outro_card', 'Thanks'),
];
$lines = Kit::chapterLines($scenes, $win(['s1' => 0, 's2' => 12, 's3' => 24, 's4' => 48, 's5' => 70]), null);
check('headed scenes become chapters', count($lines) === 3);
check('first chapter pinned to 0:00', str_starts_with($lines[0], '0:00 The Hook'));
check('outro never becomes a chapter', !str_contains(implode("\n", $lines), 'Thanks'));
check('timestamps carry labels', $lines[2] === '0:48 The Fall');

// 2. A late first beat gets an Intro chapter at 0:00.
$lines = Kit::chapterLines(
    [scn('s1', 'single_focus', null), scn('s2', 'single_focus', 'Act One'), scn('s3', 'single_focus', 'Act Two'), scn('s4', 'single_focus', 'Act Three')],
    $win(['s1' => 0, 's2' => 15, 's3' => 30, 's4' => 45]),
    null
);
check('late first beat earns an Intro at 0:00', $lines[0] === '0:00 Intro' && count($lines) === 4);

// 3. Chapters closer than 10s are dropped.
$lines = Kit::chapterLines(
    [scn('s1', 'single_focus', 'One'), scn('s2', 'single_focus', 'Too Close'), scn('s3', 'single_focus', 'Three'), scn('s4', 'single_focus', 'Four')],
    $win(['s1' => 0, 's2' => 4, 's3' => 15, 's4' => 30]),
    null
);
check('sub-10s chapters dropped', count($lines) === 3 && !str_contains(implode("\n", $lines), 'Too Close'));

// 4. Fewer than 3 legal chapters -> no block at all.
$lines = Kit::chapterLines(
    [scn('s1', 'single_focus', 'One'), scn('s2', 'single_focus', 'Two')],
    $win(['s1' => 0, 's2' => 20]),
    null
);
check('below 3 chapters the block is omitted', $lines === []);

// 5. With a chapter plan, only chapter-first scenes (and covers) open chapters.
$plan = ['chapters' => [
    ['scene_ids' => ['s1', 's2']],
    ['scene_ids' => ['s3', 's4']],
    ['scene_ids' => ['s5']],
]];
$scenes = [
    scn('s1', 'single_focus', 'Opening'),
    scn('s2', 'single_focus', 'Mid-chapter heading (not a chapter)'),
    scn('s3', 'chapter_cover', 'Act II'),
    scn('s4', 'single_focus', 'Another mid heading'),
    scn('s5', 'single_focus', 'The Verdict'),
];
$lines = Kit::chapterLines($scenes, $win(['s1' => 0, 's2' => 11, 's3' => 25, 's4' => 36, 's5' => 50]), $plan);
check('plan restricts chapters to chapter-first scenes', count($lines) === 3);
check('mid-chapter headings ignored under a plan', !str_contains(implode("\n", $lines), 'Mid-chapter'));
check('cover heading labels its chapter', str_contains($lines[1], 'Act II'));

// 6. Hour-long stamps switch to h:mm:ss.
$lines = Kit::chapterLines(
    [scn('s1', 'single_focus', 'One'), scn('s2', 'single_focus', 'Two'), scn('s3', 'single_focus', 'Three')],
    $win(['s1' => 0, 's2' => 62, 's3' => 3700]),
    null
);
check('stamps: 62s is 1:02, 3700s is 1:01:40', str_starts_with($lines[1], '1:02 ') && str_starts_with($lines[2], '1:01:40 '));

// 7. Deterministic fallback copy: hook opening + title tags.
$copy = Kit::fallbackCopy('Why PlayStation Crushed Xbox', [
    scn('s1', 'single_focus', null, 'PlayStation did not just win. It crushed Xbox completely. And here is exactly how it happened.'),
]);
check('fallback description = first two hook sentences', $copy['description'] === 'PlayStation did not just win. It crushed Xbox completely.');
check('fallback tags from the title + explainer', in_array('playstation', $copy['hashtags'], true) && in_array('explainer', $copy['hashtags'], true));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
