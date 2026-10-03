<?php

namespace Modules\Project\Support;

/**
 * The per-video look ("unique look").
 *
 * Until this existed every explainer was assembled from the same handful of
 * house choices: 17 colour schemes, 3 font packs, 5 motion styles, one
 * relation → cut table. The auto-theme call picks from those at temperature
 * 0.4, so two "neutral" videos about similar topics — from any two accounts —
 * came out with the same palette, the same type, the same timing and the same
 * cut on every "continues" beat. Viewers (and YouTube) see one template.
 *
 * A recipe is a seeded draw over a much larger space, made ONCE per project
 * and stored in `settings.style_recipe`, so every render, preview still and
 * Play-tab frame of that project agrees:
 *
 *  - palette:    a generated OKLCH palette (five families: neutral night,
 *                tinted night, deep colour field, paper, tinted paper) with
 *                every pair the cards rely on held to a WCAG floor;
 *  - fonts:      a display/body/mono trio from the registry's font library
 *                (27 × 14 × 9), weighted by the topic's character;
 *  - motion:     the topic's motion style, re-tuned (tempo, stagger, ease,
 *                overshoot, entrance, highlight, exit) — "same editor,
 *                different hand";
 *  - signatures: which cut each story relation gets, from a menu of cuts
 *                that keep the relation's meaning;
 *  - backdrop:   which texture each mood group wears, and at what scale.
 *
 * The topic still steers the draw (the auto-theme suggestion and the dominant
 * mood become the recipe's `ctx`), so a history video still leans serif and a
 * tense one stays dark — it just stops being the SAME serif and the same dark.
 *
 * Precedence never changes: an explicit user pick of scheme, typeface or
 * motion style wins over the recipe, and a project without a recipe renders
 * exactly as it did before this class existed.
 *
 * Everything here is pure and deterministic in (seed, ctx): no global RNG,
 * no clock, no I/O beyond the registry.
 */
final class StyleRecipe
{
    public const VERSION = 1;

    /** The pseudo colour-scheme name that means "this project's recipe palette". */
    public const SCHEME = 'unique';

    private const MOOD_DARK = ['dramatic', 'tense', 'suspense'];

    /** @var array<string, int> */
    private array $counters = [];

    private function __construct(private readonly int $seed)
    {
    }

    // ------------------------------------------------------------------
    // Building
    // ------------------------------------------------------------------

    public static function newSeed(): int
    {
        return random_int(1, 2147483646);
    }

    /**
     * What the topic says about the look, distilled from the analyze-time
     * signals. Stored inside the recipe so a re-roll or a palette shuffle
     * keeps steering the same way.
     *
     * @param  array<string, mixed>  $suggested  SceneStyleService::suggestTheme() output
     * @return array{mood: string, dark: ?bool, character: string, motion_base: string, math: bool}
     */
    public static function contextFrom(array $suggested, string $dominantMood, bool $math): array
    {
        $dark = null;
        if (!empty($suggested['color_scheme']) && in_array($suggested['color_scheme'], ExplainerRegistry::colorSchemeNames(), true)) {
            $bg = (string) (ExplainerRegistry::colorScheme((string) $suggested['color_scheme'])['bg_from'] ?? '');
            if (preg_match('/^#[0-9a-f]{6}$/i', $bg)) {
                $dark = SceneBudgetLinter::contrastRatio($bg, '#000000') < 9.0;
            }
        }
        if (in_array($dominantMood, self::MOOD_DARK, true)) {
            $dark = true;
        }

        $styles = ExplainerRegistry::motionStyleNames();
        $base = in_array($suggested['motion_style'] ?? null, $styles, true)
            ? (string) $suggested['motion_style']
            : ExplainerRegistry::motionStyleForMood($dominantMood);

        $packs = ExplainerRegistry::fontPackNames();
        $character = in_array($suggested['font_pack'] ?? null, $packs, true)
            ? (string) $suggested['font_pack']
            : ExplainerRegistry::fontPackForStyle($base);

        return [
            'mood' => in_array($dominantMood, ExplainerRegistry::moods(), true) ? $dominantMood : 'neutral',
            'dark' => $dark,
            'character' => $character,
            'motion_base' => $base,
            'math' => $math,
        ];
    }

