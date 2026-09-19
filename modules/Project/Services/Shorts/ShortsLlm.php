<?php

namespace Modules\Project\Services\Shorts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Services\CostTracker;
use Modules\Project\Support\LlmModels;

/**
 * One JSON-returning chat call for the shorts editor, with the plumbing every
 * call site would otherwise repeat: the GPT-5 payload shim, one retry on a
 * transient failure, cost recording, and tolerant JSON extraction.
 *
 * Never throws: a null return means "no answer", and every caller has a
 * deterministic fallback.
 */
final class ShortsLlm
{
    public static function apiKey(): string
    {
        return (string) (config('services.openai.api_key') ?: env('OPENAI_API_KEY', ''));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>|null
     */
    public static function json(string $model, array $messages, int $maxTokens, string $label, string $effort = 'minimal', int $timeout = 90): ?array
    {
        $key = self::apiKey();
        if ($key === '' || $model === '') {
            return null;
        }

        $payload = LlmModels::tune([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.6,
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'],
        ], $effort);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::withToken($key)
                    ->timeout($timeout)
                    ->post('https://api.openai.com/v1/chat/completions', $payload);

                if ($response->successful()) {
                    CostTracker::recordChat($model, $response->json('usage'), $label);
                    $content = (string) $response->json('choices.0.message.content', '');
                    $decoded = self::decode($content);
                    if ($decoded !== null) {
                        return $decoded;
                    }
                    Log::warning('[SHORTS] Model returned unparseable JSON', [
                        'label' => $label,
                        'finish' => $response->json('choices.0.finish_reason'),
                        'head' => mb_substr($content, 0, 200),
                    ]);
                } else {
                    Log::warning('[SHORTS] Model call failed', [
                        'label' => $label,
                        'status' => $response->status(),
                        'body' => mb_substr((string) $response->body(), 0, 300),
                    ]);
                    // A 4xx other than rate-limiting will not fix itself.
                    if ($response->status() < 500 && $response->status() !== 429) {
                        return null;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[SHORTS] Model call errored', ['label' => $label, 'error' => $e->getMessage()]);
            }
            if ($attempt === 1) {
                usleep(1_500_000);
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function decode(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Fenced or chatty replies: take the outermost object.
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** A JPEG on disk as a data URI for an image_url part, or null. */
    public static function imageDataUri(string $path): ?string
    {
        if (!is_file($path) || filesize($path) === 0 || filesize($path) > 4_000_000) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($path));
    }
}
