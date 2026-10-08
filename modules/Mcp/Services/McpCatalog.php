<?php

namespace Modules\Mcp\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Models\UserColorScheme;
use Modules\Project\Services\MusicProviderFactory;
use Modules\Project\Services\UserMusicLibrary;
use Modules\Project\Services\VoiceCloneService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\TtsVoices;

/**
 * The menus the model chooses from: voices, music, looks.
 *
 * Voices are the FREE ones only — the self-hosted Kokoro narrators and the
 * user's own cloned voices. The paid engine is not offered over MCP unless an
 * admin turns `mcp.allow_paid_voices` on.
 */
final class McpCatalog
{
    /**
     * Kokoro narrators worth offering. The engine has more; these are the
     * clearest English voices for explaining things.
     */
    public const VOICES = [
        'af_heart' => ['label' => 'Heart', 'gender' => 'female', 'accent' => 'American', 'tone' => 'warm, expressive — the best all-rounder'],
        'af_bella' => ['label' => 'Bella', 'gender' => 'female', 'accent' => 'American', 'tone' => 'bright, friendly'],
        'af_nicole' => ['label' => 'Nicole', 'gender' => 'female', 'accent' => 'American', 'tone' => 'soft, intimate (ASMR-like)'],
        'af_sarah' => ['label' => 'Sarah', 'gender' => 'female', 'accent' => 'American', 'tone' => 'natural, conversational'],
        'am_michael' => ['label' => 'Michael', 'gender' => 'male', 'accent' => 'American', 'tone' => 'natural, steady narrator'],
        'am_fenrir' => ['label' => 'Fenrir', 'gender' => 'male', 'accent' => 'American', 'tone' => 'deep, confident'],
        'am_puck' => ['label' => 'Puck', 'gender' => 'male', 'accent' => 'American', 'tone' => 'lively, upbeat'],
        'bf_emma' => ['label' => 'Emma', 'gender' => 'female', 'accent' => 'British', 'tone' => 'clear, polished'],
        'bf_isabella' => ['label' => 'Isabella', 'gender' => 'female', 'accent' => 'British', 'tone' => 'warm, documentary'],
        'bm_george' => ['label' => 'George', 'gender' => 'male', 'accent' => 'British', 'tone' => 'classic documentary narrator'],
        'bm_fable' => ['label' => 'Fable', 'gender' => 'male', 'accent' => 'British', 'tone' => 'storyteller'],
    ];

    public const DEFAULT_VOICE = 'af_heart';

    private const PREVIEW_TEXT = 'Here is how this works, in about a minute. '
        . 'Every idea gets its own animated scene, timed to my voice.';

    /** @return array<int, array<string, mixed>> */
    public static function voices(int $userId, bool $ensurePreviews = true): array
    {
        $out = [];
        $budget = microtime(true) + 12; // a fresh server records the samples (php artisan mcp:warm does it up front)
        foreach (self::VOICES as $id => $meta) {
            $preview = self::previewUrl($id, $ensurePreviews && microtime(true) < $budget);
            $out[] = array_merge(['id' => $id, 'engine' => 'kokoro (free)'], $meta, ['preview_url' => $preview]);
        }

        foreach (VoiceCloneService::optionsFor($userId) as $key => $name) {
            $out[] = [
                'id' => $key,
                'engine' => 'your cloned voice (free)',
                'label' => $name,
                'tone' => 'the user\'s own voice, cloned on the My Voices page',
                'preview_url' => null,
            ];
        }

        if (config('mcp.allow_paid_voices', false) && TtsVoices::openaiConfigured()) {
            foreach (TtsVoices::OPENAI as $id => $label) {
                $out[] = ['id' => $id, 'engine' => 'openai', 'label' => $label, 'preview_url' => null];
            }
        }

        return $out;
    }

    public static function isAllowedVoice(string $voice, int $userId): bool
    {
        if (isset(self::VOICES[$voice])) {
            return true;
        }
        if (TtsVoices::isClone($voice)) {
            return TtsVoices::isAllowed($voice, $userId);
        }

        return config('mcp.allow_paid_voices', false) && isset(TtsVoices::OPENAI[$voice]);
    }

    /** Is this a paid-engine voice (only possible when an admin allowed it)? */
    public static function isPaidVoice(string $voice): bool
    {
        return isset(TtsVoices::OPENAI[$voice]);
    }

