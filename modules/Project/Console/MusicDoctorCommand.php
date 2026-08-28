<?php

namespace Modules\Project\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\AccessControl\Models\ApiCredential;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Models\Project;
use Modules\Project\Services\MusicLibraryService;
use Modules\Project\Services\MusicProviderFactory;
use Modules\Project\Services\RemotionRenderService;
use ReflectionMethod;
use Throwable;

/**
 * Why is there no background music on the video — and can we fix it here?
 *
 * The render server cannot answer this. Remotion is handed a finished
 * `music: {url, volume, mood}` block (or nothing) and plays what it is given;
 * every decision that can drop the bed happens in the LARAVEL worker inside
 * RemotionRenderService::resolveMusic(). This walks the same ground the worker
 * walks, in order, and stops where the worker would stop.
 *
 * It exists as a real command (not a scratchpad script) because the box that
 * needs it is a VPS, where `storage/` was never deployed — it is gitignored,
 * so a fresh install starts with an EMPTY music library while the developer
 * machine has months of downloads in it. That single difference is the usual
 * reason music "works locally but not on the server".
 *
 *   php artisan music:doctor              environment only
 *   php artisan music:doctor 42           …plus what project 42 would render
 *   php artisan music:doctor --fix        …and repair what can be repaired
 */
class MusicDoctorCommand extends Command
{
    protected $signature = 'music:doctor
                            {project? : A project id to trace, as well as the environment}
                            {--fix : Repair what can be repaired (permissions, then fill the library)}';

    protected $description = 'Diagnose (and optionally repair) background music: credentials, library, disk, URLs';

    private bool $healthy = true;

    /** Set when the database is unreachable, so DB-dependent steps are skipped. */
    private bool $dbDown = false;

    public function handle(): int
    {
        $this->checkProviders();
        $this->checkLibrary();
        $this->checkAssetUrl();

        if ($this->option('fix')) {
            $this->repair();
        }

        $projectId = (int) $this->argument('project');
        if ($projectId > 0) {
            $this->traceProject($projectId);
        }

        $this->newLine();
        if ($this->healthy) {
            $this->info('Music looks serviceable on this host.');

            return self::SUCCESS;
        }

        $this->warn('Music is NOT serviceable on this host — see the ! lines above.');
        if (!$this->option('fix')) {
            $this->line('  Try: php artisan music:doctor --fix');
        }

        return self::FAILURE;
    }

    /* ---------------------------------------------------------------- */

    private function checkProviders(): void
    {
        $this->section('1. Provider credentials (database, not .env)');

        // Both the provider switch and the credential pool are DATABASE state.
        // A doctor that dies on an unreachable database is useless on exactly
        // the box that needs it, so a connection failure is a finding, not a
        // stack trace — and the disk checks below still run.
        try {
            $selected = MusicProviderFactory::provider();
        } catch (Throwable $e) {
            $this->problem(
                'Cannot read the provider setting: ' . $e->getMessage(),
                'The music provider and its API keys live in the database. With no database there',
                'is no catalogue to download from — only tracks already on disk can be used.'
            );
            $this->dbDown = true;

            return;
        }

        $this->kv('admin-selected provider', $selected);

        $anyConfigured = false;
        foreach (MusicProviderFactory::PROVIDERS as $name) {
            try {
                $configured = MusicProviderFactory::make($name)->isConfigured();
                $keys = ApiCredential::forProvider($name);
                $count = is_countable($keys) ? count($keys) : 0;
            } catch (Throwable $e) {
                $this->kv($name, 'could not check: ' . $e->getMessage());
                continue;
            }
            $anyConfigured = $anyConfigured || $configured;
            $this->kv(
                $name . ($name === $selected ? ' (selected)' : ''),
                $configured ? "configured, {$count} key(s)" : 'NO CREDENTIAL'
            );
        }

        if ($this->dbDown) {
            return;
        }

        if (!$anyConfigured) {
            $this->problem(
                'Neither music provider has a credential in the api_credentials table.',
                'Add one in Admin -> API keys. Credentials live in the DATABASE, so they do not',
                'travel with a deploy — a new VPS has none until someone adds them there.'
            );
        } elseif (!MusicProviderFactory::make($selected)->isConfigured()) {
            $this->note(
                'The selected provider has no credential; renders will now borrow the other one',
                'rather than going silent. Fix the selection or add the key to make this explicit.'
            );
        }
    }

