<?php

namespace Modules\Project\Services\Shorts;

use Illuminate\Support\Facades\Log;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Services\MusicProviderFactory;

/**
 * Picks the music bed for one short.
 *
 * Every short worth copying rides a bed that matches what is happening in it —
 * a hard beat under a challenge, a riser under a build-up, something silly
 * under a silly moment. The template's own music setting cannot do that,
 * because it is ONE choice for a whole run and the shorts in a run are not
 * alike: the same two-hour stream yields a hot-wing challenge, a quiet story
 * and a crowd argument.
 *
 * So the vibe is chosen per short, from what the analyser and the director
 * already worked out — the vision model's `mood`, the scene type, and how loud
 * and busy the clip measured. Nothing extra is inferred and no extra model call
 * is made.
 *
 * Two rules that matter more than the mapping:
 *
 *  - A SPOKEN short is carried by the speech, not the music. The bed sits far
 *    underneath (BED_UNDER_SPEECH) and only comes up when there is little to
 *    hear (BED_ALONE). Mixing a viral-sounding bed at viral-sounding level
 *    over a conversation makes the conversation unintelligible, which is the
 *    one thing a talking short cannot survive.
 *  - Two shorts in a batch should not open with the same track. The seed
 *    carries the clip index, so the provider's deterministic pick differs per
 *    short while staying stable across re-renders of the same project.
 */
class ShortMusicPlanner
{
    /** Under speech. Well below the provider default — this is a stream clip. */
    public const BED_UNDER_SPEECH = 0.055;

    /** For a short with little or no speech, the bed is the soundtrack. */
    public const BED_ALONE = 0.16;

    /**
     * Vision mood -> vibe. The mood vocabulary is fixed by the analyser's
     * prompt (hype|funny|serious|emotional|educational|chill|tense).
     */
    private const BY_MOOD = [
        'hype' => 'hype',
        'funny' => 'funny',
        'tense' => 'tension',
        'serious' => 'cinematic',
        'emotional' => 'emotional',
        'educational' => 'corporate',
        'chill' => 'relaxing',
    ];

    /** Scene type -> vibe, when the mood is missing or unusable. */
    private const BY_SCENE = [
        'gameplay' => 'phonk',
        'gameplay_facecam' => 'hype',
        'sports' => 'epic',
        'reaction' => 'funny',
        'vlog' => 'hype',
        'podcast' => 'relaxing',
        'interview' => 'relaxing',
        'talking_head' => 'corporate',
        'screen_recording' => 'technology',
        'presentation' => 'corporate',
    ];

    /**
     * The bed for one short, or null to render with the clip's own audio only.
     *
     * @param  array<string, mixed>  $analysis  ClipSceneAnalyzer::analyze()
     * @param  array<string, mixed>  $edit      ShortEditDirector::direct()
     * @return array{category: string, path: string, volume: float}|null
     */
    public function plan(array $analysis, array $edit, int $projectId, int $clipIndex, string $setting = 'auto'): ?array
    {
        if ($setting === 'none') {
            return null;
        }

        $category = $setting !== 'auto' && in_array($setting, MusicProviderInterface::CATEGORIES, true)
            ? $setting
            : $this->vibe($analysis);

        $provider = MusicProviderFactory::make(MusicProviderFactory::provider());
        if (!$provider->isConfigured()) {
            return null;
        }

        // Seeded per clip so the shorts in one batch differ from each other but
        // a re-render of clip 3 keeps clip 3's track.
        $seed = $projectId * 7919 + $clipIndex * 131;
        $path = $provider->pickTrack($category, $seed);
        if ($path === null && $category !== 'cinematic') {
            // A vibe the provider has nothing for should not mean silence.
            $path = $provider->pickTrack('cinematic', $seed);
            $category = $path !== null ? 'cinematic' : $category;
        }
        if ($path === null) {
            Log::info('[SHORTS] No music track available', ['clip' => $clipIndex, 'category' => $category]);

            return null;
        }

        $volume = $this->hasSpeech($edit) ? self::BED_UNDER_SPEECH : self::BED_ALONE;

        Log::info('[SHORTS] Music bed picked', [
            'clip' => $clipIndex,
            'category' => $category,
            'volume' => $volume,
            'track' => basename($path),
        ]);

        return ['category' => $category, 'path' => $path, 'volume' => $volume];
    }

    /** @param  array<string, mixed>  $analysis */
    private function vibe(array $analysis): string
    {
        $mood = strtolower(trim((string) ($analysis['mood'] ?? '')));
        if (isset(self::BY_MOOD[$mood])) {
            return self::BY_MOOD[$mood];
        }

        $scene = (string) ($analysis['scene_type'] ?? '');
        if (isset(self::BY_SCENE[$scene])) {
            return self::BY_SCENE[$scene];
        }

        // Nothing said what this is: let the footage decide. A busy frame wants
        // a beat under it; a still one does not.
        return ((float) ($analysis['motion_mean'] ?? 0)) > 0.06 ? 'hype' : 'cinematic';
    }

    /**
     * Does this short carry speech? A short built from a transcript always
     * does; one the director found nothing to caption does not.
     *
     * @param  array<string, mixed>  $edit
     */
    private function hasSpeech(array $edit): bool
    {
        return count($edit['words'] ?? []) >= 8;
    }
}