    /**
     * Draw a whole recipe.
     *
     * @param  array<string, mixed>  $ctx  see contextFrom()
     */
    public static function generate(int $seed, array $ctx = []): array
    {
        $ctx = self::normaliseCtx($ctx);
        $r = new self($seed);

        $motion = $r->drawMotion($ctx);

        return [
            'v' => self::VERSION,
            'seed' => $seed,
            'palette_seed' => $seed,
            'ctx' => $ctx,
            'palette' => (new self($seed))->drawPalette($ctx),
            // Maths is set in the tech pack on purpose (AnalyzeExplainerScriptJob
            // forces it): the board typesetter and the step-diff highlight are
            // tuned to those metrics. The recipe leaves maths type alone.
            'fonts' => $ctx['math'] ? null : $r->drawFonts($ctx),
            'motion' => $motion,
            'signatures' => $r->drawSignatures($motion['base']),
            'backdrop' => $r->drawBackdrop(),
        ];
    }

    /** The same recipe with only the palette re-drawn (the Palette "Shuffle" button). */
    public static function withNewPalette(array $recipe, int $paletteSeed): array
    {
        $ctx = self::normaliseCtx(is_array($recipe['ctx'] ?? null) ? $recipe['ctx'] : []);
        $recipe['palette_seed'] = $paletteSeed;
        $recipe['palette'] = (new self($paletteSeed))->drawPalette($ctx);

        return $recipe;
    }

    // ------------------------------------------------------------------
    // Reading (what the render payload and the UI consume)
    // ------------------------------------------------------------------

    /** The project's recipe when it is one this code can read, else null. */
    public static function of(array $settings): ?array
    {
        $recipe = $settings['style_recipe'] ?? null;
        if (!is_array($recipe) || (int) ($recipe['v'] ?? 0) !== self::VERSION) {
            return null;
        }
        if (!self::validPalette($recipe['palette'] ?? null)) {
            return null;
        }

        return $recipe;
    }

    /** The theme when the project's scheme is the recipe's own palette. */
    public static function theme(array $settings): ?array
    {
        if (($settings['color_scheme'] ?? null) !== self::SCHEME) {
            return null;
        }
        $recipe = self::of($settings);

        return $recipe ? $recipe['palette'] : null;
    }

    /**
     * The font trio for this render, or null to fall back to the font pack.
     *
     * Only when the typeface is left on auto, and only on the skins that do not
     * already dictate type (print is the serif pack by design, blueprint the
     * mono-flavoured tech pack), and never on the maths board.
     *
     * @return array{display: string, body: string, mono: string}|null
     */
    public static function fonts(array $settings, string $resolvedSkin): ?array
    {
        $recipe = self::of($settings);
        if ($recipe === null || !is_array($recipe['fonts'] ?? null)) {
            return null;
        }
        if (in_array($settings['font_pack'] ?? null, ExplainerRegistry::fontPackNames(), true)) {
            return null;
        }
        if (!in_array($resolvedSkin, ['flat', 'outline'], true)) {
            return null;
        }
        if (($settings['composition_mode'] ?? '') === 'math_board') {
            return null;
        }

        $lib = self::fontLibrary();
        $f = $recipe['fonts'];
        foreach (['display', 'body', 'mono'] as $role) {
            if (!isset($lib[$role][(string) ($f[$role] ?? '')])) {
                return null;
            }
        }

        return ['display' => (string) $f['display'], 'body' => (string) $f['body'], 'mono' => (string) $f['mono']];
    }

    /**
     * The motion tuning for this render, or null when the user chose a motion
     * style themselves (an explicit pick is that style, untouched).
     */
    public static function motion(array $settings): ?array
    {
        $recipe = self::of($settings);
        if ($recipe === null || !is_array($recipe['motion'] ?? null)) {
            return null;
        }
        if (in_array($settings['motion_style'] ?? null, ExplainerRegistry::motionStyleNames(), true)) {
            return null;
        }
        $m = $recipe['motion'];
        if (!in_array($m['base'] ?? null, ExplainerRegistry::motionStyleNames(), true)) {
            return null;
        }

        return $m;
    }

    /** Backdrop texture choices, or null for the renderer's mood defaults. */
    public static function backdrop(array $settings): ?array
    {
        $recipe = self::of($settings);

        return $recipe !== null && is_array($recipe['backdrop'] ?? null) ? $recipe['backdrop'] : null;
    }

