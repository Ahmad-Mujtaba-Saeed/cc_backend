<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * FlowVisualService — the picture for ONE part of a flow explainer card,
 * drawn per script by the image model (`fal-ai/fast-lightning-sdxl`).
 *
 * ## Why an image model at all
 *
 * The parts of this card must look like COMPONENTS built for this script — a
 * server, a valve, a heart chamber, a parcel — and neither a fixed component
 * library (which cannot know the subject) nor markup written by a text model
 * (which draws badly) got there. So the subject is drawn.
 *
 * ## Why it does not look like a photograph in a box
 *
 * Three deliberate steps:
 *
 * 1. **The prompt asks for flat black line art on plain white**, one object,
 *    centred, no text — the model cannot letter, so nothing here depends on it
 *    (the label, the state and the caption are typeset by the renderer).
 * 2. **The white is keyed out into an alpha STENCIL** (see {@see stencil()}):
 *    luminance becomes transparency, so what is left is the drawing's ink with
 *    soft edges, cropped to its bounding box. No plate, no rectangle, no
 *    off-white halo on the card's dark field.
 * 3. **The renderer paints that stencil in the video's own colours** (it is
 *    used as a CSS mask), so every visual in every video is on-palette and
 *    reads as drawn for this deck rather than pasted into it.
 *
 * Failure is always non-fatal: no token, a refusal, a timeout or a broken
 * download leaves the part without a picture, and the card still plays with
 * its label, state and links.
 */
class FlowVisualService
{
    /** The user's choice: cheap, fast, and good enough for flat line art. */
    private const MODEL = 'fal-ai/fast-lightning-sdxl';

    /** Where stencils live on the public disk. */
    public const DIR = 'explainer/flow';

    /** Anything this faint is the page, not the drawing (0..255 of ink). */
    private const PAPER_INK = 34;

    /** This much ink is a full-strength line. */
    private const LINE_INK = 150;

    /**
     * Below this the mark is a wash, not a stroke, and is dropped outright:
     * these models like to put their object on a faint panel, and a panel that
     * survives keying arrives as a plate behind the part — the one thing this
     * whole class exists to avoid.
     */
    private const WASH = 0.28;

    /**
     * The page must actually BE a page. Measured over the drawings this model
     * returns, a usable one lands at 208-234; the one that came back as a
     * smudge was white-on-black (31) — keying that yields noise, never a part.
     */
    private const PAPER_MIN = 140;

    /** More stroke crossings than this per row is a texture, not a diagram. */
    private const MAX_CROSSINGS = 28;

    /** A drawing that fills this much of the page in both axes has no margin. */
    private const BLEED = 0.97;

    /**
     * A ceiling on what one video can spend here. Flow cards are capped at
     * 3/10ths of the runtime and six parts each, and repeated subjects are
     * free, so this is a runaway guard rather than a budget.
     */
    private const MAX_PER_VIDEO = 24;

    private ?string $token;
    private string $base;

    public function __construct()
    {
        $this->token = config('services.fal_ai.auth_token') ?: env('FAL_AI_AUTH_TOKEN');
        $this->base = rtrim((string) config('services.fal_ai.url', 'https://queue.fal.run'), '/');
    }

    public function available(): bool
    {
        return !empty($this->token);
    }

    /**
     * The full prompt for a part's subject. Public so a caller can hash it:
     * an unchanged subject must never be redrawn (and re-billed).
     */
    public static function prompt(string $subject): string
    {
        return 'simple flat line-art icon of ' . trim($subject)
            . '. Minimal monoline illustration, thick even black strokes, very few lines, '
            . 'simplified geometric shapes, no interior detail, no texture, no hatching, '
            . 'flat straight-on view, one single object centred with a wide margin, '
            . 'the object alone on a pure white background, nothing behind it, '
            . 'black and white only, '
            . 'no text, no letters, no numbers, no labels, no watermark, '
            . 'not a photo, not a 3d render, no shading, no shadow, no gradient, no scenery, '
            . 'no badge, no sticker, no card, no frame, no box or circle drawn around the object.';
    }

