<?php

/**
 * vector-motif-live — ask the real model to draw, and write a shot list you
 * can render (loop iter 62).
 *
 * The deterministic check proves a bad drawing cannot hurt anything. This
 * proves the far more important thing the sanitiser cannot: that the drawings
 * are any GOOD. Print the shapes, then render the JSON it writes and look at
 * it — iter 53's lesson is that a model handed a canvas it cannot see will
 * confidently produce something unusable, and only a still tells you.
 *
 * Cost: one mini call per subject, roughly $0.001 each.
 *
 *   docker exec viralforgebackend-app-1 php scratchpad/vector-motif-live.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\VectorMotifService;

$cases = [
    [
        'subject' => 'a data packet hopping between three routers to reach a server',
        'narration' => 'Your request does not travel in a straight line. It hops from router to router, each one choosing the next step.',
        'topic' => 'How the internet actually delivers a page',
    ],
    [
        'subject' => 'a seed cracking open underground and pushing a shoot up through the soil',
        'narration' => 'Everything the seedling needs for its first days is already packed inside the seed.',
        'topic' => 'How a seed becomes a plant',
    ],
    [
        'subject' => 'sunlight hitting a solar panel and producing an electric current',
        'narration' => 'A photon knocks an electron loose, and that loose electron is the current.',
        'topic' => 'How a solar panel works',
    ],
];

$service = new VectorMotifService();
if (!$service->available()) {
    echo "No OpenAI key configured.\n";
    exit(1);
}

$scenes = [];
$order = 1;
foreach ($cases as $case) {
    $started = microtime(true);
    $motif = $service->draw($case['subject'], $case['narration'], $case['topic']);
    $took = round(microtime(true) - $started, 1);

    if ($motif === null) {
        echo "FAILED  {$case['subject']}  ({$took}s)\n";
        continue;
    }

    $kinds = [];
    foreach ($motif['shapes'] as $shape) {
        $kinds[$shape['kind']] = ($kinds[$shape['kind']] ?? 0) + 1;
    }
    $summary = [];
    foreach ($kinds as $kind => $n) {
        $summary[] = "{$n} {$kind}";
    }
    $cued = count(array_filter($motif['shapes'], fn ($s) => isset($s['at']) || isset($s['word'])));
    $accent = count(array_filter($motif['shapes'], fn ($s) => ($s['accent'] ?? null) === 'accent'
        || ($s['stroke'] ?? '') === 'accent' || ($s['fill'] ?? '') === 'accent'));

    echo "OK      {$case['subject']}  ({$took}s)\n";
    echo "        " . count($motif['shapes']) . ' shapes: ' . implode(', ', $summary) . "\n";
    echo "        {$cued} carry a timing cue, {$accent} use the accent"
        . ($motif['caption'] !== '' ? ", caption: \"{$motif['caption']}\"" : ', no caption') . "\n";

    $scenes[] = [
        'scene_id' => 'motif_' . $order,
        'order' => $order,
        'duration_seconds' => 9,
        'layout_template' => 'split_side_by_side',
        'transition' => 'fade',
        'narration' => ['text' => $case['narration']],
        'slots' => [
            'slot_left' => array_filter([
                'content_type' => 'vector_motif',
                'subject' => $case['subject'],
                'shapes' => $motif['shapes'],
                'caption' => $motif['caption'] ?: null,
            ]),
            'slot_right' => [
                'content_type' => 'text_block',
                'heading' => $case['topic'],
                'bullets' => [$case['subject']],
                'reveal' => 'sequential',
            ],
        ],
        'style' => ['variant' => 'editorial', 'kicker' => 'DRAWN'],
    ];
    $order++;
}

// ---------------------------------------------------------------------------
// The evolution pair (iter 63): one drawing continued into the next beat. The
// shapes that keep their ids are what earns the match cut between them.
// ---------------------------------------------------------------------------
$first = $service->draw(
    'a full water reservoir behind a dam wall',
    'The reservoir is full, and the water behind the wall is pure stored energy.',
    'How a hydroelectric dam makes power'
);

if ($first !== null) {
    $second = $service->evolve(
        $first['shapes'],
        'the same dam with a gate open, water falling through and spinning a turbine',
        'Open the gate and that stored energy becomes motion: falling water spins the turbine.',
        'How a hydroelectric dam makes power'
    );

    if ($second === null) {
        echo "\nEVOLVE FAILED — the second drawing did not survive.\n";
    } else {
        $shared = array_intersect(
            array_column($first['shapes'], 'id'),
            array_column($second['shapes'], 'id')
        );
        $moved = count(array_filter($second['shapes'], fn ($sh) => !empty($sh['then'])));
        echo "\nEVOLVED  " . count($first['shapes']) . ' shapes -> ' . count($second['shapes'])
            . ' shapes, ' . count($shared) . ' kept their id'
            . ($shared ? ' (' . implode(', ', array_slice($shared, 0, 6)) . ')' : '')
            . ", {$moved} carry a keyframe\n";
        echo '         match cut earned: ' . (count($shared) >= 2 ? 'YES' : 'no') . "\n";

        foreach ([['before', $first, 'a full reservoir behind a dam wall'],
                  ['after', $second, 'the gate open, water spinning a turbine']] as $k => [$label, $motif, $subject]) {
            $scenes[] = [
                'scene_id' => 'evolve_' . $k,
                'order' => $order,
                'duration_seconds' => 9,
                'layout_template' => 'split_side_by_side',
                // The second scene takes the match cut the pipeline would give
                // it, so the rendered file shows the real edit.
                'transition' => $k === 1 && count($shared) >= 2 ? 'match_dissolve' : 'fade',
                'narration' => ['text' => $subject],
                'slots' => [
                    'slot_left' => array_filter([
                        'content_type' => 'vector_motif',
                        'subject' => $subject,
                        'shapes' => $motif['shapes'],
                        'caption' => $motif['caption'] ?: null,
                    ]),
                    'slot_right' => [
                        'content_type' => 'text_block',
                        'heading' => 'How a dam makes power',
                        'bullets' => [ucfirst($subject)],
                        'reveal' => 'sequential',
                    ],
                ],
                'style' => ['variant' => 'editorial', 'kicker' => strtoupper($label)],
            ];
            $order++;
        }
    }
}

$out = [
    'project_id' => 'motif-live',
    'aspect_ratio' => '16:9',
    'composition_mode' => 'slides',
    'theme' => [
        'name' => 'indigo', 'label' => 'Indigo Pop',
        'bg_from' => '#0b1026', 'bg_to' => '#171f4a',
        'accent' => '#6366f1', 'accent2' => '#22d3ee',
        'text' => '#eef2ff', 'muted' => '#a6acd6', 'panel' => 'rgba(11,16,38,0.62)',
    ],
    'scenes' => $scenes,
];

$path = __DIR__ . '/motif-live.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "\nWrote {$path} — render it to see the drawings.\n";
