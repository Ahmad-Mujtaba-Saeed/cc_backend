<?php

namespace Modules\Project\Support;

/**
 * VectorMotif — the spec, and the deterministic repair, for a DRAWING the
 * planner authors for one specific beat.
 *
 * ## Why this exists
 *
 * Forty-five card templates draw forty-five fixed shapes. `stat_spotlight`
 * renders the same big number for "3 billion tonnes of glass" as for "72% of
 * users", and no card in the registry knows what a glass bottle looks like.
 * That is the gap between "a card animating" and motion graphics: in a real
 * explainer, the thing on screen is the thing being explained.
 *
 * `custom_card` (iter 53) was the first answer and taught the lesson this one
 * is built on. Free-form HTML gave the model a canvas it cannot see, so the
 * first fragment it wrote was a 1000x1600 SVG for a 16:9 video with chat lines
 * as `<text>` (which does not wrap) and no reveal cues at all. Freedom was not
 * the missing ingredient; a COORDINATE SYSTEM WITH GUARANTEES was.
 *
 * ## The spec
 *
 * A motif is a flat list of shapes on a fixed **100 x 100 logical view**. The
 * renderer measures their bounding box and fits it to whatever stage it has,
 * so the model never has to think about aspect ratio, pixels or the frame — a
 * drawing that is off-centre or half the size it meant to be still lands
 * correctly, because the fit is not its job.
 *
 * Seven primitives, and nothing else: `circle`, `rect`, `line`, `arrow`,
 * `path`, `icon` (from the registry's existing Lucide whitelist, which is how
 * a motif gets a real pictogram without generating an image), and `label`.
 *
 * Colours are SEMANTIC — `accent`, `ink`, `muted`, `paper` — never literal.
 * A motif is therefore in the video's scheme automatically and cannot break
 * the flat-design law by reaching for a gradient or a glow, because there is
 * no syntax in which to express one.
 *
 * Animation is the `custom_card` contract, which is already proven: a shape
 * carries `at` (a 0..1 point in the scene) or `word` (land when the narrator
 * says that word), plus an `anim`. It may also carry `life` — one of iter 61's
 * sustained loops — so a settled drawing keeps moving.
 *
 * ## Why the repair is deterministic
 *
 * Every number is clamped into the view, every enum falls back to a safe
 * value, unknown primitives are dropped, and the result is rejected outright
 * if too little survives. The model is therefore allowed to be sloppy about
 * everything except INTENT: it says what to draw and roughly where, and this
 * class guarantees the result is renderable. A validator that could throw at
 * render time, or a shape that could escape the view, would make the whole
 * feature a liability.
 *
 * Security is a smaller surface than `custom_html` by construction: there is
 * no markup here, no CSS, no URL and no attribute the caller controls. `path`
 * is the only free-form string and it is matched against a numbers-and-
 * commands regex, so it cannot carry anything but geometry.
 */
class VectorMotif
{
    /** The logical drawing surface. Every coordinate lives in 0..100. */
    public const VIEW = 100.0;

    /** More than this is an illustration, not a motif for one beat. */
    public const MAX_SHAPES = 16;

    /** Text is a label, not a paragraph. */
    public const MAX_LABELS = 6;
    public const MAX_LABEL_CHARS = 28;

    /** A motif below this is a dot on a field — not worth a slot. */
    public const MIN_SHAPES = 2;

    public const KINDS = ['circle', 'rect', 'line', 'arrow', 'path', 'icon', 'label'];

    /** Semantic palette. The renderer maps these onto the active theme. */
    public const COLORS = ['accent', 'ink', 'muted', 'paper', 'none'];

    /** Entrance vocabulary, mirroring `custom_card`'s cue contract. */
    public const ANIMS = ['draw', 'pop', 'rise', 'fade', 'sweep'];

    /** Sustained loops (iter 61, motion/sustain.ts) a settled shape may ride. */
    public const LIVES = ['none', 'breathe', 'float', 'sway', 'orbit', 'pulse'];

    /** SVG path commands a motif may use: geometry only. */
    private const PATH_RE = '/^[MmLlHhVvCcQqAaZz0-9eE ,.+-]+$/';

