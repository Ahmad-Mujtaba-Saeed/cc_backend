<?php

namespace Modules\Project\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PythonAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Services\OpenAiTtsService;
use Modules\Project\Support\TtsVoices;

/**
 * Narration voice metadata + on-demand voice previews for the create flow.
 *
 * A preview is a short fixed sentence synthesized ONCE per provider+voice and
 * cached in public storage, so the ▶ button on a voice chip costs one TTS
 * call ever (per voice), not one per click.
 */
class TtsController extends Controller
{
    private const PREVIEW_TEXT = 'The future of AI video creation starts today. '
        . 'Pick the voice that fits your story, and let\'s bring it to life.';

    private const PREVIEW_DIR = 'tts_previews';

    /**
     * The active provider and its selectable voices (optionally with the
     * suggested default for a template).
     */
    public function voices(Request $request): JsonResponse
    {
        $provider = TtsVoices::activeProvider();
        $template = $request->query('template');

        return response()->json([
            'provider' => $provider,
            'voices' => TtsVoices::forProvider($provider),
            'default' => TtsVoices::defaultFor($provider, is_string($template) ? $template : null),
        ]);
    }

    /**
     * Synthesize (or reuse) a short sample for one voice of the ACTIVE
     * provider and return its public URL.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'voice' => ['required', 'string', 'max:40'],
        ]);

        $provider = TtsVoices::activeProvider();
        $voice = TtsVoices::resolve($data['voice'], $provider);

        $relative = self::PREVIEW_DIR . "/{$provider}_{$voice}.wav";
        $absolute = Storage::disk('public')->path($relative);

        if (!Storage::disk('public')->exists($relative)) {
            $dir = dirname($absolute);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $result = $provider === 'openai'
                ? (new OpenAiTtsService())->generateTTS(
                    self::PREVIEW_TEXT,
                    $voice,
                    $absolute,
                    false,
                    'Speak warmly and clearly, with natural confident energy.'
                )
                : (new PythonAIService())->generateTTS(self::PREVIEW_TEXT, $voice, $absolute, false);

            if (!($result['success'] ?? false) || !Storage::disk('public')->exists($relative)) {
                Log::warning('TtsController: preview synthesis failed', [
                    'provider' => $provider,
                    'voice' => $voice,
                    'error' => $result['error'] ?? 'unknown',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Voice preview is unavailable right now.',
                ], 503);
            }
        }

        return response()->json([
            'success' => true,
            'provider' => $provider,
            'voice' => $voice,
            'url' => Storage::disk('public')->url($relative),
        ]);
    }
}
