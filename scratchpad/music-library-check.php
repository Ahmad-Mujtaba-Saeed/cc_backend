<?php

/**
 * Verifies the fix for the render-killing music bug: a provider must never
 * return a path whose bytes are not on disk.
 *
 * The failure this guards against (project 135, 2026-08-08): the queue worker
 * runs as www-data while the web container runs as root, so a category folder
 * created by one was unwritable by the other. `Storage::put()` on the public
 * disk is configured `'throw' => false`, so the denied write returned FALSE,
 * the old download() ignored that return value, logged "cached track" and
 * handed back audio/jamendo/technology/1129271.mp3 — a file that was never
 * written. Remotion then 404'd on it and failed the whole render with
 * "Could not play audio … MediaError".
 *
 * Run inside the container AS THE WORKER'S USER — running it as root proves
 * nothing, because root could always write:
 *
 *   docker compose exec -u www-data app php scratchpad/music-library-check.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use Modules\Project\Services\MusicLibraryService;
use Modules\Project\Services\MusicProviderFactory;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
}

$library = new MusicLibraryService();
$disk = Storage::disk('public');

echo PHP_EOL . 'Running as uid ' . (function_exists('posix_geteuid') ? posix_geteuid() : '?')
    . ' (the worker runs as www-data / 33)' . PHP_EOL . PHP_EOL;

// --- the library's own guarantees -------------------------------------------

echo "MusicLibraryService" . PHP_EOL;

check('a path that does not exist is never healthy', !$library->isHealthy('audio/nope/nope/nope.mp3'));

$stub = 'audio/_check/_check/tiny.mp3';
$disk->put($stub, str_repeat('x', 128));
check('a too-small file is not healthy (an error page is not a track)', !$library->isHealthy($stub));
$disk->deleteDirectory('audio/_check');

check('a dead URL stores nothing at all', $library->store(
    'jamendo',
    'technology',
    'checkdead',
    'https://prod-1.storage.jamendo.com/?trackid=0&format=mp32'
) === null);
check('… and leaves no half-written file behind', !$disk->exists('audio/jamendo/technology/checkdead.mp3'));

check('a path component cannot escape the library', $library->store(
    '../../..',
    '../../etc',
    '../../passwd',
    'https://example.invalid/x.mp3'
) === null);

// --- the provider, end to end ------------------------------------------------

$providerName = MusicProviderFactory::provider();
$provider = MusicProviderFactory::make($providerName);

echo PHP_EOL . "Provider: {$providerName}" . PHP_EOL;

if (!$provider->isConfigured()) {
    echo '  SKIP  no credential configured — provider round-trip not exercised' . PHP_EOL;
} else {
    $path = $provider->pickTrack('technology', 7);
    check('pickTrack returns a path', is_string($path) && $path !== '');

    if (is_string($path)) {
        // THE regression: whatever comes back must be playable, because the
        // renderer turns it straight into a URL Remotion will fetch.
        check('… whose bytes are actually on disk', $library->isHealthy($path));
        check('… at a plausible size for audio', $disk->exists($path) && $disk->size($path) > 100000);

        $id = pathinfo($path, PATHINFO_FILENAME);
        check('… filed under its category', str_contains($path, "/technology/"));
        check('… with a metadata sidecar', $disk->exists(dirname($path) . "/{$id}.json"));

        // A re-render of the same project must reuse the file, not refetch it.
        $again = $provider->pickTrack('technology', 7);
        check('the same seed picks the same cached track', $again === $path);

        // The user's explicit pick resolves to the same local copy.
        $chosen = $provider->pickTrack('technology', 99, $id);
        check('an explicit track id resolves to that local file', $chosen === $path);
    }

    $cached = $library->cached($providerName, 'technology');
    check('the category is browsable offline', count($cached) > 0);
    check('… with readable titles, not bare ids', $cached !== [] && trim($cached[0]['title']) !== '');
}

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL . PHP_EOL;

exit($fail === 0 ? 0 : 1);
