<?php

namespace Modules\Mcp\Support;

use Symfony\Component\Process\Process;

/**
 * Images the model looks at, sized for what Claude's clients accept.
 *
 * Claude.ai and Claude Desktop cap a whole tool result at ~150,000
 * characters, and Claude Code at 25,000 tokens (claude.com/docs/connectors/
 * building, "Design within the size and timeout limits"). Image content is
 * base64 (4 chars per 3 bytes) and counts against that cap, so several
 * full-size frames are silently dropped by the client: the model gets the
 * text saying "frames below" and no frames.
 *
 * So frames go out as ONE labelled contact sheet (or one larger image when a
 * single moment is asked for), JPEG-encoded down until it fits a budget.
 *
 * GD here reads/writes PNG only (no JPEG/WebP codec in this PHP build), so:
 * ffmpeg scales every input to a PNG tile → GD lays out the grid and draws
 * the labels → ffmpeg encodes the JPEG at the largest size/quality that fits.
 */
final class McpImages
{
    /** Whole tool result ceiling we aim under (the client's is ~150k). */
    public const MAX_RESULT_CHARS = 140000;

    /** Base64 characters the images of one result may use. */
    public const IMAGE_BUDGET_CHARS = 110000;

    /**
     * Claude Code caps an MCP result at 25,000 TOKENS (MAX_MCP_OUTPUT_TOKENS)
     * and is reported to count image base64 as text, so it gets a smaller
     * image and result budget than Claude.ai/Desktop's ~150k characters.
     */
    private const CLAUDE_CODE_RESULT_CHARS = 85000;
    private const CLAUDE_CODE_IMAGE_CHARS = 48000;

    private static int $imageBudget = self::IMAGE_BUDGET_CHARS;
    private static int $resultBudget = self::MAX_RESULT_CHARS;

    /** Size this request's images and result for the calling client (per request). */
    public static function forClient(?string $clientName): void
    {
        $code = $clientName !== null && preg_match('/claude[-_ ]?code/i', $clientName);
        self::$imageBudget = $code ? self::CLAUDE_CODE_IMAGE_CHARS : self::IMAGE_BUDGET_CHARS;
        self::$resultBudget = $code ? self::CLAUDE_CODE_RESULT_CHARS : self::MAX_RESULT_CHARS;
    }

    public static function imageBudget(): int
    {
        return self::$imageBudget;
    }

    public static function resultBudget(): int
    {
        return self::$resultBudget;
    }

    /**
     * Lay frames out as one labelled grid and encode it under the budget.
     *
     * @param  array<int, array{path: string, label: string}>  $items  image files (any format ffmpeg reads)
     * @return string|null JPEG bytes
     */
    public static function sheet(array $items, ?int $budgetChars = null, int $maxWidth = 1280): ?string
    {
        $items = array_values(array_filter($items, fn ($i) => is_file((string) ($i['path'] ?? ''))));
        $n = count($items);
        if ($n === 0) {
            return null;
        }

        // Tile shape from the first frame; columns so the sheet reads roughly 16:9.
        [$fw, $fh] = self::dimensions($items[0]['path']) ?? [16, 9];
        $portrait = $fh > $fw;
        $cols = $n === 1 ? 1 : ($portrait ? min($n, 4) : ($n <= 2 ? $n : ($n <= 4 ? 2 : 3)));
        $rows = (int) ceil($n / $cols);
        $gap = $n === 1 ? 0 : 8;
        // Labels sit in a strip ABOVE each frame, never on it: a label box
        // over the picture reads as text overlapping the design.
        $band = 24;
        $tileW = (int) floor(($maxWidth - $gap * ($cols - 1)) / $cols);
        $tileH = (int) max(32, round($tileW * $fh / max(1, $fw)));
        $cellH = $tileH + $band;
        $sheetW = $cols * $tileW + $gap * ($cols - 1);
        $sheetH = $rows * $cellH + $gap * ($rows - 1);

        $tmp = [];
        try {
            $canvas = imagecreatetruecolor($sheetW, $sheetH);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 24, 24, 27));
            $white = imagecolorallocate($canvas, 235, 235, 240);

            foreach ($items as $i => $item) {
                $png = self::tile($item['path'], $tileW, $tileH);
                if ($png === null) {
                    continue;
                }
                $tmp[] = $png;
                $img = @imagecreatefrompng($png);
                if (!$img) {
                    continue;
                }
                $x = ($i % $cols) * ($tileW + $gap);
                $y = intdiv($i, $cols) * ($cellH + $gap);
                imagecopy($canvas, $img, $x, $y + $band, 0, 0, imagesx($img), imagesy($img));
                imagedestroy($img);

                $label = (string) ($item['label'] ?? '');
                if ($label !== '') {
                    // GD's built-in font: no FreeType needed in the image.
                    imagestring($canvas, 5, $x + 4, $y + 4, $label, $white);
                }
            }

            $sheetPng = self::tempPath('png');
            $tmp[] = $sheetPng;
            imagepng($canvas, $sheetPng, 1);
            imagedestroy($canvas);

            return self::encodeWithin($sheetPng, $budgetChars ?? self::$imageBudget, $sheetW);
        } finally {
            foreach ($tmp as $f) {
                @unlink($f);
            }
        }
    }

    /**
     * Encode one image as the largest/sharpest JPEG whose base64 fits the
     * budget: quality first, then width.
     */
    public static function encodeWithin(string $path, int $budgetChars, int $maxWidth): ?string
    {
        $maxBytes = (int) floor($budgetChars * 3 / 4);
        $width = $maxWidth;
        while ($width >= 320) {
            foreach ([4, 6, 9, 13] as $q) { // ffmpeg mjpeg qscale: lower = better
                $jpeg = self::jpeg($path, $width, $q);
                if ($jpeg !== null && strlen($jpeg) <= $maxBytes) {
                    return $jpeg;
                }
            }
            $width = (int) round($width * 0.8);
        }

        return null;
    }

    /** @return array{0: int, 1: int}|null */
    private static function dimensions(string $path): ?array
    {
        $size = @getimagesize($path);

        return $size ? [(int) $size[0], (int) $size[1]] : null;
    }

    /** One input scaled into a WxH PNG tile, letterboxed — so GD can read it. */
    private static function tile(string $src, int $w, int $h): ?string
    {
        $out = self::tempPath('png');
        $p = new Process([
            'ffmpeg', '-y', '-v', 'error', '-i', $src,
            '-vf', "scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:color=0x18181B",
            '-frames:v', '1', $out,
        ]);
        $p->setTimeout(30);
        $p->run();

        return $p->isSuccessful() && is_file($out) ? $out : null;
    }

    private static function jpeg(string $src, int $maxWidth, int $q): ?string
    {
        $out = self::tempPath('jpg');
        try {
            $p = new Process([
                'ffmpeg', '-y', '-v', 'error', '-i', $src,
                '-vf', "scale='min({$maxWidth},iw)':-2", '-frames:v', '1', '-q:v', (string) $q, $out,
            ]);
            $p->setTimeout(30);
            $p->run();
            $bytes = $p->isSuccessful() && is_file($out) ? (string) file_get_contents($out) : '';

            return $bytes === '' ? null : $bytes;
        } finally {
            @unlink($out);
        }
    }

    private static function tempPath(string $ext): string
    {
        return sys_get_temp_dir() . '/mcpimg_' . bin2hex(random_bytes(8)) . '.' . $ext;
    }
}
