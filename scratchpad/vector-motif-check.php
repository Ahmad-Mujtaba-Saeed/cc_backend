<?php

/**
 * vector-motif-check — the spec, the repair and the wiring for drawn motifs
 * (loop iter 62).
 *
 * The whole feature rests on one claim: a model may be sloppy about anything
 * except intent, because `Support\VectorMotif` guarantees the result is
 * renderable. Every assertion here is that claim, stated as a case that would
 * otherwise reach the render browser.
 *
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/vector-motif-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ShotListValidator;
use Modules\Project\Support\VectorMotif;

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

$icons = array_flip(ExplainerRegistry::iconNames());
$exists = static fn (string $n) => isset($icons[$n]);
$sane = static fn (array $raw) => VectorMotif::sanitize($raw, $exists);

// ---------------------------------------------------------------------------
// A good drawing survives intact.
// ---------------------------------------------------------------------------
$good = ['shapes' => [
    ['kind' => 'path', 'd' => 'M 20 20 L 40 20 L 40 60 Z', 'stroke' => 'ink', 'anim' => 'draw', 'at' => 0],
    ['kind' => 'circle', 'cx' => 60, 'cy' => 40, 'r' => 12, 'stroke' => 'accent', 'at' => 0.4],
    ['kind' => 'label', 'text' => 'core', 'x' => 60, 'y' => 60, 'fill' => 'ink'],
], 'caption' => 'the core'];
$r = $sane($good);
check('a good motif is accepted', $r['ok'] === true);
check('every shape survives', count($r['shapes']) === 3);
check('the caption survives', $r['caption'] === 'the core');
check('ids are assigned when missing', ($r['shapes'][0]['id'] ?? '') !== '');

// ---------------------------------------------------------------------------
// Numbers. The model cannot see its output, so every coordinate is suspect.
// ---------------------------------------------------------------------------
$r = $sane(['shapes' => [
    ['kind' => 'circle', 'cx' => 9999, 'cy' => -500, 'r' => 400, 'stroke' => 'ink'],
    ['kind' => 'rect', 'x' => 10, 'y' => 10, 'w' => -50, 'h' => 'wide', 'stroke' => 'ink'],
]]);
check('a runaway coordinate is clamped into the view', $r['shapes'][0]['cx'] <= 110 && $r['shapes'][0]['cy'] >= -10);
check('a radius bigger than the view is clamped', $r['shapes'][0]['r'] <= 50);
check('a negative width becomes a real one', $r['shapes'][1]['w'] >= 1);
check('a non-numeric size falls back', is_float($r['shapes'][1]['h']) && $r['shapes'][1]['h'] > 0);

$r = $sane(['shapes' => [
    ['kind' => 'circle', 'cx' => NAN, 'cy' => INF, 'r' => 10, 'stroke' => 'ink'],
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'ink'],
]]);
check('NAN/INF never reach the renderer', is_finite($r['shapes'][0]['cx']) && is_finite($r['shapes'][0]['cy']));

// ---------------------------------------------------------------------------
// The closed vocabulary. Anything invented is dropped or defaulted.
// ---------------------------------------------------------------------------
$r = $sane(['shapes' => [
    ['kind' => 'star', 'x' => 10, 'y' => 10],
    ['kind' => 'polygon', 'points' => '1,2 3,4'],
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'ink'],
    ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 20, 'h' => 20, 'stroke' => 'ink'],
]]);
check('an invented primitive is dropped', count($r['shapes']) === 2);

$r = $sane(['shapes' => [
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => '#ff0000', 'fill' => 'rgba(1,2,3,.5)'],
    ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 20, 'h' => 20, 'stroke' => 'ink'],
]]);
check('a literal colour is refused', $r['shapes'][0]['stroke'] === 'ink' && $r['shapes'][0]['fill'] === 'none');

$r = $sane(['shapes' => [
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'ink', 'anim' => 'explode', 'life' => 'wobble'],
    ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 20, 'h' => 20, 'stroke' => 'ink'],
]]);
check('an invented anim falls back to a real one', in_array($r['shapes'][0]['anim'], VectorMotif::ANIMS, true));
check('an invented life is dropped entirely', !isset($r['shapes'][0]['life']));

$r = $sane(['shapes' => [
    ['kind' => 'line', 'x1' => 10, 'y1' => 10, 'x2' => 60, 'y2' => 60, 'stroke' => 'ink'],
    ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 20, 'h' => 20, 'stroke' => 'ink'],
]]);
check('a stroke defaults to being drawn', $r['shapes'][0]['anim'] === 'draw');
check('a solid defaults to popping', $r['shapes'][1]['anim'] === 'pop');

// ---------------------------------------------------------------------------
// Paths are the only free-form string in the spec.
// ---------------------------------------------------------------------------
$r = $sane(['shapes' => [
    ['kind' => 'path', 'd' => 'M 10 10 L 20 20 C 30 30 40 40 50 50 Z', 'stroke' => 'ink'],
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'ink'],
]]);
check('a plain geometry path survives', count($r['shapes']) === 2);

foreach ([
    'a path with markup' => 'M 10 10 L 20 20 " onload="alert(1)',
    'a path with a url' => 'M 10 10 url(#x)',
    'a path with a script scheme' => 'javascript:alert(1)',
    'an empty path' => '',
] as $label => $d) {
    $r = $sane(['shapes' => [
        ['kind' => 'path', 'd' => $d, 'stroke' => 'ink'],
        ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'ink'],
        ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 10, 'h' => 10, 'stroke' => 'ink'],
    ]]);
    check("{$label} is dropped", count($r['shapes']) === 2);
}
$r = $sane(['shapes' => [
    ['kind' => 'path', 'd' => str_repeat('M 1 1 L 2 2 ', 60), 'stroke' => 'ink'],
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'ink'],
    ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 10, 'h' => 10, 'stroke' => 'ink'],
]]);
check('an enormous path is dropped', count($r['shapes']) === 2);

// ---------------------------------------------------------------------------
// Icons ride the registry whitelist the renderer actually bundles.
// ---------------------------------------------------------------------------
$r = $sane(['shapes' => [
    ['kind' => 'icon', 'name' => 'flame', 'x' => 50, 'y' => 50, 'size' => 20, 'stroke' => 'accent'],
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'ink'],
]]);
check('a real icon survives', count($r['shapes']) === 2);

$r = $sane(['shapes' => [
    ['kind' => 'icon', 'name' => 'glass-bottle-of-milk', 'x' => 50, 'y' => 50, 'stroke' => 'ink'],
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'ink'],
    ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 10, 'h' => 10, 'stroke' => 'ink'],
]]);
check('an icon the renderer does not bundle is dropped', count($r['shapes']) === 2);

// ---------------------------------------------------------------------------
// Things that would render as nothing, or as too much.
// ---------------------------------------------------------------------------
$r = $sane(['shapes' => [
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 8, 'stroke' => 'none', 'fill' => 'none'],
    ['kind' => 'circle', 'cx' => 40, 'cy' => 40, 'r' => 8, 'stroke' => 'ink'],
    ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 10, 'h' => 10, 'stroke' => 'ink'],
]]);
check('an invisible shape is dropped', count($r['shapes']) === 2);

$r = $sane(['shapes' => [
    ['kind' => 'line', 'x1' => 30, 'y1' => 30, 'x2' => 30, 'y2' => 30, 'stroke' => 'ink'],
    ['kind' => 'circle', 'cx' => 40, 'cy' => 40, 'r' => 8, 'stroke' => 'ink'],
    ['kind' => 'rect', 'x' => 5, 'y' => 5, 'w' => 10, 'h' => 10, 'stroke' => 'ink'],
]]);
check('a zero-length line is dropped', count($r['shapes']) === 2);

$many = [];
for ($i = 0; $i < 40; $i++) {
    $many[] = ['kind' => 'circle', 'cx' => $i, 'cy' => 20, 'r' => 3, 'stroke' => 'ink'];
}
$r = $sane(['shapes' => $many]);
check('the shape count is capped', count($r['shapes']) === VectorMotif::MAX_SHAPES);

$labels = [];
for ($i = 0; $i < 12; $i++) {
    $labels[] = ['kind' => 'label', 'text' => "label {$i}", 'x' => 10, 'y' => 10 + $i * 5];
}
$labels[] = ['kind' => 'circle', 'cx' => 40, 'cy' => 40, 'r' => 8, 'stroke' => 'ink'];
$r = $sane(['shapes' => $labels]);
$labelCount = count(array_filter($r['shapes'], fn ($s) => $s['kind'] === 'label'));
check('labels are capped', $labelCount === VectorMotif::MAX_LABELS);

$r = $sane(['shapes' => [
    ['kind' => 'label', 'text' => 'a really very extremely long label that runs on and on', 'x' => 10, 'y' => 10],
    ['kind' => 'circle', 'cx' => 40, 'cy' => 40, 'r' => 8, 'stroke' => 'ink'],
]]);
check('a long label is truncated', mb_strlen($r['shapes'][0]['text']) <= VectorMotif::MAX_LABEL_CHARS);

// A wall of text with nothing drawn is a text block wearing a costume.
$r = $sane(['shapes' => [
    ['kind' => 'label', 'text' => 'one', 'x' => 10, 'y' => 10],
    ['kind' => 'label', 'text' => 'two', 'x' => 10, 'y' => 20],
]]);
check('labels alone are not a drawing', $r['ok'] === false);

$r = $sane(['shapes' => [['kind' => 'circle', 'cx' => 40, 'cy' => 40, 'r' => 8, 'stroke' => 'ink']]]);
check('one lone shape is not a drawing', $r['ok'] === false);

$r = $sane(['shapes' => 'a bottle and a jar']);
check('a non-array shapes field is refused', $r['ok'] === false);
$r = $sane([]);
check('an empty payload is refused', $r['ok'] === false);

// ---------------------------------------------------------------------------
// Ids and timing.
// ---------------------------------------------------------------------------
$r = $sane(['shapes' => [
    ['id' => 'x', 'kind' => 'circle', 'cx' => 10, 'cy' => 10, 'r' => 5, 'stroke' => 'ink'],
    ['id' => 'x', 'kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 5, 'stroke' => 'ink'],
    ['id' => 'x', 'kind' => 'circle', 'cx' => 50, 'cy' => 50, 'r' => 5, 'stroke' => 'ink'],
]]);
$ids = array_column($r['shapes'], 'id');
check('duplicate ids are made unique', count(array_unique($ids)) === 3);

$r = $sane(['shapes' => [
    ['kind' => 'circle', 'cx' => 10, 'cy' => 10, 'r' => 5, 'stroke' => 'ink', 'at' => 0.99],
    ['kind' => 'circle', 'cx' => 30, 'cy' => 30, 'r' => 5, 'stroke' => 'ink', 'at' => -2],
]]);
check('a reveal nobody would see is pulled back', $r['shapes'][0]['at'] <= 0.85);
check('a negative cue becomes the start', $r['shapes'][1]['at'] === 0.0);

// ---------------------------------------------------------------------------
// Label de-collision: the defect all three of the first live drawings had.
// ---------------------------------------------------------------------------
$r = $sane(['shapes' => [
    // A label straddling the bottom edge of a panel, the "solar panel" case.
    ['kind' => 'rect', 'x' => 20, 'y' => 30, 'w' => 50, 'h' => 25, 'stroke' => 'ink', 'fill' => 'muted'],
    ['kind' => 'label', 'text' => 'solar panel', 'x' => 45, 'y' => 56, 'size' => 6],
]]);
$label = $r['shapes'][1];
$box = ['y0' => $label['y'] - $label['size'], 'y1' => $label['y'] + $label['size'] * 0.35];
check('a label straddling an edge is pushed clear', $box['y0'] >= 55 || $box['y1'] <= 30);

// A label deliberately INSIDE a big shape is left where it is.
$r = $sane(['shapes' => [
    ['kind' => 'rect', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 60, 'stroke' => 'ink', 'fill' => 'none'],
    ['kind' => 'label', 'text' => 'inside', 'x' => 50, 'y' => 40, 'size' => 5],
]]);
check('a label wholly inside a shape is left alone', $r['shapes'][1]['y'] === 40.0);

// A label clear of everything does not move.
$r = $sane(['shapes' => [
    ['kind' => 'circle', 'cx' => 25, 'cy' => 25, 'r' => 10, 'stroke' => 'ink'],
    ['kind' => 'label', 'text' => 'clear', 'x' => 25, 'y' => 60, 'size' => 5],
]]);
check('a label with clearance is untouched', $r['shapes'][1]['y'] === 60.0);

// Nudged labels stay in the view.
$r = $sane(['shapes' => [
    ['kind' => 'rect', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 96, 'stroke' => 'ink', 'fill' => 'muted'],
    ['kind' => 'label', 'text' => 'edge', 'x' => 50, 'y' => 97, 'size' => 6],
]]);
check('a nudged label stays inside the view', $r['shapes'][1]['y'] <= VectorMotif::VIEW + 6);

// ---------------------------------------------------------------------------
// The validator: both states of a motif slot, and the degrade.
// ---------------------------------------------------------------------------
$validator = new ShotListValidator();
$build = static function (array $slot) use ($validator): array {
    // Scene 1 is rewritten into the cold-open hook, so the slot under test is
    // the SECOND scene — a one-scene fixture measures the opener promotion,
    // not the motif.
    $out = $validator->validate([
        'aspect_ratio' => '16:9',
        'scenes' => [
            [
                'scene_id' => 's0',
                'order' => 1,
                'duration_seconds' => 5,
                'layout_template' => 'single_focus',
                'narration' => ['text' => 'Glass is everywhere.'],
                'slots' => ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => 'Glass is everywhere',
                    'bullets' => ['It never wears out'],
                ]],
            ],
            [
                'scene_id' => 's1',
                'order' => 2,
                'duration_seconds' => 6,
                'layout_template' => 'single_focus',
                'narration' => ['text' => 'A bottle becomes a jar.'],
                'slots' => ['slot_main' => $slot],
            ],
        ],
    ]);

    return $out['scenes'][1]['slots']['slot_main'] ?? [];
};

$slot = $build(['content_type' => 'vector_motif', 'subject' => 'a bottle melted into a jar']);
check('a PENDING motif (subject, no shapes) is kept', ($slot['content_type'] ?? '') === 'vector_motif');
check('the pending subject survives validation', ($slot['subject'] ?? '') !== '');

$slot = $build([
    'content_type' => 'vector_motif',
    'subject' => 'a bottle melted into a jar',
    'shapes' => $good['shapes'],
    'caption' => 'the core',
]);
check('a DRAWN motif keeps its shapes', count($slot['shapes'] ?? []) === 3);
check('a drawn motif keeps its caption', ($slot['caption'] ?? '') === 'the core');

$slot = $build(['content_type' => 'vector_motif']);
check('a motif with neither subject nor shapes degrades to text', ($slot['content_type'] ?? '') === 'text_block');

$slot = $build([
    'content_type' => 'vector_motif',
    'subject' => 'a bottle melted into a jar',
    'shapes' => [['kind' => 'label', 'text' => 'only words', 'x' => 5, 'y' => 5]],
]);
check(
    'an unusable drawing falls back to the pending request, not to text',
    ($slot['content_type'] ?? '') === 'vector_motif' && !isset($slot['shapes'])
);

// ---------------------------------------------------------------------------
// Registry wiring: the vocabulary the model is told about must be the
// vocabulary the code enforces.
// ---------------------------------------------------------------------------
$types = ExplainerRegistry::contentTypes();
check('the registry declares vector_motif', isset($types['vector_motif']));
check('it requires a subject', ($types['vector_motif']['required'] ?? []) === ['subject']);
check('there is a per-video cap', ExplainerRegistry::maxVectorMotifs() >= 1);

$templates = ExplainerRegistry::templates();
$allowed = static fn (string $t, string $slot) => in_array(
    'vector_motif',
    $templates[$t]['slots'][$slot]['allowed'] ?? [],
    true
);
check('single_focus accepts a motif', $allowed('single_focus', 'slot_main'));
check('both halves of a split accept one', $allowed('split_side_by_side', 'slot_left') && $allowed('split_side_by_side', 'slot_right'));
check('split_top_bottom accepts one', $allowed('split_top_bottom', 'slot_top'));
check(
    'the prompt tells the model about it',
    str_contains(ExplainerRegistry::promptReference(), 'vector_motif')
);

// ---------------------------------------------------------------------------
// The render-time guarantee: a motif that never got drawn must never reach the
// renderer as an empty box. The assembler is the last stop before the payload,
// which is why the guard lives there and not only in the analyze pass.
// ---------------------------------------------------------------------------
$assembler = new ReflectionClass(Modules\Project\Support\ExplainerSceneAssembler::class);
$source = file_get_contents($assembler->getFileName());
check(
    'the assembler degrades an undrawn motif to a picture request',
    str_contains($source, "'vector_motif'") && str_contains($source, "'content_type' => 'image'")
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
