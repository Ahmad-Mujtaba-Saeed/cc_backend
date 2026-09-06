<?php

/**
 * Invariant checks for the Moments Compilation fixes.
 *
 * Boots Laravel only far enough to use the facades, then exercises clip
 * selection and the clip timeline planner with synthetic data. No network,
 * no ffmpeg, no cost.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// No DB in this environment: prime the admin model switch in the array cache
// so LlmModels never reaches for app_settings.
config(['cache.default' => 'array']);
Illuminate\Support\Facades\Cache::put('app_setting:llm_model', 'auto', 3600);

use Modules\Project\Services\MomentRankingService;

$failures = [];
$checks = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $label . ($detail !== '' ? " -- {$detail}" : '');
        echo "FAIL  {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    } else {
        echo "ok    {$label}\n";
    }
}

/** Build non-overlapping candidate windows across $sourceCount sources. */
function candidates(int $sourceCount, float $sourceSeconds, float $clipSeconds): array
{
    $ranked = [];
    $id = 0;

    for ($source = 0; $source < $sourceCount; $source++) {
        for ($t = 0.0; $t + $clipSeconds < $sourceSeconds; $t += $clipSeconds) {
            $ranked[] = [
                'id' => $id++,
                'source_index' => $source,
                'start' => round($t, 2),
                'end' => round($t + $clipSeconds, 2),
                'snippet' => '',
                'heuristic_score' => 0.5,
                'final_score' => 1.0 - ($t / 10000),
            ];
        }
    }

    return $ranked;
}

$service = new MomentRankingService();
$maxClip = 6.0;
$pad = 3.0;

// -- 1. A long single source: spacing, budget and ordering invariants --------
$picked = $service->selectClips(candidates(1, 3600.0, $maxClip), 30, $maxClip, [0 => 3600.0], $pad);

$overlaps = 0;
$tooClose = 0;
$renderOverlap = 0;
$seen = [];
$dupes = 0;

foreach ($picked as $a) {
    $key = $a['source_index'] . ':' . $a['start'];
    if (isset($seen[$key])) {
        $dupes++;
    }
    $seen[$key] = true;

    foreach ($picked as $b) {
        if ($a === $b || $a['source_index'] !== $b['source_index']) {
            continue;
        }
        if ($a['start'] < $b['end'] && $a['end'] > $b['start']) {
            $overlaps++;
        }
        // Worst case the renderer pulls $pad extra seconds past a's end.
        if ($a['start'] < $b['end'] + $pad && $a['end'] + $pad > $b['start'] && $a['start'] > $b['start']) {
            $renderOverlap++;
        }
    }
}

ok('no duplicate windows selected', $dupes === 0, "dupes={$dupes}");
ok('no overlapping windows from one source', $overlaps === 0, "overlaps={$overlaps}");
ok('stretched clips cannot re-render neighbouring footage', $renderOverlap === 0, "collisions={$renderOverlap}");

$used = array_sum(array_map(fn ($c) => $c['end'] - $c['start'], $picked));
ok('per-source footage budget respected', $used <= 90.0 + 0.01, "used={$used}s of 90s");

$counters = array_column($picked, 'counter');
ok('countdown runs N..1 with no gaps', $counters === range(count($picked), 1, -1), implode(',', array_slice($counters, 0, 5)) . '...');

// -- 2. Three sources: interleaving + reaching the target -------------------
$picked3 = $service->selectClips(
    candidates(3, 3600.0, $maxClip),
    30,
    $maxClip,
    [0 => 3600.0, 1 => 3600.0, 2 => 3600.0],
    $pad
);

ok('reaches the requested clip count', count($picked3) === 30, 'got ' . count($picked3));

$adjacentSameSource = 0;
for ($i = 1; $i < count($picked3); $i++) {
    if ($picked3[$i]['source_index'] === $picked3[$i - 1]['source_index']) {
        $adjacentSameSource++;
    }
}
ok('never two clips from the same source back to back', $adjacentSameSource === 0, "adjacent={$adjacentSameSource}");

// -- 3. A short source: degrades instead of repeating or looping ------------
$short = $service->selectClips(candidates(1, 60.0, $maxClip), 30, $maxClip, [0 => 60.0], $pad);
ok('short source yields some clips', count($short) > 0, 'got ' . count($short));
ok('short source does not fabricate the target', count($short) <= 30, 'got ' . count($short));

$shortDupes = count($short) - count(array_unique(array_map(fn ($c) => $c['start'], $short)));
ok('short source produces no repeats', $shortDupes === 0, "dupes={$shortDupes}");

// -- 4. Clip timeline planner ----------------------------------------------
$reflection = new ReflectionClass(Modules\Project\Processors\CompilationShortsProcessor::class);
$processor = $reflection->newInstanceWithoutConstructor();

$projectProp = $reflection->getProperty('project');
$projectProp->setAccessible(true);
$projectProp->setValue($processor, new Modules\Project\Models\Project());