    /** A cached sample of a free voice, synthesized once ever. */
    private static function previewUrl(string $voice, bool $synthesize): ?string
    {
        $relative = "tts_previews/mcp_kokoro_{$voice}.wav";
        $disk = Storage::disk('public');
        if ($disk->exists($relative)) {
            return $disk->url($relative);
        }
        if (!$synthesize) {
            return null;
        }
        try {
            $abs = $disk->path($relative);
            @mkdir(dirname($abs), 0775, true);
            $result = (new PythonAIService())->generateTTS(self::PREVIEW_TEXT, $voice, $abs, false);
            if (($result['success'] ?? false) && $disk->exists($relative)) {
                return $disk->url($relative);
            }
        } catch (\Throwable $e) {
            Log::info('McpCatalog: voice preview failed', ['voice' => $voice, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /** Music categories, or the tracks of one. */
    public static function music(int $userId, ?string $category): array
    {
        $categories = array_merge(['auto', 'none'], MusicProviderInterface::CATEGORIES, [UserMusicLibrary::CATEGORY]);
        if ($category === null || $category === '') {
            return [
                'categories' => $categories,
                'notes' => [
                    'auto picks a calm-to-upbeat bed from the video\'s mood; none = no music.',
                    'custom = the user\'s OWN uploaded tracks (My music in the dashboard).',
                    'Call list_music with a category to audition tracks; pass a track id as music_track_id to pin one.',
                ],
                'default_volume' => MusicProviderInterface::DEFAULT_VOLUME,
            ];
        }
        if (!in_array($category, $categories, true) || in_array($category, ['auto', 'none'], true)) {
            return ['category' => $category, 'tracks' => [], 'note' => 'auto/none have no track list.'];
        }

        if (UserMusicLibrary::isCustom($category)) {
            return ['category' => $category, 'source' => 'user', 'tracks' => UserMusicLibrary::listFor($userId)];
        }

        $result = MusicProviderFactory::make()->browseTracks($category);

        return [
            'category' => $category,
            'source' => $result['source'] ?? 'none',
            'tracks' => array_slice(array_values((array) ($result['tracks'] ?? [])), 0, 15),
        ];
    }

    /** The look menu: palettes, type, cuts, moods, frame shapes. */
    public static function styles(int $userId): array
    {
        $schemes = array_map(fn ($s) => [
            'name' => $s['name'],
            'label' => $s['label'] ?? $s['name'],
            'background' => $s['bg_from'] ?? null,
            'text' => $s['text'] ?? null,
            'accent' => $s['accent'] ?? null,
            'accent2' => $s['accent2'] ?? null,
        ], ExplainerRegistry::colorSchemes());

        $custom = UserColorScheme::where('user_id', $userId)->orderByDesc('created_at')->get()
            ->map(fn (UserColorScheme $row) => $row->toTheme())
            ->map(fn ($s) => [
                'name' => $s['name'], 'label' => ($s['label'] ?? $s['name']) . ' (yours)',
                'background' => $s['bg_from'] ?? null, 'text' => $s['text'] ?? null, 'accent' => $s['accent'] ?? null,
            ])->all();

        $packs = [];
        foreach (ExplainerRegistry::fontPacks() as $name => $pack) {
            if (!is_array($pack)) {
                continue;
            }
            $packs[] = ['name' => $name, 'display' => $pack['display'] ?? null, 'body' => $pack['body'] ?? null, 'use_when' => $pack['use_when'] ?? null];
        }

        return [
            'color_schemes' => array_merge([[
                'name' => 'unique',
                'label' => 'Unique (default) — a palette, font trio and motion tuning generated for this video alone',
            ]], $schemes, $custom),
            'font_packs' => array_merge([['name' => 'auto', 'use_when' => 'with the unique look: its own generated font trio']], $packs),
            'aspect_ratios' => ['16:9' => 'YouTube / landscape', '9:16' => 'Shorts / Reels / TikTok', '1:1' => 'square feed'],
            'fps' => ['options' => [30, 60], 'default' => (int) config('mcp.default_fps', 60)],
            'transitions' => ExplainerRegistry::transitions(),
            'transition_meanings' => ExplainerRegistry::transitionMeanings(),
            'moods' => ExplainerRegistry::moods(),
            'presenter_layouts' => [
                'full' => 'the presenter fills the frame (optionally with your code drawn as a transparent overlay on top)',
                'pip' => 'graphics fill the frame, the presenter sits in a small corner window',
                'split' => 'presenter on one side (top in 9:16), graphics on the other — custom code only',
                'hidden' => 'graphics only; the presenter\'s voice keeps playing',
            ],
        ];
    }
}
