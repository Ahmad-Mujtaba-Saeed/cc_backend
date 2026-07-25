<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AccessControl\Models\ApiCredential;

/**
 * RapidApiTranscriptionService
 *
 * Fetches a YouTube video's transcript via the RapidAPI "youtube-transcriber11"
 * endpoint instead of transcribing the downloaded file locally (Faster-Whisper).
 * This is far faster — no audio extraction, no local inference — and returns
 * YouTube's own caption timings.
 *
 * Endpoint (GET):
 *   https://youtube-transcriber11.p.rapidapi.com/transcript
 *     ?video_url={VIDEO_ID}&format=json&include_timestamp=true&send_metadata=false
 *
 * Response:
 *   { "video_id": "...", "language": "en",
 *     "transcript": [ { "text": "...", "start": <ms>, "duration": <ms> }, ... ] }
 *
 * NOTE: start/duration are in MILLISECONDS. We normalise to the seconds-based
 * { segments:[{start,end,text}], fullText, totalDuration } shape the rest of the
 * pipeline (ClipSelectionService, caption builder) already expects.
 *
 * Only works for YouTube videos (needs a video id); uploaded files must still
 * use the local VideoTranscriptionService.
 */
class RapidApiTranscriptionService
{
    private string $host;

    public function __construct()
    {
        $this->host = (string) config('services.rapidapi.transcribe_host', 'youtube-transcriber11.p.rapidapi.com');
    }

    /**
     * Keys live in the admin-managed api_credentials pool (never .env).
     */
    public function isConfigured(): bool
    {
        return ApiCredential::hasActive('rapidapi');
    }

    /**
     * Transcribe a YouTube video by its 11-char video id.
     *
     * Tries each active RapidAPI key in order (default first) and fails over
     * to the next key when a request errors, recording per-key health.
     *
     * @return array{success: bool, segments: array<int, array{start: float, end: float, text: string}>, fullText: string, totalDuration: float, language: string}
     */
    public function transcribeByVideoId(string $videoId, string $language = 'en'): array
    {
        $credentials = ApiCredential::forProvider('rapidapi');

        if ($credentials->isEmpty()) {
            throw new \Exception('No active RapidAPI key configured. Add one in Settings → Integrations.');
        }

        $data = null;
        $lastError = null;

        foreach ($credentials->values() as $index => $credential) {
            try {
                $data = $this->requestTranscript($credential->credential, $videoId);
                $credential->markSuccess();

                if ($index > 0) {
                    Log::info('RapidApiTranscriptionService: fallback key succeeded', [
                        'credential_id' => $credential->id,
                        'keys_tried' => $index + 1,
                    ]);
                }
                break;
            } catch (\Exception $e) {
                $lastError = $e;
                $credential->markFailure($e->getMessage());

                Log::warning('RapidApiTranscriptionService: key failed, trying next', [
                    'credential_id' => $credential->id,
                    'credential_label' => $credential->label,
                    'error' => $e->getMessage(),
                    'remaining_keys' => $credentials->count() - $index - 1,
                ]);
            }
        }

        if ($data === null) {
            throw new \Exception(sprintf(
                'All %d RapidAPI key(s) failed for transcription. Last error: %s',
                $credentials->count(),
                $lastError?->getMessage() ?? 'unknown'
            ));
        }

        $rows = $this->extractTranscriptRows($data);

        if (empty($rows)) {
            Log::error('RapidApiTranscriptionService: empty transcript', [
                'video_id' => $videoId,
                'keys' => is_array($data) ? array_keys($data) : gettype($data),
            ]);
            throw new \Exception('RapidAPI returned no transcript — the video may have captions disabled');
        }

        return $this->normalize($rows, $data['language'] ?? $language);
    }

    /**
     * Perform the transcript request with one specific API key.
     * Throws on any non-2xx response so the caller can fail over.
     */
    private function requestTranscript(string $apiKey, string $videoId)
    {
        Log::info('RapidApiTranscriptionService: requesting transcript', [
            'video_id' => $videoId,
            'host' => $this->host,
        ]);

        $response = Http::withHeaders([
            'x-rapidapi-host' => $this->host,
            'x-rapidapi-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(60)->get("https://{$this->host}/transcript", [
            'video_url' => $videoId,
            'format' => 'json',
            'include_timestamp' => 'true',
            'send_metadata' => 'false',
        ]);

        if (!$response->successful()) {
            Log::error('RapidApiTranscriptionService: request failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            throw new \Exception('RapidAPI transcription failed: HTTP ' . $response->status());
        }

        return $response->json();
    }

    /**
     * The API returns `transcript` as the rows array, but be tolerant of a bare
     * array or alternate keys so a minor response change doesn't break us.
     *
     * @return array<int, array>
     */
    private function extractTranscriptRows($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        foreach (['transcript', 'segments', 'data', 'result'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        // Bare list of rows (no wrapper object).
        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        return [];
    }

    /**
     * Convert millisecond rows → seconds-based segments and clamp overlaps so
     * rolling YouTube auto-captions don't produce double-stacked captions.
     */
    private function normalize(array $rows, string $language): array
    {
        $segments = [];
        foreach ($rows as $row) {
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $startMs = (float) ($row['start'] ?? $row['offset'] ?? 0);
            $durMs = (float) ($row['duration'] ?? $row['dur'] ?? 0);

            $start = $startMs / 1000.0;
            $end = ($startMs + max(0.0, $durMs)) / 1000.0;

            $segments[] = ['start' => $start, 'end' => $end, 'text' => $text];
        }

        // Ensure chronological order, then clamp each end to the next start so
        // overlapping caption windows become sequential.
        usort($segments, fn ($a, $b) => $a['start'] <=> $b['start']);
        $count = count($segments);
        for ($i = 0; $i < $count - 1; $i++) {
            $nextStart = $segments[$i + 1]['start'];
            if ($segments[$i]['end'] > $nextStart) {
                $segments[$i]['end'] = max($segments[$i]['start'] + 0.2, $nextStart);
            }
        }

        $fullText = trim(implode(' ', array_column($segments, 'text')));
        $totalDuration = $count > 0 ? (float) $segments[$count - 1]['end'] : 0.0;

        Log::info('RapidApiTranscriptionService: transcript normalised', [
            'segments' => $count,
            'duration' => $totalDuration,
            'language' => $language,
        ]);

        return [
            'success' => true,
            'segments' => $segments,
            'fullText' => $fullText,
            'totalDuration' => $totalDuration,
            'language' => $language,
        ];
    }
}
