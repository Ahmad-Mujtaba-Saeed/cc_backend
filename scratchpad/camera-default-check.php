<?php

/**
 * camera-default-check — the content/aspect-aware camera-move default
 * (loop iter 41): a tall subject climbs, a wide vista sweeps, a face pushes
 * in; a deliberate model choice is always kept; the neutral default is only
 * upgraded on a clear signal. Deploys iter 40's new moves (arc_pan /
 * pedestal_up) automatically.
 *   docker exec viralforgebackend-app-1 php scratchpad/camera-default-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($got !== '' ? "  -> {$got}" : '') . "\n";
    }
}

$v = new ShotListValidator();
$rc = new ReflectionClass($v);
$pick = $rc->getMethod('pickCameraDefault');
$pick->setAccessible(true);
$resolve = $rc->getMethod('resolveCameraMove');
$resolve->setAccessible(true);
$aspect = $rc->getProperty('aspectRatio');
$aspect->setAccessible(true);

$setAspect = fn (string $a) => $aspect->setValue($v, $a);
$P = fn (string $d) => $pick->invoke($v, $d);
$R = fn ($given, string $d) => $resolve->invoke($v, $given, $d);

echo "--- registry has the new moves ---\n";
$moves = ExplainerRegistry::cameraMoves();
foreach (['arc_pan', 'whip_settle', 'pedestal_up', 'pedestal_down'] as $m) {
    check("\"{$m}\" registered", in_array($m, $moves, true));
}
check('default is still ken_burns', ExplainerRegistry::defaultCameraMove() === 'ken_burns');

echo "--- the picker: a face pushes in gently ---\n";
$setAspect('16:9');
foreach (['a portrait of the founder', 'headshot of the CEO', 'a close-up face', 'a woman smiling'] as $d) {
    check("\"{$d}\" -> slow_zoom_in", $P($d) === 'slow_zoom_in', (string) $P($d));
}

echo "--- the picker: a tall subject climbs (aspect-aware) ---\n";
$setAspect('9:16');
foreach (['a tall skyscraper at dusk', 'a rocket on the launch pad', 'a waterfall in the jungle', 'a giant statue'] as $d) {
    check("portrait \"{$d}\" -> pedestal_up", $P($d) === 'pedestal_up', (string) $P($d));
}
$setAspect('16:9');
check('landscape "a tall tower" -> pan_up_zoom_in', $P('a tall tower') === 'pan_up_zoom_in', (string) $P('a tall tower'));
check('the SAME description flips move by aspect',
    $R(null, 'a tall skyscraper') === 'pan_up_zoom_in', (string) $R(null, 'a tall skyscraper'));

echo "--- the picker: a wide vista sweeps ---\n";
foreach (['a wide desert landscape', 'an aerial vista of the coast', 'a city skyline panorama', 'open fields at dawn'] as $d) {
    check("\"{$d}\" -> arc_pan", $P($d) === 'arc_pan', (string) $P($d));
}

echo "--- the picker: no signal -> null (keep the neutral default) ---\n";
foreach (['a bar chart of revenue', 'an abstract concept', 'a plain blue background', 'a coffee cup on a desk'] as $d) {
    check("\"{$d}\" -> null", $P($d) === null, var_export($P($d), true));
}

echo "--- whole-word matching only: no substring false positives ---\n";
check('"seasonal trends" does NOT read as "sea"', $P('seasonal trends chart') === null, var_export($P('seasonal trends chart'), true));
check('"a human cell" does NOT read as "man"', $P('a human cell diagram') === null, var_export($P('a human cell diagram'), true));
check('"command line" does NOT read as "man"', $P('a command line terminal') === null, var_export($P('a command line terminal'), true));

echo "--- resolve: the model is respected, the neutral default is upgraded ---\n";
check('a deliberate valid move is kept', $R('tilt_zoom', 'a tall tower') === 'tilt_zoom', (string) $R('tilt_zoom', 'a tall tower'));
check('a deliberate whip_settle is kept', $R('whip_settle', 'a plain chart') === 'whip_settle', (string) $R('whip_settle', 'a plain chart'));
check('ken_burns is UPGRADED on a signal', $R('ken_burns', 'a wide desert') === 'arc_pan', (string) $R('ken_burns', 'a wide desert'));
check('ken_burns STANDS without a signal', $R('ken_burns', 'a coffee cup') === 'ken_burns', (string) $R('ken_burns', 'a coffee cup'));
check('an ABSENT move takes the signal pick', $R(null, 'a rocket launch') === 'pan_up_zoom_in', (string) $R(null, 'a rocket launch'));
check('an ABSENT move with no signal -> default', $R(null, 'a coffee cup') === 'ken_burns', (string) $R(null, 'a coffee cup'));
check('an INVALID move takes the signal pick', $R('bogus_move', 'a wide vista') === 'arc_pan', (string) $R('bogus_move', 'a wide vista'));
check('an INVALID move with no signal -> default', $R('nonsense', 'a coffee cup') === 'ken_burns', (string) $R('nonsense', 'a coffee cup'));

echo "--- end-to-end through validate(), aspect from the shot list ---\n";
$run = function (string $aspectRatio, array $slot): array {
    $scene = [
        'scene_id' => 's1',
        'layout_template' => 'single_focus',
        'duration_seconds' => 8,
        'narration' => ['text' => 'A short beat about the subject on screen.'],
        'slots' => ['slot_main' => array_merge(['content_type' => 'image'], $slot)],
    ];
    $out = (new ShotListValidator())->validate(
        ['aspect_ratio' => $aspectRatio, 'scenes' => [$scene]],
        ['hook_enabled' => false, 'outro_enabled' => false]
    );
    return $out['scenes'][0]['slots']['slot_main'] ?? [];
};

$s = $run('9:16', ['asset_request' => ['description' => 'a tall skyscraper at dusk']]);
check('portrait video, tall subject, no move -> pedestal_up', ($s['camera_move'] ?? '') === 'pedestal_up', $s['camera_move'] ?? '');

$s = $run('16:9', ['asset_request' => ['description' => 'a wide desert landscape']]);
check('landscape video, wide vista, no move -> arc_pan', ($s['camera_move'] ?? '') === 'arc_pan', $s['camera_move'] ?? '');

$s = $run('16:9', ['camera_move' => 'ken_burns', 'asset_request' => ['description' => 'a rocket launch']]);
check('a neutral ken_burns on a tall subject is upgraded', ($s['camera_move'] ?? '') === 'pan_up_zoom_in', $s['camera_move'] ?? '');

$s = $run('16:9', ['camera_move' => 'hover', 'asset_request' => ['description' => 'a tall tower']]);
check('a deliberate hover is respected through validate()', ($s['camera_move'] ?? '') === 'hover', $s['camera_move'] ?? '');

$s = $run('16:9', ['asset_request' => ['description' => 'a bar chart of quarterly revenue']]);
check('no-signal subject keeps ken_burns through validate()', ($s['camera_move'] ?? '') === 'ken_burns', $s['camera_move'] ?? '');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
