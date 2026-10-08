<?php

namespace Modules\Mcp\Services;

use App\Services\PythonAIService;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Mcp\Models\McpUpload;
use Modules\Project\Models\Project;
use Symfony\Component\Process\Process;

/**
 * Presenter mode: the user's own talking-head recording drives the video.
 *
 *   upload (chunked, McpUploadController) → prepare():
 *     probe        ≤ 15 minutes, has video and audio
 *     normalize    constant-frame-rate H.264, no audio, fast seeking (short
 *                  GOP) — what the renderer's frame-exact video reader likes
 *     audio        mono 48 kHz WAV — the soundtrack of the whole video
 *     transcribe   self-hosted Whisper with word timestamps (free)
 *
 * The model then reads the transcript and lays scenes over windows of it.
 */
final class PresenterService
{
    public static function dir(Project $project): string
    {
        return "projects/{$project->id}/explainer/presenter";
    }

    /** Run the whole preparation for an uploaded recording (queued job). */
    public static function prepare(Project $project, McpUpload $upload): void
    {
        $disk = Storage::disk('public');
        $source = (string) $upload->path;
        $sourceAbs = Storage::disk('local')->path($source);

        self::setState($project, ['status' => 'processing', 'step' => 'checking the recording']);

        try {
            $info = self::probe($sourceAbs);
            $max = (int) config('mcp.max_video_seconds', 900);
            if ($info['duration'] <= 1) {
                throw new \RuntimeException('That file has no readable video.');
            }
            if ($info['duration'] > $max + 1) {
                throw new \RuntimeException(sprintf('The recording is %.1f minutes long; the limit is %d minutes.', $info['duration'] / 60, $max / 60));
            }
            if (!$info['has_audio']) {
                throw new \RuntimeException('The recording has no audio track — presenter videos use the voice in the recording.');
            }

            $dir = self::dir($project);
            $disk->makeDirectory($dir);
            $video = "{$dir}/presenter.mp4";
            $audio = "{$dir}/presenter.wav";

            self::setState($project, ['status' => 'processing', 'step' => 'preparing the video']);
            $fps = $info['fps'] >= 45 ? 60 : 30;
            // Fit inside 1920 on the long side, even dimensions, CFR, a
            // keyframe every second so the renderer seeks fast anywhere.
            $scale = $info['width'] >= $info['height']
                ? "scale='min(1920,iw)':-2"
                : "scale=-2:'min(1920,ih)'";
            self::run([
                'ffmpeg', '-y', '-v', 'error', '-i', $sourceAbs,
                '-map', '0:v:0', '-vf', "{$scale},fps={$fps},format=yuv420p",
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '19',
                '-g', (string) $fps, '-keyint_min', (string) $fps, '-sc_threshold', '0',
                '-an', '-movflags', '+faststart', $disk->path($video),
            ], 3600);

            self::setState($project, ['status' => 'processing', 'step' => 'extracting the voice']);
            self::run([
                'ffmpeg', '-y', '-v', 'error', '-i', $sourceAbs,
                '-map', '0:a:0', '-ac', '1', '-ar', '48000', '-c:a', 'pcm_s16le', $disk->path($audio),
            ], 1200);

            self::setState($project, ['status' => 'processing', 'step' => 'transcribing (word timings)']);
            $result = (new PythonAIService())->transcribe($disk->path($audio), (string) ($project->settings['mcp']['language'] ?? 'en'), true);
            if (!($result['success'] ?? false)) {
                throw new \RuntimeException('Transcription failed: ' . mb_substr((string) ($result['error'] ?? 'unknown'), 0, 200));
            }
            $segments = [];
            $words = [];
            foreach ((array) ($result['segments'] ?? []) as $seg) {
                $segments[] = [
                    'start' => round((float) ($seg['start'] ?? 0), 3),
                    'end' => round((float) ($seg['end'] ?? 0), 3),
                    'text' => trim((string) ($seg['text'] ?? '')),
                ];
                foreach ((array) ($seg['words'] ?? []) as $w) {
                    $token = trim((string) ($w['word'] ?? ''));
                    if ($token === '') {
                        continue;
                    }
                    $words[] = [
                        'word' => $token,
                        'start' => round((float) ($w['start'] ?? 0), 3),
                        'end' => round((float) ($w['end'] ?? 0), 3),
                    ];
                }
            }
            $transcript = "{$dir}/transcript.json";
            $disk->put($transcript, json_encode(['segments' => $segments, 'words' => $words], JSON_UNESCAPED_UNICODE));

            self::setState($project, ['status' => 'processing', 'step' => 'preparing frames for the renderer']);
            $frames = self::extractFrames($video);

            $out = self::probe($disk->path($video));
            self::setState($project, [
                'frames' => $frames,
                'status' => 'ready',
                'video_path' => $video,
                'audio_path' => $audio,
                'transcript_path' => $transcript,
                'duration' => round($info['duration'], 3),
                'width' => $out['width'] ?: $info['width'],
                'height' => $out['height'] ?: $info['height'],
                'fps' => $fps,
                'words' => count($words),
                'segments' => count($segments),
            ], true);
            $upload->update(['status' => 'ready']);

            // The original is no longer needed: the normalized copies are.
            Storage::disk('local')->delete($source);
        } catch (\Throwable $e) {
            Log::warning('PresenterService: prepare failed', ['project_id' => $project->id, 'error' => $e->getMessage()]);
            self::setState($project, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)], true);
            $upload->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /**
     * The recording as a 30 fps JPEG strip the renderer draws frame by frame.
     *
     * Why not <OffthreadVideo>: on a memory-starved host its frame cache
     * evicted frames before use and long renders died with "No frame found at
     * position" (reproduced on the dev laptop at 0.5 GB free, at random
     * points, whatever the cache size). One <Img> per frame is deterministic
     * and needs no decoder at all — the same reason slot videos already
     * render from extracted strips (RemotionRenderService::ensureFrameSequence).
     *
     * @return array{dir: string, count: int, fps: int}|null
     */
    public static function extractFrames(string $videoRel): ?array
    {
        $disk = Storage::disk('public');
        $dirRel = dirname($videoRel) . '/frames30';
        $dirAbs = $disk->path($dirRel);
        if (is_dir($dirAbs)) {
            foreach (glob($dirAbs . '/f_*.jpg') ?: [] as $old) {
                @unlink($old);
            }
        } else {
            @mkdir($dirAbs, 0775, true);
        }
        try {
            self::run([
                'ffmpeg', '-y', '-v', 'error', '-i', $disk->path($videoRel),
                '-vf', 'fps=30', '-q:v', '4', $dirAbs . '/f_%06d.jpg',
            ], 3600);
        } catch (\Throwable $e) {
            Log::warning('PresenterService: frame extraction failed (the renderer falls back to the video)', ['error' => $e->getMessage()]);

            return null;
        }
        $count = count(glob($dirAbs . '/f_*.jpg') ?: []);

        return $count > 0 ? ['dir' => $dirRel, 'count' => $count, 'fps' => 30] : null;
    }

