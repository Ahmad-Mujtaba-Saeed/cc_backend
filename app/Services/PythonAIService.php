<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;

class PythonAIService
{
    private string $baseUrl;
    private int $timeout;
    private string $phpStorageRoot;
    private string $aiStorageRoot;
    private string $imageGenerationEndpoint;
    private ?string $authToken;

    public function __construct()
    {
        $this->baseUrl = config('services.python_ai.url', 'http://ai:8000');
        $this->falEndpoint = config('services.fal_ai.url', 'http://fal:8000');
        $this->timeout = config('services.fal_ai.timeout', 3600); // 1 hour
        $this->phpStorageRoot = env('PYTHON_AI_PHP_STORAGE_ROOT', '/var/www/storage');
        $this->aiStorageRoot = env('PYTHON_AI_AI_STORAGE_ROOT', '/app/storage');
        $this->imageGenerationEndpoint = config('services.fal_ai.image_generation_endpoint', '/generate-images');
        $this->authToken = env('FAL_AI_AUTH_TOKEN', null);
    }

    private function toAiPath(string $path): string
    {
        if (str_starts_with($path, $this->phpStorageRoot)) {
            return $this->aiStorageRoot . substr($path, strlen($this->phpStorageRoot));
        }

        return $path;
    }

    /**
     * Health check for Python AI service
     */
    public function healthCheck(): array
    {
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/health");

            if ($response->successful()) {
                return $response->json();
            }

            return ['status' => 'unhealthy', 'services' => []];
        } catch (Exception $e) {
            Log::error('Python AI service health check failed: ' . $e->getMessage());
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Upload file to Python service
     */
    public function uploadFile($file): array
    {
        try {
            $response = Http::timeout(300)->attach(
                'file',
                file_get_contents($file->getPathname()),
                $file->getClientOriginalName()
            )->post("{$this->baseUrl}/upload");

            return $response->json();
        } catch (Exception $e) {
            Log::error('File upload to Python service failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Transcribe video. Pass $wordTimestamps=true to get per-word timings in
     * each segment's `words` array (used for karaoke captions).
     */
    public function transcribe(string $videoPath, string $language = 'en', bool $wordTimestamps = false): array
    {
        try {
            $aiVideoPath = $this->toAiPath($videoPath);

            Log::info('PYTHON_AI: transcribe request', [
                'base_url' => $this->baseUrl,
                'php_video_path' => $videoPath,
                'ai_video_path' => $aiVideoPath,
                'language' => $language,
                'word_timestamps' => $wordTimestamps,
            ]);

            Log::info('[PYTHON_AI] Starting HTTP POST to transcribe endpoint', [
                'url' => "{$this->baseUrl}/transcribe",
                'timeout' => 3600
            ]);

            $response = Http::timeout(3600)->post("{$this->baseUrl}/transcribe", [
                'video_path' => $aiVideoPath,
                'language' => $language,
                'word_timestamps' => $wordTimestamps,
            ]);

            Log::info('[PYTHON_AI] HTTP response received', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_length' => strlen($response->body())
            ]);

            if (!$response->successful()) {
                Log::error('PYTHON_AI: transcribe HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [
                    'success' => false,
                    'error' => "HTTP {$response->status()}: {$response->body()}"
                ];
            }

            Log::info('[PYTHON_AI] Parsing JSON response');

            try {
                $result = $response->json();
                Log::info('[PYTHON_AI] JSON parsed successfully', [
                    'keys' => array_keys($result),
                    'success_field' => $result['success'] ?? 'missing'
                ]);
            } catch (\Exception $e) {
                Log::error('[PYTHON_AI] JSON parse error', [
                    'error' => $e->getMessage(),
                    'body_preview' => substr($response->body(), 0, 200)
                ]);
                return [
                    'success' => false,
                    'error' => "JSON parse error: " . $e->getMessage()
                ];
            }

            if ($result['success'] ?? false) {
                $segments = $result['segments'] ?? [];
                $duration = (float) ($result['duration'] ?? 0);
                if ($duration <= 0 && !empty($segments)) {
                    $duration = (float) (end($segments)['end'] ?? 0);
                }

                Log::info('[PYTHON_AI] Transcription successful', [
                    'segment_count' => count($segments),
                    'text_length' => strlen($result['text'] ?? ''),
                    'duration' => $duration
                ]);
                return [
                    'success' => true,
                    'segments' => $segments,
                    'text' => $result['text'] ?? '',
                    'duration' => $duration
                ];
            }

            $errorMsg = $result['error'] ?? 'Unknown error';
            Log::error('[PYTHON_AI] Transcription failed', [
                'error' => $errorMsg,
                'full_response' => $result
            ]);
            return ['success' => false, 'error' => $errorMsg];
        } catch (Exception $e) {
            Log::error('Transcription failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate TTS audio
     */
    public function generateTTS(string $text, string $voice = 'am_michael', string $outputPath = '', bool $wordTiming = false): array
    {
        try {
            $aiOutputPath = $outputPath !== '' ? $this->toAiPath($outputPath) : '';

            Log::info('PYTHON_AI: tts request', [
                'base_url' => $this->baseUrl,
                'voice' => $voice,
                'php_output_path' => $outputPath,
                'ai_output_path' => $aiOutputPath,
                'word_timing' => $wordTiming,
                'text_length' => strlen($text),
            ]);

            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/tts", [
                'text' => $text,
                'voice' => $voice,
                'output_path' => $aiOutputPath,
                'word_timing' => $wordTiming
            ]);

            if (!$response->successful()) {
                Log::error('PYTHON_AI: tts HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            Log::info('PYTHON_AI: tts raw response', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500), // Log first 500 chars
                'content_type' => $response->header('Content-Type'),
                'word_timing_requested' => $wordTiming,
            ]);

            try {
                $result = $response->json();
            } catch (Exception $jsonError) {
                Log::error('PYTHON_AI: JSON parse error on tts response', [
                    'error' => $jsonError->getMessage(),
                    'full_body' => $response->body(),
                    'status' => $response->status(),
                ]);
                throw $jsonError;
            }

            if ($result['success'] ?? false) {
                return [
                    'success' => true,
                    'audio_path' => $result['audio_path'] ?? $outputPath,
                    'word_timings' => $result['word_timings'] ?? []
                ];
            }

            return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
        } catch (Exception $e) {
            Log::error('TTS generation failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Separate vocals from an audio file, returning the background (no-vocals)
     * stem via the Python Demucs endpoint.
     *
     * Both paths are PHP-absolute storage paths; they are remapped into the AI
     * container's storage namespace before sending. The shared bind-mount means
     * the written stem round-trips straight back to PHP.
     */
    public function separateVocals(string $inputPath, string $outputPath): array
    {
        try {
            $aiInput = $this->toAiPath($inputPath);
            $aiOutput = $this->toAiPath($outputPath);

            Log::info('PYTHON_AI: separate-vocals request', [
                'base_url' => $this->baseUrl,
                'php_input' => $inputPath,
                'ai_input' => $aiInput,
                'php_output' => $outputPath,
                'ai_output' => $aiOutput,
            ]);

            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/separate-vocals", [
                'input_path' => $aiInput,
                'output_path' => $aiOutput,
            ]);

            if (!$response->successful()) {
                Log::warning('PYTHON_AI: separate-vocals HTTP error', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);
                return ['success' => false, 'error' => "HTTP {$response->status()}"];
            }

            $result = $response->json();

            if ($result['success'] ?? false) {
                return ['success' => true, 'output_path' => $this->toPhpPath($result['output_path'] ?? $outputPath)];
            }

            return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
        } catch (Exception $e) {
            Log::warning('Vocal separation failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Assemble the final Fal prompt for one image.
     *
     * Every stylistic instruction is OPT-IN. A caller that says nothing gets its
     * own prompt back (plus the shared detail boosters) and nothing else — a
     * template's art direction must never leak into another template's images.
     *
     * Order is `subject, [style] style, [visual_tone], [prompt_modifiers], [boosters]`.
     *
     * Options:
     *   visual_tone           string  art direction for THIS template (e.g. the horror grading)
     *   prompt_modifiers      string  extra per-run hints appended after the tone
     *   character_consistency bool    add "consistent character design" (only for templates with recurring characters)
     *   detail_boosters       bool    add the sharpness/coherence tail (default true)
     */
    public function buildImagePrompt(string $prompt, string $style, array $options = []): string
    {
        $finalPrompt = $prompt;
        if (!empty($style)) {
            $finalPrompt .= ", " . $style . " style";
        }

        $parts = [];

        // Art direction belongs to the CALLER. There is deliberately no default:
        // this used to fall back to the horror template's cel-shaded/eerie-fog
        // grading, which silently contaminated every other template's images.
        if (!empty($options['visual_tone']) && is_string($options['visual_tone'])) {
            $parts[] = $options['visual_tone'];
        }

        if (!empty($options['prompt_modifiers']) && is_string($options['prompt_modifiers'])) {
            $parts[] = $options['prompt_modifiers'];
        }

        if ($options['detail_boosters'] ?? true) {
            $parts[] = 'highly detailed';
            $parts[] = 'sharp focus';
            $parts[] = 'intricate details';
            // Meaningless (and actively harmful) for object/diagram/illustration
            // renders, so only templates with recurring characters ask for it.
            if (!empty($options['character_consistency'])) {
                $parts[] = 'consistent character design';
            }
            $parts[] = 'consistent color palette';
            $parts[] = 'coherent art style across scenes';
        }

        if (!empty($parts)) {
            $finalPrompt .= ', ' . implode(', ', $parts);
        }

        return $finalPrompt;
    }

    /**
     * Seed for image $index. Shared across a run by default so scenes hold one
     * look; callers whose images are independent (illustrations, props) pass
     * `vary_seed` so they don't all collapse onto the same composition.
     */
    public function imageSeed(string $projectId, int $index, array $options = []): int
    {
        $basis = !empty($options['vary_seed']) ? $projectId . '|' . $index : $projectId;

        return (int) (crc32($basis) % 2147483647);
    }

    /**
     * Generate images from script for AI image based shorts
     */
    public function generateImages($script, int $sceneCount = 5, string $style = 'cinematic', array $options = []): array
    {
        try {
            $prompts = [];

            // If script is a string, parse it into individual scene prompts
            if (is_string($script)) {
                Log::info("Parsing script string inside PythonAIService", ['script_length' => strlen($script)]);
                $parts = preg_split('/(?=(?:\d+\.\s*\*?[^\n]*Scene|Scene\s+\d+|Scene:\s+\d+|\b\d+\b\s*:\s*(?:Image|Visual)))/i', $script);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (empty($part)) {
                        continue;
                    }

                    $imagePrompt = '';
                    $lines = preg_split('/\r?\n/', $part);
                    $lines = array_map('trim', $lines);

                    // Skip the scene header (first non-empty line that contains Scene/number)
                    $descriptionStartIdx = 0;
                    foreach ($lines as $idx => $line) {
                        if (preg_match('/(?:^\d+\.|Scene\s+\d+|Scene:\s+\d+|^\*\*\d+)/i', $line)) {
                            $descriptionStartIdx = $idx + 1;
                            break;
                        }
                    }

                    // Now search for Image Description in remaining lines
                    for ($i = $descriptionStartIdx; $i < count($lines); $i++) {
                        $line = $lines[$i];
                        if (empty($line)) {
                            continue;
                        }
                        // Stop if we hit Narration
                        if (preg_match('/^\*?Narration|VO:|^Text:/i', $line)) {
                            break;
                        }
                        // Extract if this line has "Image Description:" label
                        if (preg_match('/(?:^\*?Image Description|^\*?Image|^\*?Visual|^\*?Prompt)\s*:\s*(.*)$/i', $line, $matches)) {
                            $imagePrompt = trim($matches[1]);
                            break;
                        }
                        // Otherwise if this is a description-like line (not a label), use it
                        if (!empty($imagePrompt) || ($i == $descriptionStartIdx && strlen($line) > 5)) {
                            if (empty($imagePrompt) && !preg_match('/^\*[A-Za-z]+\s*:/i', $line)) {
                                $imagePrompt = $line;
                                break;
                            }
                        }
                    }

                    // Clean up markdown symbols
                    $imagePrompt = trim(preg_replace('/[\*_]+/', '', $imagePrompt));
                    $imagePrompt = trim(preg_replace('/^(?:image|visual|prompt|description)\s*:\s*/i', '', $imagePrompt));
                    $imagePrompt = trim($imagePrompt, " \t\n\r\0\x0B\"'");

                    Log::debug("Extracted image prompt for scene", ['prompt' => substr($imagePrompt, 0, 100), 'length' => strlen($imagePrompt)]);

                    if (!empty($imagePrompt)) {
                        $prompts[] = $imagePrompt;
                    }
                }

                // Fallback if parsing failed
                if (empty($prompts)) {
                    Log::warning("Script parsing returned zero prompts, falling back to full script as single prompt");
                    $prompts[] = $script;
                }
            } elseif (is_array($script)) {
                $prompts = $script;
            } else {
                throw new \InvalidArgumentException("Script must be a string or an array of prompts");
            }

            // Cap the scene count
            $prompts = array_slice($prompts, 0, $sceneCount);
            Log::info("Starting Fal AI concurrent image generation", [
                'scene_count' => count($prompts),
                'style' => $style,
                'aspect_ratio' => $options['aspect_ratio'] ?? $options['ratio'] ?? '9:16',
                'project_id' => $options['project_id'] ?? 'temp'
            ]);

            $headers = [];
            if (!empty($this->authToken)) {
                $headers['Authorization'] = 'Key ' . $this->authToken;
            }

            $jobs = [];
            $endpointUrl = $this->falEndpoint . $this->imageGenerationEndpoint;

            $projectId = $options['project_id'] ?? 'temp';

            // Step 1: Submit all queue requests concurrently
            foreach ($prompts as $index => $prompt) {
                // Get aspect ratio from options or default to 9:16 for shorts
                $aspectRatioOpt = $options['aspect_ratio'] ?? $options['ratio'] ?? '9:16';

                // Map aspect ratio to Fal AI image_size preset values
                // Reference: https://fal.ai/models/fal-ai/flux/dev/api
                // Available: square_hd, square, portrait_4_3, portrait_16_9, landscape_4_3, landscape_16_9
                $imageSizeMap = [
                    '9:16' => 'portrait_16_9',      // Portrait 9:16
                    '16:9' => 'landscape_16_9',      // Landscape 16:9
                    '1:1' => 'square',               // Square 1:1
                    '4:3' => 'landscape_4_3',        // Landscape 4:3
                    '3:4' => 'portrait_4_3',         // Portrait 3:4 (4:3 rotated)
                ];

                $imageSize = $imageSizeMap[$aspectRatioOpt] ?? 'portrait_16_9';

                $finalPrompt = $this->buildImagePrompt($prompt, $style, $options);

                // Build payload - use image_size per Fal AI official API.
                // Allow callers to pass an explicit image_size array (e.g. ['width'=>576,'height'=>1024])
                // to override the preset string; this removes any ambiguity in Fal AI's naming.
                $resolvedImageSize = $options['image_size'] ?? $imageSize;

                $payload = [
                    'prompt' => $finalPrompt,
                    'num_images' => $options['num_images'] ?? 1,
                    'enable_safety_checker' => true,
                    'output_format' => 'jpeg',
                    'safety_tolerance' => '2',
                    'image_size' => $resolvedImageSize,
                    'seed' => $this->imageSeed((string) $projectId, $index, $options),
                    // flux/schnell is step-distilled for 1-4 steps; more steps add
                    // cost/latency with no quality gain. Callers may still override.
                    'num_inference_steps' => $options['num_inference_steps'] ?? 12,
                    'guidance_scale' => $options['guidance_scale'] ?? 4.0
                ];

                Log::info("Submitting scene " . ($index + 1) . " to Fal AI Queue", [
                    'image_size' => $payload['image_size'],
                    'aspect_ratio_input' => $aspectRatioOpt,
                    'prompt_preview' => substr($finalPrompt, 0, 150)
                ]);

                $response = Http::withHeaders($headers)
                    ->timeout(30)
                    ->post($endpointUrl, $payload);

                if (!$response->successful()) {
                    Log::error("Failed to submit scene " . ($index + 1) . " to Fal AI queue", [
                        'endpoint' => $endpointUrl,
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    throw new \Exception("Fal AI queue submission failed for scene " . ($index + 1));
                }

                $data = $response->json();
                $requestId = $data['request_id'] ?? null;
                if (empty($requestId)) {
                    Log::error("Fal AI queue submission did not return request_id for scene " . ($index + 1), ['response' => $data]);
                    throw new \Exception("Fal AI queue submission failed: no request_id returned");
                }

                $jobs[$index] = [
                    'request_id' => $requestId,
                    'status_url' => $data['status_url'] ?? "https://queue.fal.run/requests/{$requestId}/status",
                    'response_url' => $data['response_url'] ?? "https://queue.fal.run/requests/{$requestId}",
                    'status' => 'IN_QUEUE',
                    'completed' => false,
                    'local_path' => null
                ];
            }

            // Step 2: Poll status concurrently
            $startTime = time();
            $timeoutLimit = 180; // 3 minutes timeout limit
            $allCompleted = false;

            while (!$allCompleted && (time() - $startTime) < $timeoutLimit) {
                $allCompleted = true;
                sleep(2); // Poll every 2 seconds

                foreach ($jobs as $index => &$job) {
                    if ($job['completed']) {
                        continue;
                    }

                    $allCompleted = false; // At least one job is not completed yet

                    $statusResponse = Http::withHeaders($headers)->get($job['status_url']);
                    if (!$statusResponse->successful()) {
                        Log::warning("Failed to poll status for Fal AI job " . $job['request_id'] . ", status code: " . $statusResponse->status());
                        continue;
                    }

                    $statusData = $statusResponse->json();
                    $status = $statusData['status'] ?? 'IN_QUEUE';
                    $job['status'] = $status;

                    Log::info("Job status checked", [
                        'scene' => $index + 1,
                        'request_id' => $job['request_id'],
                        'status' => $status
                    ]);

                    if ($status === 'COMPLETED') {
                        // Some fal.ai API versions embed the output in the status response
                        $resultData = null;
                        if (!empty($statusData['payload']['images']) || !empty($statusData['output']['images'])) {
                            $resultData = $statusData['payload'] ?? $statusData['output'] ?? null;
                            Log::info("Fal AI result found in status response for scene " . ($index + 1));
                        }

                        if ($resultData === null) {
                            $fetchAttempts = 3;
                            $resultResponse = null;
                            for ($fetchTry = 1; $fetchTry <= $fetchAttempts; $fetchTry++) {
                                $resultResponse = Http::withHeaders($headers)->timeout(30)->get($job['response_url']);
                                if ($resultResponse->successful()) {
                                    break;
                                }
                                Log::warning("Attempt {$fetchTry}/{$fetchAttempts}: failed to fetch Fal AI response for scene " . ($index + 1), [
                                    'response_url' => $job['response_url'],
                                    'http_status' => $resultResponse->status(),
                                    'body_preview' => substr($resultResponse->body(), 0, 300),
                                ]);
                                if ($fetchTry < $fetchAttempts) {
                                    sleep(2);
                                }
                            }

                            if (!$resultResponse || !$resultResponse->successful()) {
                                Log::error("All attempts to fetch Fal AI response payload failed for job " . $job['request_id'], [
                                    'response_url' => $job['response_url'],
                                    'final_http_status' => $resultResponse ? $resultResponse->status() : 'no_response',
                                    'final_body' => $resultResponse ? substr($resultResponse->body(), 0, 500) : 'no_response',
                                ]);
                                throw new \Exception("Failed to fetch response payload for scene " . ($index + 1));
                            }

                            $resultData = $resultResponse->json();
                        }

                        $images = $resultData['images'] ?? [];
                        if (empty($images) || empty($images[0]['url'])) {
                            Log::error("Completed Fal AI job did not return any image URLs", ['response' => $resultData]);
                            throw new \Exception("No image URL returned from Fal AI for scene " . ($index + 1));
                        }

                        $cdnUrl = $images[0]['url'];
                        Log::info("Fal AI image generated successfully for scene " . ($index + 1), ['url' => $cdnUrl]);

                        $localPath = $this->downloadCdnImage($cdnUrl, $index, $options['project_id'] ?? 'temp');
                        if (!$localPath) {
                            throw new \Exception("Failed to download generated image for scene " . ($index + 1));
                        }

                        $job['local_path'] = $localPath;
                        $job['completed'] = true;

                    } elseif ($status === 'FAILED') {
                        Log::error("Fal AI job failed for scene " . ($index + 1), ['response' => $statusData]);
                        throw new \Exception("Fal AI image generation failed for scene " . ($index + 1) . ": " . ($statusData['error'] ?? 'Unknown Fal AI error'));
                    }
                }
            }

            if (!$allCompleted) {
                throw new \Exception("Image generation timed out after {$timeoutLimit} seconds");
            }

            // Ensure deterministic order by scene index (jobs keys were created from prompt order)
            ksort($jobs);
            $localPaths = array_values(array_map(fn($j) => $j['local_path'] ?? null, $jobs));

            Log::info('Image generation completed', [
                'returned_count' => count($localPaths),
                'paths' => $localPaths,
            ]);

            // Ledger: every path here is a real Fal generation (prompt-hash
            // caches live in the callers, above this method).
            \Modules\Project\Services\CostTracker::recordImages(
                count(array_filter($localPaths)),
                (string) ($options['cost_label'] ?? 'image_generation')
            );

            return [
                'success' => true,
                'images' => $localPaths
            ];

        } catch (Exception $e) {
            Log::error('Image generation failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Download image from Fal CDN and save it in Laravel local storage.
     */
    private function downloadCdnImage(string $url, int $index, string $projectId): ?string
    {
        try {
            $imageContent = Http::timeout(30)->get($url)->body();
            if (empty($imageContent)) {
                Log::error("Failed to download image contents from CDN: " . $url);
                return null;
            }

            // The index alone is NOT unique: a project generates images in
            // several independent passes (scene illustrations, then slot
            // fills, then the ambient backdrop) and each pass numbers its own
            // batch from 0. Sharing one directory, pass 2 silently overwrote
            // pass 1's files, so scenes ended up displaying an image generated
            // for a different scene (project 42: three asset rows, three
            // different prompts, one file). The CDN url is unique per
            // generated image, so a short digest of it keeps every file
            // distinct while the readable scene_N prefix stays.
            $relativePath = "projects/{$projectId}/images/scene_{$index}_" . substr(md5($url), 0, 8) . '.jpg';
            Storage::disk('public')->put($relativePath, $imageContent);

            $absolutePath = Storage::disk('public')->path($relativePath);

            Log::info("Downloaded Fal CDN image to local storage", [
                'url' => $url,
                'local_relative_path' => $relativePath,
                'local_absolute_path' => $absolutePath
            ]);

            return $absolutePath;
        } catch (Exception $e) {
            Log::error("Failed to download CDN image: " . $e->getMessage());
            return null;
        }
    }


    /**
     * Detect characters inside generated images
     */
    /**
     * Saliency focal point of an image (explainer §8 smart crop): where the
     * subject actually is, as fractions of the frame. Returns null on any
     * failure — callers treat the focus as decorative.
     *
     * @return array{fx: float, fy: float}|null
     */
    public function smartCrop(string $imagePath): ?array
    {
        try {
            $response = Http::timeout(30)->post("{$this->baseUrl}/smart-crop", [
                'image_path' => $this->toAiPath($imagePath),
            ]);
            $result = $response->json();
            if (!$response->successful() || !($result['success'] ?? false)) {
                return null;
            }

            return [
                'fx' => max(0.0, min(1.0, (float) ($result['fx'] ?? 0.5))),
                'fy' => max(0.0, min(1.0, (float) ($result['fy'] ?? 0.5))),
            ];
        } catch (Exception $e) {
            Log::info('PYTHON_AI: smart-crop unavailable (non-fatal): ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Perceptual (difference) hashes for a batch of images — the explainer's
     * duplicate-look guard (§8) compares hamming distances between them.
     *
     * @param  string[]  $imagePaths
     * @return array<int, ?string> hex hashes aligned with the input (null = unhashable), or null on service failure
     */
    public function imagePhash(array $imagePaths): ?array
    {
        try {
            $response = Http::timeout(60)->post("{$this->baseUrl}/image-phash", [
                'image_paths' => array_map([$this, 'toAiPath'], $imagePaths),
            ]);
            $result = $response->json();
            if (!$response->successful() || !($result['success'] ?? false)) {
                return null;
            }

            return array_values((array) ($result['hashes'] ?? []));
        } catch (Exception $e) {
            Log::info('PYTHON_AI: image-phash unavailable (non-fatal): ' . $e->getMessage());

            return null;
        }
    }

    public function detectCharacters(array $imagePaths): array
    {
        try {
            $aiImagePaths = array_map([$this, 'toAiPath'], $imagePaths);
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/detect-characters", [
                'image_paths' => $aiImagePaths,
            ]);

            if (!$response->successful()) {
                Log::error('PYTHON_AI: detect-characters HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            $result = $response->json();
            if (!($result['success'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }

            return [
                'success' => true,
                'characters' => $result['characters'] ?? [],
            ];
        } catch (Exception $e) {
            Log::error('Character detection failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Apply a subtle vibrate animation to detected characters.
     */
    public function applyVibrateEffect(array $imagePaths, array $characterData, array $options = []): array
    {
        try {
            $aiImagePaths = array_map([$this, 'toAiPath'], $imagePaths);
            $segmentIndex = $options['segment_index'] ?? 0;

            Log::info('PYTHON_AI: applyVibrateEffect request', [
                'image_count' => count($imagePaths),
                'image_paths' => $imagePaths,
                'ai_paths' => $aiImagePaths,
                'segment_index' => $segmentIndex,
                'project_id' => $options['project_id'] ?? 'N/A'
            ]);

            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/apply-vibrate", [
                'image_paths' => $aiImagePaths,
                'character_data' => $characterData,
                'segment_index' => $segmentIndex,
                'options' => $options,
            ]);

            if (!$response->successful()) {
                Log::error('PYTHON_AI: apply-vibrate HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            $result = $response->json();
            if (!($result['success'] ?? false)) {
                Log::error('PYTHON_AI: applyVibrateEffect failed', [
                    'error' => $result['error'] ?? 'Unknown error',
                    'input_images' => $aiImagePaths
                ]);
                return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }

            $normalized = $this->normalizePaths($result['animated_segments'] ?? []);

            Log::info('PYTHON_AI: applyVibrateEffect succeeded', [
                'input_count' => count($imagePaths),
                'output_count' => count($normalized),
                'outputs' => $normalized
            ]);

            return ['success' => true, 'animated_segments' => $normalized];
        } catch (Exception $e) {
            Log::error('Vibrate effect failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Assemble animated segments, narration and captions into a final video.
     */
    public function assembleScenes(array $sceneDefinitions, string $audioPath, string $outputPath, array $settings = []): array
    {
        try {
            if (empty($sceneDefinitions)) {
                throw new \Exception('No scene definitions provided');
            }

            Log::info('PYTHON_AI: assembleScenes - received scene definitions', [
                'scene_count' => count($sceneDefinitions),
                'audio_path' => $audioPath,
                'output_path' => $outputPath
            ]);

            $aiSceneDefinitions = [];
            foreach ($sceneDefinitions as $index => $scene) {
                $normalizedScene = [
                    'segment_path' => $this->toAiPath($scene['segment_path'] ?? ''),
                    'order' => $scene['order'] ?? ($index + 1),
                    'effect' => $scene['effect'] ?? null,
                    'duration' => $scene['duration'] ?? null,
                    'scene_index' => $scene['scene_index'] ?? $index,
                    'total_scenes' => $scene['total_scenes'] ?? count($sceneDefinitions),
                ];
                $aiSceneDefinitions[] = $normalizedScene;

                Log::debug("Normalizing scene $index", [
                    'order' => $normalizedScene['order'],
                    'php_path' => $scene['segment_path'],
                    'ai_path' => $normalizedScene['segment_path'],
                    'duration' => $normalizedScene['duration']
                ]);
            }

            Log::info('PYTHON_AI: assembleScenes - sending to Python service', [
                'scene_definitions_count' => count($aiSceneDefinitions),
                'scenes' => array_map(fn($s) => [
                    'order' => $s['order'],
                    'path' => $s['segment_path'],
                    'duration' => $s['duration']
                ], $aiSceneDefinitions),
                'audio_path' => $this->toAiPath($audioPath),
                'output_path' => $this->toAiPath($outputPath),
                'settings' => $settings
            ]);

            // Map the optional background music path into the AI container's
            // storage namespace before sending it on.
            if (!empty($settings['background_music_path'])) {
                $settings['background_music_path'] = $this->toAiPath($settings['background_music_path']);
            }

            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/assemble-scenes", [
                'scenes' => $aiSceneDefinitions,
                'audio_path' => $this->toAiPath($audioPath),
                'output_path' => $this->toAiPath($outputPath),
                'settings' => $settings,
            ]);

            if (!$response->successful()) {
                Log::error('PYTHON_AI: assemble-scenes HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            $result = $response->json();
            if (!($result['success'] ?? false)) {
                Log::error('PYTHON_AI: assembleScenes failed', [
                    'error' => $result['error'] ?? 'Unknown error',
                    'scenes_sent' => count($aiSceneDefinitions)
                ]);
                return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }

            Log::info('PYTHON_AI: assembleScenes succeeded', [
                'output_path' => $result['output_path'] ?? $outputPath,
                'scenes_processed' => count($aiSceneDefinitions)
            ]);

            return ['success' => true, 'output_path' => $this->toPhpPath($result['output_path'] ?? $outputPath)];
        } catch (Exception $e) {
            Log::error('Scene assembly failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Normalize paths returned from the Python service into PHP storage paths.
     */
    private function toPhpPath(string $path): string
    {
        if (empty($path)) {
            return $path;
        }

        if (Str::startsWith($path, $this->aiStorageRoot)) {
            return $this->phpStorageRoot . substr($path, strlen($this->aiStorageRoot));
        }

        return $path;
    }

    private function normalizePaths(array $paths): array
    {
        return array_values(array_filter(array_map(fn($path) => $this->toPhpPath($path), $paths), fn($path) => !empty($path)));
    }

    /**
     * Detect faces in video
     */
    public function detectFaces(string $videoPath, bool $detectObjects = true): array
    {
        try {
            $aiVideoPath = $this->toAiPath($videoPath);

            Log::info('PYTHON_AI: detect-faces request', [
                'base_url' => $this->baseUrl,
                'php_video_path' => $videoPath,
                'ai_video_path' => $aiVideoPath,
                'detect_objects' => $detectObjects,
            ]);

            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/detect-faces", [
                'video_path' => $aiVideoPath,
                'detect_objects' => $detectObjects
            ]);

            if (!$response->successful()) {
                Log::error('PYTHON_AI: detect-faces HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            $result = $response->json();

            if ($result['success'] ?? false) {
                return [
                    'success' => true,
                    'faces' => $result['faces'] ?? [],
                    'objects' => $result['objects'] ?? [],
                    'tracks' => $result['tracks'] ?? []
                ];
            }

            return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
        } catch (Exception $e) {
            Log::error('Face detection failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Edit video
     */
    public function editVideo(string $videoPath, string $outputPath, string $editType, array $settings = [], ?string $audioPath = null, ?array $faceData = null): array
    {
        try {
            $aiVideoPath = $this->toAiPath($videoPath);
            $aiOutputPath = $this->toAiPath($outputPath);
            $payload = [
                'video_path' => $aiVideoPath,
                'output_path' => $aiOutputPath,
                'edit_type' => $editType,
                'settings' => $settings
            ];

            if ($audioPath) {
                $payload['audio_path'] = $this->toAiPath($audioPath);
            }

            if ($faceData) {
                $payload['face_data'] = $faceData;
            }

            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/edit-video", $payload);

            if (!$response->successful()) {
                Log::error('PYTHON_AI: edit-video HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'edit_type' => $editType,
                ]);
            }

            try {
                $result = $response->json();
            } catch (\Exception $e) {
                Log::error('PYTHON_AI: edit-video JSON parse error', [
                    'body' => $response->body(),
                    'exception' => $e->getMessage(),
                    'edit_type' => $editType,
                ]);
                return ['success' => false, 'error' => $e->getMessage()];
            }

            if ($result['success'] ?? false) {
                return [
                    'success' => true,
                    'output_path' => $result['output_path'] ?? $outputPath
                ];
            }

            return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
        } catch (Exception $e) {
            Log::error('Video editing failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete file from Python service storage
     */
    public function deleteFile(string $filePath): array
    {
        try {
            $response = Http::timeout(60)->delete("{$this->baseUrl}/files/" . ltrim($filePath, '/'));

            return $response->json();
        } catch (Exception $e) {
            Log::error('File deletion failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generic request to Python AI service.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $uri Endpoint URI starting with '/'
     * @param array $payload Data to send with the request (for POST/PUT)
     * @return array Response array containing 'success' flag and data or error message
     */
    public function makeRequest(string $method, string $uri, array $payload = []): array
    {
        try {
            $url = rtrim($this->baseUrl, '/') . '/' . ltrim($uri, '/');
            $http = Http::timeout($this->timeout);
            $methodUpper = strtoupper($method);
            switch ($methodUpper) {
                case 'GET':
                    $response = $http->get($url, $payload);
                    break;
                case 'POST':
                    $response = $http->post($url, $payload);
                    break;
                case 'PUT':
                    $response = $http->put($url, $payload);
                    break;
                case 'DELETE':
                    $response = $http->delete($url, $payload);
                    break;
                default:
                    Log::error('PythonAIService: Unsupported HTTP method', ['method' => $method]);
                    return ['success' => false, 'error' => 'Unsupported HTTP method'];
            }

            if (!$response->successful()) {
                Log::error('PythonAIService request failed', [
                    'method' => $methodUpper,
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['success' => false, 'error' => "HTTP {$response->status()}: {$response->body()}"];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('PythonAIService makeRequest exception', ['exception' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get file URL from Python service
     */
    public function getFileUrl(string $filePath): string
    {
        return "{$this->baseUrl}/files/" . ltrim($filePath, '/');
    }

    /**
     * Strip the background off a generated image — a REAL alpha channel, via
     * fal's BiRefNet segmentation (synchronous endpoint; the image goes up as
     * a data URI so nothing has to be publicly hosted first).
     *
     * Flux cannot emit transparency, so free-standing sprites (a car that sits
     * ON the road line of a scenario diagram rather than inside a rectangle of
     * background) are generated on a plain backdrop and cut out here.
     *
     * Returns the absolute path of the new `<name>.alpha.png`, or null on any
     * failure — callers treat a missing cutout as "fall back to the icon".
     */
    public function removeBackground(string $absolutePath): ?string
    {
        try {
            if (empty($this->authToken) || !is_file($absolutePath)) {
                return null;
            }

            $out = preg_replace('/\.[A-Za-z0-9]+$/', '', $absolutePath) . '.alpha.png';
            if (is_file($out)) {
                return $out; // idempotent: same source image ⇒ same cutout
            }

            $mime = mime_content_type($absolutePath) ?: 'image/jpeg';
            $data = base64_encode((string) file_get_contents($absolutePath));

            $response = Http::withHeaders(['Authorization' => 'Key ' . $this->authToken])
                ->timeout(120)
                ->post('https://fal.run/fal-ai/birefnet/v2', [
                    'image_url' => "data:{$mime};base64,{$data}",
                    'model' => 'General Use (Light)',
                    'operating_resolution' => '1024x1024',
                    'output_format' => 'png',
                ]);

            if (!$response->successful()) {
                Log::warning('PythonAIService: birefnet background removal failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);
                return null;
            }

            $url = $response->json('image.url');
            if (empty($url)) {
                return null;
            }
            $png = Http::timeout(120)->get($url);
            if (!$png->successful()) {
                return null;
            }
            file_put_contents($out, $png->body());

            \Modules\Project\Services\CostTracker::recordExternal('fal', 'birefnet_rembg');

            return $out;
        } catch (\Throwable $e) {
            Log::warning('PythonAIService: removeBackground exception (non-fatal): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if service is available
     */
    public function isAvailable(): bool
    {
        $health = $this->healthCheck();
        return ($health['status'] ?? 'error') === 'healthy';
    }
}
