<?php

namespace Modules\Project\Services;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class VideoTranscriptionService
{
    private PythonAIService $pythonAI;

    public function __construct()
    {
        $this->pythonAI = new PythonAIService();
    }

    /**
     * Transcribe video audio to text using Python AI service.
     * With $wordTimestamps=true each segment carries a `words` array of
     * per-word {word, start, end} timings (for karaoke captions).
     */
    public function transcribe(string $videoPath, string $language = 'en', bool $wordTimestamps = false): array
    {
        try {
            Log::info("[TRANSCRIPTION_SERVICE] Starting transcription");
            Log::info("[TRANSCRIPTION_SERVICE] Video path: {$videoPath}");
            Log::info("[TRANSCRIPTION_SERVICE] Language: {$language}");
            Log::info("[TRANSCRIPTION_SERVICE] Word timestamps: " . ($wordTimestamps ? 'yes' : 'no'));
            Log::info("[TRANSCRIPTION_SERVICE] File exists: " . (file_exists($videoPath) ? 'yes' : 'no'));
            
            if (file_exists($videoPath)) {
                $fileSize = filesize($videoPath);
                Log::info("[TRANSCRIPTION_SERVICE] File size: {$fileSize} bytes");
            }
            
            // Check if Python AI service is available
            Log::info("[TRANSCRIPTION_SERVICE] Checking Python AI service availability");
            $isAvailable = $this->pythonAI->isAvailable();
            Log::info("[TRANSCRIPTION_SERVICE] Python AI service available: " . ($isAvailable ? 'yes' : 'no'));
            
            if (!$isAvailable) {
                Log::error("[TRANSCRIPTION_SERVICE] Python AI service is not available");
                throw new Exception('Python AI service is not available');
            }

            // Call Python AI service
            Log::info("[TRANSCRIPTION_SERVICE] Calling pythonAI->transcribe()");
            $result = $this->pythonAI->transcribe($videoPath, $language, $wordTimestamps);
            
            Log::info("[TRANSCRIPTION_SERVICE] PythonAI returned", [
                'success' => $result['success'] ?? false,
                'error' => $result['error'] ?? null,
                'segments_count' => count($result['segments'] ?? [])
            ]);

            if (!$result['success']) {
                Log::error("[TRANSCRIPTION_SERVICE] Transcription failed: {$result['error']}");
                throw new Exception($result['error']);
            }

            Log::info("[TRANSCRIPTION_SERVICE] Transcription completed successfully");
            return $result;

        } catch (Exception $e) {
            Log::error("[TRANSCRIPTION_SERVICE] Exception: " . $e->getMessage());
            Log::error("[TRANSCRIPTION_SERVICE] Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Extract audio from video for transcription.
     */
    public function extractAudio(string $videoPath, string $audioPath): bool
    {
        try {
            $process = new Process([
                'ffmpeg',
                '-i', $videoPath,
                '-vn', // No video
                '-acodec', 'pcm_s16le', // 16-bit PCM
                '-ar', '16000', // 16kHz sample rate for Whisper
                '-ac', '1', // Mono
                '-y', // Overwrite output
                $audioPath
            ]);

            $process->setTimeout(120);
            $process->run();

            return $process->isSuccessful();

        } catch (\Exception $e) {
            Log::error('Audio extraction failed: ' . $e->getMessage());
            return false;
        }
    }
}
