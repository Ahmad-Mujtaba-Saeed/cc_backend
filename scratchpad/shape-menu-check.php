<?php

/**
 * shape-menu-check — the story spine, and the cards it puts out of reach.
 *
 *   docker compose exec -T app php scratchpad/shape-menu-check.php
 *
 * No API calls, no cost. Two defects from the iter-55 bench:
 *
 *  1. A journey with no TIME in it. The water cycle and the atmosphere's five
 *     layers were both planned `journey`, whose `era` menu offers neither
 *     cycle_diagram nor layer_stack — so both beats landed on a MAP CARD.
 *  2. custom_card cast zero times in thirty scripts, including the two written
 *     for it. The composer's critique now names it once when the script is
 *     plainly about a drawable object.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptSkeletonService;

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
            return $c['script'];
        }
    }
    throw new \RuntimeException("no bench case {$id}");
};

$planner = new ScriptSkeletonService();
$journey = [
    ['intent' => 'hook', 'brief' => 'the loop everyone half remembers'],
    ['intent' => 'origin', 'brief' => 'evaporation off the ocean'],
    ['intent' => 'era', 'brief' => 'condensation into cloud droplets'],
    ['intent' => 'era', 'brief' => 'precipitation, mostly back to sea'],
    ['intent' => 'era', 'brief' => 'the three ways water splits on land'],
    ['intent' => 'legacy', 'brief' => 'nine days or ten thousand years'],
];
$intents = fn (array $sk) => array_column($sk, 'intent');

echo "\na journey with no time in it is not a journey\n";

$out = $planner->repairGeneric('journey', $journey, $script('water-cycle'));
ok('the water cycle is rebuilt off the journey spine',
    !in_array('era', $intents($out), true) && !in_array('origin', $intents($out), true));
ok('its beats survive as aspects', count(array_filter($intents($out), fn ($i) => $i === 'aspect')) >= 3);
ok('nothing is lost in the move', count($out) >= count($journey) - 1);
ok('and the generic spine can now reach cycle_diagram',
    in_array('cycle_diagram', GenericStoryboardComposerService::menuFor('aspect'), true));
ok('…and layer_stack, the atmosphere card',
    in_array('layer_stack', GenericStoryboardComposerService::menuFor('aspect'), true));
ok('neither was on the era menu, which is the whole bug',
    !in_array('cycle_diagram', GenericStoryboardComposerService::menuFor('era'), true)
    && !in_array('layer_stack', GenericStoryboardComposerService::menuFor('era'), true));

$out = $planner->repairGeneric('journey', $journey, $script('atmosphere-layers'));
ok('the atmosphere layers are demoted too', !in_array('era', $intents($out), true));

echo "\na real chronology keeps its timeline\n";

$history = [
    ['intent' => 'hook', 'brief' => 'five dollars a ton, then sixteen cents'],
    ['intent' => 'origin', 'brief' => 'break bulk cargo before the box'],
    ['intent' => 'era', 'brief' => 'the Ideal X sails in 1956'],
    ['intent' => 'era', 'brief' => 'ISO settles the standard'],
    ['intent' => 'legacy', 'brief' => 'eight hundred million movements a year'],
];
$out = $planner->repairGeneric('journey', $history, $script('shipping-container'));
ok('the shipping container keeps its journey', in_array('era', $intents($out), true));
$out = $planner->repairGeneric('journey', $history, $script('penicillin'));
ok('penicillin keeps its journey', in_array('era', $intents($out), true));
$out = $planner->repairGeneric('journey', $history, $script('nokia'));
ok('nokia keeps its journey', in_array('era', $intents($out), true));

// The dates may live in the BRIEFS even when the script is terse.
$out = $planner->repairGeneric('journey', $history, 'A short topic line with no dates at all.');
ok('a dated brief alone is enough to keep the journey', in_array('era', $intents($out), true));

// …and an era word counts as chronology, so "ancient Rome to today" survives.
$vague = [
    ['intent' => 'hook', 'brief' => 'how the recipe travelled'],
    ['intent' => 'origin', 'brief' => 'ancient origins of the dish'],
    ['intent' => 'era', 'brief' => 'the modern version'],
    ['intent' => 'legacy', 'brief' => 'what it means today'],
];
$out = $planner->repairGeneric('journey', $vague, 'A dish that changed as it travelled.');
ok('era words count as chronology', in_array('era', $intents($out), true));

echo "\nthe other shapes are untouched\n";
foreach (['argument', 'compare', 'countdown', 'demo', 'generic'] as $shape) {
    $order = ScriptSkeletonService::GENERIC_SHAPES[$shape];
    $raw = array_map(fn ($i) => ['intent' => $i, 'brief' => "brief for {$i}"], $order);
    $out = $planner->repairGeneric($shape, $raw, 'A script with no dates whatsoever.');
    ok("{$shape} keeps its own spine",
        array_diff($intents($out), $order) === [] && $intents($out) !== []);
}

echo "\nthe custom_card nudge\n";

$compose = new GenericStoryboardComposerService();
$ref = new ReflectionMethod($compose, 'nudgeCustomCard');
$ref->setAccessible(true);
$skeleton = [
    ['intent' => 'hook', 'brief' => 'the fields nobody reads'],
    ['intent' => 'context', 'brief' => 'what the pass is for'],
    ['intent' => 'aspect', 'brief' => 'the barcode carries your name'],
    ['intent' => 'payoff', 'brief' => 'why not to post it'],
];
$textScenes = [
    ['layout_template' => 'single_focus', 'slots' => []],
    ['layout_template' => 'stat_spotlight', 'slots' => []],
];

$nudge = $ref->invoke($compose, $textScenes, $skeleton, $script('boarding-pass'));
ok('a boarding-pass script gets nudged', is_string($nudge) && str_contains($nudge, 'custom_card'));
ok('the nudge names the object', is_string($nudge) && str_contains($nudge, 'boarding pass'));
ok('and names a phase that can carry it', is_string($nudge) && str_contains($nudge, 'phase'));

$nudge = $ref->invoke($compose, $textScenes, $skeleton, $script('text-scam'));
ok('a scam-text script gets nudged', is_string($nudge));

$cast = array_merge($textScenes, [['layout_template' => 'custom_card', 'slots' => []]]);
ok('a draft that already cast it is left alone',
    $ref->invoke($compose, $cast, $skeleton, $script('boarding-pass')) === null);

foreach (['city-flooding', 'vreato-demo', 'front-doors', 'shipping-container', 'coffee-tea'] as $id) {
    ok("{$id} is not nudged", $ref->invoke($compose, $textScenes, $skeleton, $script($id)) === null);
}

// A phase list with no menu offering the card must stay silent.
$noMenu = [['intent' => 'ranking_reveal', 'brief' => 'count them down']];
ok('silent when no phase offers the card',
    $ref->invoke($compose, $textScenes, $noMenu, $script('boarding-pass')) === null);
ok('silent with no script', $ref->invoke($compose, $textScenes, $skeleton, '') === null);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