    private function checkLibrary(): void
    {
        $this->section('2. The local library on THIS box');

        $library = new MusicLibraryService();
        $disk = Storage::disk('public');
        $root = MusicLibraryService::ROOT;

        $this->kv('library root', $disk->path($root));
        $this->kv('exists', $disk->exists($root) ? 'yes' : 'no');
        $this->kv('writable', is_writable($disk->path('')) ? 'yes' : 'NO');

        $total = 0;
        $rows = [];
        foreach (MusicProviderInterface::CATEGORIES as $category) {
            $counts = [];
            foreach (MusicProviderFactory::PROVIDERS as $provider) {
                $n = count($library->cached($provider, $category));
                $total += $n;
                if ($n > 0) {
                    $counts[] = "{$provider}:{$n}";
                }
            }
            $folder = count($library->folderTracks($root . '/' . $category));
            $total += $folder;
            if ($folder > 0) {
                $counts[] = "folder:{$folder}";
            }
            $rows[] = [$category, $counts === [] ? '—' : implode('  ', $counts)];
        }
        $this->table(['Category', 'Playable tracks'], $rows);

        // The rung that actually decides whether a render can be silent.
        $sweep = $library->anyHealthyTrack(0, [], ['audio/horror', 'audio/user']);
        $this->kv('last-resort sweep finds', $sweep ?? 'NOTHING');

        if ($sweep === null) {
            $this->healthy = false;
            $this->problem(
                'There is no playable music file anywhere on this host, so every explainer',
                'renders SILENT no matter what the storyboard asks for.',
                '',
                'This is the usual "works locally, not on the server" cause: storage/ is',
                'gitignored, so a deploy never carries the music library, and the provider',
                'API keys live in the database, so a new box has none either. Two ways out:',
                '',
                '  A) Add a Pixabay or Jamendo key in Admin -> API keys, then run:',
                '       php artisan music:doctor --fix',
                '',
                '  B) Copy the library from a machine that has one, e.g. from your dev box:',
                '       rsync -av storage/app/public/audio/ USER@VPS:'
                    . '/path/to/app/storage/app/public/audio/',
                '     then, on the VPS:  chmod -R a+rX storage/app/public/audio'
            );
        } elseif ($total === 0) {
            $this->note('Only the sweep found anything — categories are empty. Run: php artisan music:cache');
        }
    }

    private function checkAssetUrl(): void
    {
        $this->section('3. The URL the render service will fetch');

        $base = config('services.remotion.asset_base_url') ?: config('app.url');
        $this->kv('REMOTION_ASSET_BASE_URL', config('services.remotion.asset_base_url') ?: '(unset — falling back to APP_URL)');
        $this->kv('APP_URL', config('app.url'));
        $this->kv('render service URL', config('services.remotion.url'));
        $this->kv('effective asset base', $base);

        if (!$base) {
            $this->healthy = false;
            $this->problem('No asset base URL at all — every asset URL would be relative and unfetchable.');

            return;
        }

        $sweep = (new MusicLibraryService())->anyHealthyTrack(0, [], ['audio/horror', 'audio/user']);
        if ($sweep === null) {
            return; // nothing to probe; already reported above
        }

        // Encoded exactly the way RemotionRenderService::publicUrl() encodes it,
        // so what we probe is byte-for-byte what Remotion would be handed.
        $url = rtrim((string) $base, '/') . '/storage/'
            . implode('/', array_map('rawurlencode', explode('/', ltrim($sweep, '/'))));
        $this->kv('probing', $url);

        try {
            $head = Http::timeout(8)->head($url);
            $this->kv('HTTP status', (string) $head->status());
            if ($head->status() !== 200) {
                $this->healthy = false;
                $this->problem(
                    'That URL does not return 200. Remotion fetches music with an <Audio> tag, so a',
                    'non-200 makes the render FAIL outright, not merely lose the bed. Images use the',
                    'same base, so if pictures appear in your videos the problem is this file, not the base.'
                );
            }
        } catch (Throwable $e) {
            $this->note(
                'Could not reach it from here: ' . $e->getMessage(),
                'That is EXPECTED when the render service runs outside this container. Run the same',
                "check on the render host:  curl -sSI '{$url}' | head -n 1"
            );
        }
    }

    private function repair(): void
    {
        $this->section('4. Repairing');

        // Permissions first: the web container runs as root and the worker as
        // www-data, and whoever creates a folder first owns it — the download
        // below fails silently if the other user cannot write inside.
        $disk = Storage::disk('public');
        foreach (['', MusicLibraryService::ROOT] as $dir) {
            try {
                $abs = $disk->path($dir);
                if (!is_dir($abs)) {
                    @mkdir($abs, 0777, true);
                }
                @chmod($abs, 0777);
                $this->kv('relaxed', $abs);
            } catch (Throwable $e) {
                $this->note('could not chmod ' . $dir . ': ' . $e->getMessage());
            }
        }

        if ($this->dbDown) {
            $this->note('Database unreachable — cannot look up credentials, so nothing can be downloaded.');

            return;
        }

        try {
            $usable = false;
            foreach (MusicProviderFactory::PROVIDERS as $name) {
                $usable = $usable || MusicProviderFactory::make($name)->isConfigured();
            }
        } catch (Throwable $e) {
            $this->note('Could not check credentials: ' . $e->getMessage());

            return;
        }

        if (!$usable) {
            $this->note('No provider credential — cannot download anything. Add a key in Admin -> API keys.');

            return;
        }

        $this->line('  Filling the library (php artisan music:cache)…');
        $this->call('music:cache', ['--per-category' => 2]);

        // Re-check the one thing that decides silence.
        $sweep = (new MusicLibraryService())->anyHealthyTrack(0, [], ['audio/horror', 'audio/user']);
        if ($sweep !== null) {
            $this->healthy = true;
            $this->info('  Library now holds playable tracks — ' . $sweep);
        }
    }

