<?php

/**
 * math-routing-check — the guard that keeps science scripts off the board.
 *
 *   docker compose exec -T app php scratchpad/math-routing-check.php
 *   docker compose exec -T app php scratchpad/math-routing-check.php --live
 *
 * Bench iter 55 found the most expensive misroute in the pipeline: a script
 * about a THING (a headphone, an atom, a bank account) that happens to state
 * an equation was classified `proof_concept`, composed by composeProof, and
 * came back as one 51-72 second figure with no pictures in the video at all.
 *
 * Two halves are checked here:
 *   1. MathTopicService::looksLikeMathArgument — the deterministic gate on
 *      `proof_concept`. Offline, free, and the part that must never regress.
 *   2. ShotListValidator::mergeEvolvingPlots — the run cap and the narration
 *      timing that stop a merged figure owning the whole video.
 *
 * --live also runs the real classifier over the bench scripts (7 calls).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\MathTopicService;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $what . "\n";
}

$cases = require __DIR__ . '/bench/corpus.php';
$script = function (string $id) use ($cases): string {
    foreach ($cases as $c) {
        if ($c['id'] === $id) {
            return $c['title'] . ' ' . $c['script'];
        }
    }
    throw new \RuntimeException("no bench case {$id}");
};

echo "\nthe gate — a proof must argue mathematics in its own words\n";

// The three the bench caught. Every one states an equation and draws a curve,
// so the gate cannot lean on either.
foreach (['noise-cancelling', 'science-half-life', 'compound-interest'] as $id) {
    ok("{$id} is NOT a mathematical argument",
        !MathTopicService::looksLikeMathArgument($script($id)));
}

// The ones that must survive it.
ok('the Pythagoras proof IS one (triangle, hypotenuse, squares)',
    MathTopicService::looksLikeMathArgument($script('math-pythagoras')));
ok('a quadratic solve IS one (factors, middle term, brackets)',
    MathTopicService::looksLikeMathArgument($script('math-quadratic')));

// Concept videos with no numbers on screen at all — the class the classifier
// was built for in the first place (v18).
ok('"What is a derivative, visually?" survives',
    MathTopicService::looksLikeMathArgument('What is a derivative, visually? The derivative is the slope of the tangent line at a point.'));
ok('"The beauty of Euler\'s identity" survives',
    MathTopicService::looksLikeMathArgument("The beauty of Euler's identity. This identity ties five constants together."));
ok('a trig proof survives',
    MathTopicService::looksLikeMathArgument('Why sin squared plus cos squared equals one. Draw the unit circle and read the triangle.'));
ok('a geometry area explainer survives',
    MathTopicService::looksLikeMathArgument('Why is the area of a circle pi r squared? Cut it into wedges and lay them out.'));

// Ordinary explainers must not accidentally pass the gate either — the gate
// only ever runs after the classifier said "maths", but a gate that passes
// everything is not a gate.
foreach (['city-flooding', 'front-doors', 'vreato-demo', 'plastic-recycled'] as $id) {
    ok("{$id} does not read as a mathematical argument",
        !MathTopicService::looksLikeMathArgument($script($id)));
}

echo "\nthe merge — one figure may not own the video\n";

/** Six consecutive plots of the same curve, each padded to 12s by the composer. */
$plotScenes = [];
for ($i = 1; $i <= 6; $i++) {
    $plotScenes[] = [
        'scene_id' => "scene_{$i}",
        'order' => $i,
        'layout_template' => 'function_plot',
        'duration_seconds' => 12,
        'narration' => ['text' => "Beat {$i} of the same curve, said in about twelve words so it has a real length."],
        'slots' => ['slot_plot' => ['content_type' => 'function_plot', 'expression' => '2^x', 'heading' => 'Growth']],
    ];
}
$validated = (new ShotListValidator())->validate(
    ['aspect_ratio' => '16:9', 'scenes' => $plotScenes],
    ['hook_enabled' => false, 'outro_enabled' => false, 'math_mode' => true]
);
$scenes = $validated['scenes'];
$plots = array_values(array_filter($scenes, fn ($s) => $s['layout_template'] === 'function_plot'));
$longest = max(array_map(fn ($s) => (float) $s['duration_seconds'], $scenes));

ok('six identical plots do not collapse into one card', count($plots) >= 2);
ok('no merged figure runs past 30s', $longest <= 30.0);
ok('the merge still happens (six scenes did not survive as six)', count($scenes) < 6);
ok('the merged card is timed from its narration, not the sum of padded parts',
    $longest < 6 * 12);

/** Two plots of DIFFERENT curves must not merge at all. */
$twoCurves = [
    array_replace_recursive($plotScenes[0], ['slots' => ['slot_plot' => ['expression' => '2^x']]]),
    array_replace_recursive($plotScenes[1], ['slots' => ['slot_plot' => ['expression' => 'x^2']]]),
];
$out = (new ShotListValidator())->validate(
    ['aspect_ratio' => '16:9', 'scenes' => $twoCurves],
    ['hook_enabled' => false, 'outro_enabled' => false, 'math_mode' => true]
);
ok('different curves are left alone', count($out['scenes']) === 2);

/** Outside math mode nothing merges, exactly as before. */
$out = (new ShotListValidator())->validate(
    ['aspect_ratio' => '16:9', 'scenes' => $plotScenes],
    ['hook_enabled' => false, 'outro_enabled' => false, 'math_mode' => false]
);
ok('no merging outside math mode', count($out['scenes']) === 6);

if (in_array('--live', $argv, true)) {
    echo "\nlive classifier (7 calls)\n";
    $svc = new MathTopicService();
    $expect = [
        'noise-cancelling' => false,
        'science-half-life' => false,
        'compound-interest' => false,
        'math-pythagoras' => true,
        'math-quadratic' => true,
        'city-flooding' => false,
        'water-cycle' => false,
    ];
    foreach ($expect as $id => $wantMath) {
        $case = null;
        foreach ($cases as $c) {
            if ($c['id'] === $id) {
                $case = $c;
            }
        }
        $topic = $svc->classify($case['title'], $case['script']);
        $isMath = $topic !== [];
        ok(sprintf('%-20s routed %s%s', $id, $isMath ? 'MATH' : 'explainer',
            $isMath ? ' (' . ($topic['kind'] ?? '?') . ')' : ''), $isMath === $wantMath);
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
