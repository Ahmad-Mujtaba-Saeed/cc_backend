<?php

namespace Modules\Project\Services\Shorts;

use Illuminate\Support\Facades\Log;
use Modules\Project\Services\OpenAiTtsService;
use Modules\Project\Services\TTSGenerationService;
use Modules\Project\Support\TtsVoices;
use Illuminate\Support\Facades\Storage;

/**
 * A narrator's voice on some of the shorts.
 *
 * WHY. A clip with captions and effects is still someone else's video; what
 * YouTube's reused-content policy treats as transformation is commentary — the
 * channel's own voice adding something. And a batch where every short opens
 * the same way (a title card over the first frame) is a template. So some
 * shorts open with a narrator setting the moment up, in a different voice and
 * delivery from the short before, and the rest do not.
 *
 * Two openings, dealt per short:
 *   intro_freeze — the first frame holds while the narrator speaks, then the
 *                  clip plays (every word and beat shifts later by that much)
 *   duck         — the narrator speaks over the opening; the clip's own audio
 *                  and the music duck under the line
 *
 * The line itself is the director's `narration`. TTS goes through the admin's
 * engine (OpenAI with a delivery instruction when it is active, Kokoro
 * otherwise). Any failure leaves the short exactly as it was.
 */
class ShortNarrator
{
    /** Delivery per playbook; one is dealt per short. */
    private const DELIVERY = [
        'stream' => [
            'Hyped gaming commentator, fast and amused, like you cannot believe what is about to happen.',
            'Deadpan and dry, a little sarcastic, like a friend narrating a fail.',
            'Low, dramatic trailer voice building suspense.',
        ],
        'podcast' => [
            'Calm, curious documentary narrator teasing an interesting idea.',
            'Warm and conversational, like recommending a clip to a friend.',
            'Serious and measured, like a news anchor setting up a story.',
        ],
        'irl' => [
            'Excited friend telling a wild story, quick and animated.',
            'Deadpan narrator, understated, letting the situation be funny.',
        ],
        'default' => [
            'Natural and upbeat, like a creator introducing their favourite clip.',
            'Calm storyteller setting up a moment.',
            'Energetic, punchy, short-form video narrator.',
        ],
    ];

    /**
     * @param  array<string, mixed>  $edit  ShortEditDirector::direct() output
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}  [edit, narration info]
     */
    public function apply(array $edit, array $analysis, array $style, string $mode, int $projectId, int $clip): array
    {
        $line = trim((string) ($edit['narration'] ?? ''));
        if ($mode === 'off' || $line === '' || str_word_count($line) < 4) {
            return [$edit, null];
        }
        // auto: about half the shorts, seeded so a re-render is stable.
        mt_srand($projectId * 131 + $clip * 17);
        $roll = mt_rand(1, 100);
        $opening = mt_rand(0, 1) ? 'intro_freeze' : 'duck';
        mt_srand();
        if ($mode !== 'always' && $roll > 55) {
            return [$edit, null];
        }

        $playbook = ShortPlaybook::key((string) ($analysis['source_format'] ?? ''), (string) ($analysis['scene_type'] ?? ''), !empty($analysis['webcam']));
        $deliveries = self::DELIVERY[$playbook] ?? self::DELIVERY['default'];
        $delivery = $deliveries[($projectId + $clip) % count($deliveries)];
        $provider = TtsVoices::activeProvider();
        $voices = array_keys($provider === 'openai' ? TtsVoices::OPENAI : TtsVoices::KOKORO);
        // Rotate from a per-project offset so two projects do not start alike.
        $voice = $voices[($projectId * 7 + $clip) % count($voices)];

        $relative = "projects/{$projectId}/tmp/narration_{$clip}.wav";
        $absolute = Storage::disk('public')->path($relative);
        try {
            $result = $provider === 'openai'
                ? (new OpenAiTtsService())->generateTTS($line, $voice, $absolute, false, $delivery)
                : (new TTSGenerationService())->synthesize($line, $relative, ['voice' => $voice], ['template_type' => 'yt_gameplay_short']);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }
        if (!($result['success'] ?? false) || !is_file($absolute)) {
            Log::warning('[SHORTS] Narration skipped — TTS failed', ['clip' => $clip, 'error' => $result['error'] ?? null]);
            return [$edit, null];
        }
        $dur = $this->duration($absolute);
        if ($dur <= 0.5 || $dur > 7.0) {
            return [$edit, null];
        }

        $url = ViralShortRenderService::assetUrl($relative);
        if ($opening === 'intro_freeze' && !empty($edit['segments'])) {
            $hold = round($dur + 0.25, 3);
            $edit = $this->shift($edit, $hold);
            array_unshift($edit['segments'], [
                // A beat into the clip: cuts often open on a transition frame.
                'src' => round((float) $edit['segments'][0]['src'] + 0.3, 3), 'dur' => $hold, 'rate' => 1, 'freeze' => true,
            ]);
            $edit['duration'] = round((float) $edit['duration'] + $hold, 3);
            $edit['events'][] = ['type' => 'voice', 'start' => 0.1, 'end' => round(0.1 + $dur, 3), 'url' => $url, 'volume' => 1.0, 'duck' => 0.25];
        } else {
            $edit['events'][] = ['type' => 'voice', 'start' => 0.15, 'end' => round(0.15 + $dur, 3), 'url' => $url, 'volume' => 1.0, 'duck' => 0.2];
        }

        $info = ['line' => $line, 'voice' => $voice, 'delivery' => $delivery, 'opening' => $opening, 'seconds' => round($dur, 2)];
        Log::info('[SHORTS] Narration added', ['clip' => $clip] + $info);

        return [$edit, $info];
    }

    /** Push every word, event and the hook later by $by seconds. */
    private function shift(array $edit, float $by): array
    {
        foreach ($edit['words'] as $i => $w) {
            $edit['words'][$i]['start'] = round($w['start'] + $by, 3);
            $edit['words'][$i]['end'] = round($w['end'] + $by, 3);
        }
        foreach ($edit['events'] as $i => $e) {
            foreach (['start', 'end'] as $k) {
                if (isset($e[$k])) {
                    $edit['events'][$i][$k] = round((float) $e[$k] + $by, 3);
                }
            }
        }
        if (!empty($edit['hook'])) {
            // The hook card sits over the narrated freeze, then leaves with it.
            $edit['hook']['until'] = round(max((float) ($edit['hook']['until'] ?? 0), $by - 0.1), 3);
        }

        return $edit;
    }

    private function duration(string $path): float
    {
        $out = @shell_exec('ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 ' . escapeshellarg($path));

        return (float) trim((string) $out);
    }
}
