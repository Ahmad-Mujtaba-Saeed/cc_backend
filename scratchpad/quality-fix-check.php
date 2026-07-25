<?php

/**
 * quality-fix-check — the project-91 quality-check fixes (loop iter 30):
 * (1) prompt-constraint HEADINGS are scrubbed and re-derived from narration
 *     (the bullet scrub existed since v26; "≤40" shipped as a heading);
 * (2) flat_pacing warns on genuinely flat spans (static seconds / 3+ card
 *     monotony), not on any two text scenes whose bullets are still landing;
 * (3) static_hold behaviour unchanged — it is the check that catches the
 *     single-bullet 10s claim card, now structurally fixed in composeProof.
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/quality-fix-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

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

$validator = new ShotListValidator();
$textSlot = function (array $slot, string $narration) use ($validator): array {
    $m = new ReflectionMethod($validator, 'validateTextSlot');
    $m->setAccessible(true);

    return $m->invoke($validator, 's1', 'slot_main', $slot, $narration);
};

// ---- 1. Heading scrub -------------------------------------------------------
$narr = 'The distance formula is essential for measuring space between two points. It allows us to calculate the distance using coordinates.';
$out = $textSlot(['heading' => '≤40', 'bullets' => ['A real bullet', 'Another one']], $narr);
check('"≤40" heading scrubbed + derived from narration', $out['heading'] === 'The distance formula is essential');
check('derived heading never dangles on a stopword', !preg_match('/\s(?:for|and|of|to|the|is)$/i', $out['heading']));
$out = $textSlot(['heading' => '<= 60 chars', 'bullets' => ['A', 'B']], $narr);
check('"<= 60 chars" heading scrubbed too', str_starts_with($out['heading'], 'The distance formula'));
$out = $textSlot(['heading' => 'Need to Measure Distance?', 'bullets' => ['A', 'B']], $narr);
check('legit heading untouched', $out['heading'] === 'Need to Measure Distance?');
$out = $textSlot(['heading' => '2026', 'bullets' => ['A', 'B']], $narr);
check('a bare year is NOT treated as an artifact', $out['heading'] === '2026');
$out = $textSlot(['heading' => '≤40', 'bullets' => ['A', 'B']], '');
check('artifact heading with no narration falls to Key Points', $out['heading'] === 'Key Points');

$derive = function (string $narrText) use ($validator): string {
    $m = new ReflectionMethod($validator, 'headingFromNarration');
    $m->setAccessible(true);

    return $m->invoke($validator, $narrText);
};
check('short sentence keeps itself, punctuation dropped', $derive('Here is the trick!') === 'Here is the trick');
$long = $derive('This opening sentence is deliberately much longer than forty characters in total. Second.');
check('long sentence clips <=40 at a word boundary', mb_strlen($long) <= 40 && !str_contains($long, '  ') && str_ends_with($long, mb_substr($long, mb_strrpos($long, ' ') + 1)));

// ---- 2/3. Linter matrix -----------------------------------------------------
$scene = fn (string $id, float $dur, array $bullets) => [
    'scene_id' => $id, 'layout_template' => 'single_focus', 'duration_seconds' => $dur,
    'narration' => ['text' => 'Narration long enough that the word-count lints stay out of the picture here.'],
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'H', 'bullets' => $bullets, 'reveal' => 'sequential']],
];
$codes = function (array $scenes, string $code): array {
    $report = SceneBudgetLinter::lint($scenes, ['hook_enabled' => false, 'outro_enabled' => false, 'math_mode' => false]);

    return array_values(array_filter($report['items'] ?? [], fn ($i) => ($i['code'] ?? '') === $code));
};

// project 91's ORIGINAL top: hook (3 bullets) + claim (1 bullet).
$orig = [$scene('scene_1', 11.65, ['a', 'b', 'c']), $scene('scene_2', 9.45, ['only one'])];
check('original claim still fires static_hold (1 bullet, 9.45s)', count($codes($orig, 'static_hold')) === 1);
check('original pair no longer fires flat_pacing (9.45s static < 12)', $codes($orig, 'flat_pacing') === []);

// project 91's REPAIRED top: both scenes carry >=2 reveal bullets.
$fixed = [$scene('scene_1', 11.65, ['a', 'b', 'c']), $scene('scene_2', 9.45, ['statement', 'plain words'])];
check('repaired pair is clean of both warnings', $codes($fixed, 'static_hold') === [] && $codes($fixed, 'flat_pacing') === []);

// Genuinely flat: two long single-bullet holds.
$flat = [$scene('a', 11, ['x']), $scene('b', 11, ['y'])];
check('two no-reveal scenes at 22s still warn flat_pacing', count($codes($flat, 'flat_pacing')) === 1);
check('...with the static seconds named in the message', str_contains($codes($flat, 'flat_pacing')[0]['message'] ?? '', '22s with no reveal'));

// Monotony: three same cards past 20s warn even with live reveals.
$mono = [$scene('a', 7, ['x', 'y']), $scene('b', 7, ['x', 'y']), $scene('c', 7, ['x', 'y'])];
check('three same-template scenes at 21s warn (monotony)', count($codes($mono, 'flat_pacing')) === 1);

// Two long scenes with live reveals: the old rule fired here and the
// message ("nothing new on screen") was simply false — pinned as clean.
$lively = [$scene('a', 12.5, ['x', 'y', 'z']), $scene('b', 12.5, ['x', 'y'])];
check('two reveal-driven scenes at 25s stay clean', $codes($lively, 'flat_pacing') === []);

// Span break: a media scene between text scenes resets the span.
$broken = [$scene('a', 11, ['x']), [
    'scene_id' => 'img', 'layout_template' => 'single_focus', 'duration_seconds' => 6,
    'narration' => ['text' => 'An image beat.'],
    'slots' => ['slot_main' => ['content_type' => 'image', 'asset_ref' => ['url' => 'x.jpg']]],
], $scene('b', 11, ['y'])];
check('a media beat between holds breaks the span', $codes($broken, 'flat_pacing') === []);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