    /**
     * Draw one subject and store its stencil.
     *
     * The file is keyed on the SUBJECT, not on the part: three servers in one
     * diagram asked to draw the same thing get the SAME drawing, which is what
     * makes them read as three of one component instead of three pictures.
     * It also means a subject is only ever paid for once.
     *
     * @param  string $subject What to draw, in the staging pass's words.
     * @param  string $key     Only a hint for the file name (scene + part id).
     * @param  int    $seed    Fixed per subject, so a redraw is a redraw.
     * @return string|null     The stencil's path on the public disk.
     */
    public function draw(string $subject, string $key, int $seed = 0, bool $redraw = false): ?string
    {
        $subject = trim($subject);
        if (!$this->available() || $subject === '') {
            return null;
        }

        $prompt = self::prompt($subject);
        // No seed given means "draw this subject": the seed comes from the
        // subject itself, so the same thing is the same drawing everywhere
        // and only an explicit seed (a redraw) changes it.
        $seed = $seed > 0 ? $seed : (int) (crc32($prompt) % 1_000_000_000) + 1;
        $hash = substr(sha1($prompt . '|' . $seed), 0, 12);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', $key), '-');
        $path = self::DIR . '/' . ($slug !== '' ? mb_substr($slug, 0, 40) . '-' : '') . $hash . '.png';
        $rawPath = self::DIR . '/raw/' . $hash . '.png';
        $disk = Storage::disk('public');

        if (!$redraw && $disk->exists($path)) {
            return $path;
        }
        // (A stored image_path whose file has gone is redrawn: see drawAll.)

        // The model's own png is kept: the keying below is a recipe we tune,
        // and re-keying a drawing must never re-bill for it.
        $raw = !$redraw && $disk->exists($rawPath) ? $disk->get($rawPath) : null;
        if ($raw === null) {
            $raw = $this->generate($prompt, $seed);
            if ($raw === null) {
                return null;
            }
            // Paid for: record it even if it cannot be kept.
            CostTracker::recordImages(1, 'flow_visual');
            if (!$disk->put($rawPath, $raw)) {
                Log::warning('FlowVisualService: could not save the raw drawing (storage not writable?)', ['path' => $rawPath]);
            }
        }

        $stencil = $this->stencil($raw);
        if ($stencil === null) {
            // The model drew something that will not key (a blank page, or a
            // texture). One more roll of the dice is worth a tenth of a cent;
            // after that the card plays without a picture.
            $retry = $this->generate($prompt, $seed + 1);
            $stencil = $retry === null ? null : $this->stencil($retry);
            if ($stencil === null) {
                return null;
            }
            CostTracker::recordImages(1, 'flow_visual');
            $disk->put($rawPath, $retry);
        }

        // Only a path that really holds the picture is handed back: a failed
        // save used to return the path anyway, and the storyboard then pointed
        // at a drawing that 404'd forever (project 211, scene 15).
        if (!$disk->put($path, $stencil) || !$disk->exists($path)) {
            Log::error('FlowVisualService: could not save the drawing (storage not writable?)', ['path' => $path]);

            return null;
        }

        return $path;
    }

