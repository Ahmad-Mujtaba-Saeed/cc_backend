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
     * A usable track for a category: the selected provider first, and the OTHER
     * provider only when the selected one has no usable credential at all.
     *
     * The class comment above rules out failing over between two WORKING
     * providers, and that still holds — serving Jamendo under a project an
     * admin configured for Pixabay would make the setting a lie. This is the
     * case it does not cover: the configured provider cannot be honoured in any
     * form (no key in api_credentials — the usual state of a freshly deployed
     * VPS, since credentials are database rows and do not travel with a
     * deploy), so the real choice is "some music" or "silence", and silence is
     * not the better answer.
     *
     * Lives here rather than in each caller so the policy has exactly one
     * definition: the explainer renderer and the shorts processors must not
     * drift apart on when borrowing is allowed.
     *
     * Every failure is swallowed — a database or network problem while picking
     * music must degrade to the caller's local fallbacks, never break a render.
     *
     * @return string|null public-disk relative path
     */
    public static function pickTrackWithFallback(string $category, int $seed = 0, ?string $trackId = null): ?string
    {
        try {
            $selected = self::provider();
        } catch (\Throwable $e) {
            // Cannot even read WHICH provider (app_settings is a DB round trip).
            \Illuminate\Support\Facades\Log::warning(
                'MusicProviderFactory: provider setting unreadable — skipping remote catalogues',
                ['error' => $e->getMessage()]
            );

            return null;
        }

        try {
            $track = self::make($selected)->pickTrack($category, $seed, $trackId);
            if ($track !== null) {
                return $track;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MusicProviderFactory: selected provider threw', [
                'provider' => $selected,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if (self::make($selected)->isConfigured()) {
                return null; // it works and simply had nothing — do not second-guess it
            }

            foreach (self::PROVIDERS as $alternative) {
                if ($alternative === $selected) {
                    continue;
                }
                $other = self::make($alternative);
                if (!$other->isConfigured()) {
                    continue;
                }

                // A user's explicit track id belongs to the SELECTED provider's
                // catalogue, so it is not passed on: the other provider would
                // either miss it or, worse, match an unrelated track of its own.
                $track = $other->pickTrack($category, $seed);
                if ($track !== null) {
                    \Illuminate\Support\Facades\Log::info(
                        'MusicProviderFactory: borrowed a track from the unselected provider',
                        ['selected' => $selected, 'used' => $alternative, 'category' => $category]
                    );

                    return $track;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MusicProviderFactory: fallback provider threw', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
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
