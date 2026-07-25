<?php
/**
 * scenario-newshapes-check — the compare/split/cycle scenario shapes plus the
 * per-entity `emphasis` field survive the validator's clampScenarioContent and
 * come out with a legal layout, so the renderer can draw them.
 *
 *   php scratchpad/scenario-newshapes-check.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ShotListValidator;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $got = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $ok ? '' : "  (got: $got)");
}

$v = new ShotListValidator();
$ref = new ReflectionMethod($v, 'clampScenarioContent');
$ref->setAccessible(true);
// mathMode must be true for the scenario clamp to run in-scene; the method
// itself doesn't gate on it, but call it directly.
$call = fn (array $slot, string $narr = '') => $ref->invoke($v, $slot, $narr);

// compare + emphasis
$out = $call([
    'layout' => 'compare',
    'heading' => 'A vs B',
    'question' => 'who wins?',
    'entities' => [
        ['label' => 'Car A', 'value' => '60 km/h', 'sprite' => 'a blue sedan'],
        ['label' => 'Car B', 'value' => '80 km/h', 'emphasis' => 'key'],
    ],
    'connectors' => [['label' => '60 km/h'], ['label' => '80 km/h']],
]);
check('compare layout preserved', ($out['layout'] ?? '') === 'compare', json_encode($out['layout'] ?? null));
check('emphasis key passed through', (($out['entities'][1]['emphasis'] ?? '')) === 'key', json_encode($out['entities'][1] ?? null));
check('no-emphasis entity stays clean', !array_key_exists('emphasis', $out['entities'][0]), json_encode($out['entities'][0]));

// split
$out = $call([
    'layout' => 'split',
    'entities' => [['label' => 'Bag'], ['label' => 'Red'], ['label' => 'Blue']],
    'connectors' => [['label' => '0.6'], ['label' => '0.4']],
]);
check('split layout preserved', ($out['layout'] ?? '') === 'split', json_encode($out['layout'] ?? null));

// cycle
$out = $call([
    'layout' => 'cycle',
    'entities' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C'], ['label' => 'D']],
]);
check('cycle layout preserved', ($out['layout'] ?? '') === 'cycle', json_encode($out['layout'] ?? null));

// invalid emphasis dropped
$out = $call(['layout' => 'line', 'entities' => [['label' => 'X', 'emphasis' => 'sparkly'], ['label' => 'Y']]]);
check('bogus emphasis dropped', !array_key_exists('emphasis', $out['entities'][0]), json_encode($out['entities'][0]));

// unknown layout falls back to inference (no keywords -> line)
$out = $call(['layout' => 'wobble', 'entities' => [['label' => 'X'], ['label' => 'Y']]]);
check('unknown layout -> inferred line', ($out['layout'] ?? '') === 'line', json_encode($out['layout'] ?? null));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