    /**
     * Draw every part of every flow card in a storyboard that is still
     * waiting for its picture, and return the storyboard with `image_path`
     * filled in.
     *
     * Runs after staging, on the same convergence point as the motifs: the
     * staging pass names the subjects, this pays for them. Scene keys are
     * preserved (a revision hands over drafts keyed by scene id), a part that
     * cannot be drawn is simply left without a picture, and a part that
     * already has one is never redrawn — which is what makes re-analysis of an
     * existing storyboard free.
     */
    public function drawAll(array $parsed, string $topic = ''): array
    {
        $scenes = (array) ($parsed['scenes'] ?? []);
        if ($scenes === [] || !$this->available()) {
            return $parsed;
        }

        $drawn = 0;
        foreach ($scenes as $si => $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $slot = $scene['slots']['slot_cinematic'] ?? null;
            if (!is_array($slot) || !is_array($slot['elements'] ?? null)) {
                continue;
            }
            foreach ($slot['elements'] as $i => $el) {
                if (!is_array($el) || ($el['kind'] ?? '') !== 'visual') {
                    continue;
                }
                $subject = trim((string) ($el['prompt'] ?? ''));
                // A part that already has its picture is never redrawn — but
                // only if the picture is really there; a path to a missing
                // file (a save that failed) is drawn again.
                if ($subject === '' || (!empty($el['image_path'])
                    && Storage::disk('public')->exists((string) $el['image_path']))) {
                    continue;
                }
                if ($drawn >= self::MAX_PER_VIDEO) {
                    break 2;
                }

                try {
                    $path = $this->draw($subject, (string) ($scene['scene_id'] ?? $si) . '-' . (string) ($el['id'] ?? $i));
                } catch (\Throwable $e) {
                    Log::info('FlowVisualService: part not drawn', ['error' => $e->getMessage()]);
                    $path = null;
                }
                if ($path !== null) {
                    $scenes[$si]['slots']['slot_cinematic']['elements'][$i]['image_path'] = $path;
                    $drawn++;
                }
            }
        }
        $parsed['scenes'] = $scenes;

        return $parsed;
    }

