<?php

/**
 * restage-cinematic — re-run the staging pass for every cinematic_card of an
 * existing project (keeps the narration, the brief and every other scene),
 * then print what the model designed and write a probe spec.
 *
 *   docker compose exec app php scratchpad/restage-cinematic.php <project_id> [out.json]
 *
 * ~$0.003 per card on gpt-4o-mini, plus ~$0.001 per part DRAWN by the image
 * model (a subject already drawn for any project costs nothing).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\Project;
use Modules\Project\Services\CinematicSceneService;
use Modules\Project\Services\FlowVisualService;

// Flags: --dry prints and writes the spec but saves nothing.
$flags = array_values(array_filter($argv, fn ($a) => str_starts_with($a, '--')));
$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
$dry = in_array('--dry', $flags, true);
$project = Project::findOrFail((int) ($args[0] ?? 0));
$out = $args[1] ?? __DIR__ . "/restage-{$project->id}.json";
$service = new CinematicSceneService();
$drawer = new FlowVisualService();
echo 'staging model: ' . Modules\Project\Support\LlmModels::for('explainer') . ($dry ? ' (dry run)' : '') . "\n";
$specs = [];

foreach ($project->explainerScenes()->orderBy('order')->get() as $scene) {
    if ($scene->layout_template !== 'cinematic_card') {
        continue;
    }
    $slots = $scene->slots ?? [];
    $old = $slots['slot_cinematic'] ?? [];
    $staged = $service->design(
        (string) ($old['brief'] ?? ''),
        (string) $scene->narration,
        (string) ($old['heading'] ?? ''),
        (string) $project->title,
        (string) ($project->aspect_ratio ?? '16:9')
    );
    if ($staged === null) {
        echo "scene {$scene->scene_id}: staging failed, kept the old one\n";
        continue;
    }
    // Draw the parts this staging named. Same pass the analysis job runs;
    // done here per scene so the print below shows what was drawn.
    $drawn = $drawer->drawAll(
        ['scenes' => [['scene_id' => $scene->scene_id, 'slots' => ['slot_cinematic' => $staged]]]],
        (string) $project->title
    );
    $staged = $drawn['scenes'][0]['slots']['slot_cinematic'];

    if (!$dry) {
        $slots['slot_cinematic'] = $staged;
        $scene->slots = $slots;
        $scene->save();
    }

    echo "\n== {$scene->scene_id} ({$scene->duration_seconds}s): " . ($staged['heading'] ?? '') . "\n";
    echo '   narration: ' . mb_substr((string) $scene->narration, 0, 160) . "\n";
    foreach ($staged['elements'] as $el) {
        $then = array_map(
            fn ($c) => ($c['word'] ?? '?') . ':' . ($c['status'] ?? $c['note'] ?? 'changes'),
            (array) ($el['then'] ?? [])
        );
        printf(
            "   %-7s %-12s %-4s %-6s w=%-11s %-22s %s%s\n",
            $el['kind'],
            $el['place'],
            $el['depth'],
            $el['camera'],
            $el['word'] ?? '-',
            mb_substr((string) ($el['title'] ?? $el['text'] ?? $el['formula'] ?? ''), 0, 22),
            $el['kind'] === 'visual'
                ? (empty($el['image_path']) ? '[NOT DRAWN] ' : '[drawn] ') . mb_substr((string) ($el['prompt'] ?? ''), 0, 70)
                : mb_substr((string) ($el['sub'] ?? ''), 0, 70),
            $then ? '  then: ' . implode(' ', $then) : ''
        );
    }
    foreach ($staged['links'] ?? [] as $l) {
        printf("   link %s -> %s%s%s\n", $l['from'], $l['to'], isset($l['label']) ? ' "' . $l['label'] . '"' : '', !empty($l['flow']) ? ' (flow)' : '');
    }
    echo '   weak: ' . json_encode(CinematicSceneService::weaknesses($staged)) . "\n";
    // The probe renders from remotion-render/public; a real render maps the
    // same paths through RemotionRenderService::publicUrl().
    foreach ($staged['elements'] as $i => $el) {
        if (!empty($el['image_path'])) {
            $staged['elements'][$i]['image_url'] = 'flow/' . basename($el['image_path']);
        }
    }
    $specs[] = ['scene_id' => $scene->scene_id, 'narration' => (string) $scene->narration, 'slot' => $staged];
}

file_put_contents($out, json_encode($specs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nwrote " . count($specs) . " staging(s) to {$out}\n";
