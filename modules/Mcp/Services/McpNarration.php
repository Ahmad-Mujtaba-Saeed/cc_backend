<?php

namespace Modules\Mcp\Services;

use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Services\TTSGenerationService;
use Modules\Project\Support\SpeechDictionary;
use Modules\Project\Support\TtsVoices;

/**
 * The voice of one narrated MCP scene: synthesized once, cached by a hash of
 * engine + voice + spoken text (same file names and asset row the regular
 * explainer uses, so the renderer and the SRT export read it unchanged), and
 * the scene re-timed to the real recording.
 *
 * Used by preview_scene (so the model sees frames against the real voice and
 * its real word timings) and by the render job, which then only synthesizes
 * what was never previewed.
 */
final class McpNarration
{
    /** Silence after the last word before the cut. */
    public const TAIL_SECONDS = 0.45;

    /**
     * @return array{ok: bool, seconds: float, words: array, cached: bool, error?: string}
     */
    public static function ensure(Project $project, ExplainerScene $scene): array
    {
        $settings = $project->settings ?? [];
        $meta = (array) ($settings['mcp']['scenes'][$scene->scene_id] ?? []);
        $hold = (float) ($meta['hold'] ?? 0);
        $text = trim((string) $scene->narration);
        if ($text === '') {
            $seconds = max(1.5, $hold);
            $scene->update(['duration_seconds' => round($seconds, 2)]);

            return ['ok' => true, 'seconds' => $seconds, 'words' => [], 'cached' => true];
        }

        $voice = (string) ($settings['tts_voice'] ?? McpCatalog::DEFAULT_VOICE);
        $isClone = TtsVoices::isClone($voice);
        $paid = !$isClone && McpCatalog::isPaidVoice($voice) && config('mcp.allow_paid_voices', false);
        $engine = $isClone ? 'clone' : ($paid ? 'openai' : 'kokoro');
        if (!$isClone && !$paid && !isset(McpCatalog::VOICES[$voice]) && !preg_match('/^[ab][mf]_[a-z0-9_]+$/', $voice)) {
            $voice = McpCatalog::DEFAULT_VOICE;
        }

        $hints = is_array($settings['speech_hints'] ?? null) ? $settings['speech_hints'] : [];
        $spoken = SpeechDictionary::forSpeech($text, $hints);
        $hash = md5($engine . ':' . $voice . '|' . $spoken);
        $relPath = "projects/{$project->id}/explainer/narration_{$scene->scene_id}.wav";
        $wordsRel = "projects/{$project->id}/explainer/narration_{$scene->scene_id}.words.json";
        $disk = Storage::disk('public');

        $existing = ExplainerAsset::where('project_id', $project->id)
            ->where('scene_id', $scene->scene_id)->where('slot_key', '__narration__')->first();
        $cached = $existing && $existing->original_name === $hash
            && $disk->exists($existing->path) && $disk->exists($wordsRel);

        if ($cached) {
            $duration = self::probe($disk->path($existing->path));
            $words = json_decode((string) $disk->get($wordsRel), true) ?: [];
        } else {
            $result = (new TTSGenerationService())->synthesize(
                $spoken,
                $relPath,
                ['voice' => $voice, 'word_timings' => true],
                [
                    'template_type' => 'ai_explainer_video',
                    'settings' => $settings,
                    'user_id' => (int) $project->user_id,
                    'force_provider' => $paid ? 'openai' : 'kokoro',
                ]
            );
            if (!($result['success'] ?? false)) {
                Log::warning('McpNarration: synthesis failed', ['project_id' => $project->id, 'scene' => $scene->scene_id, 'error' => $result['error'] ?? null]);

                return ['ok' => false, 'seconds' => (float) $scene->duration_seconds, 'words' => [], 'cached' => false, 'error' => $result['error'] ?? 'voice synthesis failed'];
            }
            $duration = (float) ($result['duration'] ?? 0);
            $words = array_values($result['word_timings'] ?? []);
            $disk->put($wordsRel, json_encode($words));
            $fellBack = $isClone && ($result['engine'] ?? null) !== 'clone';
            ExplainerAsset::updateOrCreate(
                ['project_id' => $project->id, 'scene_id' => $scene->scene_id, 'slot_key' => '__narration__'],
                ['type' => 'audio', 'path' => $result['audio_path'], 'original_name' => $hash . ($fellBack ? '~fallback' : '')]
            );
        }

        $voiced = round(max(0.5, $duration) + self::TAIL_SECONDS, 2);
        $seconds = round(min(150.0, max(1.5, $voiced + $hold)), 2);
        $scene->update(['duration_seconds' => $seconds]);

        McpVideoService::locked($project, function (Project $p) use ($scene, $voiced, $hash) {
            $s = $p->settings ?? [];
            if (isset($s['mcp']['scenes'][$scene->scene_id])) {
                $s['mcp']['scenes'][$scene->scene_id]['voiced'] = ['seconds' => $voiced, 'hash' => $hash];
                $p->update(['settings' => $s]);
            }
        });

        return ['ok' => true, 'seconds' => $seconds, 'words' => $words, 'cached' => $cached];
    }

    private static function probe(string $abs): float
    {
        try {
            return (float) FFProbe::create()->format($abs)->get('duration');
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}
