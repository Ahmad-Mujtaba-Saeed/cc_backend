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

        $files = $this->libraryFiles();

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
     * A pool of library videos to spread across $count shorts.
     *
     * The library is shuffled first, so a batch of shorts does not always draw
     * the same file in the same order; when the library holds fewer videos
     * than there are shorts the pool cycles (the caller then varies the START
     * OFFSET inside each file, so two shorts on the same video still look
     * different).
     *
     * @return array<int, string> absolute paths, $count entries
     */
    public function getPoolFromLibrary(int $count): array
    {
        $files = $this->libraryFiles();

        if (empty($files)) {
            throw new \Exception(
                'Gameplay library is empty. Please upload gameplay videos to storage/app/public/gameplay/ '
                . 'or choose custom upload mode.'
            );
        }

        shuffle($files);

        $pool = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $pool[] = $files[$i % count($files)];
        }

        Log::info('GameplayVideoService: Built gameplay pool', [
            'requested' => $count,
            'distinct_files' => min($count, count($files)),
            'library_size' => count($files),
        ]);

        return $pool;
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

    /** Every playable file in the library, absolute paths. */
    private function libraryFiles(): array
    {
        if (!is_dir($this->libraryPath)) {
            return [];
        }

        $files = glob($this->libraryPath . '/*.{mp4,mov,webm,avi,mkv}', GLOB_BRACE) ?: [];

        return array_values(array_filter($files, 'is_file'));
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