    /**
     * What the storyboard UI shows about the recipe (labels, not keys).
     */
    public static function summary(array $settings): ?array
    {
        $recipe = self::of($settings);
        if ($recipe === null) {
            return null;
        }
        $lib = self::fontLibrary();
        $fonts = is_array($recipe['fonts'] ?? null) ? $recipe['fonts'] : null;
        $styles = ExplainerRegistry::motionStyles();
        $m = $recipe['motion'] ?? [];
        $base = (string) ($m['base'] ?? '');

        return [
            'palette' => $recipe['palette'],
            'fonts' => $fonts === null ? null : [
                'display' => $lib['display'][$fonts['display']]['label'] ?? $fonts['display'],
                'body' => $lib['body'][$fonts['body']]['label'] ?? $fonts['body'],
                'mono' => $lib['mono'][$fonts['mono']]['label'] ?? $fonts['mono'],
            ],
            'motion' => [
                'base' => $base,
                'label' => self::motionLabel($m, (string) ($styles[$base]['label'] ?? $base)),
            ],
            'signatures' => $recipe['signatures'] ?? [],
            'fonts_active' => self::fonts($settings, \Modules\Project\Services\RemotionRenderService::resolveSkin($settings)) !== null,
            'motion_active' => self::motion($settings) !== null,
            'palette_active' => ($settings['color_scheme'] ?? null) === self::SCHEME,
        ];
    }

    /**
     * Swap each scene's cut for this recipe's signature where the scene still
     * carries the cut `$from` gives its relation (the house table, or the
     * previous recipe). A cut the user or the planner chose deliberately is
     * some OTHER transition and is left alone. Scene 1 never has an incoming
     * cut. Works on arrays (analyze/revise) — the caller persists.
     *
     * @param  array<int, array>  $scenes  ordered
     * @param  array<string, string>|null  $from  null = the registry's house signatures
     * @param  array<string, string>  $to
     */
    public static function remapTransitions(array $scenes, ?array $from, array $to): array
    {
        $from = $from ?? ExplainerRegistry::relationSignatures();
        foreach ($scenes as $i => $scene) {
            if ($i === 0) {
                continue;
            }
            $relation = (string) ($scene['relation'] ?? '');
            $old = $from[$relation] ?? null;
            $new = $to[$relation] ?? null;
            if (!is_string($old) || !is_string($new) || $old === $new) {
                continue;
            }
            if ((string) ($scene['transition'] ?? '') === $old && in_array($new, ExplainerRegistry::transitions(), true)) {
                $scenes[$i]['transition'] = $new;
            }
        }

        return $scenes;
    }

    // ------------------------------------------------------------------
    // Palette (OKLCH)
    // ------------------------------------------------------------------

