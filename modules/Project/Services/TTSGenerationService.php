<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Support\TtsInstructions;
use Modules\Project\Support\TtsVoices;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Exception;

class TTSGenerationService
{
    private PythonAIService $pythonAI;

    public function __construct()
    {
        $this->pythonAI = new PythonAIService();
    }

    /**
     * Generate narration through the admin-selected engine (AppSetting
     * `tts_provider`): self-hosted Kokoro (default, free) or OpenAI
     * gpt-4o-mini-tts (styled per template via TtsInstructions).
     *
     * $context = ['template_type' => string, 'settings' => array] lets the
     * OpenAI path pick delivery instructions and both paths resolve a stored
     * voice that belongs to the other engine (after an admin switch) onto the
     * template's default. An OpenAI failure falls back to Kokoro so a render
     * never dies because of a provider outage.
     */
    public function generateTTS(string $text, string $voice = 'am_michael', string $outputPath = null, bool $wordTiming = false, array $context = []): array
    {
        try {
            // Generate output path if not provided
            if (!$outputPath) {
                $outputPath = 'tts_output_' . time() . '.wav';
            }

            $templateType = $context['template_type'] ?? null;
            $settings = (array) ($context['settings'] ?? []);
            $provider = TtsVoices::activeProvider();

            Log::info("Starting TTS generation", [
                'provider' => $provider,
                'requested_voice' => $voice,
                'template_type' => $templateType,
            ]);

            if ($provider === 'openai') {
                $openaiVoice = TtsVoices::resolve($voice, 'openai', $templateType);
                $instructions = TtsInstructions::for($templateType, $settings);

                $result = (new OpenAiTtsService())->generateTTS($text, $openaiVoice, $outputPath, $wordTiming, $instructions);

                if ($result['success'] ?? false) {
                    return $result;
                }

                Log::warning('TTS: OpenAI engine failed — falling back to Kokoro', [
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }

            $kokoroVoice = TtsVoices::resolve($voice, 'kokoro', $templateType);

            // Check if Python AI service is available
            if (!$this->pythonAI->isAvailable()) {
                throw new Exception('Python AI service is not available');
            }

            // Call Python AI service (Kokoro)
            $result = $this->pythonAI->generateTTS($text, $kokoroVoice, $outputPath, $wordTiming);

            if (!$result['success']) {
                Log::error("TTS generation failed: {$result['error']}");
                throw new Exception($result['error']);
            }

            Log::info("TTS generation completed successfully");
            return $result;

        } catch (Exception $e) {
            Log::error("TTS generation service error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * KokoroTtsService-style contract (relative public-disk path in, duration
     * probed) on top of the provider routing above — the explainer processor's
     * entry point.
     *
     * @param  array  $options ['voice' => string, 'word_timings' => bool]
     * @return array{success: bool, audio_path?: string, duration?: float, word_timings?: array, error?: string}
     */
    public function synthesize(string $text, string $outputRelativePath, array $options = [], array $context = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['success' => false, 'error' => 'Empty narration text'];
        }

        $absolutePath = Storage::disk('public')->path($outputRelativePath);
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        try {
            $result = $this->generateTTS(
                $text,
                (string) ($options['voice'] ?? ''),
                $absolutePath,
                (bool) ($options['word_timings'] ?? false),
                $context
            );
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (!($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'TTS failed'];
        }

        if (!Storage::disk('public')->exists($outputRelativePath)) {
            return ['success' => false, 'error' => 'TTS reported success but produced no file'];
        }

        try {
            $duration = (float) FFProbe::create()->format($absolutePath)->get('duration');
        } catch (\Throwable $e) {
            Log::warning('TTSGenerationService: ffprobe failed, defaulting duration: ' . $e->getMessage());
            $duration = 0.0;
        }

        return [
            'success' => true,
            'audio_path' => $outputRelativePath,
            'duration' => $duration,
            'word_timings' => (array) ($result['word_timings'] ?? []),
        ];
    }

    /**
     * Generate TTS with word-level timing for subtitles.
     */
    public function generateTTSWithTiming(string $text, array $settings = []): ?array
    {
        try {
            Log::info('Starting TTS generation with timing');

            $voice = $settings['voice'] ?? 'am_michael';
            $speed = $settings['speed'] ?? 1.05;
            $langCode = $settings['lang_code'] ?? 'a';
            $outputPath = $settings['output_path'] ?? $this->generateTempPath();
            $timingsPath = str_replace('.wav', '_timings.json', $outputPath);

            $process = new Process([
                $this->pythonPath,
                $this->scriptPath,
                'tts-timing',
                $text,
                '--voice', $voice,
                '--speed', (string)$speed,
                '--lang-code', $langCode,
                '--output', $outputPath,
                '--timings', $timingsPath
            ]);

            $process->setTimeout(180); // 3 minutes
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Read timings file
            $timings = null;
            if (file_exists($timingsPath)) {
                $timingsJson = file_get_contents($timingsPath);
                $timings = json_decode($timingsJson, true);
            }

            Log::info('TTS generation with timing completed', [
                'output_path' => $outputPath,
                'timings_path' => $timingsPath,
                'voice' => $voice,
                'text_length' => strlen($text)
            ]);

            return [
                'audio_path' => $outputPath,
                'timings' => $timings
            ];

        } catch (\Exception $e) {
            Log::error('TTS generation with timing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate TTS for multiple scenes.
     */
    public function generateScenesTTS(array $scenes, array $settings = []): ?array
    {
        try {
            Log::info('Starting scenes TTS generation');

            $voice = $settings['voice'] ?? 'am_michael';
            $speed = $settings['speed'] ?? 1.05;
            $langCode = $settings['lang_code'] ?? 'a';
            $outputPath = $settings['output_path'] ?? $this->generateTempPath();
            $timingsPath = str_replace('.wav', '_timings.json', $outputPath);

            // Create temporary scenes file
            $scenesPath = $this->generateTempPath('.json');
            file_put_contents($scenesPath, json_encode($scenes));

            $process = new Process([
                $this->pythonPath,
                $this->scriptPath,
                'tts-scenes',
                $scenesPath,
                '--voice', $voice,
                '--speed', (string)$speed,
                '--lang-code', $langCode,
                '--output', $outputPath,
                '--timings', $timingsPath
            ]);

            $process->setTimeout(300); // 5 minutes
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // Read timings file
            $timings = null;
            if (file_exists($timingsPath)) {
                $timingsJson = file_get_contents($timingsPath);
                $timings = json_decode($timingsJson, true);
            }

            // Clean up temporary scenes file
            if (file_exists($scenesPath)) {
                unlink($scenesPath);
            }

            Log::info('Scenes TTS generation completed', [
                'output_path' => $outputPath,
                'timings_path' => $timingsPath,
                'scenes_count' => count($scenes),
                'voice' => $voice
            ]);

            return [
                'audio_path' => $outputPath,
                'timings' => $timings
            ];

        } catch (\Exception $e) {
            Log::error('Scenes TTS generation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get available voices for TTS.
     */
    public function getAvailableVoices(): array
    {
        return [
            'am_michael' => [
                'name' => 'Michael (Male, US)',
                'language' => 'en-US',
                'gender' => 'male',
                'description' => 'Natural male voice with American accent'
            ],
            'af_sarah' => [
                'name' => 'Sarah (Female, US)',
                'language' => 'en-US',
                'gender' => 'female',
                'description' => 'Natural female voice with American accent'
            ],
            'bm_george' => [
                'name' => 'George (Male, UK)',
                'language' => 'en-GB',
                'gender' => 'male',
                'description' => 'Natural male voice with British accent'
            ],
            'bf_emma' => [
                'name' => 'Emma (Female, UK)',
                'language' => 'en-GB',
                'gender' => 'female',
                'description' => 'Natural female voice with British accent'
            ]
        ];
    }

    /**
     * Validate TTS settings.
     */
    public function validateSettings(array $settings): array
    {
        $errors = [];
        $availableVoices = array_keys($this->getAvailableVoices());

        if (isset($settings['voice']) && !in_array($settings['voice'], $availableVoices)) {
            $errors[] = "Invalid voice. Available voices: " . implode(', ', $availableVoices);
        }

        if (isset($settings['speed']) && ($settings['speed'] < 0.5 || $settings['speed'] > 2.0)) {
            $errors[] = "Speed must be between 0.5 and 2.0";
        }

        if (isset($settings['lang_code']) && !in_array($settings['lang_code'], ['a', 'b'])) {
            $errors[] = "Language code must be 'a' (US) or 'b' (UK)";
        }

        return $errors;
    }

    /**
     * Generate temporary file path.
     */
    private function generateTempPath(string $extension = '.wav'): string
    {
        $tempDir = sys_get_temp_dir();
        $fileName = 'tts_' . uniqid() . $extension;
        return $tempDir . '/' . $fileName;
    }

    /**
     * Clean up temporary files.
     */
    public function cleanup(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Also clean up related files
        $timingsPath = str_replace('.wav', '_timings.json', $filePath);
        if (file_exists($timingsPath)) {
            unlink($timingsPath);
        }
    }
}
