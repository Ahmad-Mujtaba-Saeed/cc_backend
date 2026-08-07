<?php

namespace Modules\Project\Services;

use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Contracts\MusicProviderInterface;

/**
 * Resolves the background-music source an admin has selected at runtime
 * (app_settings → `music_provider`). Both providers satisfy
 * MusicProviderInterface, so every caller — the explainer renderer, the
 * template processors, the create flow and the storyboard's music panel — is
 * provider-agnostic.
 *
 * Client ids / API keys come from the admin-managed api_credentials pool
 * (provider `pixabay` or `jamendo`), never .env, exactly like the YouTube
 * downloader switch this mirrors.
 *
 * Deliberately NOT a failover chain across providers: the pick has to be
 * stable for a given project, and silently rendering a Jamendo track under a
 * video an admin configured for Pixabay would make the setting a lie. When the
 * chosen provider has nothing, callers already fall back to the local library
 * or render silent.
 */
class MusicProviderFactory
{
    public const SETTING_KEY = 'music_provider';

    public const PROVIDERS = ['pixabay', 'jamendo'];

    public const DEFAULT_PROVIDER = 'pixabay';

    /** The admin's selection, validated. */
    public static function provider(): string
    {
        $provider = AppSetting::get(
            self::SETTING_KEY,
            config('services.music.default', self::DEFAULT_PROVIDER)
        );

        return in_array($provider, self::PROVIDERS, true) ? $provider : self::DEFAULT_PROVIDER;
    }

    public static function make(?string $provider = null): MusicProviderInterface
    {
        return match ($provider ?? self::provider()) {
            'jamendo' => new JamendoMusicService(),
            default => new PixabayMusicService(),
        };
    }

    /**
     * Human labels for the admin UI. Kept here so the switch and its captions
     * cannot drift apart.
     */
    public static function labels(): array
    {
        return [
            'pixabay' => 'Pixabay',
            'jamendo' => 'Jamendo',
        ];
    }
}
