<?php
// Window numbering + duplicate guard for GenericStoryboardComposerService (no API calls).
//   docker compose exec app php scratchpad/composer-window-check.php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService as C;

$m = new ReflectionMethod(C::class, 'numberWindow');
$m->setAccessible(true);
$phases = fn ($r) => implode(',', array_map(fn ($s) => $s['phase'], $r));
$ok = 0; $bad = 0;
$check = function (string $label, bool $pass) use (&$ok, &$bad) { $pass ? $ok++ : $bad++; echo ($pass ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; };

// Window = beats 17-26 (indexes 16..25).
$idx = range(16, 25);
$check('global numbers kept', $phases($m->invoke(null, [['phase' => 17], ['phase' => 18]], $idx)) === '17,18');
$check('local 1..n renumbered to 17..', $phases($m->invoke(null, [['phase' => 1], ['phase' => 2], ['phase' => 3]], $idx)) === '17,18,19');
$check('no numbers -> window position', $phases($m->invoke(null, [[], []], $idx)) === '17,18');
$check('out-of-window number dropped', $phases($m->invoke(null, [['phase' => 17], ['phase' => 40]], $idx)) === '17');
$check('duplicate phase dropped', $phases($m->invoke(null, [['phase' => 17], ['phase' => 17]], $idx)) === '17');
// First window answered only half: 1..8 stay 1..8, nothing invented for 9..10.
$check('half-answered first window', $phases($m->invoke(null, array_map(fn ($n) => ['phase' => $n], range(1, 8)), range(0, 9))) === '1,2,3,4,5,6,7,8');

$card = fn ($h, $b) => ['layout_template' => 'split_side_by_side', 'slots' => ['slot_right' => ['content_type' => 'text_block', 'heading' => $h, 'bullets' => $b]]];
$out = C::dropDuplicateCards([$card('Primate Eye Comparison', ['1997', 'Findings']), $card('Other', ['x y z']), $card('Primate Eye Comparison', ['1997', 'Findings'])]);
$check('duplicate card rebuilt as text', $out[2]['layout_template'] === 'single_focus' && $out[0]['layout_template'] === 'split_side_by_side');

$twin = C::collapseTwinPictures([['layout_template' => 'split_side_by_side', 'slots' => [
    'slot_left' => ['content_type' => 'image', 'asset_request' => ['description' => "a chimpanzee's eye, showing the dark area around the iris"]],
    'slot_right' => ['content_type' => 'image', 'asset_request' => ['description' => "a chimpanzee's eye, the dark area around the iris in detail"]],
]], ['layout_template' => 'split_side_by_side', 'slots' => [
    'slot_left' => ['content_type' => 'image', 'asset_request' => ['description' => "a chimpanzee's eye with a dark sclera"]],
    'slot_right' => ['content_type' => 'image', 'asset_request' => ['description' => 'a human eye in a mirror with bright white sclera']],
]]]);
$check('twin chimp pictures -> one picture + text', ($twin[0]['slots']['slot_right']['content_type'] ?? '') === 'text_block');
$check('real comparison (chimp | human) kept', ($twin[1]['slots']['slot_right']['content_type'] ?? '') === 'image');

echo "{$ok} passed, {$bad} failed\n";
exit($bad ? 1 : 0);