    private function traceProject(int $projectId): void
    {
        $this->section("5. What project {$projectId} would actually render");

        try {
            $project = Project::find($projectId);
        } catch (Throwable $e) {
            $this->note('Database unreachable: ' . $e->getMessage());

            return;
        }

        if (!$project) {
            $this->note("No project {$projectId}.");

            return;
        }

        $settings = $project->settings ?? [];
        $this->kv('music_enabled', var_export($settings['music_enabled'] ?? true, true));
        $this->kv('music_category', (string) ($settings['music_category'] ?? 'auto'));
        $this->kv('music_track_id', (string) ($settings['music_track_id'] ?? '—'));
        $this->kv('music_volume', (string) ($settings['music_volume'] ?? MusicProviderInterface::DEFAULT_VOLUME));
        if (!empty($settings['music_last_miss'])) {
            $this->kv('last miss', json_encode($settings['music_last_miss']));
        }

        if (($settings['music_enabled'] ?? true) === false) {
            $this->note("Music is switched OFF for this project (the storyboard's Music toggle).");

            return;
        }
        if (strtolower(trim((string) ($settings['music_category'] ?? 'auto'))) === 'none') {
            $this->note("The category is 'none', which means silence by request.");

            return;
        }

        // Call the real method the real way, so this can never drift from it.
        try {
            $scenes = $project->explainerScenes()->orderBy('order')->get()
                ->map(fn ($s) => ['mood' => $s->mood ?? 'neutral'])->all();
            $method = new ReflectionMethod(RemotionRenderService::class, 'resolveMusic');
            $method->setAccessible(true);
            $music = $method->invoke(new RemotionRenderService(), $project, $scenes);
        } catch (Throwable $e) {
            $this->note('Could not run resolveMusic(): ' . $e->getMessage());

            return;
        }

        if ($music === null) {
            $this->healthy = false;
            $this->problem('resolveMusic() returned null — this project renders SILENT.');

            return;
        }

        $this->kv('url', $music['url']);
        $this->kv('volume', (string) $music['volume']);
        $this->kv('mood', (string) $music['mood']);

        // "Dropped" and "present but inaudible" look identical to a listener,
        // and the second is far more common on a narrated explainer: the bed is
        // quiet to begin with and musicVolumeCurve ducks it to 0.35x whenever
        // words are active (ExplainerVideo.tsx, copilot.md 6.2). Spell the
        // level out so nobody re-opens this as a bug when the mix is just low.
        $base = (float) $music['volume'];
        $ducked = $base * 0.35;
        $db = fn (float $v) => $v > 0 ? sprintf('%.1f dBFS', 20 * log10($v)) : '-inf';
        $this->kv('bed level, gaps', sprintf('%.3f  (%s)', $base, $db($base)));
        $this->kv('bed level, under speech', sprintf('%.3f  (%s)', $ducked, $db($ducked)));
        if ($ducked < 0.04) {
            $this->note(
                'Under narration this bed sits below -28 dBFS, which is close to inaudible on',
                'laptop speakers — and an explainer is narrated almost end to end. If the video',
                'sounds like it has no music, raise music_volume in the storyboard music panel',
                'before concluding the bed was dropped.'
            );
        }

        // The commonest false alarm: the MP4 on disk simply predates the music
        // choice. A music change does not mark a render stale (the stale badge
        // hashes things that change a FRAME, and music cannot).
        $output = $project->output_path;
        if ($output && Storage::disk('public')->exists($output)) {
            $rendered = \Illuminate\Support\Carbon::createFromTimestamp(Storage::disk('public')->lastModified($output));
            $this->kv('video rendered at', $rendered->toDateTimeString());
            if ($project->updated_at && $project->updated_at->greaterThan($rendered)) {
                $this->note(
                    'The project changed AFTER this MP4 was written. If you picked the music since then,',
                    'the file simply predates the choice — nothing re-renders on its own, and a music',
                    'change does not raise the stale badge. Re-render before concluding it was dropped.'
                );
            }
            $this->line('  Definitive check on the finished file:');
            $this->line('    ffmpeg -i ' . Storage::disk('public')->path($output) . ' -af volumedetect -f null - 2>&1 | tail -5');
        }
    }

    /* ---------------------------------------------------------------- */

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<fg=cyan>=== {$title} ===</>");
    }

    private function kv(string $label, string $value): void
    {
        $this->line(sprintf('  %-28s %s', $label . ':', $value));
    }

    private function problem(string ...$lines): void
    {
        $this->healthy = false;
        foreach ($lines as $i => $line) {
            $this->line($i === 0 ? "  <fg=red>! {$line}</>" : "    <fg=red>{$line}</>");
        }
    }

    private function note(string ...$lines): void
    {
        foreach ($lines as $i => $line) {
            $this->line($i === 0 ? "  <fg=yellow>~ {$line}</>" : "    <fg=yellow>{$line}</>");
        }
    }
}
