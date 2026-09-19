<?php
// Takes a saved relayout props file, adds a narrator (real TTS) + native hook,
// humanizes events, writes props for a test render.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\Project\Services\Shorts\{ShortNarrator, ViralShortRenderService};
$n = $argv[1]; $line = $argv[2];
$dir = '/var/www/scratchpad/f195keep';
$p = json_decode(file_get_contents("$dir/relayout_$n.json"), true);
$edit = ['segments' => $p['segments'], 'words' => $p['words'], 'events' => $p['events'], 'hook' => $p['hook'], 'duration' => 0, 'narration' => $line];
// try seeds until the dealt opening is intro_freeze (to exercise the harder path)
for ($clip = 1; $clip < 40; $clip++) {
    mt_srand(9000 * 131 + $clip * 17); mt_rand(1, 100); $o = mt_rand(0, 1) ? 'intro_freeze' : 'duck'; mt_srand();
    if ($o === ($argv[3] ?? 'intro_freeze')) break;
}
[$edit, $info] = (new ShortNarrator())->apply($edit, ['scene_type' => 'gameplay_facecam', 'webcam' => [0,0,0.1,0.1]], [], 'always', 9000, $clip);
echo json_encode($info), "\n";
$p['segments'] = $edit['segments']; $p['words'] = $edit['words']; $p['hook'] = $edit['hook'];
$p['events'] = ViralShortRenderService::humanizeEvents($edit['events'], 42);
$p['style']['hook'] = ['style' => 'native', 'bg' => '#ffffff', 'color' => '#111111', 'font' => 'inter', 'y' => 0.36, 'scale' => 1.04, 'rotate' => -2.5, 'align' => 'left'];
foreach ($p['events'] as &$e) if (($e['type'] ?? '') === 'voice') $e['url'] = str_replace('http://localhost:3020/storage/', 'http://localhost:3020/storage/', $e['url']);
file_put_contents("$dir/narr_$n.json", json_encode($p));
echo "segments: ", json_encode(array_slice($p['segments'], 0, 2)), "\nvoice: ", json_encode(array_values(array_filter($p['events'], fn ($e) => $e['type'] === 'voice'))), "\n";
