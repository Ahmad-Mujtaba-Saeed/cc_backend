<?php

namespace Modules\Mcp\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Project\Models\Project;
use Modules\Project\Support\ShotListValidator;
use Symfony\Component\Process\Process;

/**
 * Small pure-ish helpers shared by the studio's services.
 */
final class McpScenes
{
    public const PRESENTER_LAYOUTS = ['full', 'pip', 'split', 'hidden'];
    public const PIP_CORNERS = ['bottom_right', 'bottom_left', 'top_right', 'top_left'];
    public const PIP_SHAPES = ['rounded', 'circle'];

    /** Spoken text: no control characters, one line, bounded. */
    public static function cleanNarration(string $raw): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_substr(trim($text), 0, (int) config('mcp.max_narration_chars', 1500));
    }

    /**
     * Compile a model-written scene through the render service's guard
     * (remotion-render src/hero/guard.ts): the source is parsed and refused if
     * it reaches outside the sandbox, then compiled to the JS the renderer runs.
     *
     * @return array{ok: bool, js: ?string, errors: string[], warnings: string[]}
     */
    public static function compile(string $code): array
    {
        if (!str_contains($code, 'export default')) {
            return ['ok' => false, 'js' => null, 'errors' => ['The module must `export default function Scene()`.'], 'warnings' => []];
        }
        $url = McpRouting::renderUrl();
        try {
            $r = Http::timeout(40)->post("{$url}/hero/compile", ['code' => $code]);
        } catch (\Throwable $e) {
            Log::warning('MCP compile: render service unreachable', ['error' => $e->getMessage()]);

            return ['ok' => false, 'js' => null, 'errors' => ['The render service is unreachable right now — try again in a minute.'], 'warnings' => []];
        }
        $json = (array) $r->json();

        return [
            'ok' => (bool) ($json['ok'] ?? false) && !empty($json['js']),
            'js' => $json['js'] ?? null,
            'errors' => array_values(array_map('strval', (array) ($json['errors'] ?? (($json['ok'] ?? false) ? [] : ['compile failed'])))),
            'warnings' => array_values(array_map('strval', (array) ($json['warnings'] ?? []))),
        ];
    }

    /**
     * The plain card a custom scene stands on: shown only if its code throws
     * at render time (the sandbox falls back to it instead of a black frame).
     *
     * @return array{0: string, 1: array}
     */
    public static function fallbackCard(?string $title, string $narration): array
    {
        $heading = trim((string) $title);
        if ($heading === '') {
            $words = preg_split('/\s+/u', trim($narration)) ?: [];
            $heading = implode(' ', array_slice($words, 0, 7));
        }
        if ($heading === '') {
            $heading = ' ';
        }

        return ['single_focus', [
            'slot_main' => [
                'content_type' => 'text_block',
                'heading' => mb_substr($heading, 0, 80),
                'bullets' => [],
            ],
        ]];
    }

    /** Length before the voice exists: the validator's own pacing + the hold. */
    public static function estimateSeconds(string $narration, array $slots, float $hold): float
    {
        $base = trim($narration) === ''
            ? 0.0
            : (new ShotListValidator())->paceScene($narration, $slots);
        // The validator caps a card at 14 s; a model-written scene may carry a
        // longer line, and the estimate should say so.
        $words = str_word_count($narration);
        $base = max($base, $words / 2.6 + 0.6);

        return round(max(1.5, $base + $hold), 2);
    }

    public static function orientation(Project $project): string
    {
        return match ($project->aspect_ratio ?? '16:9') {
            '9:16' => 'portrait',
            '1:1' => 'square',
            default => 'landscape',
        };
    }

    /** A shelf name: lowercase slug, what `<Asset name>` and card slots use. */
    public static function mediaName(string $raw): string
    {
        $name = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $raw), '_-'));
        $name = substr($name, 0, 40);
        if ($name === '') {
            throw new \Modules\Mcp\Server\ToolException('`name` must contain letters or digits (e.g. "city_skyline").');
        }

        return $name;
    }

    /** A provider thumbnail, re-encoded as a small JPEG for the model to look at. */
    public static function fetchThumbnail(string $url): ?string
    {
        try {
            $r = Http::timeout(8)->withHeaders(['User-Agent' => 'VreatoStudio/1.0'])->get($url);
            if (!$r->successful() || strlen($r->body()) > 4 * 1024 * 1024) {
                return null;
            }

            return self::toJpeg($r->body(), 360, 72);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Any picture (PNG/JPEG/WebP…) → a JPEG no wider than $maxWidth, for the
     * model to look at. ffmpeg, not GD: the PHP image's GD has no JPEG codec.
     */
    public static function toJpeg(string $binary, int $maxWidth = 960, int $quality = 80): ?string
    {
        $in = tempnam(sys_get_temp_dir(), 'mcpimg');
        $out = $in . '.jpg';
        try {
            file_put_contents($in, $binary);
            // ffmpeg's mjpeg qscale: 2 (best) .. 31; map 0-100 quality onto it.
            $q = (string) max(2, min(31, (int) round(31 - ($quality / 100) * 29)));
            $process = new Process([
                'ffmpeg', '-y', '-v', 'error', '-i', $in,
                '-vf', "scale='min({$maxWidth},iw)':-2", '-frames:v', '1', '-q:v', $q, $out,
            ]);
            $process->setTimeout(30);
            $process->run();
            $jpeg = ($process->isSuccessful() && is_file($out)) ? (string) file_get_contents($out) : '';

            return $jpeg === '' ? null : $jpeg;
        } catch (\Throwable $e) {
            return null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * Make a clip safe for the renderer (constant frame rate, moov first):
     * the same treatment the storyboard gives an upload. Best effort.
     */
    public static function normalizeVideo(string $absPath): void
    {
        $tmp = $absPath . '.norm.mp4';
        $process = new Process([
            'ffmpeg', '-y', '-v', 'error', '-i', $absPath,
            '-vf', "scale='min(1920,iw)':-2,fps=30",
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p',
            '-an', '-movflags', '+faststart', $tmp,
        ]);
        $process->setTimeout(600);
        try {
            $process->run();
            if ($process->isSuccessful() && is_file($tmp) && filesize($tmp) > 1024) {
                @rename($tmp, $absPath);
            } else {
                @unlink($tmp);
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
        }
    }
}
