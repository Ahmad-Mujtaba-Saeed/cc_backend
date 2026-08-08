<?php

namespace Modules\Project\Console;

use Illuminate\Console\Command;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Services\MusicLibraryService;
use Modules\Project\Services\MusicProviderFactory;
use Throwable;

/**
 * Fill the local background-music library ahead of time, one folder per
 * category, so renders never wait on (or depend on) the provider's API.
 *
 * Renders already cache the one track they use; this pulls a handful per
 * category in advance, which is what makes the picker useful offline and makes
 * an expired key or a Jamendo outage a non-event instead of a silent video.
 *
 *   php artisan music:cache                       every category, 3 tracks each
 *   php artisan music:cache --category=technology --per-category=8
 *   php artisan music:cache --list                what's already downloaded
 */
class CacheMusicLibraryCommand extends Command
{
    protected $signature = 'music:cache
                            {--category=* : Categories to fill (default: all of them)}
                            {--per-category=3 : How many tracks to keep per category}
                            {--list : Only show what the library already holds}';

    protected $description = 'Download background music into the local library, filed by category';

    public function handle(): int
    {
        $library = new MusicLibraryService();
        $providerName = MusicProviderFactory::provider();
        $provider = MusicProviderFactory::make($providerName);

        $categories = array_values(array_filter(
            (array) $this->option('category'),
            fn ($c) => in_array(strtolower(trim((string) $c)), MusicProviderInterface::CATEGORIES, true)
        )) ?: MusicProviderInterface::CATEGORIES;

        if ($this->option('list')) {
            return $this->listLibrary($library, $providerName, $categories);
        }

        if (!$provider->isConfigured()) {
            $this->error("No usable {$providerName} credential — add one in the admin API keys screen first.");

            return self::FAILURE;
        }

        $want = max(1, (int) $this->option('per-category'));
        $downloaded = 0;
        $failed = 0;

        foreach ($categories as $category) {
            $have = count($library->cached($providerName, $category));
            if ($have >= $want) {
                $this->line("  {$category}: already holds {$have} track(s)");
                continue;
            }

            $this->line("  {$category}: fetching " . ($want - $have) . ' track(s)…');

            // Walk the seeds rather than the raw catalogue: pickTrack() is the
            // same path a render takes, so anything cached here is provably
            // something a render can pick.
            for ($seed = 0; $seed < ($want - $have) * 3 && count($library->cached($providerName, $category)) < $want; $seed++) {
                try {
                    $path = $provider->pickTrack($category, $seed);
                    if ($path === null) {
                        $failed++;
                        break; // category is unavailable, not just this track
                    }
                    $downloaded++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("    {$category}: {$e->getMessage()}");
                    break;
                }
            }
        }

        $this->newLine();
        $this->info("Library filled from {$providerName}: {$downloaded} pick(s), {$failed} category miss(es).");

        return self::SUCCESS;
    }

    /** @param array<int, string> $categories */
    private function listLibrary(MusicLibraryService $library, string $providerName, array $categories): int
    {
        $rows = [];
        foreach ($categories as $category) {
            foreach ($library->cached($providerName, $category) as $track) {
                $rows[] = [$category, $track['id'], mb_strimwidth($track['title'], 0, 48, '…'), $track['duration'] ?: '—'];
            }
        }

        if ($rows === []) {
            $this->warn("Nothing downloaded yet for {$providerName}. Run `php artisan music:cache` to fill it.");

            return self::SUCCESS;
        }

        $this->table(['Category', 'Track id', 'Title', 'Secs'], $rows);
        $this->info(count($rows) . " track(s) available locally for {$providerName}.");

        return self::SUCCESS;
    }
}
