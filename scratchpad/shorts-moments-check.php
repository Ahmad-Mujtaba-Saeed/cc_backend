<?php
// Regression check: 'moments' mode (Ranking Moments template) still selects.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\ClipSelectionService;

$id = $argv[1] ?? '-Ty0HRzrY4k';
$count = (int) ($argv[2] ?? 5);
$t = json_decode(file_get_contents(__DIR__ . "/shorts-cache/$id.json"), true);

$clips = (new ClipSelectionService())->findBestClips($t['segments'], $t['totalDuration'], $count, 'moments');
printf("moments mode: requested %d, got %d (video %.1f min)\n", $count, count($clips), $t['totalDuration']/60);
foreach ($clips as $i => $c) {
    printf("  #%d %7.1f-%7.1fs  len=%5.1fs  sc=%3d  pos=%3.0f%%\n", $i+1,
        $c['final_start_seconds'], $c['final_end_seconds'],
        $c['final_end_seconds']-$c['final_start_seconds'], $c['final_score'],
        100*$c['final_start_seconds']/$t['totalDuration']);
}
