<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\ClipSelectionService;

$id = $argv[1] ?? 'XRJOAv_7qnY';
$count = (int) ($argv[2] ?? 4);
$dir = __DIR__ . '/shorts-cache';
$t = json_decode(file_get_contents("$dir/$id.json"), true);
$segs = $t['segments']; $dur = $t['totalDuration'];

$svc = new ClipSelectionService();
$ref = new ReflectionClass($svc);
$call = function (string $m, array $a) use ($ref, $svc) {
    $x = $ref->getMethod($m); $x->setAccessible(true); return $x->invokeArgs($svc, $a);
};

$strategy = $call('calculateWindowStrategy', [$dur, $count]);
$windows  = $call('buildWindows', [$segs, $strategy]);
$perWindow = $count > 1 ? min(3, max(2, (int) ceil(($count * 2) / max(1, count($windows))))) : 1;
$pool = $call('scoreWindowsParallel', [$windows, $dur, $perWindow, 'story']);

printf("model=%s  windows=%d perWindow=%d  pool=%d\n\n",
    \Modules\Project\Support\LlmModels::for('general'), count($windows), $perWindow, count($pool));
printf("%-4s %-4s %9s %9s %7s %6s  %s\n", 'i', 'win', 'start', 'end', 'len', 'score', 'winRange');
foreach ($pool as $i => $c) {
    $w = $windows[array_search($c['window_index'], array_column($windows,'window_index'))] ?? null;
    printf("%-4d w%-3d %9.1f %9.1f %7.1f %6d  [%.0f-%.0f]%s\n", $i, $c['window_index'],
        $c['start_seconds'], $c['end_seconds'], $c['end_seconds']-$c['start_seconds'], $c['score'],
        $w['start_time'], $w['end_time'],
        ($c['start_seconds'] < $w['start_time'] - 1 || $c['end_seconds'] > $w['end_time'] + 1) ? '  <-- OUTSIDE WINDOW' : '');
}

$ranked = $call('filterAndRank', [$pool, max(6,$count*3), 'story']);
echo "\nAFTER filterAndRank (".count($ranked)."):\n";
foreach ($ranked as $i => $c) {
    printf("  %-2d w%-3d %8.1f-%8.1f  score=%3d  pos=%3.0f%%\n", $i, $c['window_index'],
        $c['start_seconds'], $c['end_seconds'], $c['score'], 100*$c['start_seconds']/$dur);
}
