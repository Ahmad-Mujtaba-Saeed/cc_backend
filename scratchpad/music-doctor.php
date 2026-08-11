<?php

/**
 * music-doctor — why is there no music on the video?
 *
 * The render server cannot answer this. Remotion is handed a finished
 * `music: {url, volume, mood}` block (or nothing at all) and simply plays what
 * it is given — every decision that can drop the bed happens in the LARAVEL
 * worker, inside RemotionRenderService::resolveMusic(), and most of its exits
 * are silent. This walks the same decision in order and prints where it stops.
 *
 *   docker compose exec app php scratchpad/music-doctor.php <project_id>
 *   php scratchpad/music-doctor.php <project_id>          # on a bare VPS
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Models\Project;
use Modules\Project\Services\MusicLibraryService;
use Modules\Project\Services\MusicProviderFactory;
use Modules\Project\Services\RemotionRenderService;

$projectId = (int) ($argv[1] ?? 0);
$project = $projectId > 0 ? Project::find($projectId) : null;
if (!$project) {
    echo "usage: php scratchpad/music-doctor.php <project_id>\n";
    exit(1);
}

$settings = $project->settings ?? [];
$say = fn (string $label, $value) => printf("  %-26s %s\n", $label . ':', is_scalar($value) || $value === null
    ? var_export($value, true)
    : json_encode($value));

echo "\n=== project {$project->id} — {$project->title} ===\n";
$say('template', $project->template_type);
$say('status', $project->status);

echo "\n=== 1. is a bed wanted at all? ===\n";
$enabled = ($settings['music_enabled'] ?? true) !== false;
$category = strtolower(trim((string) ($settings['music_category'] ?? 'auto')));
$say('music_enabled', $enabled);
$say('music_category', $category);
$say('music_track_id', $settings['music_track_id'] ?? null);
$say('music_volume', $settings['music_volume'] ?? MusicProviderInterface::DEFAULT_VOLUME);
$say('music_track (cached path)', $settings['music_track'] ?? null);

if (!$enabled) {
    echo "\nSTOP: music is switched OFF for this project — the storyboard's Music toggle.\n";
    exit(0);
}
if ($category === 'none') {
    echo "\nSTOP: the category is 'none', which means silence. Pick a style in the music panel.\n";
    exit(0);
}

echo "\n=== 2. can the provider hand us a track? ===\n";
$provider = MusicProviderFactory::provider();
$service = MusicProviderFactory::make();
$say('admin-selected provider', $provider);
$say('provider configured', $service->isConfigured());
if (!$service->isConfigured()) {
    echo "  ! No API credential for {$provider} in the api_credentials pool (Admin → API keys).\n"
        . "    Without one the provider returns nothing and the render falls back to the local\n"
        . "    library — storage/app/public/audio/<category>/*.mp3 — which may be empty on this box.\n";
}

$mood = 'neutral';
$counts = [];
foreach ($project->explainerScenes()->get() as $scene) {
    $m = (string) ($scene->mood ?? 'neutral');
    $counts[$m] = ($counts[$m] ?? 0) + 1;
}
if ($counts !== []) {
    arsort($counts);
    $mood = (string) array_key_first($counts);
}
$say('dominant scene mood', $mood);

$t0 = microtime(true);
$picked = $service->pickTrack(
    $category === '' || $category === 'auto' ? 'corporate' : $category,
    (int) $project->id,
    ($settings['music_track_id'] ?? '') !== '' ? (string) $settings['music_track_id'] : null
);
$say('pickTrack() returned', $picked);
$say('...in seconds', round(microtime(true) - $t0, 1));
if ($picked === null) {
    echo "  ! The provider had nothing for this category. On a fresh VPS the usual causes are\n"
        . "    a missing/blocked API key, no outbound HTTPS from this container, or a download\n"
        . "    that failed to WRITE (check permissions on storage/app/public/audio).\n";
}

echo "\n=== 3. is the file really on disk, and big enough? ===\n";
$track = $settings['music_track'] ?? $picked;
if ($track === null) {
    echo "  no track to check\n";
} else {
    $say('relative path', $track);
    $say('absolute path', Storage::disk('public')->path($track));
    $exists = Storage::disk('public')->exists($track);
    $say('exists', $exists);
    $say('bytes', $exists ? Storage::disk('public')->size($track) : 0);
    $say('passes isHealthy()', (new MusicLibraryService())->isHealthy($track));
    if ($exists && !(new MusicLibraryService())->isHealthy($track)) {
        echo "  ! The file is there but too small — a truncated or error-page download.\n"
            . "    resolveMusic() drops it deliberately: Remotion FAILS THE WHOLE RENDER on a bad\n"
            . "    audio source, so a suspect bed becomes silence instead.\n";
    }
}

echo "\n=== 4. what the render server is actually told ===\n";
// The real method, called the real way — no re-implementation to drift.
$renderer = new RemotionRenderService();
$reflection = new ReflectionMethod($renderer, 'resolveMusic');
$reflection->setAccessible(true);
$sceneArrays = $project->explainerScenes()->orderBy('order')->get()
    ->map(fn ($s) => ['mood' => $s->mood ?? 'neutral'])->all();
$music = $reflection->invoke($renderer, $project, $sceneArrays);

if ($music === null) {
    echo "  music: null  → the video renders SILENT. The step above that printed a ! is why.\n";
} else {
    $say('url', $music['url']);
    $say('volume', $music['volume']);
    $say('mood', $music['mood']);

    echo "\n  Remotion FETCHES that URL — the bed is not muxed in by ffmpeg, it is an <Audio>\n"
        . "  tag inside the composition. Images work the same way, so if pictures appear in the\n"
        . "  video the base URL is fine and the problem is upstream of here.\n";
    $say('REMOTION_ASSET_BASE_URL', config('services.remotion.asset_base_url'));
    $say('render service URL', config('services.remotion.url'));
    $say('APP_URL', config('app.url'));
    echo "  (Pointing the asset base at the RENDER service's own port is deliberate: it serves\n"
        . "   /storage itself from HOST_STORAGE_PREFIX. On a VPS that env var must point at the\n"
        . "   very directory Laravel writes to, or every asset 404s — music and images alike.)\n";

    echo "\n  Reachability, tested from THIS container (not necessarily the render host):\n";
    try {
        $head = \Illuminate\Support\Facades\Http::timeout(8)->head($music['url']);
        $say('HTTP status', $head->status());
        $say('content-type', $head->header('Content-Type'));
        $say('content-length', $head->header('Content-Length'));
        if ($head->status() !== 200) {
            echo "  ! Not 200 — Remotion would get the same and drop (or fail on) the bed.\n";
        }
    } catch (\Throwable $e) {
        echo "  (could not reach it from here: {$e->getMessage()})\n";
        echo "  That is EXPECTED when the render service runs outside this container — the check\n"
            . "  that counts is the same curl run ON the render host:\n";
    }
    echo "    curl -sSI '{$music['url']}' | head -n 1     # want: HTTP/1.1 200 OK\n";
}

echo "\n=== 5. are you even watching a NEW render? ===\n";
// The commonest false alarm: audio settings do not mark a finished video
// stale, because the staleness signal is a hash of things that change a FRAME.
$output = $project->output_path;
$say('output_path', $output);
if ($output && Storage::disk('public')->exists($output)) {
    $rendered = \Illuminate\Support\Carbon::createFromTimestamp(Storage::disk('public')->lastModified($output));
    $say('video rendered at', $rendered->toDateTimeString());
    $say('project last touched', $project->updated_at?->toDateTimeString());
    if ($project->updated_at && $project->updated_at->greaterThan($rendered)) {
        echo "  ! The project changed AFTER this file was rendered. If you picked the music since\n"
            . "    then, this MP4 simply predates the choice — nothing re-renders on its own, and\n"
            . "    a music change does NOT mark the video stale in the UI (the stale badge tracks\n"
            . "    the LOOK hash, and music cannot change a frame). Re-render and listen again.\n";
    }
} else {
    echo "  no rendered file on disk yet\n";
}

echo "\n=== 6. can you actually HEAR it? ===\n";
$volume = (float) ($settings['music_volume'] ?? MusicProviderInterface::DEFAULT_VOLUME);
$say('mix level', $volume);
echo "  The bed sits under the voiceover and is DUCKED further while anyone speaks\n"
    . "  (musicVolumeCurve in ExplainerVideo.tsx). On a video that is narrated end to end,\n"
    . "  {$volume} can be almost inaudible on laptop speakers — raise it in the music panel\n"
    . "  before concluding it was dropped. Definitive test on the finished file:\n";
if ($output) {
    echo "    ffmpeg -i " . Storage::disk('public')->path($output) . " -af volumedetect -f null - 2>&1 | tail -5\n";
}

echo "\n=== 7. where the logs are ===\n";
echo "  This whole decision is made in the LARAVEL WORKER. The render service never sees it:\n";
echo "    docker compose logs -f worker\n";
echo "    tail -f storage/logs/laravel.log | grep -i -E 'music|remotion'\n";
echo "  The render service only logs [render]/[preview]/[thumbnail] lines and Chrome errors.\n";

echo "\n";
