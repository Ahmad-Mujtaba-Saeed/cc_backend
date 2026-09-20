<?php

/**
 * make-cinematic-test — create a real ~4 minute explainer project on a topic
 * far from the diagram reference (biology, not servers) and analyse it
 * synchronously, so the storyboard (and its cinematic cards) can be inspected
 * in the dashboard without the queue worker.
 *
 *   docker compose exec app php scratchpad/make-cinematic-test.php [user_id]
 *
 * Costs the same as creating the project in the dashboard (analysis only, no
 * render): roughly $0.05-0.10 on gpt-4o-mini for a four-minute script.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Jobs\AnalyzeExplainerScriptJob;
use Modules\Project\Models\Project;

$userId = (int) ($argv[1] ?? 1);

$script = <<<'TXT'
Your heart beats about a hundred thousand times a day, and it never takes a day off. Most people picture it as one simple pump. It is really four chambers, two circuits, four one-way doors and its own electrical wiring, all working in a rhythm you never have to think about.

Start with the layout. The two chambers on top are the atria, the receiving rooms. The two on the bottom are the ventricles, the pumping rooms. The right side handles blood that has already given up its oxygen. The left side handles fresh blood that has just come back from the lungs. A thick wall down the middle keeps the two sides from ever mixing.

Now follow one drop of blood. It comes back from your body tired and low on oxygen, and it enters the right atrium. From there it drops into the right ventricle, which squeezes it out to the lungs. In the lungs it dumps carbon dioxide and picks up oxygen, turning from dark red to bright red. It flows back into the left atrium, down into the left ventricle, and the left ventricle, the strongest muscle in the heart, squeezes it out through the aorta to every part of your body. The whole round trip takes about a minute.

What stops the blood from sliding backwards? Four valves. Each one is a set of flaps that opens in one direction only. When a chamber squeezes, the valve in front of it opens and the valve behind it slams shut. That slamming is the sound you hear through a stethoscope: lub, as the valves between the chambers close, and dub, as the valves leading out of the heart close.

But what tells the heart when to squeeze? The heart has its own pacemaker, a small patch of cells called the sinoatrial node, sitting in the right atrium. About once a second it fires an electrical signal. The signal spreads across both atria, and they contract together. Then it reaches a second checkpoint, the atrioventricular node, which holds the signal back for about a tenth of a second. That tiny delay lets the ventricles finish filling. Then the signal races down into the ventricles, and they squeeze from the bottom up, like squeezing toothpaste from the end of the tube.

Every squeeze pushes a wave of pressure through your arteries. That is what blood pressure measures. The top number, around one hundred and twenty, is the pressure while the ventricles squeeze. The bottom number, around eighty, is the pressure while they relax and refill. If those numbers stay high for years, the heart has to work harder on every single beat, and the artery walls slowly wear.

The heart muscle needs oxygen too, and it does not take it from the blood passing through its chambers. It has its own supply lines, the coronary arteries, wrapped around the outside. Over decades, fatty plaque can build up inside them. If a plaque cracks, a clot forms on it and can block the artery completely. The muscle downstream is cut off from oxygen, and within minutes it starts to die. That is a heart attack. It is a plumbing problem, not an electrical one.

A cardiac arrest is different. There, the electrical system fails. The signal turns into chaos, the ventricles quiver instead of squeezing, and blood stops moving. That is why a defibrillator works: one strong shock resets every cell at once, giving the pacemaker a chance to take over again.

So the next time you feel your pulse, remember what is behind it: four chambers, four one-way doors, and a spark that fires about once every second, for your entire life.
TXT;

$project = Project::create([
    'user_id' => $userId,
    'title' => 'How your heart actually pumps blood',
    'template_type' => 'ai_explainer_video',
    'aspect_ratio' => '16:9',
    'status' => 'analyzing',
    'progress' => 0,
    'settings' => ['script' => $script, 'target_seconds' => 240],
]);
echo "created project {$project->id} for user {$userId} (" . str_word_count($script) . " words)\n";

$t0 = microtime(true);
(new AnalyzeExplainerScriptJob($project))->handle();
$project->refresh();
printf("analysis finished in %.0fs -> status %s\n", microtime(true) - $t0, $project->status);
if ($project->error_message) {
    echo "error: {$project->error_message}\n";
}

$scenes = $project->explainerScenes()->orderBy('order')->get();
$total = 0.0;
$cine = 0.0;
foreach ($scenes as $s) {
    $total += (float) $s->duration_seconds;
    if ($s->layout_template === 'cinematic_card') {
        $cine += (float) $s->duration_seconds;
    }
}
printf("%d scenes, %.0fs, cinematic %.0fs = %d%%\n\n", $scenes->count(), $total, $cine, $total > 0 ? round(100 * $cine / $total) : 0);

foreach ($scenes as $s) {
    printf("%2d. %-26s %5.1fs  %s\n", $s->order, $s->layout_template, $s->duration_seconds, mb_substr((string) $s->narration, 0, 90));
    if ($s->layout_template !== 'cinematic_card') {
        continue;
    }
    $slot = ($s->slots ?? [])['slot_cinematic'] ?? [];
    echo '      HEADING: ' . ($slot['heading'] ?? '') . "\n";
    foreach ((array) ($slot['elements'] ?? []) as $el) {
        $label = $el['title'] ?? $el['text'] ?? $el['formula'] ?? $el['url'] ?? '';
        $body = isset($el['rows']) ? 'rows ' . implode(', ', array_map(fn ($r) => $r['label'] . '=' . $r['value'], $el['rows']))
            : (isset($el['bars']) ? 'bars ' . implode(', ', array_map(fn ($b) => $b['label'] . ' ' . round($b['value'] * 100) . '%', $el['bars']))
            : (isset($el['skeleton']) ? 'skeleton ' . $el['skeleton'] : ''));
        $then = isset($el['then']) ? '  THEN ' . implode(' ; ', array_map(fn ($p) => ($p['word'] ?? '?') . ':' . json_encode(array_diff_key($p, ['word' => 1, 'at' => 1])), $el['then'])) : '';
        printf("      %-7s %-12s %-4s %-6s w=%-11s %s%s %s%s\n", $el['kind'], $el['place'], $el['depth'], $el['camera'], $el['word'] ?? '-',
            $label, isset($el['status']) ? ' [' . $el['status'] . ']' : '', $body, $then);
    }
    foreach ((array) ($slot['links'] ?? []) as $l) {
        printf("      link %s -> %s%s%s\n", $l['from'], $l['to'], isset($l['label']) ? ' "' . $l['label'] . '"' : '', !empty($l['flow']) ? ' (flow)' : '');
    }
}
