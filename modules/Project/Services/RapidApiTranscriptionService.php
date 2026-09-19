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

        $errors = [];
        // Providers in order, each tried with every key. A RapidAPI key is
        // subscribed per API, so a key that 403s on one may work on the next.
        foreach ($this->providers() as $provider) {
            foreach ($credentials->values() as $credential) {
                try {
                    $data = $this->requestTranscript($credential->credential, $videoId, $provider, $language);
                    $rows = $this->extractTranscriptRows($data);
                    if (empty($rows)) {
                        throw new \Exception('empty transcript (captions disabled?)');
                    }
                    $credential->markSuccess();
                    if ($provider['host'] !== $this->host || $errors) {
                        Log::info('RapidApiTranscriptionService: fallback succeeded', [
                            'host' => $provider['host'],
                            'credential_id' => $credential->id,
                            'earlier_failures' => count($errors),
                        ]);
                    }

                    return $this->normalize($rows, (string) ($data['language'] ?? $language), $provider['unit']);
                } catch (\Exception $e) {
                    $errors[] = "{$provider['host']}: {$e->getMessage()}";
                    Log::warning('RapidApiTranscriptionService: provider/key failed, trying next', [
                        'host' => $provider['host'],
                        'credential_id' => $credential->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        throw new \Exception('All RapidAPI transcript providers failed: ' . implode(' | ', array_slice($errors, -3)));
    }

    /**
     * The transcript APIs, in the order they are tried. `unit` is what their
     * timings are in (1000 = milliseconds, 1 = seconds).
     *
     * @return array<int, array{host: string, path: string, unit: float, query: \Closure}>
     */
    private function providers(): array
    {
        return [
            [
                'host' => $this->host,
                'path' => '/transcript',
                'unit' => 1000.0,
                'query' => fn (string $id, string $lang) => [
                    'video_url' => $id, 'format' => 'json', 'include_timestamp' => 'true', 'send_metadata' => 'false',
                ],
            ],
            // youtube-transcript3: rows are {text, offset, duration} in SECONDS
            // (as strings), text HTML-escaped. Added 2026-09-19 after the first
            // API 503'd for a whole day and every run fell to local Whisper,
            // which the laptop's Docker VM could not hold for a long video.
            [
                'host' => (string) config('services.rapidapi.transcribe_fallback_host', 'youtube-transcript3.p.rapidapi.com'),
                'path' => '/api/transcript-with-url',
                'unit' => 1.0,
                'query' => fn (string $id, string $lang) => [
                    'url' => "https://www.youtube.com/watch?v={$id}", 'flat_text' => 'false', 'lang' => $lang,
                ],
            ],
        ];
    }

    /**
     * Perform the transcript request with one specific API key.
     * Throws on any non-2xx response so the caller can fail over.
     *
     * A 5xx from this upstream is usually transient (502s show up on healthy
     * keys under load), so retry the SAME key on a server-side error before
     * giving up on it; a 4xx (bad key, quota, not subscribed) is not retried.
     */
    private function requestTranscript(string $apiKey, string $videoId, array $provider, string $language)
    {
        $attempts = 3;
        $lastStatus = null;
        $host = $provider['host'];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            Log::info('RapidApiTranscriptionService: requesting transcript', [
                'video_id' => $videoId,
                'host' => $host,
                'attempt' => $attempt,
            ]);

            $response = Http::withHeaders([
                'x-rapidapi-host' => $host,
                'x-rapidapi-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(90)->get("https://{$host}{$provider['path']}", ($provider['query'])($videoId, $language));

            if ($response->successful()) {
                $json = $response->json();
                if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
                    throw new \Exception('provider said success=false: ' . substr((string) ($json['error'] ?? $json['message'] ?? ''), 0, 160));
                }

                return $json;
            }

            $lastStatus = $response->status();
            $retryable = $lastStatus >= 500 || $lastStatus === 429;

            Log::error('RapidApiTranscriptionService: request failed', [
                'host' => $host,
                'status' => $lastStatus,
                'attempt' => $attempt,
                'will_retry' => $retryable && $attempt < $attempts,
                'body' => substr($response->body(), 0, 300),
            ]);

            if (!$retryable || $attempt === $attempts) {
                break;
            }

            sleep($attempt * 2);
        }

        throw new \Exception('HTTP ' . $lastStatus);
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
    private function normalize(array $rows, string $language, float $unit = 1000.0): array
    {
        $segments = [];
        foreach ($rows as $row) {
            $text = trim(html_entity_decode((string) ($row['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                continue;
            }

            $startMs = (float) ($row['start'] ?? $row['offset'] ?? 0);
            $durMs = (float) ($row['duration'] ?? $row['dur'] ?? 0);

            $start = $startMs / $unit;
            $end = ($startMs + max(0.0, $durMs)) / $unit;

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