    /** The raw PNG bytes from the image model, or null. */
    private function generate(string $prompt, int $seed): ?string
    {
        try {
            // Every fal hop gets a 20s connect timeout and retries: Laravel's
            // default 10s connect lost two drawings on 2026-09-23 (the image
            // pipeline got the same treatment earlier).
            $submit = Http::withHeaders(['Authorization' => 'Key ' . $this->token])
                ->connectTimeout(20)
                ->retry(2, 1000, throw: false)
                ->timeout(60)
                ->post($this->base . '/' . self::MODEL, [
                    'prompt' => $prompt,
                    'negative_prompt' => 'text, letters, numbers, words, watermark, signature, photograph, photorealistic, '
                        . '3d render, cgi, glossy, metallic, reflection, shading, shadow, gradient, vignette, '
                        . 'sketch, hatching, crosshatch, stippling, noisy lines, thin lines, double lines, '
                        . 'duplicate, multiple objects, cluttered, intricate detail, ornate, '
                        . 'grey background, textured background, busy background, '
                        . 'badge, sticker, rounded square background, card, frame, border, '
                        . 'box around object, circle around object, enclosing shape',
                    'image_size' => 'square_hd',
                    'num_images' => 1,
                    'num_inference_steps' => '4',
                    'format' => 'png',
                    'enable_safety_checker' => true,
                    'seed' => $seed,
                ]);
            if (!$submit->successful()) {
                Log::info('FlowVisualService: submit failed', ['status' => $submit->status(), 'body' => mb_substr($submit->body(), 0, 200)]);
                return null;
            }

            $statusUrl = (string) $submit->json('status_url');
            $responseUrl = (string) $submit->json('response_url');
            if ($statusUrl === '' || $responseUrl === '') {
                return null;
            }

            // Lightning models answer in a second or two; give it thirty.
            $done = false;
            for ($i = 0; $i < 30; $i++) {
                usleep(1_000_000);
                $status = Http::withHeaders(['Authorization' => 'Key ' . $this->token])
                    ->connectTimeout(20)->retry(3, 1000, throw: false)->timeout(20)->get($statusUrl);
                $state = (string) $status->json('status');
                if ($state === 'COMPLETED') {
                    $done = true;
                    break;
                }
                if (!in_array($state, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
                    Log::info('FlowVisualService: unexpected status', ['status' => $state]);
                    return null;
                }
            }
            if (!$done) {
                return null;
            }

            $result = Http::withHeaders(['Authorization' => 'Key ' . $this->token])
                ->connectTimeout(20)->retry(3, 1000, throw: false)->timeout(30)->get($responseUrl);
            $url = (string) $result->json('images.0.url');
            if ($url === '') {
                return null;
            }
            $image = Http::connectTimeout(20)->retry(3, 1000, throw: false)->timeout(60)->get($url);

            return $image->successful() ? $image->body() : null;
        } catch (\Throwable $e) {
            Log::info('FlowVisualService: draw failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Erase a rectangular FRAME drawn around the object.
     *
     * A frame shows up as a bounding box whose edge rows and columns are
     * almost solid ink. Real drawings very rarely have that (an object touches
     * its own bounding box at a few points, not along a whole side), so the
     * test is: at least three of the four sides more than 70% inked. When one
     * is found, each mostly-inked edge is wiped and the box walks inward until
     * a side is ordinary again — which also takes double-ruled frames and the
     * grey wash some of them sit on.
     *
     * If wiping leaves almost nothing, the "frame" WAS the drawing (a window,
     * a screen, a box) and the original box is restored untouched.
     */
    private static function stripFrame(\GdImage $im, int &$minX, int &$maxX, int &$minY, int &$maxY): void
    {
        // Any VISIBLE ink counts here, not just full-strength strokes: the
        // frames these models draw are often a thin grey rule, and a faint
        // frame is still a plate once the renderer paints it.
        $solid = static fn (int $x, int $y): bool => ((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 96;
        $rowInk = static function (int $y, int $x0, int $x1) use ($solid): float {
            $n = 0;
            for ($x = $x0; $x <= $x1; $x++) {
                $n += $solid($x, $y) ? 1 : 0;
            }

            return $n / max(1, $x1 - $x0 + 1);
        };
        $colInk = static function (int $x, int $y0, int $y1) use ($solid): float {
            $n = 0;
            for ($y = $y0; $y <= $y1; $y++) {
                $n += $solid($x, $y) ? 1 : 0;
            }

            return $n / max(1, $y1 - $y0 + 1);
        };

        $w = $maxX - $minX + 1;
        $h = $maxY - $minY + 1;
        // A frame sits at the edge, but not exactly on it: it is drawn a few
        // pixels in, and its outer edge fades over a pixel or two. So each
        // side is read as a BAND, and the frame is wherever the last solid
        // line in that band is.
        $bandY = max(3, (int) round($h * 0.08));
        $bandX = max(3, (int) round($w * 0.08));

        $edge = static function (callable $ink, int $from, int $step, int $band, int $a, int $b): array {
            $strongest = 0.0;
            $last = $from;
            for ($i = 0; $i <= $band; $i++) {
                $v = $ink($from + $i * $step, $a, $b);
                $strongest = max($strongest, $v);
                if ($v > 0.5) {
                    $last = $from + $i * $step;
                }
            }

            return [$strongest, $last + $step];
        };

        [$topInk, $top] = $edge($rowInk, $minY, 1, $bandY, $minX, $maxX);
        [$bottomInk, $bottom] = $edge($rowInk, $maxY, -1, $bandY, $minX, $maxX);
        [$leftInk, $left] = $edge($colInk, $minX, 1, $bandX, $minY, $maxY);
        [$rightInk, $right] = $edge($colInk, $maxX, -1, $bandX, $minY, $maxY);

        // A frame has a GAP behind it. A solid object does not, and that is
        // what stops this from shaving the edge off a filled shape.
        $framed = static fn (float $ink, float $behind) => $ink > 0.7 && $behind < 0.35;
        $topFramed = $framed($topInk, $rowInk(min($top, $maxY), $minX, $maxX));
        $bottomFramed = $framed($bottomInk, $rowInk(max($bottom, $minY), $minX, $maxX));
        $leftFramed = $framed($leftInk, $colInk(min($left, $maxX), $minY, $maxY));
        $rightFramed = $framed($rightInk, $colInk(max($right, $minX), $minY, $maxY));
        $sides = ($topFramed ? 1 : 0) + ($bottomFramed ? 1 : 0) + ($leftFramed ? 1 : 0) + ($rightFramed ? 1 : 0);
        if ($sides < 3 || $right - $left < 8 || $bottom - $top < 8) {
            return;
        }

        // Once three sides are a frame, the fourth is one too as soon as there
        // is a rule in its band — it just failed the gap test because the
        // object stands close behind it. Left alone it renders as a stray line
        // beside the part.
        $topFramed = $topFramed || $topInk > 0.5;
        $bottomFramed = $bottomFramed || $bottomInk > 0.5;
        $leftFramed = $leftFramed || $leftInk > 0.5;
        $rightFramed = $rightFramed || $rightInk > 0.5;

        // Wipe the bands that ARE frame. Only those: a side that did not
        // qualify may be the object itself touching the edge, and a frame's
        // own band is a rule plus the white gap behind it, so nothing of the
        // drawing lives there. Corners of a fourth, softer side go with them,
        // which is what stops an L of leftover rules.
        $clear = imagecolorallocatealpha($im, 255, 255, 255, 127);
        if ($topFramed) {
            for ($y = $minY; $y < $top; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    imagesetpixel($im, $x, $y, $clear);
                }
            }
        }
        if ($bottomFramed) {
            for ($y = $bottom + 1; $y <= $maxY; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    imagesetpixel($im, $x, $y, $clear);
                }
            }
        }
        if ($leftFramed) {
            for ($x = $minX; $x < $left; $x++) {
                for ($y = $minY; $y <= $maxY; $y++) {
                    imagesetpixel($im, $x, $y, $clear);
                }
            }
        }
        if ($rightFramed) {
            for ($x = $right + 1; $x <= $maxX; $x++) {
                for ($y = $minY; $y <= $maxY; $y++) {
                    imagesetpixel($im, $x, $y, $clear);
                }
            }
        }

        // What is left of the object inside the frame. Nothing is erased: the
        // crop that follows is what removes the frame, so a wrong guess here
        // costs a few pixels of margin and never the drawing.
        $found = false;
        $ix0 = $left;
        $ix1 = $right;
        $iy0 = $top;
        $iy1 = $bottom;
        for ($y = $top; $y <= $bottom; $y++) {
            for ($x = $left; $x <= $right; $x++) {
                if (!$solid($x, $y)) {
                    continue;
                }
                if (!$found) {
                    [$ix0, $ix1, $iy0, $iy1] = [$x, $x, $y, $y];
                    $found = true;
                    continue;
                }
                $ix0 = min($ix0, $x);
                $ix1 = max($ix1, $x);
                $iy0 = min($iy0, $y);
                $iy1 = max($iy1, $y);
            }
        }

        // Too little left means the "frame" WAS the drawing — a window, a
        // screen, a filled block — so the original box stands.
        $inner = $found ? ($ix1 - $ix0 + 1) * ($iy1 - $iy0 + 1) : 0;
        if ($inner < 0.12 * $w * $h) {
            return;
        }
        [$minX, $maxX, $minY, $maxY] = [$ix0, $ix1, $iy0, $iy1];
    }

    /**
     * White out, ink in: luminance becomes alpha, the result is cropped to the
     * drawing and returned as PNG bytes.
     *
     * This is what makes the picture a COMPONENT rather than a photo — there
     * is no plate behind it, and the renderer can paint what is left in the
     * video's own ink (the stencil is used as a mask).
     */
    private function stencil(string $bytes): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        // Half size first: a megapixel of per-pixel PHP is seconds we do not
        // need — the stencil is drawn at a few hundred px on screen.
        $w = (int) max(1, imagesx($src) / 2);
        $h = (int) max(1, imagesy($src) / 2);
        $small = imagescale($src, $w, $h);
        imagedestroy($src);
        if ($small === false) {
            return null;
        }

        $lum = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $lum[$y][$x] = (int) (0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255));
            }
        }
        imagedestroy($small);

        // The paper is whatever the BORDER is, not 255: these models like to
        // lay a faint wash or a vignette over the page, and measuring it here
        // is what stops that wash arriving as a plate behind the part.
        $edge = [];
        for ($x = 0; $x < $w; $x++) {
            $edge[] = $lum[0][$x];
            $edge[] = $lum[$h - 1][$x];
        }
        for ($y = 0; $y < $h; $y++) {
            $edge[] = $lum[$y][0];
            $edge[] = $lum[$y][$w - 1];
        }
        sort($edge);
        $paper = max(1, $edge[(int) (count($edge) * 0.35)]);
        if ($paper < self::PAPER_MIN) {
            // A dark page is usually the SAME drawing inverted — the model
            // answers a dark subject (a vault, a night sky, a black box) with
            // white lines on black about one time in five. Flip it rather than
            // throwing away a drawing that is already paid for.
            //
            // The WHOLE image has to be dark, not just its border: a light
            // drawing inside a heavy frame has a dark border too, and
            // inverting that would hand back a negative of a page.
            $sample = [];
            for ($y = 0; $y < $h; $y += 3) {
                for ($x = 0; $x < $w; $x += 3) {
                    $sample[] = $lum[$y][$x];
                }
            }
            sort($sample);
            $median = $sample[(int) (count($sample) / 2)] ?? 255;
            if ($median >= self::PAPER_MIN || 255 - $paper < self::PAPER_MIN) {
                Log::info('FlowVisualService: drawing is not on paper', ['paper' => $paper]);

                return null;
            }
            foreach ($lum as $y => $row) {
                foreach ($row as $x => $value) {
                    $lum[$y][$x] = 255 - $value;
                }
            }
            $paper = 255 - $paper;
            Log::info('FlowVisualService: inverted a white-on-black drawing', ['paper' => $paper]);
        }

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));

        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;
        $crossings = 0;
        for ($y = 0; $y < $h; $y++) {
            $wasInk = false;
            for ($x = 0; $x < $w; $x++) {
                // Ink is darkness BELOW the paper, normalised so a grey page
                // still yields black lines at full strength.
                $ink = (int) round((max(0, $paper - $lum[$y][$x]) / $paper) * 255);
                if ($ink <= self::PAPER_INK) {
                    continue; // paper, wash, jpeg noise
                }
                $t = min(1.0, ($ink - self::PAPER_INK) / (self::LINE_INK - self::PAPER_INK));
                if ($t < self::WASH) {
                    $wasInk = false;
                    continue;
                }
                $t = ($t - self::WASH) / (1 - self::WASH);
                $alpha = max(0, min(127, 127 - (int) round(127 * $t)));
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, 255, 255, 255, $alpha));
                if ($t > 0.35) {
                    // A stroke crossing: how many separate marks this row cuts
                    // through. A diagram cuts through a handful; a texture
                    // cuts through dozens.
                    $crossings += $wasInk ? 0 : 1;
                    $wasInk = true;
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                } else {
                    $wasInk = false;
                }
            }
        }

        if ($maxX < 0) {
            imagedestroy($out);

            return null; // a blank page is not a drawing
        }

        // A scribble (a crowd texture, a hatched fill) is not a diagram part:
        // keyed out it reads as a smudge. Nor is a drawing that bleeds off
        // every edge — it was never one object on a page. Both are refused so
        // the caller can redraw, or the card plays without a picture.
        $rows = max(1, $maxY - $minY + 1);
        $perRow = $crossings / $rows;
        if ($perRow > self::MAX_CROSSINGS) {
            imagedestroy($out);
            Log::info('FlowVisualService: drawing is a texture', ['crossings_per_row' => round($perRow, 1)]);

            return null;
        }
        if (($maxX - $minX + 1) / $w > self::BLEED && $rows / $h > self::BLEED) {
            imagedestroy($out);
            Log::info('FlowVisualService: drawing has no margin');

            return null;
        }

        // The model likes to put its object in a box, and a box that survives
        // keying reads as a plate around the part. Strip it here rather than
        // trusting the prompt: it is the same drawing with its frame removed,
        // and it costs nothing to re-key one already paid for.
        self::stripFrame($out, $minX, $maxX, $minY, $maxY);

        $pad = 8;
        $crop = imagecrop($out, [
            'x' => max(0, $minX - $pad),
            'y' => max(0, $minY - $pad),
            'width' => min($w, $maxX - $minX + 2 * $pad),
            'height' => min($h, $maxY - $minY + 2 * $pad),
        ]);
        imagedestroy($out);
        if ($crop === false) {
            return null;
        }
        imagesavealpha($crop, true);

        ob_start();
        imagepng($crop, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($crop);

        return $png !== '' ? $png : null;
    }
}
