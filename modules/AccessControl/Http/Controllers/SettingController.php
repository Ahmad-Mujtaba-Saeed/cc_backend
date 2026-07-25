<?php

namespace Modules\AccessControl\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AccessControl\Models\ApiCredential;
use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Processors\TemplateProcessorFactory;
use Modules\Project\Services\TemplateSettingsService;
use Modules\Project\Services\YoutubeDownloaderFactory;
use Modules\Project\Support\LlmModels;
use Modules\Project\Support\TtsVoices;

/**
 * Admin-only runtime application settings (gated by `manage-settings`),
 * plus a small public read for the marketing landing page.
 */
class SettingController extends Controller
{
    /** Which marketing landing page design is served at `/`. */
    // Liquid Glass ("prism") is now the single shipped design. The other
    // variants/skins are kept in the codebase but disabled here so only
    // Liquid Glass is ever served or selectable.
    public const LANDING_SETTING_KEY = 'landing_variant';
    public const LANDING_VARIANTS = ['prism'];
    public const LANDING_DEFAULT = 'prism';

    /**
     * The app-wide colour scheme ("skin"). Locked to Liquid Glass — users
     * only choose light/dark mode, not the skin.
     */
    public const THEME_SETTING_KEY = 'theme_default';
    public const THEMES = ['prism'];
    public const THEME_DEFAULT = 'prism';

