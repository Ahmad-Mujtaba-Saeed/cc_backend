<?php
// Free: analyser + planner on podcast samples (no vision call).
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\PythonAIService;
use Modules\Project\Services\Shorts\{ClipSceneAnalyzer, ShortLayoutPlanner};
$merge = (new ReflectionClass(ClipSceneAnalyzer::class))->getMethod('merge'); $merge->setAccessible(true);
foreach (array_slice($argv, 1) as $f) {
    $raw = (new PythonAIService())->makeRequest('POST', '/analyze-clip', [
        'video_path' => "/var/www/scratchpad/pod/$f.mp4", 'frames_dir' => "/var/www/scratchpad/pod/frames_$f",
        'interval_seconds' => 0.35, 'max_samples' => 400, 'vlm_frames' => 3, 'vlm_width' => 480, 'project_id' => 0,
    ]);
    $a = $merge->invoke(new ClipSceneAnalyzer(), $raw, null);
    $a['source_format'] = 'podcast';
    $tr = implode(' ', array_map(fn ($t) => sprintf('%s[p%.2f s%.3f cx%.2f spk%.2f]', $t['id'], $t['presence'], $t['size'], $t['cx'], $t['speaking']), $a['face_tracks']));
    $sp = $a['speaker_track'];
    echo "$f scene={$a['scene_type']} cuts=" . count($a['scene_cuts']) . " tracks: $tr\n   speaker=" . ($sp ? json_encode($sp['switches']) : 'none');
    foreach ([0, 1] as $v) { $l = (new ShortLayoutPlanner())->plan($a, false, 'auto', 1.0, $v); echo " | v$v={$l['kind']} ({$l['reason']})"; }
    echo "\n";
}
