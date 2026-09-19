<?php
// Re-run the current sanitizer over a cinematic-live out file (in place).
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$file = $argv[1];
$icons = array_flip(Modules\Project\Support\ExplainerRegistry::iconNames());
$specs = json_decode(file_get_contents($file), true);
foreach ($specs as &$spec) {
    $r = Modules\Project\Support\CinematicScene::sanitize($spec['slot'], fn ($n) => isset($icons[$n]), $spec['narration']);
    foreach ($r['warnings'] as $w) echo "  {$spec['scene_id']}: {$w}\n";
    $spec['slot'] = $r['slot'];
}
file_put_contents($file, json_encode($specs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "ok\n";
