<?php

namespace Modules\Project\Support;

use Modules\AccessControl\Models\AppSetting;

/**
 * The voice vocabulary for both narration engines and the admin-controlled
 * provider switch.
 *
 * Two engines coexist:
 *  - kokoro  — self-hosted, free, returns real word timings, no style control.
 *  - openai  — gpt-4o-mini-tts, paid, styled via natural-language instructions
 *              (see {@see TtsInstructions}); word timings are recovered by
 *              transcribing the result with Whisper.
 *
 * The active provider is a runtime AppSetting so an admin can flip engines
 * without a deploy. Voices are provider-specific: this class owns both lists,
 * per-template defaults, and the resolution rule that keeps a stored project
 * voice working after the admin switches providers.
 */
class TtsVoices
{
    public const SETTING_KEY = 'tts_provider';
    public const PROVIDERS = ['kokoro', 'openai'];
    public const DEFAULT_PROVIDER = 'kokoro';

    /** User-selectable Kokoro voices (the engine accepts more; these are curated). */
    public const KOKORO = [
        'am_michael' => 'Michael — natural male, US',
        'af_sarah' => 'Sarah — natural female, US',
        'bm_george' => 'George — natural male, UK',
        'bf_emma' => 'Emma — natural female, UK',
    ];

    /** gpt-4o-mini-tts built-in voices. */
    public const OPENAI = [
        'alloy' => 'Alloy — neutral, balanced',
        'echo' => 'Echo — clear, professional',
        'fable' => 'Fable — expressive, narrative',
        'onyx' => 'Onyx — deep, authoritative',
        'nova' => 'Nova — young, upbeat',
        'shimmer' => 'Shimmer — soft, gentle',
        'ash' => 'Ash — warm, conversational',
        'ballad' => 'Ballad — smooth, storytelling',
        'coral' => 'Coral — friendly, energetic',
        'sage' => 'Sage — calm, mature',
    ];

    /**
     * Suggested OpenAI voice per template (documentary→onyx/sage,
     * storytelling→ballad/fable, social→nova, horror→onyx). The explainer
     * defaults to coral — friendly and energetic, the right register for a
     * teaching voice.
     */
    private const OPENAI_TEMPLATE_DEFAULTS = [
        'ai_horror_shorts' => 'onyx',
        'ai_image_based_shorts' => 'nova',
        'yt_automation_short' => 'echo',
        'yt_compilation_short' => 'nova',
        'ai_explainer_video' => 'coral',
    ];

    private const KOKORO_FALLBACK = 'am_michael';
    private const OPENAI_FALLBACK = 'alloy';

    /**
     * The admin-selected provider, degraded to kokoro when openai is selected
     * but no API key is configured (narration must never silently die because
     * a key was removed).
     */
    public static function activeProvider(): string
    {
        $provider = (string) AppSetting::get(self::SETTING_KEY, self::DEFAULT_PROVIDER);

        if (!in_array($provider, self::PROVIDERS, true)) {
            return self::DEFAULT_PROVIDER;
        }

        if ($provider === 'openai' && !config('services.openai.api_key')) {
            return self::DEFAULT_PROVIDER;
        }

        return $provider;
    }

    public static function openaiConfigured(): bool
    {
        return (bool) config('services.openai.api_key');
    }

    /** @return array<string,string> voice id → label for the given provider */
    public static function forProvider(string $provider): array
    {
        return $provider === 'openai' ? self::OPENAI : self::KOKORO;
    }

    /**
     * Every voice id either engine understands — used for settings validation
     * so a project saved under one provider still validates after a switch.
     *
     * @return string[]
     */
    public static function allIds(): array
    {
        return array_merge(array_keys(self::KOKORO), array_keys(self::OPENAI));
    }

    /**
     * Map a stored/requested voice onto one the given provider can speak.
     * A voice from the other engine (e.g. after the admin flips providers)
     * resolves to the template's default rather than erroring.
     */
    public static function resolve(?string $voice, string $provider, ?string $templateType = null): string
    {
        $voice = trim((string) $voice);

        if ($provider === 'openai') {
            if (isset(self::OPENAI[$voice])) {
                return $voice;
            }

            return self::defaultFor($provider, $templateType);
        }

        // Kokoro voice ids follow <lang><gender>_<name> (af_bella, am_michael…).
        // Accept any well-formed id so non-curated voices (the explainer's
        // af_bella default) keep working.
        if (preg_match('/^[ab][mf]_[a-z0-9_]+$/', $voice)) {
            return $voice;
        }

        return self::defaultFor($provider, $templateType);
    }

    public static function defaultFor(string $provider, ?string $templateType = null): string
    {
        if ($provider === 'openai') {
            return self::OPENAI_TEMPLATE_DEFAULTS[$templateType] ?? self::OPENAI_FALLBACK;
        }

        if ($templateType === 'ai_explainer_video') {
            return (string) config('services.kokoro.voice', 'af_bella');
        }

        return self::KOKORO_FALLBACK;
    }
}
