<?php

namespace Modules\Project\Services;

use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Contracts\YoutubeDownloaderInterface;

/**
 * Resolves the YouTube downloader implementation an admin has selected at
 * runtime (app_settings → `youtube_downloader_provider`), falling back to the
 * config default. Both providers satisfy YoutubeDownloaderInterface, so the
 * rest of the pipeline is provider-agnostic.
 *
 * Returns a FailoverYoutubeDownloader: API keys come from the admin-managed
 * api_credentials pool (default key first, next key on error) — never .env.
 * Key lookup is lazy, so make() is safe even when no key is configured yet.
 */
class YoutubeDownloaderFactory
{
    public const SETTING_KEY = 'youtube_downloader_provider';

    public const PROVIDERS = ['rapidapi', 'apify'];

    public static function provider(): string
    {
        $provider = AppSetting::get(
            self::SETTING_KEY,
            config('services.youtube_downloader.default', 'rapidapi')
        );

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'rapidapi';
    }

    public static function make(): YoutubeDownloaderInterface
    {
        return new FailoverYoutubeDownloader(self::provider());
    }
}
