<?php
// Fetch + cache transcripts for the test URLs. Run once; cheap.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$urls = [
    'XRJOAv_7qnY', 'GJ1pZWtFvt0', '-Ty0HRzrY4k', 'HGD5Cx2SVvw', 'DcAHosNxz9E',
];

$dir = __DIR__ . '/shorts-cache';
@mkdir($dir, 0777, true);

$svc = new \Modules\Project\Services\RapidApiTranscriptionService();

foreach ($urls as $id) {
    $file = "$dir/$id.json";
    if (file_exists($file)) { echo "[cached] $id\n"; continue; }
    try {
        $t = $svc->transcribeByVideoId($id);
        file_put_contents($file, json_encode($t));
        printf("[ok] %s  segments=%d  duration=%.1fs (%.1f min)\n", $id, count($t['segments']), $t['totalDuration'], $t['totalDuration']/60);
    } catch (\Throwable $e) {
        echo "[FAIL] $id: " . $e->getMessage() . "\n";
    }
}