    private function drawPalette(array $ctx): array
    {
        $dark = $ctx['dark'];
        // Plain near-black ("night") is what the shared house schemes already
        // look like, so it is the LEAST likely draw; the tinted and deep
        // fields are where a video stops looking like every other one.
        $weights = match (true) {
            $dark === true => ['night' => 20, 'tinted_night' => 38, 'deep_field' => 38, 'paper' => 2, 'tinted_paper' => 2],
            $dark === false => ['night' => 5, 'tinted_night' => 5, 'deep_field' => 15, 'paper' => 45, 'tinted_paper' => 30],
            default => ['night' => 13, 'tinted_night' => 21, 'deep_field' => 24, 'paper' => 24, 'tinted_paper' => 18],
        };
        $family = $this->weighted('family', $weights);
        $light = in_array($family, ['paper', 'tinted_paper'], true);

        // The field. Each family keeps to the hues that read as a deliberate
        // colour at its lightness — a deep field in the olive/khaki band
        // (40–145°) is mud, and a lilac paper reads as a greeting card.
        $bgHue = match ($family) {
            'deep_field' => $this->bandHue('bg_h', [[0, 35], [148, 272], [285, 360]]),
            'tinted_night' => $this->bandHue('bg_h', [[0, 68], [132, 360]]),
            'tinted_paper' => $this->bandHue('bg_h', [[35, 105], [128, 175], [195, 262], [345, 380]]),
            default => $this->range('bg_h', 0, 360),
        };
        [$bgL, $bgC] = match ($family) {
            'night' => [$this->range('bg_l', 0.15, 0.205), $this->range('bg_c', 0.004, 0.018)],
            'tinted_night' => [$this->range('bg_l', 0.17, 0.235), $this->range('bg_c', 0.03, 0.058)],
            'deep_field' => [$this->range('bg_l', 0.27, 0.355), $this->range('bg_c', 0.07, 0.12)],
            // Paper leans warm most of the time — cream and bone read as paper,
            // a cold blue-white reads as a spreadsheet.
            'paper' => [$this->range('bg_l', 0.955, 0.978), $this->range('bg_c', 0.006, 0.024)],
            default => [$this->range('bg_l', 0.925, 0.958), $this->range('bg_c', 0.026, 0.045)],
        };
        if ($family === 'paper' && $this->u('paper_warm') < 0.7) {
            $bgHue = $this->range('bg_h_warm', 60, 100);
        }
        $bg = self::oklchHex($bgL, $bgC, $bgHue);

        // The panel sits one step off the field, same hue.
        $panelL = $light ? $bgL - $this->range('panel_l', 0.03, 0.045) : $bgL + $this->range('panel_l', 0.035, 0.05);
        $panel = self::oklchHex($panelL, $bgC * 1.05, $bgHue);

        // Ink.
        $text = $light
            ? self::oklchHex($this->range('text_l', 0.17, 0.23), min(0.03, $bgC + 0.01), $bgHue)
            : self::oklchHex($this->range('text_l', 0.945, 0.975), min(0.02, $bgC * 0.4 + 0.004), $bgHue);
        $text = self::pushContrast($text, [$bg => 9.0, $panel => 7.5], $light ? -1 : 1);

        // The accent: a harmony off the field hue (on a near-neutral field the
        // field hue is invisible, so this is effectively a free hue there).
        $harmony = $this->weighted('harmony', ['complement' => 3, 'split' => 2, 'triad' => 2, 'analogous' => 1, 'free' => 2]);
        $sign = $this->u('harmony_sign') < 0.5 ? -1 : 1;
        $accentHue = match ($harmony) {
            'complement' => $bgHue + 180 + $this->range('h_jit', -25, 25),
            'split' => $bgHue + $sign * 150 + $this->range('h_jit', -15, 15),
            'triad' => $bgHue + $sign * 120 + $this->range('h_jit', -15, 15),
            'analogous' => $bgHue + $sign * $this->range('h_jit', 40, 65),
            default => $this->range('h_free', 0, 360),
        };
        $accentHue = fmod($accentHue + 720, 360);
        // On a TINTED field the field hue is visible, and an accent within
        // ~50° of it melts in ("indigo on indigo night"); swing it away.
        if ($family !== 'night' && $family !== 'paper' && self::hueDistance($accentHue, $bgHue) < 50) {
            $accentHue = fmod($bgHue + $sign * 150 + 720, 360);
        }
        // Yellows and limes cannot reach text contrast on a pale field without
        // turning olive; on paper they move to the nearest strong hue instead.
        if ($light && $accentHue >= 80 && $accentHue <= 130) {
            $accentHue = $this->u('olive_dodge') < 0.5 ? $this->range('h_orange', 35, 60) : $this->range('h_green', 145, 165);
        }
        // A deep field needs a LIGHT accent, and blues/violets cannot hold
        // chroma that high in sRGB — they go pastel. The hues that stay vivid
        // at L≈0.85 are the warm and the cyan ones, so a deep field moves its
        // accent there (whichever lands further from the field's own hue).
        if ($family === 'deep_field' && $accentHue >= 225 && $accentHue <= 332) {
            $warm = $this->range('h_deep_warm', 58, 98);
            $cyan = $this->range('h_deep_cyan', 182, 202);
            $accentHue = self::hueDistance($warm, $bgHue) >= self::hueDistance($cyan, $bgHue) ? $warm : $cyan;
        }
        // Start the accent a little LOW on dark fields and let the contrast
        // walk lift it only as far as it must: the less lightness, the more
        // chroma survives the gamut clip.
        $accentL = match (true) {
            $light => $this->range('acc_l', 0.48, 0.6),
            $family === 'deep_field' => $this->range('acc_l', 0.8, 0.9),
            default => $this->range('acc_l', 0.68, 0.84),
        };
        $accent = self::oklchHex($accentL, $this->range('acc_c', 0.14, 0.21), $accentHue);
        // 4.5:1 against the field — the accent paints highlighted WORDS, not
        // just rules, so it is held to the body-text floor.
        $accent = self::pushContrast($accent, [$bg => 4.5], $light ? -1 : 1);

        $acc2Hue = $this->u('acc2_mode') < 0.6
            ? fmod($accentHue + $sign * $this->range('acc2_h', 28, 55) + 720, 360)
            : fmod($bgHue + $this->range('acc2_h_bg', -20, 20) + 720, 360);
        if ($light && $acc2Hue >= 80 && $acc2Hue <= 130) {
            $acc2Hue = fmod($acc2Hue + 60, 360);
        }
        $accent2 = self::oklchHex($accentL + $this->range('acc2_l', -0.05, 0.04), $this->range('acc2_c', 0.1, 0.18), $acc2Hue);
        $accent2 = self::pushContrast($accent2, [$bg => 3.2], $light ? -1 : 1);

        // Muted copy: the quietest ink that still clears 4.6:1 on the field
        // and 4.0:1 on the panel.
        $muted = self::solveMuted($bgL, $bgC, $bgHue, $bg, $panel, $light);

        return [
            'name' => self::SCHEME,
            'label' => self::paletteLabel($family, $bgHue, $accentHue),
            'family' => $family,
            'bg_from' => $bg,
            'bg_to' => $bg,
            'accent' => $accent,
            'accent2' => $accent2,
            'text' => $text,
            'muted' => $muted,
            'panel' => $panel,
        ];
    }