$settings = $reflection->getProperty('settings');
$settings->setAccessible(true);
$settings->setValue($processor, ['aspect_ratio' => '9:16', 'original_audio' => 'background_only']);

$plan = $reflection->getMethod('planClipTimeline');
$plan->setAccessible(true);

// A fake voiceover file so the planner treats the clip as voiced.
$fakeVoice = sys_get_temp_dir() . '/compilation_check_voice.wav';
file_put_contents($fakeVoice, 'x');

$clips = [
    ['source_index' => 0, 'start' => 10.0, 'end' => 16.0, 'counter' => 3],   // voice fits
    ['source_index' => 0, 'start' => 40.0, 'end' => 43.0, 'counter' => 2],   // voice overruns -> tempo
    ['source_index' => 0, 'start' => 118.0, 'end' => 124.0, 'counter' => 1], // runs off the end -> freeze
];
$tts = [
    ['audio_path' => $fakeVoice, 'duration' => 4.0],
    ['audio_path' => $fakeVoice, 'duration' => 9.0],
    ['audio_path' => $fakeVoice, 'duration' => 5.0],
];

$steps = $plan->invoke($processor, $clips, $tts, [0 => 120.0]);

$lead = 0.15;
$tail = 0.30;
$speechFits = true;
$forwardOnly = true;

foreach ($steps as $i => $step) {
    $spoken = $tts[$i]['duration'] / $step['voice_tempo'];
    if ($step['duration'] + 1e-6 < $lead + $spoken + $tail) {
        $speechFits = false;
    }
    if ($step['start'] !== round($clips[$i]['start'], 3)) {
        $forwardOnly = false;
    }
}

ok('every clip is long enough to finish its commentary', $speechFits);
ok('clips only ever extend forwards from their window', $forwardOnly);
ok('overrunning line is sped up, not cut', $steps[1]['voice_tempo'] > 1.0 && $steps[1]['voice_tempo'] <= 1.25, 'tempo=' . $steps[1]['voice_tempo']);
ok('clip past the end of the source holds its last frame', $steps[2]['freeze_seconds'] > 0.0, 'freeze=' . $steps[2]['freeze_seconds']);
ok('clip inside the source needs no freeze', $steps[0]['freeze_seconds'] === 0.0, 'freeze=' . $steps[0]['freeze_seconds']);

$keys = array_column($steps, 'key');
ok('cache keys are content-derived and unique', count(array_unique($keys)) === count($keys), implode(',', $keys));

// Same inputs -> same keys (a resume reuses its own files, not a stale run's).
$again = $plan->invoke($processor, $clips, $tts, [0 => 120.0]);
ok('cache keys are stable across runs', array_column($again, 'key') === $keys);

// A different window must not reuse another clip's file.
$moved = $clips;
$moved[0]['start'] = 200.0;
$moved[0]['end'] = 206.0;
$movedKeys = array_column($plan->invoke($processor, $moved, $tts, [0 => 300.0]), 'key');
ok('moving a window changes its cache key', $movedKeys[0] !== $keys[0]);

@unlink($fakeVoice);

// -- 5. Caption lines never span two clips ---------------------------------
$captionService = new Modules\Project\Services\WordLevelCaptionService();
$eventsMethod = new ReflectionMethod($captionService, 'generateASSEvents');
$eventsMethod->setAccessible(true);
$chunk = new ReflectionMethod($captionService, 'chunkIntoLines');
$chunk->setAccessible(true);

$words = [];
foreach ([0, 1] as $clipIndex) {
    for ($w = 0; $w < 4; $w++) {
        $base = $clipIndex * 10.0;
        $words[] = ['word' => "c{$clipIndex}w{$w}", 'start' => $base + $w * 0.3, 'end' => $base + $w * 0.3 + 0.25, 'group' => $clipIndex];
    }
}

$lines = $chunk->invoke($captionService, $words, 5);
$mixed = 0;
foreach ($lines as $line) {
    if (count(array_unique(array_column($line, 'group'))) > 1) {
        $mixed++;
    }
}
ok('no caption line mixes two clips', $mixed === 0, "mixed={$mixed}");
ok('caption lines are split, not merged into one', count($lines) >= 2, 'lines=' . count($lines));

// Templates that pass ungrouped timings (horror, AI video, image shorts...)
// must keep their existing five-word lines.
$ungrouped = array_map(function ($w) {
    unset($w['group']);
    return $w;
}, $words);
$plainLines = $chunk->invoke($captionService, $ungrouped, 5);
ok('ungrouped timings keep the old 5-word chunking', $plainLines == array_chunk($ungrouped, 5), 'lines=' . count($plainLines));

echo "\n" . ($failures ? count($failures) . " FAILED of {$checks}\n" : "all {$checks} checks passed\n");
exit($failures ? 1 : 0);