    /** Recordings prepared before the frame strip existed get one on first render. */
    public static function ensureFrames(Project $project): void
    {
        $p = (array) ($project->settings['mcp']['presenter'] ?? []);
        if (($p['status'] ?? null) !== 'ready' || empty($p['video_path'])) {
            return;
        }
        $frames = $p['frames'] ?? null;
        if (is_array($frames) && !empty($frames['count']) && is_dir(Storage::disk('public')->path((string) $frames['dir']))) {
            return;
        }
        $frames = self::extractFrames((string) $p['video_path']);
        self::setState($project, ['frames' => $frames]);
    }

    private static function setState(Project $project, array $state, bool $replace = false): void
    {
        McpVideoService::locked($project, function (Project $p) use ($state, $replace) {
            $s = $p->settings ?? [];
            $s['mcp']['presenter'] = $replace ? $state : array_merge((array) ($s['mcp']['presenter'] ?? []), $state);
            $p->update(['settings' => $s]);
        });
    }

    /** @return array{duration: float, width: int, height: int, fps: float, has_audio: bool} */
    public static function probe(string $abs): array
    {
        $probe = FFProbe::create();
        $duration = (float) $probe->format($abs)->get('duration');
        $width = 0;
        $height = 0;
        $fps = 30.0;
        $hasAudio = false;
        foreach ($probe->streams($abs) as $stream) {
            if ($stream->isVideo() && $width === 0) {
                $width = (int) $stream->get('width');
                $height = (int) $stream->get('height');
                $rate = (string) ($stream->get('avg_frame_rate') ?: $stream->get('r_frame_rate') ?: '30/1');
                if (str_contains($rate, '/')) {
                    [$n, $d] = array_map('floatval', explode('/', $rate, 2));
                    $fps = $d > 0 ? $n / $d : 30.0;
                }
                // Phone recordings carry rotation as metadata; ffmpeg applies
                // it on decode, so report the displayed shape.
                $rotate = (int) ($stream->get('tags')['rotate'] ?? 0);
                if (in_array(abs($rotate), [90, 270], true)) {
                    [$width, $height] = [$height, $width];
                }
            }
            if ($stream->isAudio()) {
                $hasAudio = true;
            }
        }

        return ['duration' => $duration, 'width' => $width, 'height' => $height, 'fps' => $fps, 'has_audio' => $hasAudio];
    }

    private static function run(array $cmd, int $timeout): void
    {
        $process = new Process($cmd);
        $process->setTimeout($timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('ffmpeg failed: ' . mb_substr(trim($process->getErrorOutput()), -300));
        }
    }

    // ------------------------------------------------------------------
    // transcript reads
    // ------------------------------------------------------------------

    /** @return array{segments: array, words: array} */
    public static function transcript(Project $project): array
    {
        $path = (string) ($project->settings['mcp']['presenter']['transcript_path'] ?? '');
        static $cache = [];
        if ($path === '' || !Storage::disk('public')->exists($path)) {
            return ['segments' => [], 'words' => []];
        }
        // Keyed by mtime too: the worker is long-running and a re-uploaded
        // recording writes its transcript to the same path.
        $key = $path . '@' . Storage::disk('public')->lastModified($path);
        if (!isset($cache[$key])) {
            $data = json_decode((string) Storage::disk('public')->get($path), true);
            $cache = [$key => is_array($data) ? $data + ['segments' => [], 'words' => []] : ['segments' => [], 'words' => []]];
        }

        return $cache[$key];
    }

    /** Words spoken inside [start, end), by word midpoint, re-based to `start`. */
    public static function wordsBetween(Project $project, float $start, float $end, bool $rebase = true): array
    {
        $out = [];
        foreach (self::transcript($project)['words'] as $w) {
            $mid = ((float) $w['start'] + (float) $w['end']) / 2;
            if ($mid >= $start && $mid < $end) {
                $out[] = [
                    'word' => (string) $w['word'],
                    'start' => round((float) $w['start'] - ($rebase ? $start : 0), 3),
                    'end' => round((float) $w['end'] - ($rebase ? $start : 0), 3),
                ];
            }
        }

        return $out;
    }

    public static function textBetween(Project $project, float $start, float $end): string
    {
        return trim(implode(' ', array_map(fn ($w) => $w['word'], self::wordsBetween($project, $start, $end))));
    }
}