    private static function solveMuted(float $bgL, float $bgC, float $hue, string $bg, string $panel, bool $light): string
    {
        $c = min(0.04, $bgC * 0.7 + 0.008);
        $step = $light ? -0.01 : 0.01;
        $l = $bgL;
        $hex = self::oklchHex($l, $c, $hue);
        for ($i = 0; $i < 100; $i++) {
            $l += $step;
            $hex = self::oklchHex($l, $c, $hue);
            if (SceneBudgetLinter::contrastRatio($hex, $bg) >= 4.6 && SceneBudgetLinter::contrastRatio($hex, $panel) >= 4.0) {
                return $hex;
            }
        }

        return $hex;
    }

    /**
     * Walk a colour's OKLCH lightness away from its backgrounds until it clears
     * every floor. `$dir` = +1 lightens (dark field), -1 darkens (pale field).
     *
     * @param  array<string, float>  $floors  background hex => minimum ratio
     */
    private static function pushContrast(string $hex, array $floors, int $dir): string
    {
        [$l, $c, $h] = self::hexToOklch($hex);
        for ($i = 0; $i < 80; $i++) {
            $ok = true;
            foreach ($floors as $bg => $min) {
                if (SceneBudgetLinter::contrastRatio($hex, (string) $bg) < $min) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $hex;
            }
            $l = max(0.0, min(1.0, $l + 0.012 * $dir));
            $hex = self::oklchHex($l, $c, $h);
        }

        return $hex;
    }

    private static function paletteLabel(string $family, float $bgHue, float $accentHue): string
    {
        $acc = self::hueName($accentHue);
        $field = match ($family) {
            'night' => 'Night',
            'tinted_night' => self::hueName($bgHue) . ' Night',
            'deep_field' => 'Deep ' . self::hueName($bgHue),
            'paper' => 'Paper',
            default => self::hueName($bgHue) . ' Mist',
        };

        return "{$acc} on {$field}";
    }

    private static function hueName(float $h): string
    {
        $h = fmod($h + 360, 360);
        $names = [
            [15, 'Rose'], [40, 'Coral'], [65, 'Tangerine'], [95, 'Amber'], [115, 'Citrus'],
            [140, 'Lime'], [165, 'Green'], [190, 'Teal'], [215, 'Cyan'], [245, 'Sky'],
            [270, 'Cobalt'], [295, 'Indigo'], [320, 'Violet'], [345, 'Magenta'], [361, 'Rose'],
        ];
        foreach ($names as [$max, $name]) {
            if ($h < $max) {
                return $name;
            }
        }

        return 'Rose';
    }

