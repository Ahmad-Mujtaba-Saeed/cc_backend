<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;

/**
 * GameplayVideoService
 * 
 * Manages gameplay video selection from either:
 * 1. Backend library (random selection)
 * 2. Custom upload (user-provided)
 */
class GameplayVideoService
{
    private string $libraryPath;

    public function __construct()
    {
        $this->libraryPath = storage_path('app/public/gameplay');
    }

    /**
     * Get a random gameplay video from the backend library
     * 
     * Throws if library is empty or doesn't exist
     */
    public function getRandomFromLibrary(): string
    {
        if (!$this->libraryExists()) {
            throw new \Exception(
                'Gameplay library is empty. Please upload gameplay videos to storage/app/public/gameplay/ '
                . 'or choose custom upload mode.'
            );
        }

        $files = glob($this->libraryPath . '/*.{mp4,mov,webm,avi,mkv}', GLOB_BRACE);
        $files = array_values(array_filter($files, 'is_file'));

        if (empty($files)) {
            throw new \Exception('No gameplay videos found in library');
        }

        $selectedFile = $files[array_rand($files)];

        Log::info('GameplayVideoService: Selected random library video', [
            'file' => basename($selectedFile),
            'total_available' => count($files)
        ]);

        return $selectedFile;
    }

    /**
     * Get list of available gameplay videos in library (for frontend preview)
     */
    public function getLibraryList(): array
    {
        if (!is_dir($this->libraryPath)) {
            return [];
        }

        $files = glob($this->libraryPath . '/*.{mp4,mov,webm,avi,mkv}', GLOB_BRACE);
        $files = array_values(array_filter($files, 'is_file'));

        return array_map(fn($f) => [
            'name' => basename($f),
            'path' => $f,
            'size' => filesize($f)
        ], $files);
    }

    /**
     * Check if library exists and has at least one video
     */
    public function libraryExists(): bool
    {
        if (!is_dir($this->libraryPath)) {
            return false;
        }

        $files = glob($this->libraryPath . '/*.{mp4,mov,webm,avi,mkv}', GLOB_BRACE);
        return count($files ?? []) > 0;
    }

    /**
     * Ensure library directory exists and is readable
     */
    public function initializeLibrary(): bool
    {
        if (!is_dir($this->libraryPath)) {
            return @mkdir($this->libraryPath, 0755, true);
        }
        return is_readable($this->libraryPath);
    }
}
