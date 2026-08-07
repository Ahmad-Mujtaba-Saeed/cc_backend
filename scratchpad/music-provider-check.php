<?php

/**
 * music-provider-check — the admin-switchable music source (Pixabay ⇄ Jamendo).
 * Mirrors the YouTube downloader switch: one contract, a factory reading
 * app_settings, credentials from the api_credentials pool.
 *   docker exec viralforgebackend-app-1 php scratchpad/music-provider-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\AccessControl\Models\ApiCredential;
use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Services\JamendoMusicService;
use Modules\Project\Services\MusicProviderFactory;
use Modules\Project\Services\PixabayMusicService;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

$restore = AppSetting::get(MusicProviderFactory::SETTING_KEY, null);

// ------------------------------------------------------------- the contract
check('both providers satisfy one contract', (new PixabayMusicService()) instanceof MusicProviderInterface
    && (new JamendoMusicService()) instanceof MusicProviderInterface);
check('the category vocabulary lives on the contract', count(MusicProviderInterface::CATEGORIES) === 12);
check('… and is still readable off either implementation', PixabayMusicService::CATEGORIES === MusicProviderInterface::CATEGORIES
    && JamendoMusicService::CATEGORIES === MusicProviderInterface::CATEGORIES);
check('the default volume has one definition', MusicProviderInterface::DEFAULT_VOLUME === 0.09
    && PixabayMusicService::DEFAULT_VOLUME === 0.09);

// Every category a project can store must be translatable by BOTH providers,
// or switching source would silently break saved videos.
$ref = new ReflectionClass(JamendoMusicService::class);
$tagMap = $ref->getConstant('TAG_MAP');
$missing = array_diff(MusicProviderInterface::CATEGORIES, array_keys($tagMap));
check('Jamendo maps EVERY shared category (' . count($tagMap) . '/12)', $missing === []);
if ($missing !== []) {
    echo '      unmapped: ' . implode(', ', $missing) . "\n";
}
check('… and invents no categories of its own', array_diff(array_keys($tagMap), MusicProviderInterface::CATEGORIES) === []);

// --------------------------------------------------------------- the factory
check('pixabay is the default', MusicProviderFactory::DEFAULT_PROVIDER === 'pixabay');

AppSetting::set(MusicProviderFactory::SETTING_KEY, 'jamendo');
check('the factory honours the admin setting', MusicProviderFactory::provider() === 'jamendo');
check('… and builds the right service', MusicProviderFactory::make() instanceof JamendoMusicService);

AppSetting::set(MusicProviderFactory::SETTING_KEY, 'pixabay');
check('switching back works', MusicProviderFactory::make() instanceof PixabayMusicService);

AppSetting::set(MusicProviderFactory::SETTING_KEY, 'garbage-provider');
check('a bogus stored provider falls back to the default', MusicProviderFactory::provider() === 'pixabay');
check('… and still returns a usable service', MusicProviderFactory::make() instanceof MusicProviderInterface);

check('make() can be forced past the setting', MusicProviderFactory::make('jamendo') instanceof JamendoMusicService);
check('every provider has a label', count(array_diff(
    MusicProviderFactory::PROVIDERS,
    array_keys(MusicProviderFactory::labels())
)) === 0);

// ----------------------------------------------------------- the credentials
check("'jamendo' is an allowed credential provider", in_array('jamendo', ApiCredential::PROVIDERS, true));
check('… and gets a group in the admin listing', array_key_exists('jamendo', ApiCredential::groupedForAdmin()));
check('isConfigured tracks the jamendo pool', (new JamendoMusicService())->isConfigured() === ApiCredential::hasActive('jamendo'));

// --------------------------------------------------------- graceful degrade
// With no client id configured, nothing may throw — callers fall back.
foreach (MusicProviderInterface::CATEGORIES as $category) {
    $browse = (new JamendoMusicService())->browseTracks($category);
    if (!isset($browse['source'], $browse['tracks']) || !is_array($browse['tracks'])) {
        check("browseTracks('{$category}') returns the contract shape", false);
        break;
    }
}
check('browseTracks returns the contract shape for all 12 categories', true);
check("browseTracks('auto') is empty, not an error", (new JamendoMusicService())->browseTracks('auto')['tracks'] === []);
check("browseTracks('none') is empty, not an error", (new JamendoMusicService())->browseTracks('none')['tracks'] === []);
check("pickTrack('none') is null", (new JamendoMusicService())->pickTrack('none', 1) === null);
check('pickTrack with no key does not throw', (function () {
    try {
        (new JamendoMusicService())->pickTrack('corporate', 7);

        return true;
    } catch (\Throwable $e) {
        echo '      threw: ' . $e->getMessage() . "\n";

        return false;
    }
})());

// ------------------------------------------------------------ caller wiring
// The point of the switch is that the render path obeys it.
$src = file_get_contents(__DIR__ . '/../modules/Project/Services/RemotionRenderService.php');
check('the explainer renderer resolves via the factory', str_contains($src, 'MusicProviderFactory::make()->pickTrack'));
foreach ([
    'AbstractVideoProcessor',
    'AIHorrorShortsProcessor',
    'AIImageShortsProcessor',
] as $processor) {
    $p = file_get_contents(__DIR__ . "/../modules/Project/Processors/{$processor}.php");
    check("{$processor} resolves via the factory", str_contains($p, 'MusicProviderFactory::make()')
        && !str_contains($p, 'new \Modules\Project\Services\PixabayMusicService()'));
}
$mc = file_get_contents(__DIR__ . '/../modules/Project/Http/Controllers/MusicController.php');
check('the browse endpoint follows the active provider', str_contains($mc, 'MusicProviderFactory::provider()'));

// Restore. AppSetting::get caches forever and only set() invalidates, so a
// direct delete has to forget the key by hand or the next read serves the
// value this script just wrote.
if ($restore === null) {
    AppSetting::where('key', MusicProviderFactory::SETTING_KEY)->delete();
    Illuminate\Support\Facades\Cache::forget('app_setting:' . MusicProviderFactory::SETTING_KEY);
} else {
    AppSetting::set(MusicProviderFactory::SETTING_KEY, $restore);
}
check('the provider setting was restored', AppSetting::get(MusicProviderFactory::SETTING_KEY, null) === $restore);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
