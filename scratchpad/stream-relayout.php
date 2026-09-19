<?php
// Re-plan saved project-195 shorts with the current analyser + layout planner,
// keeping each short's saved edit (words/segments/events). Vision ~$0.001/clip.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\Project\Services\Shorts\{ClipSceneAnalyzer, ShortLayoutPlanner, ViralShortRenderService};

$dir = '/var/www/scratchpad/f195keep';
$clips = array_slice($argv, 1);
$an = new ClipSceneAnalyzer();
$analyses = [];
foreach ($clips as $n) {
    $props = json_decode(file_get_contents("$dir/short_props_$n.json"), true);
    $text = implode(' ', array_map(fn ($w) => $w['text'], $props['words'] ?? []));
    $analyses[$n] = $an->analyze("$dir/main_clip_$n.mp4", "/var/www/storage/app/public/tmp/rl_$n", $text, 195, (int) $n);
}
$analyses = ClipSceneAnalyzer::shareWebcam($analyses);
$planner = new ShortLayoutPlanner();
$svc = new ViralShortRenderService();
$cap = (new ReflectionClass($svc))->getMethod('captionY'); $cap->setAccessible(true);
$pc = (new ReflectionClass($svc))->getMethod('placeCards'); $pc->setAccessible(true);
$tk = (new ReflectionClass($svc))->getMethod('takeovers'); $tk->setAccessible(true);
$i = 0;
foreach ($analyses as $n => $a) {
    $props = json_decode(file_get_contents("$dir/short_props_$n.json"), true);
    $layout = $planner->plan($a, false, 'auto', 1.0, 195 + $i++);
    $props['layout'] = ['kind' => $layout['kind'], 'panels' => $layout['panels'], 'background' => $layout['background'] ?? 'black', 'divider' => $layout['divider'] ?? null];
    $props['style']['caption']['y'] = $cap->invoke($svc, $layout, (float) $props['style']['caption']['y']);
    $events = array_values(array_filter($props['events'], fn ($e) => $e['type'] !== 'takeover'));
    $props['events'] = $tk->invoke($svc, $pc->invoke($svc, $events, $layout['kind']), $layout['kind']);
    $props['video']['url'] = "http://localhost:3020/storage/tmp_check/s195_c$n.mp4";
    file_put_contents("$dir/relayout_$n.json", json_encode($props));
    $take = array_values(array_filter($props['events'], fn ($e) => $e['type'] === 'takeover'));
    printf("clip %s scene=%s webcam=%s%s layout=%s (%s) takeovers=%s\n", $n, $a['scene_type'], json_encode($a['webcam']), !empty($a['webcam_shared']) ? ' [shared]' : '', $layout['kind'], $layout['reason'], json_encode(array_map(fn ($e) => [$e['start'], $e['end']], $take)));
}
