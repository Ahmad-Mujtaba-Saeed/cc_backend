<?php

/**
 * Runs the REAL renderSingleClip filtergraph against synthetic footage and
 * checks the invariant the fixes rest on: every clip is exactly as long as it
 * was planned to be, on BOTH streams, so nothing is ever cut short and the
 * caption/counter offsets stay true.
 *
 * Needs a full ffmpeg on PATH (or FFMPEG_DIR pointing at its bin/).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['cache.default' => 'array']);
Illuminate\Support\Facades\Cache::put('app_setting:llm_model', 'auto', 3600);

use Modules\Project\Processors\CompilationShortsProcessor;
use Modules\Project\Models\Project;
use Symfony\Component\Process\Process;

$ffmpegDir = getenv('FFMPEG_DIR') ?: '';
if ($ffmpegDir !== '') {
    putenv('PATH=' . $ffmpegDir . PATH_SEPARATOR . getenv('PATH'));
    $_SERVER['PATH'] = getenv('PATH');
}

$work = sys_get_temp_dir() . '/compilation_render_check';
if (!is_dir($work)) {
    mkdir($work, 0777, true);
}
array_map('unlink', glob($work . '/*') ?: []);

$failures = 0;
$checks = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "ok    {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    } else {
        $failures++;
        echo "FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function run(array $cmd): Process
{
    $p = new Process($cmd);
    $p->setTimeout(600);
    $p->run();

    return $p;
}

function probe(string $path, string $stream): float
{
    $p = run(['ffprobe', '-v', 'error', '-select_streams', $stream, '-show_entries', 'stream=duration',
        '-of', 'csv=p=0', $path]);

    return (float) trim($p->getOutput());
}

// --- synthetic inputs ------------------------------------------------------
// 20s of moving colour with a tone, so both streams are real.
$source = $work . '/source.mp4';
$make = run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=30:duration=20',
    '-f', 'lavfi', '-i', 'sine=frequency=440:duration=20',
    '-c:v', 'libx264', '-preset', 'ultrafast', '-c:a', 'aac', '-shortest', $source]);
if (!$make->isSuccessful()) {
    fwrite(STDERR, "could not build the test source:\n" . $make->getErrorOutput() . "\n");
    exit(2);
}

// A 5s "voiceover".
$voice = $work . '/voice.wav';
run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'sine=frequency=880:duration=5', '-ar', '44100', '-ac', '2', $voice]);

// --- reach into the processor ---------------------------------------------
$reflection = new ReflectionClass(CompilationShortsProcessor::class);
$processor = $reflection->newInstanceWithoutConstructor();

$projectProp = $reflection->getProperty('project');
$projectProp->setAccessible(true);
$projectProp->setValue($processor, new Project());

$settingsProp = $reflection->getProperty('settings');
$settingsProp->setAccessible(true);
$settingsProp->setValue($processor, []);

$render = $reflection->getMethod('renderSingleClip');
$render->setAccessible(true);

// A "background bed" that deliberately runs SHORT: the old graph let this
// decide when the mix stopped, which is what cut commentary off mid-word.
$shortBed = $work . '/bed_short.wav';
run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=1.5', '-ar', '44100', '-ac', '2', $shortBed]);

$fullBed = $work . '/bed_full.wav';
run(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=6', '-ar', '44100', '-ac', '2', $fullBed]);

$cases = [
    'bed + voice' => [
        'step' => ['start' => 2.0, 'duration' => 6.0, 'source_seconds' => 6.0, 'freeze_seconds' => 0.0,
            'voice_path' => $voice, 'voice_tempo' => 1.0, 'voice_offset' => 0.15],
        'bed' => $fullBed,
        'mode' => 'background_only',
    ],
    'bed runs out mid-clip' => [
        'step' => ['start' => 2.0, 'duration' => 6.0, 'source_seconds' => 6.0, 'freeze_seconds' => 0.0,
            'voice_path' => $voice, 'voice_tempo' => 1.0, 'voice_offset' => 0.15],
        'bed' => $shortBed,
        'mode' => 'background_only',
    ],
    'voice sped up to fit' => [
        'step' => ['start' => 2.0, 'duration' => 4.5, 'source_seconds' => 4.5, 'freeze_seconds' => 0.0,
            'voice_path' => $voice, 'voice_tempo' => 1.25, 'voice_offset' => 0.15],
        'bed' => $fullBed,
        'mode' => 'keep',
    ],
    'voice only (muted original)' => [
        'step' => ['start' => 2.0, 'duration' => 5.6, 'source_seconds' => 5.6, 'freeze_seconds' => 0.0,
            'voice_path' => $voice, 'voice_tempo' => 1.0, 'voice_offset' => 0.15],
        'bed' => null,
        'mode' => 'mute',
    ],
    'bed only (no commentary)' => [
        'step' => ['start' => 2.0, 'duration' => 5.0, 'source_seconds' => 5.0, 'freeze_seconds' => 0.0,
            'voice_path' => null, 'voice_tempo' => 1.0, 'voice_offset' => 0.0],
        'bed' => $fullBed,
        'mode' => 'keep',
    ],
    'silent clip' => [
        'step' => ['start' => 2.0, 'duration' => 4.0, 'source_seconds' => 4.0, 'freeze_seconds' => 0.0,
            'voice_path' => null, 'voice_tempo' => 1.0, 'voice_offset' => 0.0],
        'bed' => null,
        'mode' => 'mute',
    ],
    'runs past the end of the source' => [
        'step' => ['start' => 17.0, 'duration' => 6.0, 'source_seconds' => 3.0, 'freeze_seconds' => 3.0,
            'voice_path' => $voice, 'voice_tempo' => 1.0, 'voice_offset' => 0.15],
        'bed' => $fullBed,
        'mode' => 'background_only',
    ],
];

$clipPaths = [];
$i = 0;

foreach ($cases as $label => $case) {
    $out = $work . '/clip_' . $i++ . '.mp4';
    $clipPaths[] = $out;

    try {
        $render->invoke($processor, $source, $out, $case['step'], 1080, 1920, $case['bed'], $case['mode']);
    } catch (\Throwable $e) {
        ok($label . ': renders', false, substr($e->getMessage(), 0, 300));
        continue;
    }

    $planned = (float) $case['step']['duration'];
    $video = probe($out, 'v:0');
    $audio = probe($out, 'a:0');

    ok($label . ': video is the planned length', abs($video - $planned) <= 0.06, sprintf('%.3fs vs %.3fs', $video, $planned));
    ok($label . ': audio is the planned length', abs($audio - $planned) <= 0.06, sprintf('%.3fs vs %.3fs', $audio, $planned));
    ok($label . ': streams stay locked together', abs($video - $audio) <= 0.06, sprintf('delta %.3fs', abs($video - $audio)));

    // Frame size must be identical everywhere or concat re-encodes badly.
    $p = run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height',
        '-of', 'csv=p=0', $out]);
    ok($label . ': 9:16 frame', trim($p->getOutput()) === '1080,1920', trim($p->getOutput()));
}

// --- concat them the way assembleVideo does and check the total ------------
$list = $work . '/concat.txt';
$content = '';
$sum = 0.0;
foreach ($clipPaths as $path) {
    if (!file_exists($path)) {
        continue;
    }
    $content .= "file '" . str_replace('\\', '/', $path) . "'\n";
    $p = run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path]);
    $sum += (float) trim($p->getOutput());
}
file_put_contents($list, $content);

$assembled = $work . '/assembled.mp4';
$concat = run(['ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', $list,
    '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '28',
    '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2', '-pix_fmt', 'yuv420p', $assembled]);

ok('concat succeeds', $concat->isSuccessful(), substr($concat->getErrorOutput(), -300));

if ($concat->isSuccessful()) {
    $p = run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $assembled]);
    $total = (float) trim($p->getOutput());

    // This is the number assembleVideo's cursor accumulates. If it drifts, the
    // countdown badge and the captions slide onto the wrong clip.
    ok('timeline cursor matches the assembled video', abs($total - $sum) <= 0.25, sprintf('%.3fs vs cursor %.3fs', $total, $sum));
}

echo "\n" . ($failures ? "{$failures} FAILED of {$checks}\n" : "all {$checks} checks passed\n");
exit($failures ? 1 : 0);