    public function index(): JsonResponse
    {
        return response()->json([
            'youtube_downloader' => $this->youtubeDownloaderState(),
            'landing' => $this->landingState(),
            'tts' => $this->ttsState(),
            'llm' => LlmModels::state(),
            'music' => $this->musicState(),
            'stock' => $this->stockState(),
            'templates' => $this->templatesState(),
            'theme' => $this->themeState(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'youtube_downloader_provider' => ['sometimes', 'string', 'in:' . implode(',', YoutubeDownloaderFactory::PROVIDERS)],
            'landing_variant' => ['sometimes', 'string', 'in:' . implode(',', self::LANDING_VARIANTS)],
            'theme_default' => ['sometimes', 'string', 'in:' . implode(',', self::THEMES)],
            'tts_provider' => ['sometimes', 'string', 'in:' . implode(',', TtsVoices::PROVIDERS)],
            'llm_model' => ['sometimes', 'string', 'in:' . implode(',', array_merge([LlmModels::AUTO], array_keys(LlmModels::MODELS)))],
            'templates' => ['sometimes', 'array'],
            'templates.*.enabled' => ['sometimes', 'boolean'],
            'templates.*.credit_cost' => ['sometimes', 'integer', 'min:' . TemplateSettingsService::MIN_CREDIT_COST, 'max:' . TemplateSettingsService::MAX_CREDIT_COST],
            'templates.*.trending' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('youtube_downloader_provider', $data)) {
            AppSetting::set(YoutubeDownloaderFactory::SETTING_KEY, $data['youtube_downloader_provider']);
        }

        if (array_key_exists('landing_variant', $data)) {
            AppSetting::set(self::LANDING_SETTING_KEY, $data['landing_variant']);
        }

        if (array_key_exists('theme_default', $data)) {
            AppSetting::set(self::THEME_SETTING_KEY, $data['theme_default']);
        }

        if (array_key_exists('tts_provider', $data)) {
            AppSetting::set(TtsVoices::SETTING_KEY, $data['tts_provider']);
        }

        if (array_key_exists('llm_model', $data)) {
            AppSetting::set(LlmModels::SETTING_KEY, $data['llm_model']);
        }

        if (array_key_exists('templates', $data)) {
            TemplateSettingsService::update($data['templates']);
        }

        return response()->json([
            'message' => 'Settings updated.',
            'youtube_downloader' => $this->youtubeDownloaderState(),
            'landing' => $this->landingState(),
            'tts' => $this->ttsState(),
            'llm' => LlmModels::state(),
            'music' => $this->musicState(),
            'stock' => $this->stockState(),
            'templates' => $this->templatesState(),
            'theme' => $this->themeState(),
        ]);
    }

    private function themeState(): array
    {
        return [
            'default' => $this->themeDefault(),
            'options' => self::THEMES,
        ];
    }

    private function themeDefault(): string
    {
        $theme = AppSetting::get(self::THEME_SETTING_KEY, self::THEME_DEFAULT);

        return in_array($theme, self::THEMES, true) ? $theme : self::THEME_DEFAULT;
    }

    /**
     * Admin template controls: enable/disable, credit cost override, and
     * trending flag, merged with display metadata for the settings UI.
     */
    private function templatesState(): array
    {
        $meta = TemplateProcessorFactory::getAvailableTemplates();
        $settings = TemplateSettingsService::all();

        $out = [];
        foreach ($meta as $type => $info) {
            $out[$type] = array_merge(
                ['name' => $info['name'], 'description' => $info['description'], 'icon' => $info['icon']],
                $settings[$type] ?? []
            );
        }

        return $out;
    }

    /**
     * Narration engine switch state. `provider` is what the admin selected;
     * `effective_provider` is what renders actually use (OpenAI degrades to
     * Kokoro when no API key is configured).
     */
    private function ttsState(): array
    {
        $selected = (string) AppSetting::get(TtsVoices::SETTING_KEY, TtsVoices::DEFAULT_PROVIDER);
        if (!in_array($selected, TtsVoices::PROVIDERS, true)) {
            $selected = TtsVoices::DEFAULT_PROVIDER;
        }

        return [
            'provider' => $selected,
            'effective_provider' => TtsVoices::activeProvider(),
            'options' => TtsVoices::PROVIDERS,
            'openai_configured' => TtsVoices::openaiConfigured(),
        ];
    }

    /**
     * Background-music (Pixabay) state: key pool for the admin UI. Templates
     * fall back to the legacy local library when no key is active.
     */
    private function musicState(): array
    {
        return [
            'pixabay_configured' => ApiCredential::hasActive('pixabay'),
            'credentials' => ApiCredential::groupedForAdmin()['pixabay'] ?? [],
        ];
    }

    /**
     * Stock-footage (explainer §8 b-roll) state: the Pexels key pool, plus
     * whether Pixabay (the fallback video source, shared with music) is
     * active. With neither key, stock_video slots render their placeholder.
     */
    private function stockState(): array
    {
        return [
            'pexels_configured' => ApiCredential::hasActive('pexels'),
            'pixabay_configured' => ApiCredential::hasActive('pixabay'),
            'credentials' => ApiCredential::groupedForAdmin()['pexels'] ?? [],
        ];
    }

    /**
     * Public: the active landing page variant. Consumed by the Next.js
     * marketing page so an admin switch takes effect without a deploy.
     */
    public function landing(): JsonResponse
    {
        return response()->json([
            'variant' => $this->landingVariant(),
            'theme' => $this->themeDefault(),
            'templates' => $this->publicTemplates(),
        ]);
    }

    /**
     * Enabled templates only, shaped for the marketing landing page.
     */
    private function publicTemplates(): array
    {
        $meta = TemplateProcessorFactory::getAvailableTemplates();
        $settings = TemplateSettingsService::all();

        $out = [];
        foreach ($meta as $type => $info) {
            $row = $settings[$type] ?? ['enabled' => true, 'credit_cost' => 3, 'trending' => false];
            if (!($row['enabled'] ?? true)) {
                continue;
            }

            $out[] = [
                'key' => $type,
                'name' => $info['name'],
                'description' => $info['description'],
                'icon' => $info['icon'],
                'aspect_ratio' => $info['aspect_ratio'],
                'credits' => $row['credit_cost'],
                'badge' => ($row['trending'] ?? false) ? 'Trending' : null,
            ];
        }

        return $out;
    }

    private function landingState(): array
    {
        return [
            'variant' => $this->landingVariant(),
            'options' => self::LANDING_VARIANTS,
        ];
    }

    private function landingVariant(): string
    {
        $variant = AppSetting::get(self::LANDING_SETTING_KEY, self::LANDING_DEFAULT);

        return in_array($variant, self::LANDING_VARIANTS, true) ? $variant : self::LANDING_DEFAULT;
    }

    private function youtubeDownloaderState(): array
    {
        // "Configured" now means at least one active key in the admin-managed
        // api_credentials pool — .env keys are no longer consulted at runtime.
        return [
            'provider' => YoutubeDownloaderFactory::provider(),
            'options' => YoutubeDownloaderFactory::PROVIDERS,
            'rapidapi_configured' => ApiCredential::hasActive('rapidapi'),
            'apify_configured' => ApiCredential::hasActive('apify'),
            'credentials' => ApiCredential::groupedForAdmin(),
        ];
    }
}
