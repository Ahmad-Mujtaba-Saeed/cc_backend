<?php
// Free: runs the deterministic analyser on saved project-195 clips and prints
// the webcam it finds + the start of the action track.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\PythonAIService;
$dir = $argv[1];
foreach (array_slice($argv, 2) as $n) {
    $raw = (new PythonAIService())->makeRequest('POST', '/analyze-clip', [
        'video_path' => "$dir/main_clip_$n.mp4", 'frames_dir' => "/var/www/storage/app/public/tmp/wc_$n",
        'interval_seconds' => 0.35, 'max_samples' => 400, 'vlm_frames' => 0, 'vlm_width' => 640, 'project_id' => 0,
    ]);
    $a = $raw['action_track']['points'] ?? [];
    $xs = array_map(fn ($p) => round($p['x'] + 0.05, 2), $a);
    printf("clip %s webcam=%s tracks=%d action[%d] x range %.2f-%.2f\n", $n, json_encode($raw['webcam'] ?? null), count($raw['face_tracks'] ?? []), count($a), $xs ? min($xs) : 0, $xs ? max($xs) : 0);
}
