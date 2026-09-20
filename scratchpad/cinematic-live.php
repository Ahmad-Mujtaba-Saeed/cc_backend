<?php

/**
 * cinematic-live — does the composer cast cinematic_card on the beats that
 * actually EXPLAIN, does the staging pass produce a usable staging from real
 * narration, and does the budget hold?
 *
 *   docker compose exec app php scratchpad/cinematic-live.php [outFile.json]
 *
 * Runs the SHIPPING path the analyze job takes for a non-maths video:
 * ScriptSkeletonService::planGeneric -> GenericStoryboardComposerService
 * -> CinematicSceneService::designAll -> ShotListValidator. Costs roughly
 * $0.01-0.02 per script on gpt-4o-mini. Writes every surviving cinematic
 * slot (with its narration) to the out file, which the renderer's
 * `scripts/cinematic-probe.ts --spec=<file>` renders.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\CinematicSceneService;
use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptSkeletonService;
use Modules\Project\Support\ShotListValidator;

$scripts = [
    'noise-cancelling headphones' => <<<'TXT'
Noise-cancelling headphones do not block sound. They add more of it.
On the outside of each ear cup sits a tiny microphone, listening to the world around you: the engine hum, the air conditioning, the train.
A chip inside reads that sound thousands of times a second and builds its mirror image, a wave that rises exactly where the noise falls and falls where it rises.
The speaker plays that inverted wave into your ear at the same moment the noise arrives. The two waves meet, cancel, and what reaches your eardrum is close to silence. Engineers call it destructive interference.
It works best on steady, low sounds, because those are easy to predict. A sudden voice or a clattering plate changes too fast for the chip to follow, which is why you still hear people talking.
So the quiet you hear is not an absence of sound. It is two sounds, perfectly opposed, erasing each other.
TXT,
    'how vaccines train the body' => <<<'TXT'
The first time a new virus gets into your body, your immune system is caught off guard. It has to work out, from scratch, what the invader looks like, and that takes days — days in which the virus is multiplying.
A vaccine gives it that lesson in advance. It shows your body a harmless piece of the virus, often just the spike protein it uses to break into cells, with no way to cause disease.
Your immune system reacts as if it were the real thing. B cells learn to make antibodies shaped to lock onto that exact spike, and T cells learn to recognise infected cells.
Then most of those defenders fade away, but a few memory cells stay behind, sometimes for decades, carrying the recipe.
When the real virus arrives, the memory cells recognise it within hours instead of days, and flood your body with the right antibodies before the infection can take hold.
That head start is the whole trick. A vaccine does not fight the disease. It rehearses the fight.
TXT,
];

$scripts['logged out when you scale'] = <<<'TXT'
Your app is growing, so you add two more servers behind a load balancer. The next morning, users start complaining that they keep getting logged out.
Here is why. When Bob logs in, the server that handles him writes his session into its own memory. It knows Bob. The other two servers have never heard of him.
The load balancer does not care. It sends each click to whichever server is least busy, so two out of three of Bob's clicks land on a server with no session, and he is asked to log in again.
The fix is to stop keeping sessions in the servers at all. Put them in one shared store, like Redis, and every server looks Bob up in the same place. Now any server can answer any click, and Bob stays logged in.
TXT;

$out = $argv[1] ?? __DIR__ . '/cinematic-live-out.json';
$specs = [];

foreach ($scripts as $name => $script) {
    echo "\n== {$name} ==\n";
    $skeleton = (new ScriptSkeletonService())->planGeneric($script, '', 60);
    echo '  skeleton: ' . implode(' | ', array_map(fn ($p) => $p['intent'], $skeleton)) . "\n";

    $composer = new GenericStoryboardComposerService();
    $raw = $composer->compose($script, $skeleton, 60);
    if ($raw === null) {
        echo "  composer returned null (would fall back to the giant call)\n";
        continue;
    }
    echo '  composer attempts: ' . $composer->attempts() . "\n";
    $cast = array_column($raw['scenes'], 'layout_template');
    echo '  cast: ' . implode(', ', $cast) . "\n";
    foreach ($raw['scenes'] as $s) {
        if ($s['layout_template'] === 'cinematic_card') {
            echo '  brief: ' . ($s['slots']['slot_cinematic']['brief'] ?? '(none)') . "\n";
        }
    }

    $raw = (new CinematicSceneService())->designAll($raw, $name, '16:9');
    $result = (new ShotListValidator())->validate($raw, ['hook_enabled' => false, 'outro_enabled' => false]);

    $total = 0.0;
    $cine = 0.0;
    foreach ($result['scenes'] as $s) {
        $total += (float) $s['duration_seconds'];
        if ($s['layout_template'] === 'cinematic_card') {
            $cine += (float) $s['duration_seconds'];
        }
    }
    printf("  after validation: %s\n", implode(', ', array_column($result['scenes'], 'layout_template')));
    printf("  cinematic share: %.1fs of %.1fs = %d%%\n", $cine, $total, $total > 0 ? round(100 * $cine / $total) : 0);
    foreach ($result['warnings'] as $w) {
        if (str_contains($w, 'cinematic')) {
            echo "  warning: {$w}\n";
        }
    }

    foreach ($result['scenes'] as $s) {
        if ($s['layout_template'] !== 'cinematic_card') {
            continue;
        }
        $slot = $s['slots']['slot_cinematic'];
        echo "\n  -- {$s['scene_id']} ({$s['duration_seconds']}s): " . ($slot['heading'] ?? '') . "\n";
        echo '     narration: ' . $s['narration']['text'] . "\n";
        foreach ($slot['elements'] as $el) {
            $label = $el['title'] ?? $el['text'] ?? $el['formula'] ?? (isset($el['prompt']) ? 'draw: ' . mb_substr($el['prompt'], 0, 44) : '');
            $body = isset($el['rows']) ? 'rows ' . implode(', ', array_map(fn ($r) => $r['label'] . '=' . $r['value'], $el['rows']))
                : (isset($el['bars']) ? 'bars ' . implode(', ', array_map(fn ($b) => $b['label'] . ' ' . round($b['value'] * 100) . '%', $el['bars']))
                : (isset($el['lines']) ? 'lines ' . count($el['lines']) : (isset($el['skeleton']) ? 'skeleton ' . $el['skeleton'] : '')));
            $then = isset($el['then']) ? ' THEN ' . implode(' ; ', array_map(fn ($p) => ($p['word'] ?? '?') . ':' . json_encode(array_diff_key($p, ['word' => 1, 'at' => 1])), $el['then'])) : '';
            printf("     %-7s %-12s %-4s %-6s w=%-11s %s%s %s%s
", $el['kind'], $el['place'], $el['depth'], $el['camera'], $el['word'] ?? '-',
                $label, isset($el['status']) ? ' [' . $el['status'] . ']' : '', $body, $then);
        }
        foreach ($slot['links'] ?? [] as $l) {
            printf("     link %s -> %s%s%s
", $l['from'], $l['to'], isset($l['label']) ? ' "' . $l['label'] . '"' : '', !empty($l['flow']) ? ' (flow)' : '');
        }
        $specs[] = ['scene_id' => $s['scene_id'] . '-' . preg_replace('/[^a-z]+/', '-', $name), 'narration' => $s['narration']['text'], 'slot' => $slot];
    }
}

file_put_contents($out, json_encode($specs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nwrote " . count($specs) . " staged card(s) to {$out}\n";
