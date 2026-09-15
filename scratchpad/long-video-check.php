<?php

/**
 * long-video-check — the length slider now reaches six minutes, and nothing
 * downstream may quietly cap the video back to three.
 *
 * Deterministic halves only (no LLM, no DB): the phase caps that decide how
 * many scenes a length can hold, the script window every planning call reads
 * through, and the windowing that keeps a long board inside one call's output
 * budget. The prompt-side halves are exercised by generating a real script.
 *
 *   php scratchpad/long-video-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Modules\Project\Services\ScriptSkeletonService;

// Nothing here talks to a model, but the services read the admin's model
// switch in their constructors. Seed it so this check runs without a DB.
config(['cache.default' => 'array']);
Cache::store('array')->forever('app_setting:llm_model', '__null__');

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

$sk = new ScriptSkeletonService();

/** A planner reply with more repeats than any cap would keep. */
$flood = function (string $intent, int $n): array {
    $out = [['intent' => 'hook', 'brief' => 'the opening']];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = ['intent' => $intent, 'brief' => "beat {$i}"];
    }
    $out[] = ['intent' => 'payoff', 'brief' => 'the close'];

    return $out;
};

$countOf = fn (array $phases, string $intent): int => count(array_filter(
    $phases,
    fn ($p) => $p['intent'] === $intent
));

// ------------------------------------------------- short videos are untouched
$short = $sk->repairGeneric('argument', $flood('point', 30), '', 60);
check('60s keeps the tuned cap of 5 points', $countOf($short, 'point') === 5);

$noTarget = $sk->repairGeneric('argument', $flood('point', 30));
check('an unstated target behaves exactly as 60s did', $countOf($noTarget, 'point') === 5);

check('90s — still the tuned band — is unchanged', $countOf($sk->repairGeneric('argument', $flood('point', 30), '', 90), 'point') === 5);

// ------------------------------------------------ long videos get more phases
$long = $sk->repairGeneric('argument', $flood('point', 40), '', 360);
check('360s lets the argument make 20 points', $countOf($long, 'point') === 20);
check('360s still has exactly one hook', $countOf($long, 'hook') === 1);
check('360s still has exactly one payoff', $countOf($long, 'payoff') === 1);

$phases = count($long);
$perScene = 360 / max(1, $phases);
check(
    sprintf('360s plans %d phases — %.1fs a scene, not a slideshow', $phases, $perScene),
    $perScene <= 16.0
);

// `compare` rather than `journey`: a journey with no dates in its script is
// demoted to `generic` before the caps are even read, which is its own
// (correct) behaviour and would mask what this line is checking.
$compare = $sk->repairGeneric('compare', $flood('round', 40), '', 360);
check('360s compare gets 16 rounds', $countOf($compare, 'round') === 16);

// The repeat scale must be monotonic: a longer video never plans FEWER beats.
$last = 0;
$monotonic = true;
foreach ([60, 90, 120, 180, 240, 300, 360] as $seconds) {
    $n = count($sk->repairGeneric('generic', $flood('aspect', 40), '', $seconds));
    $monotonic = $monotonic && $n >= $last;
    $last = $n;
}
check('phase counts never go DOWN as the video gets longer', $monotonic);

// --------------------------------------------------------- the script window
check('the script window covers a 60s script', ScriptSkeletonService::scriptWindow(60) >= 2400);
check('a 60s video reads the same 2400 chars it always did', ScriptSkeletonService::scriptWindow(60) === 2400);
// ~2.5 words/s at ~6 chars a word is ~15 chars a second of speech; the window
// has to clear that with room for the retry draft's overshoot.
check('the window clears a 360s script (~5400 chars)', ScriptSkeletonService::scriptWindow(360) >= 5400);

// ------------------------------------------------------------- call windowing
$ref = new ReflectionClass(\Modules\Project\Services\GenericStoryboardComposerService::class);
$perCall = $ref->getConstant('PHASES_PER_CALL');
check('a composing call writes at most 16 phases', $perCall === 16);
check(
    'the biggest skeleton the old slider could plan still fits ONE call',
    // demo: hook, problem, product_intro, 8x demo_step, result, 3x second_feature, payoff
    (1 + 1 + 1 + 8 + 1 + 3 + 1) <= $perCall
);

$windows = array_chunk(range(0, $phases - 1), $perCall, true);
check(
    sprintf('a %d-phase board splits into %d calls with global phase numbers', $phases, count($windows)),
    count($windows) >= 2
        && array_key_first($windows[1]) === $perCall
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
