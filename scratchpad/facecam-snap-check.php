<?php
// Re-run the (free, deterministic) face analyser on saved project-195 clips and
// apply reconcileFacecams() to the facecam box the vision model actually gave.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\Shorts\ClipSceneAnalyzer;
use App\Services\PythonAIService;

$dir = '/var/www/scratchpad/f195keep';
foreach (array_slice($argv, 1) as $n) {
    $props = json_decode(file_get_contents("$dir/short_props_$n.json"), true);
    $raw = (new PythonAIService())->makeRequest('POST', '/analyze-clip', [
        'video_path' => "$dir/main_clip_$n.mp4", 'frames_dir' => "/var/www/storage/app/public/tmp/fsnap_$n",
        'interval_seconds' => 0.35, 'max_samples' => 400, 'vlm_frames' => 0, 'vlm_width' => 640, 'project_id' => 195,
    ]);
    $tracks = $raw['face_tracks'] ?? [];
    foreach ($tracks as $t) {
        printf("clip %s track %s presence=%.2f cx=%.3f cy=%.3f size=%.3f pts=%d\n", $n, $t['id'] ?? '?', $t['presence'], $t['cx'], $t['cy'], $t['size'], count($t['points'] ?? []));
    }
    $cams = array_values(array_filter($props['layout']['panels'], fn ($p) => !empty($p['primary']) && isset($p['crop'])));
    if (!$cams) { echo "clip $n: no facecam panel (layout {$props['layout']['kind']})\n"; continue; }
    $regions = [['role' => 'facecam', 'label' => 'webcam', 'box' => $cams[0]['crop']]];
    $out = ClipSceneAnalyzer::reconcileFacecams($regions, $tracks, (int) ($raw['width'] ?? 1920), (int) ($raw['height'] ?? 1080));
    echo "clip $n: vision " . json_encode($cams[0]['crop']) . " -> " . json_encode($out[0]['box']) . " ({$out[0]['label']})\n";
}