    private static function validPalette(mixed $p): bool
    {
        if (!is_array($p)) {
            return false;
        }
        foreach (['bg_from', 'bg_to', 'accent', 'accent2', 'text', 'muted', 'panel'] as $k) {
            if (!preg_match('/^#[0-9A-F]{6}$/i', (string) ($p[$k] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    // ---- colour maths (Björn Ottosson's OKLab) -----------------------

    /** OKLCH → #RRGGBB, chroma reduced until the colour is inside sRGB. */
    public static function oklchHex(float $l, float $c, float $h): string
    {
        $l = max(0.0, min(1.0, $l));
        $c = max(0.0, $c);
        for ($i = 0; $i < 60; $i++) {
            $rgb = self::oklabToLinearSrgb($l, $c * cos(deg2rad($h)), $c * sin(deg2rad($h)));
            if (min($rgb) >= -0.0001 && max($rgb) <= 1.0001) {
                break;
            }
            $c = max(0.0, $c - 0.004);
        }
        $out = '#';
        foreach ($rgb as $v) {
            $v = max(0.0, min(1.0, $v));
            $s = $v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055;
            $out .= str_pad(strtoupper(dechex((int) round($s * 255))), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /** @return array{0: float, 1: float, 2: float} [L, C, h°] */
    public static function hexToOklch(string $hex): array
    {
        $h = ltrim($hex, '#');
        $lin = [];
        foreach ([0, 2, 4] as $i) {
            $s = hexdec(substr($h, $i, 2)) / 255;
            $lin[] = $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }
        [$r, $g, $b] = $lin;
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        $l_ = $l ** (1 / 3);
        $m_ = $m ** (1 / 3);
        $s_ = $s ** (1 / 3);
        $L = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
        $A = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
        $B = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

        return [$L, sqrt($A * $A + $B * $B), fmod(rad2deg(atan2($B, $A)) + 360, 360)];
    }

    /** @return array{0: float, 1: float, 2: float} linear sRGB */
    private static function oklabToLinearSrgb(float $L, float $a, float $b): array
    {
        $l_ = $L + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $L - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $L - 0.0894841775 * $a - 1.2914855480 * $b;
        $l = $l_ ** 3;
        $m = $m_ ** 3;
        $s = $s_ ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    // ------------------------------------------------------------------
    // Type
    // ------------------------------------------------------------------

    /** @return array{display: array, body: array, mono: array} */
    public static function fontLibrary(): array
    {
        $lib = ExplainerRegistry::all()['style_recipe']['fonts'] ?? [];

        return [
            'display' => is_array($lib['display'] ?? null) ? $lib['display'] : [],
            'body' => is_array($lib['body'] ?? null) ? $lib['body'] : [],
            'mono' => is_array($lib['mono'] ?? null) ? $lib['mono'] : [],
        ];
    }

    private function drawFonts(array $ctx): ?array
    {
        $lib = self::fontLibrary();
        if ($lib['display'] === [] || $lib['body'] === [] || $lib['mono'] === []) {
            return null;
        }

        $tagWeights = match ($ctx['character']) {
            'classic' => ['serif' => 4, 'slab' => 3, 'elegant' => 2, 'grotesque' => 1, 'condensed' => 0.8],
            'tech' => ['tech' => 4, 'grotesque' => 2, 'geometric' => 1.6, 'condensed' => 0.8, 'expressive' => 0.5],
            default => ['geometric' => 2, 'grotesque' => 2, 'expressive' => 2, 'condensed' => 1.2, 'serif' => 1, 'slab' => 0.6, 'elegant' => 1],
        };
        $moodBoost = match ($ctx['mood']) {
            'upbeat' => ['expressive' => 1.6, 'wide' => 1.3],
            'calm', 'inspirational' => ['geometric' => 1.4, 'elegant' => 1.6, 'serif' => 1.2],
            'dramatic', 'suspense' => ['serif' => 1.4, 'condensed' => 1.4],
            'tense' => ['condensed' => 1.4, 'tech' => 1.3],
            default => [],
        };

        $weights = [];
        foreach ($lib['display'] as $key => $meta) {
            $w = 0.3;
            foreach ((array) ($meta['tags'] ?? []) as $tag) {
                $w = max($w, (float) ($tagWeights[$tag] ?? 0.3));
            }
            foreach ((array) ($meta['tags'] ?? []) as $tag) {
                $w *= (float) ($moodBoost[$tag] ?? 1);
            }
            $weights[$key] = $w;
        }
        $display = $this->weighted('font_display', $weights);

        // Body is always a quiet sans and never the display family itself — a
        // pairing, not a single face at two weights.
        $bodies = array_values(array_filter(array_keys($lib['body']), fn ($k) => $k !== $display));
        $body = $this->pick('font_body', $bodies);

        $monoWeights = [];
        foreach (array_keys($lib['mono']) as $key) {
            $monoWeights[$key] = $ctx['character'] === 'tech'
                && in_array($key, ['jetbrains-mono', 'ibm-plex-mono', 'geist-mono', 'fira-code', 'roboto-mono'], true) ? 2 : 1;
        }

        return ['display' => (string) $display, 'body' => (string) $body, 'mono' => (string) $this->weighted('font_mono', $monoWeights)];
    }

    // ------------------------------------------------------------------
    // Motion
    // ------------------------------------------------------------------

    private function drawMotion(array $ctx): array
    {
        $styles = ExplainerRegistry::motionStyles();
        $base = isset($styles[$ctx['motion_base']]) ? $ctx['motion_base'] : 'crisp';
        $def = $styles[$base] ?? [];
        $smooth = in_array($base, ['classic', 'elegant'], true);

        $ease = (string) ($def['ease'] ?? 'expo_out');
        if ($this->u('ease_keep') >= ($base === 'bounce' ? 0.7 : 0.55)) {
            $ease = $this->pick('ease', $smooth
                ? ['sine_in_out', 'quint_in_out', 'cubic_out']
                : ['expo_out', 'quint_out', 'cubic_out']);
        }

        $overshoot = match ($base) {
            'bounce' => $this->range('overshoot', 0.04, 0.08),
            'crisp' => $this->range('overshoot', 0.0, 0.035),
            'swiss' => $this->u('overshoot') < 0.7 ? 0.0 : 0.012,
            default => $this->u('overshoot') < 0.85 ? 0.0 : 0.01,
        };
        if ($ease === 'spring_pop') {
            $overshoot = max($overshoot, 0.04);
        }

        $entrances = [
            'crisp' => ['rise_mask', 'pop_rise', 'column_snap', 'fade_settle'],
            'classic' => ['fade_settle', 'tracking_in', 'rise_mask'],
            'bounce' => ['pop_rise', 'rise_mask'],
            'elegant' => ['tracking_in', 'fade_settle', 'rise_mask'],
            'swiss' => ['column_snap', 'rise_mask'],
        ][$base] ?? ['rise_mask'];
        $entrance = $this->u('entrance_keep') < 0.55 ? (string) ($def['entrance'] ?? $entrances[0]) : $this->pick('entrance', $entrances);

        $underlineBias = in_array($base, ['classic', 'elegant', 'swiss'], true) ? 0.65 : 0.4;

        return [
            'base' => $base,
            'tempo' => round($this->range('tempo', 0.84, 1.2), 2),
            'stagger' => (int) $this->pick('stagger', [-1, 0, 0, 1, 1, 2]),
            'ease' => $ease,
            'overshoot' => round($overshoot, 3),
            'entrance' => $entrance,
            'highlight' => $this->u('highlight') < $underlineBias ? 'underline' : 'sweep',
            'exit_ratio' => round(max(0.4, min(0.8, (float) ($def['exit_ratio'] ?? 0.6) + $this->range('exit', -0.1, 0.1))), 2),
        ];
    }

    private static function motionLabel(array $m, string $baseLabel): string
    {
        $tempo = (float) ($m['tempo'] ?? 1);
        $pace = $tempo < 0.93 ? 'brisk' : ($tempo > 1.08 ? 'unhurried' : 'even');

        return "{$baseLabel}, {$pace}";
    }

    // ------------------------------------------------------------------
    // Cuts and backdrop
    // ------------------------------------------------------------------

    private function drawSignatures(string $base): array
    {
        $options = ExplainerRegistry::all()['style_recipe']['signature_options'] ?? [];
        $house = ExplainerRegistry::relationSignatures();
        $affinity = match ($base) {
            'swiss' => ['column_reveal' => 2, 'line_sweep' => 2, 'mask_wipe_diagonal' => 2, 'wipe' => 1.6],
            'bounce' => ['whip_pan' => 2, 'push_left' => 1.8, 'push_right' => 1.8, 'push_up' => 1.8, 'zoom_through' => 2],
            'classic', 'elegant' => ['match_dissolve' => 2, 'fade' => 2, 'wipe' => 1.6, 'zoom_out_in' => 2],
            default => ['mask_wipe_diagonal' => 1.5, 'stack_push' => 1.5, 'split_slide' => 1.5],
        };

        $out = [];
        $used = [];
        foreach ($options as $relation => $cuts) {
            $cuts = array_values(array_filter((array) $cuts, fn ($t) => in_array($t, ExplainerRegistry::transitions(), true)));
            if ($cuts === []) {
                continue;
            }
            $weights = [];
            foreach ($cuts as $t) {
                $w = (float) ($affinity[$t] ?? 1);
                // Two relations sharing one cut blur the grammar; allowed, but rare.
                if (in_array($t, $used, true)) {
                    $w *= 0.15;
                }
                // Keep the house signature in the mix — it is the best fit, it
                // just must not be EVERY video's fit.
                if (($house[$relation] ?? null) === $t) {
                    $w *= 1.3;
                }
                $weights[$t] = $w;
            }
            $pick = $this->weighted("cut_{$relation}", $weights);
            $out[$relation] = $pick;
            $used[] = $pick;
        }
        $out['opening'] = 'none';

        return $out;
    }

    private function drawBackdrop(): array
    {
        $kinds = ExplainerRegistry::all()['style_recipe']['backdrop_kinds'] ?? [];
        $out = ['kinds' => []];
        foreach (['order', 'drive', 'buoyant'] as $group) {
            $menu = array_values((array) ($kinds[$group] ?? []));
            if ($menu !== []) {
                $out['kinds'][$group] = (string) $this->pick("backdrop_{$group}", $menu);
            }
        }
        $out['scale'] = round($this->range('backdrop_scale', 0.8, 1.35), 2);
        $out['drift'] = $this->u('backdrop_drift') < 0.5 ? -1 : 1;

        return $out;
    }

    // ------------------------------------------------------------------
    // Deterministic draws: one independent stream per decision, so adding a
    // decision later never reshuffles the ones before it.
    // ------------------------------------------------------------------

    private function u(string $stream): float
    {
        $n = $this->counters[$stream] = ($this->counters[$stream] ?? 0) + 1;

        return hexdec(substr(hash('sha256', "{$this->seed}|{$stream}|{$n}"), 0, 12)) / 281474976710656;
    }

    private function range(string $stream, float $a, float $b): float
    {
        return $a + ($b - $a) * $this->u($stream);
    }

    /**
     * A hue drawn uniformly over the union of some bands (degrees; a band may
     * run past 360 to wrap through red).
     *
     * @param  array<int, array{0: float|int, 1: float|int}>  $bands
     */
    private function bandHue(string $stream, array $bands): float
    {
        $total = array_sum(array_map(fn ($b) => $b[1] - $b[0], $bands));
        $x = $this->u($stream) * $total;
        foreach ($bands as [$lo, $hi]) {
            if ($x < $hi - $lo) {
                return fmod($lo + $x, 360);
            }
            $x -= $hi - $lo;
        }

        return (float) $bands[0][0];
    }

    private static function hueDistance(float $a, float $b): float
    {
        $d = abs(fmod($a - $b + 720, 360));

        return min($d, 360 - $d);
    }

    private function pick(string $stream, array $items): mixed
    {
        $items = array_values($items);

        return $items[min(count($items) - 1, (int) floor($this->u($stream) * count($items)))];
    }

    /** @param  array<string, float|int>  $weights */
    private function weighted(string $stream, array $weights): string
    {
        $total = array_sum($weights);
        $x = $this->u($stream) * $total;
        foreach ($weights as $key => $w) {
            $x -= $w;
            if ($x < 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    private static function normaliseCtx(array $ctx): array
    {
        return [
            'mood' => (string) ($ctx['mood'] ?? 'neutral'),
            'dark' => isset($ctx['dark']) ? (bool) $ctx['dark'] : null,
            'character' => in_array($ctx['character'] ?? null, ['editorial', 'classic', 'tech'], true) ? $ctx['character'] : 'editorial',
            'motion_base' => (string) ($ctx['motion_base'] ?? 'crisp'),
            'math' => (bool) ($ctx['math'] ?? false),
        ];
    }
}
