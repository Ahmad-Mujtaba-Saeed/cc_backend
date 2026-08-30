<?php
// Audit clip selection over the cached transcripts. No downloads, no renders.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\ClipSelectionService;
use Modules\Project\Services\ClipEditPlanService;

$ids = ['XRJOAv_7qnY', 'GJ1pZWtFvt0', '-Ty0HRzrY4k', 'HGD5Cx2SVvw', 'DcAHosNxz9E'];
$dir = __DIR__ . '/shorts-cache';
$count = (int) ($argv[1] ?? 4);
$only  = $argv[2] ?? null;

$plan = new ClipEditPlanService();

foreach ($ids as $id) {
    if ($only && $only !== $id) continue;
    $t = json_decode(file_get_contents("$dir/$id.json"), true);
    $segs = $t['segments'];
    $dur  = $t['totalDuration'];

    // --- transcript shape diagnostics ---
    $punct = 0; $gaps = []; $prevEnd = null;
    foreach ($segs as $s) {
        $tx = rtrim((string)$s['text']);
        if (preg_match('/[.!?]["\'\)\]]?$/', $tx)) $punct++;
        if ($prevEnd !== null) $gaps[] = round($s['start'] - $prevEnd, 3);
        $prevEnd = $s['end'];
    }
    $bigGaps = count(array_filter($gaps, fn($g) => $g > 0.9));
    $avgSeg  = $dur / max(1, count($segs));

    printf("\n=========== %s  (%.1f min, %d segs, avg seg %.2fs) ===========\n", $id, $dur/60, count($segs), $avgSeg);
    printf("  punctuated segment ends: %d/%d (%.0f%%)   gaps>0.9s: %d\n",
        $punct, count($segs), 100*$punct/max(1,count($segs)), $bigGaps);
    printf("  model: %s\n", \Modules\Project\Support\LlmModels::for('general'));

    $t0 = microtime(true);
    try {
        $clips = (new ClipSelectionService())->findBestClips($segs, $dur, $count);
    } catch (\Throwable $e) {
        echo "  !! selection threw: " . $e->getMessage() . "\n"; continue;
    }
    $el = microtime(true) - $t0;

    printf("  requested %d, got %d in %.1fs\n", $count, count($clips), $el);
    foreach ($clips as $i => $c) {
        $s = $c['final_start_seconds']; $e = $c['final_end_seconds'];
        $p = $plan->plan($segs, $s, $e);
        printf("   #%d  %6.1f-%6.1fs  len=%5.1fs  score=%3d  pos=%3.0f%%  edit:%s(%d ranges, -%.1fs)\n",
            $i+1, $s, $e, $e-$s, $c['final_score'], 100*$s/$dur,
            $p['is_edited'] ? 'YES' : 'no ', count($p['ranges']), $p['removed_seconds']);
        printf("        hook: \"%s\"\n", mb_substr(preg_replace('/\s+/',' ',trim($c['subtitle'])), 0, 110));
        printf("        why : %s\n", mb_substr(trim($c['reason']), 0, 110));
    }
}