    /**
     * Clamp a raw motif into a renderable one.
     *
     * @param  array<string,mixed> $raw Slot-shaped input (`shapes`, `caption`).
     * @return array{ok: bool, shapes: array<int,array<string,mixed>>, caption: string, warnings: array<int,string>}
     */
    public static function sanitize(array $raw, ?callable $iconExists = null): array
    {
        $warnings = [];
        $shapes = [];
        $labels = 0;
        $ids = [];

        $input = $raw['shapes'] ?? [];
        if (!is_array($input)) {
            $input = [];
        }

        foreach ($input as $i => $item) {
            if (!is_array($item) || count($shapes) >= self::MAX_SHAPES) {
                continue;
            }

            $kind = strtolower(trim((string) ($item['kind'] ?? '')));
            if (!in_array($kind, self::KINDS, true)) {
                $warnings[] = "motif: dropped shape {$i} of unknown kind '{$kind}'";
                continue;
            }

            if ($kind === 'label') {
                if ($labels >= self::MAX_LABELS) {
                    $warnings[] = 'motif: dropped a label past the cap';
                    continue;
                }
                $labels++;
            }

            $shape = self::shape($kind, $item, $iconExists, $warnings);
            if ($shape === null) {
                continue;
            }

            // Ids only matter for being unique — they are the React key and
            // nothing else references them, so a collision is renamed rather
            // than rejected.
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($item['id'] ?? ''))) ?: $kind;
            $id = substr($id, 0, 24);
            $base = $id;
            $n = 2;
            while (in_array($id, $ids, true)) {
                $id = $base . '-' . $n++;
            }
            $ids[] = $id;
            $shape['id'] = $id;

