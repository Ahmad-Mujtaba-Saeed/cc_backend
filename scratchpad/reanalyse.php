<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$project = Modules\Project\Models\Project::findOrFail((int) $argv[1]);
$t0 = microtime(true);
(new Modules\Project\Jobs\AnalyzeExplainerScriptJob($project))->handle();
$project->refresh();
printf("project %d re-analysed in %.0fs -> %s\n", $project->id, microtime(true) - $t0, $project->status);

$scenes = $project->explainerScenes()->orderBy('order')->get();
$total = 0.0; $flow = 0.0;
foreach ($scenes as $s) {
    $total += (float) $s->duration_seconds;
    if ($s->layout_template === 'cinematic_card') { $flow += (float) $s->duration_seconds; }
}
printf("%d scenes, %.0fs, flow cards %.0fs = %d%%\n\n", $scenes->count(), $total, $flow, $total > 0 ? round(100 * $flow / $total) : 0);

foreach ($scenes as $s) {
    if ($s->layout_template !== 'cinematic_card') { continue; }
    $slot = ($s->slots ?? [])['slot_cinematic'] ?? [];
    printf("scene %d (%.0fs): %s\n", $s->order, $s->duration_seconds, $slot['heading'] ?? '');
    echo '   "' . mb_substr((string) $s->narration, 0, 150) . "\"\n";
    foreach ((array) ($slot['elements'] ?? []) as $el) {
        $then = array_map(fn ($c) => ($c['word'] ?? '?') . ':' . ($c['status'] ?? $c['note'] ?? 'changes'), (array) ($el['then'] ?? []));
        printf("   %-7s %-12s w=%-11s %-22s %s%s\n", $el['kind'], $el['place'], $el['word'] ?? '-',
            mb_substr((string) ($el['title'] ?? $el['text'] ?? ''), 0, 20) . (isset($el['status']) ? ' [' . $el['status'] . ']' : ''),
            ($el['kind'] ?? '') === 'visual' ? (empty($el['image_path']) ? '[NOT DRAWN] ' : '[drawn] ') . mb_substr((string) ($el['prompt'] ?? ''), 0, 58) : '',
            $then ? '  then: ' . implode(' ', $then) : '');
    }
    foreach ((array) ($slot['links'] ?? []) as $l) {
        printf("   link %s -> %s%s%s\n", $l['from'], $l['to'], isset($l['label']) ? ' "' . $l['label'] . '"' : '', !empty($l['flow']) ? ' (flow)' : '');
    }
    echo "\n";
}
