<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\ClipSelectionService;

$ids = ['XRJOAv_7qnY', 'GJ1pZWtFvt0', '-Ty0HRzrY4k', 'HGD5Cx2SVvw', 'DcAHosNxz9E'];
$dir = __DIR__ . '/shorts-cache';
$count = (int) ($argv[1] ?? 4);

$svc = new ClipSelectionService();
$ref = new ReflectionClass($svc);
$call = function (string $m, array $a) use ($ref, $svc) {
    $x = $ref->getMethod($m); $x->setAccessible(true); return $x->invokeArgs($svc, $a);
};

foreach ($ids as $id) {
    $t = json_decode(file_get_contents("$dir/$id.json"), true);
    $segs = $t['segments']; $dur = $t['totalDuration'];

    $strategy = $call('calculateWindowStrategy', [$dur, $count]);
    $windows  = $call('buildWindows', [$segs, $strategy]);

    printf("\n=== %s  %.1f min ===\n", $id, $dur/60);
    printf("  strategy: win=%ds overlap=%ds max=%d  -> %d windows\n",
        $strategy['window_seconds'], $strategy['overlap_seconds'], $strategy['max_windows'], count($windows));
    $covered = 0;
    foreach ($windows as $w) {
        printf("   w%-2d %7.0f-%7.0fs  segs=%-5d %s\n", $w['window_index'], $w['start_time'], $w['end_time'],
            count($w['segments']),
            $w['end_time'] > $dur ? '(past end)' : '');
        $covered = max($covered, min($w['end_time'], $dur));
    }
    printf("  COVERAGE: 0-%.0fs of %.0fs = %.0f%%   TAIL IGNORED: %.1f min\n",
        $covered, $dur, 100*$covered/$dur, ($dur-$covered)/60);

    // token estimate of the biggest window prompt
    $maxChars = 0;
    foreach ($windows as $w) {
        $c = 0; foreach ($w['segments'] as $s) { $c += strlen($s['text']) + 12; }
        $maxChars = max($maxChars, $c);
    }
    printf("  biggest window prompt: ~%d chars (~%d tokens)\n", $maxChars, (int)($maxChars/4));
    $perWindow = $count > 1 ? min(3, max(2, (int) ceil(($count * 2) / max(1, count($windows))))) : 1;
    printf("  candidates asked per window: %d  -> pool max %d (need %d)\n", $perWindow, $perWindow*count($windows), $count);
}