            $shapes[] = $shape;
        }

        $shapes = self::deCollideLabels($shapes);

        // A drawing needs something to draw. One circle is not a motif, and a
        // motif of labels only is a text block wearing a costume.
        $drawn = count(array_filter($shapes, fn ($s) => $s['kind'] !== 'label'));
        $ok = count($shapes) >= self::MIN_SHAPES && $drawn >= 1;
        if (!$ok && $shapes !== []) {
            $warnings[] = 'motif: too little survived to be worth drawing';
        }

        $caption = trim((string) ($raw['caption'] ?? ''));

        return [
            'ok' => $ok,
            'shapes' => $shapes,
            'caption' => $caption === '' ? '' : mb_substr($caption, 0, 90),
            'warnings' => $warnings,
        ];
    }

    /**
     * One shape, clamped. Null when it cannot be salvaged.
     *
     * @param  array<string,mixed> $item
     * @param  array<int,string>   $warnings
     * @return array<string,mixed>|null
     */
    private static function shape(string $kind, array $item, ?callable $iconExists, array &$warnings): ?array
    {
        $shape = ['kind' => $kind];

        switch ($kind) {
            case 'circle':
                $shape['cx'] = self::coord($item['cx'] ?? null, 50);
                $shape['cy'] = self::coord($item['cy'] ?? null, 50);
                // A zero-radius circle is invisible; a 200-radius one swallows
                // the drawing. Both are clamped rather than dropped, because
                // the intent ("there is a circle here") is still usable.
                $shape['r'] = self::num($item['r'] ?? null, 10, 1, 50);
                break;

            case 'rect':
                $shape['x'] = self::coord($item['x'] ?? null, 25);
                $shape['y'] = self::coord($item['y'] ?? null, 25);
                $shape['w'] = self::num($item['w'] ?? null, 50, 1, self::VIEW);
                $shape['h'] = self::num($item['h'] ?? null, 30, 1, self::VIEW);
                $shape['round'] = self::num($item['round'] ?? null, 0, 0, 24);
                break;

            case 'line':
            case 'arrow':
                $shape['x1'] = self::coord($item['x1'] ?? null, 20);
                $shape['y1'] = self::coord($item['y1'] ?? null, 50);
                $shape['x2'] = self::coord($item['x2'] ?? null, 80);
                $shape['y2'] = self::coord($item['y2'] ?? null, 50);
                // A line from a point to itself draws nothing at all.
                if (abs($shape['x1'] - $shape['x2']) < 0.5 && abs($shape['y1'] - $shape['y2']) < 0.5) {
                    $warnings[] = "motif: dropped a zero-length {$kind}";
                    return null;
                }
                break;

            case 'path': {
                $d = trim((string) ($item['d'] ?? ''));
                // The only free-form string in the spec, so it is matched
                // rather than escaped: commands and numbers, nothing else.
                if ($d === '' || strlen($d) > 400 || !preg_match(self::PATH_RE, $d)) {
                    $warnings[] = 'motif: dropped a path that is not plain geometry';
                    return null;
                }
                $shape['d'] = $d;
                break;
            }

            case 'icon': {
                $name = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($item['name'] ?? '')));
                if ($name === '' || ($iconExists !== null && !$iconExists($name))) {
                    $warnings[] = "motif: dropped unknown icon '{$name}'";
                    return null;
                }
                $shape['name'] = $name;
                $shape['x'] = self::coord($item['x'] ?? null, 50);
                $shape['y'] = self::coord($item['y'] ?? null, 50);
                $shape['size'] = self::num($item['size'] ?? null, 20, 6, 70);
                break;
            }

            case 'label': {
                $text = trim((string) ($item['text'] ?? ''));
                if ($text === '') {
                    return null;
                }
                $shape['text'] = mb_substr($text, 0, self::MAX_LABEL_CHARS);
                $shape['x'] = self::coord($item['x'] ?? null, 50);
                $shape['y'] = self::coord($item['y'] ?? null, 50);
                $shape['size'] = self::num($item['size'] ?? null, 6, 3, 14);
                $anchor = strtolower(trim((string) ($item['anchor'] ?? 'middle')));
                $shape['anchor'] = in_array($anchor, ['start', 'middle', 'end'], true) ? $anchor : 'middle';
                break;
            }
        }

        // ---- Shared style ---------------------------------------------------
        // Defaults differ by primitive because the useful default differs: a
        // line with no stroke is nothing, a label with a stroke is unreadable.
        $shape['stroke'] = self::color($item['stroke'] ?? null, $kind === 'label' ? 'none' : 'ink');
        $shape['fill'] = self::color(
            $item['fill'] ?? null,
            in_array($kind, ['line', 'arrow', 'path', 'icon'], true) ? 'none' : ($kind === 'label' ? 'ink' : 'none')
        );
        if ($shape['stroke'] === 'none' && $shape['fill'] === 'none') {
            $warnings[] = "motif: dropped an invisible {$kind} (no stroke, no fill)";
            return null;
        }
        $shape['width'] = self::num($item['width'] ?? null, 1.4, 0.4, 5);
        $shape['opacity'] = self::num($item['opacity'] ?? null, 1, 0.08, 1);

        // ---- Timing ---------------------------------------------------------
        $anim = strtolower(trim((string) ($item['anim'] ?? '')));
        $shape['anim'] = in_array($anim, self::ANIMS, true)
            ? $anim
            // Strokes want to be drawn; anything solid wants to arrive.
            : (in_array($kind, ['line', 'arrow', 'path'], true) ? 'draw' : 'pop');

        $word = trim((string) ($item['word'] ?? ''));
        if ($word !== '') {
            $shape['word'] = mb_substr(preg_replace('/[^\p{L}\p{N}\- ]/u', '', $word) ?? '', 0, 30);
        }
        if (isset($item['at']) && is_numeric($item['at'])) {
            // 0.92+ is a reveal nobody sees; the last fifth of a scene is for
            // reading what is already there (the breathing-room rule).
            $shape['at'] = self::num($item['at'], 0, 0, 0.85);
        }

        $life = strtolower(trim((string) ($item['life'] ?? '')));
        if (in_array($life, self::LIVES, true) && $life !== 'none') {
            $shape['life'] = $life;
        }

        return $shape;
    }

    /**
     * Move labels off the EDGES of the shapes they name.
     *
     * The first three live drawings all had it: "Server" straddling a router,
     * "solar panel" cut in half by the panel's own border. The prompt asks for
     * clearance and the model agrees and then does it anyway, because it
     * cannot see the result — which is the standing argument for a guarantee
     * over an instruction.
     *
     * The rule is deliberately narrow. A label that STRADDLES a shape's border
     * is always wrong: half the text sits on the fill and half on the field,
     * and it reads as a printing error. A label wholly INSIDE a large shape is
     * usually deliberate ("electron" inside the panel), so it is left alone.
     * Only the straddle is repaired, by pushing the label the shortest way out.
     *
     * Boxes are approximate — a label's width is estimated from its character
     * count, and a path's extent is not computed at all — so this nudges
     * rather than solves. Nudging is the right ambition: the renderer's own
     * bounding-box fit absorbs the rest.
     *
     * @param  array<int,array<string,mixed>> $shapes
     * @return array<int,array<string,mixed>>
     */
    private static function deCollideLabels(array $shapes): array
    {
        $boxes = [];
        foreach ($shapes as $i => $shape) {
            // Paths are excluded as obstacles: their true extent needs a parser
            // this class does not have, and claiming the whole view would push
            // every label off a drawing made of outlines.
            if (in_array($shape['kind'], ['circle', 'rect', 'icon'], true)) {
                $boxes[$i] = self::boxOf($shape);
            }
        }

        foreach ($shapes as $i => $shape) {
            if ($shape['kind'] !== 'label') {
                continue;
            }

            $box = self::boxOf($shape);
            $h = $box['y1'] - $box['y0'];

            foreach ($boxes as $j => $obstacle) {
                if ($j === $i) {
                    continue;
                }
                $overlaps = $box['x0'] < $obstacle['x1'] && $box['x1'] > $obstacle['x0']
                    && $box['y0'] < $obstacle['y1'] && $box['y1'] > $obstacle['y0'];
                if (!$overlaps) {
                    continue;
                }
                $inside = $box['x0'] >= $obstacle['x0'] && $box['x1'] <= $obstacle['x1']
                    && $box['y0'] >= $obstacle['y0'] && $box['y1'] <= $obstacle['y1'];
                if ($inside) {
                    continue;
                }

                // Shortest way out, vertically. Going DOWN means the label's
                // TOP must clear the obstacle's bottom; going UP means its
                // BOTTOM must clear the obstacle's top. Measuring the wrong
                // edge leaves a sliver of overlap that still reads as a
                // printing error, which is the whole defect being fixed.
                $gap = $h * 0.25;
                $down = $obstacle['y1'] + $gap - $box['y0'];
                $up = $obstacle['y0'] - $gap - $box['y1'];
                $shift = abs($down) <= abs($up) ? $down : $up;
                $y = self::num($shapes[$i]['y'] + $shift, $shapes[$i]['y'], -6, self::VIEW + 6);

                $shapes[$i]['y'] = $y;
                $box = self::boxOf($shapes[$i]);
            }
        }

        return $shapes;
    }

    /**
     * A shape's approximate bounding box in view units. Mirrors the renderer's
     * `shapeBox` closely enough for collision work; the two do not have to
     * agree exactly, because this only decides a nudge.
     *
     * @param  array<string,mixed> $s
     * @return array{x0: float, y0: float, x1: float, y1: float}
     */
    private static function boxOf(array $s): array
    {
        switch ($s['kind']) {
            case 'circle':
                return [
                    'x0' => $s['cx'] - $s['r'], 'y0' => $s['cy'] - $s['r'],
                    'x1' => $s['cx'] + $s['r'], 'y1' => $s['cy'] + $s['r'],
                ];
            case 'rect':
                return [
                    'x0' => $s['x'], 'y0' => $s['y'],
                    'x1' => $s['x'] + $s['w'], 'y1' => $s['y'] + $s['h'],
                ];
            case 'icon': {
                $h = $s['size'] / 2;
                return [
                    'x0' => $s['x'] - $h, 'y0' => $s['y'] - $h,
                    'x1' => $s['x'] + $h, 'y1' => $s['y'] + $h,
                ];
            }
            case 'label': {
                $size = $s['size'];
                // The same rough advance width the renderer estimates with.
                $w = mb_strlen((string) $s['text']) * $size * 0.52;
                $half = ($s['anchor'] ?? 'middle') === 'start' ? 0 : (($s['anchor'] ?? '') === 'end' ? $w : $w / 2);
                return [
                    'x0' => $s['x'] - $half, 'y0' => $s['y'] - $size,
                    'x1' => $s['x'] - $half + $w, 'y1' => $s['y'] + $size * 0.35,
                ];
            }
            default:
                return ['x0' => 0.0, 'y0' => 0.0, 'x1' => 0.0, 'y1' => 0.0];
        }
    }

    /** A coordinate, clamped into the view (with a little bleed allowed). */
    private static function coord(mixed $v, float $default): float
    {
        return self::num($v, $default, -10, self::VIEW + 10);
    }

    /** A number, clamped, rounded to two places so payloads stay small. */
    private static function num(mixed $v, float $default, float $min, float $max): float
    {
        $n = is_numeric($v) ? (float) $v : $default;
        if (!is_finite($n)) {
            $n = $default;
        }

        return round(max($min, min($max, $n)), 2);
    }

    /** A semantic colour name, or the given default. */
    private static function color(mixed $v, string $default): string
    {
        $c = strtolower(trim((string) $v));

        return in_array($c, self::COLORS, true) ? $c : $default;
    }
}
