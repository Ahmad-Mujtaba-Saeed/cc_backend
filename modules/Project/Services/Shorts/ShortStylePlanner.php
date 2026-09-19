<?php

namespace Modules\Project\Services\Shorts;

/**
 * Gives every short in a batch its own edit.
 *
 * YouTube's inauthentic/repetitive-content policy targets channels that ship
 * the same template over and over, so a batch must not look mass-produced.
 * Each short gets a FAMILY (the edit's personality: meme chaos, clean
 * podcast, storyteller, ...) chosen to suit its footage, and then a set of
 * VARIATIONS inside that family (accent colour, caption position, words per
 * line, animation, font, hook look) that are dealt so no two shorts in the
 * batch share a combination.
 *
 * The returned style is the renderer's ShortStyle (remotion-render
 * src/shorts/types.ts) plus a `directives` block the edit director reads.
 */
class ShortStylePlanner
{
    public const FAMILY_LABELS = [
        'meme_chaos' => 'Meme chaos',
        'hormozi' => 'Bold captions',
        'podcast_clean' => 'Clean podcast',
        'neon_gamer' => 'Neon gamer',
        'storyteller' => 'Storyteller',
        'comic_pop' => 'Comic pop',
        'news_flash' => 'News flash',
        'minimal' => 'Minimal',
    ];


    /** Which families suit which footage (higher = better). */
    private const AFFINITY = [
        'podcast' => ['podcast_clean' => 3, 'hormozi' => 3, 'storyteller' => 2, 'minimal' => 2, 'comic_pop' => 1, 'meme_chaos' => 1, 'news_flash' => 1],
        'interview' => ['podcast_clean' => 3, 'hormozi' => 2, 'storyteller' => 2, 'news_flash' => 2, 'minimal' => 2],
        'talking_head' => ['hormozi' => 3, 'storyteller' => 2, 'minimal' => 2, 'news_flash' => 2, 'meme_chaos' => 1, 'comic_pop' => 1],
        'gameplay_facecam' => ['meme_chaos' => 3, 'neon_gamer' => 3, 'comic_pop' => 2, 'hormozi' => 1],
        'gameplay' => ['neon_gamer' => 3, 'meme_chaos' => 3, 'comic_pop' => 2],
        'reaction' => ['meme_chaos' => 3, 'comic_pop' => 3, 'neon_gamer' => 1, 'hormozi' => 1],
        'vlog' => ['meme_chaos' => 2, 'comic_pop' => 2, 'storyteller' => 2, 'hormozi' => 1],
        'sports' => ['meme_chaos' => 3, 'news_flash' => 2, 'hormozi' => 2],
        'screen_recording' => ['news_flash' => 3, 'minimal' => 2, 'podcast_clean' => 2, 'hormozi' => 1],
        'presentation' => ['storyteller' => 2, 'news_flash' => 2, 'podcast_clean' => 2, 'minimal' => 2],
        'other' => ['hormozi' => 2, 'storyteller' => 2, 'comic_pop' => 1, 'minimal' => 1, 'meme_chaos' => 1],
    ];

    private const MOOD_BONUS = [
        'funny' => ['meme_chaos' => 2, 'comic_pop' => 2],
        'hype' => ['meme_chaos' => 2, 'neon_gamer' => 1, 'hormozi' => 1],
        'tense' => ['meme_chaos' => 1, 'storyteller' => 1],
        'emotional' => ['storyteller' => 2, 'minimal' => 1],
        'serious' => ['podcast_clean' => 1, 'news_flash' => 1, 'minimal' => 1],
        'educational' => ['hormozi' => 1, 'news_flash' => 1, 'podcast_clean' => 1],
        'chill' => ['minimal' => 2, 'storyteller' => 1],
    ];

    /**
     * @param  array<int, array<string, mixed>>  $analyses  one per short, in order
     * @param  string  $mode  'auto_mix' or a family id
     * @return array<int, array<string, mixed>>
     */
    public function assign(array $analyses, string $mode, int $seed): array
    {
        mt_srand($seed);
        $uses = array_fill_keys(array_keys(self::FAMILY_LABELS), 0);
        $signatures = [];
        $recentAccents = [];
        $recentY = [];
        $styles = [];

        foreach (array_values($analyses) as $i => $analysis) {
            $family = array_key_exists($mode, self::FAMILY_LABELS)
                ? $mode
                : $this->pickFamily($analysis, $uses);
            $uses[$family]++;

            // Deal variations until this combination is new in the batch.
            $style = null;
            for ($try = 0; $try < 12; $try++) {
                $candidate = $this->build($family, $recentAccents, $recentY, $analysis);
                $sig = $candidate['signature'];
                if (!isset($signatures[$sig])) {
                    $style = $candidate;
                    break;
                }
            }
            $style ??= $candidate;
            $signatures[$style['signature']] = true;
            $recentAccents = array_slice(array_merge([$style['accent']], $recentAccents), 0, 3);
            $recentY = array_slice(array_merge([$style['caption']['y']], $recentY), 0, 2);
            $style['id'] = $family . '_' . ($i + 1);
            $styles[] = $style;
        }

        return $styles;
    }

    /**
     * The look for the next short — never one already used in this batch until
     * every look has been.
     *
     * The penalty used to be a soft -2.6, and it did not hold: on a vlog only
     * four families score above zero at all, so a strong favourite could still
     * out-score a fresh family after paying it. A real 7-short run came back
     * with meme_chaos twice and comic_pop twice, which is what "it's just a few
     * templates" looks like from the outside — and two near-identical shorts
     * from one source is exactly what YouTube's repetitious-content policy is
     * looking for.
     *
     * So reuse is now LEXICOGRAPHIC: the least-used families are the only
     * candidates, and affinity merely orders them within that set. Fit still
     * decides which look a short gets; it no longer decides whether the batch
     * repeats itself.
     */
    private function pickFamily(array $analysis, array $uses): string
    {
        $type = (string) ($analysis['scene_type'] ?? 'other');
        $mood = (string) ($analysis['mood'] ?? '');
        $fewest = min($uses);

        $scores = [];
        foreach (array_keys(self::FAMILY_LABELS) as $family) {
            if ($uses[$family] > $fewest) {
                continue;
            }
            $score = (float) (self::AFFINITY[$type][$family] ?? 0);
            $score += (float) (self::MOOD_BONUS[$mood][$family] ?? 0);
            $score += mt_rand(0, 100) / 100 * 0.9;
            $scores[$family] = $score;
        }
        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function pick(array $options, array $avoid = []): mixed
    {
        $pool = array_values(array_filter($options, fn ($o) => !in_array($o, $avoid, true)));
        if (!$pool) {
            $pool = array_values($options);
        }

        return $pool[mt_rand(0, count($pool) - 1)];
    }

    /** @return array<string, mixed> */
    private function build(string $family, array $recentAccents, array $recentY, array $analysis): array
    {
        $accent = $this->accent($recentAccents);
        $twoPanel = (int) ($analysis['people_on_screen'] ?? 0) >= 2;

        // Base looks per family; `alts` are the knobs dealt per short.
        switch ($family) {
            case 'meme_chaos':
                $caption = [
                    'font' => $this->pick(['impact', 'bricolage']), 'weight' => 900, 'size' => $this->pick([98, 108, 116]),
                    'uppercase' => true, 'wordsPerLine' => $this->pick([1, 2, 2, 3]), 'color' => '#ffffff',
                    'highlight' => $accent, 'keyColor' => $this->pick(['#ff3b3b', '#39ff14', '#00e5ff']),
                    'highlightMode' => $this->pick(['color', 'scale']), 'stroke' => $this->pick([10, 14]), 'strokeColor' => '#000000',
                    'shadow' => true, 'animation' => $this->pick(['pop', 'bounce']),
                    'y' => $this->pick([0.62, 0.68, 0.74], $recentY),
                ];
                $hook = ['style' => $this->pick(['bold', 'banner', 'tape']), 'bg' => $accent, 'color' => '#111111', 'font' => 'impact', 'y' => 0.15];
                $grade = ['filter' => $this->pick(['contrast(1.1) saturate(1.25)', 'contrast(1.15) saturate(1.1)', 'saturate(1.35)']), 'vignette' => 0.35, 'tint' => null];
                $directives = ['intensity' => 3, 'sfx' => ['boom', 'record_scratch', 'bass_drop', 'pop', 'wrong', 'glitch', 'whoosh_fast', 'shutter', 'heartbeat'],
                    'beats' => ['zoom', 'zoom_big', 'shake', 'sticker', 'emoji', 'freeze', 'slowmo', 'bw', 'flash', 'glitch'],
                    'stickers' => true, 'emoji' => true, 'cut_transition' => 'flash', 'punch_on_peaks' => true];
                $progress = ['position' => $this->pick(['bottom', 'none']), 'color' => $accent, 'height' => 10];
                $drift = 1.04;
                $sticker = 'impact';
                break;

            case 'hormozi':
                $caption = [
                    'font' => $this->pick(['bricolage', 'grotesk', 'inter']), 'weight' => 900, 'size' => $this->pick([84, 92]),
                    'uppercase' => true, 'wordsPerLine' => $this->pick([2, 3]), 'color' => '#ffffff',
                    'highlight' => $this->pick(['#39ff14', '#ffe600', $accent]), 'keyColor' => $accent,
                    'highlightMode' => $this->pick(['box', 'color', 'scale']), 'stroke' => $this->pick([0, 8]), 'strokeColor' => '#000000',
                    'shadow' => true, 'animation' => $this->pick(['bounce', 'pop', 'typewriter']),
                    'y' => $twoPanel ? 0.5 : $this->pick([0.58, 0.66, 0.72], $recentY),
                ];
                $hook = ['style' => $this->pick(['bold', 'banner']), 'bg' => '#000000', 'color' => '#ffffff', 'font' => $caption['font'], 'y' => 0.14];
                $grade = ['filter' => 'contrast(1.06) saturate(1.1)', 'vignette' => 0.2, 'tint' => null];
                $directives = ['intensity' => 1, 'sfx' => ['whoosh_fast', 'pop', 'ding', 'snare', 'cash'],
                    'beats' => ['zoom', 'zoom_big', 'emoji', 'flash'], 'stickers' => false, 'emoji' => true,
                    'cut_transition' => 'zoom', 'punch_on_peaks' => true];
                $progress = ['position' => $this->pick(['bottom', 'top', 'none']), 'color' => $accent, 'height' => 8];
                $drift = 1.03;
                $sticker = 'bricolage';
                break;

            case 'podcast_clean':
                $caption = [
                    'font' => $this->pick(['inter', 'grotesk']), 'weight' => $this->pick([700, 800]), 'size' => $this->pick([60, 66]),
                    'uppercase' => false, 'wordsPerLine' => $this->pick([4, 5, 6]), 'color' => '#ffffff',
                    'highlight' => $accent, 'keyColor' => $accent, 'highlightMode' => $this->pick(['color', 'underline']),
                    'stroke' => 0, 'strokeColor' => '#000000', 'shadow' => false,
                    'animation' => $this->pick(['fade', 'slide', 'karaoke']),
                    'y' => $twoPanel ? 0.5 : $this->pick([0.7, 0.78], $recentY),
                    'background' => $this->pick(['rgba(0,0,0,0.62)', 'rgba(12,12,20,0.72)']),
                ];
                $hook = ['style' => 'bubble', 'bg' => $accent, 'color' => '#111111', 'font' => $caption['font'], 'y' => 0.12];
                $grade = ['filter' => $this->pick(['contrast(1.03)', 'contrast(1.05) saturate(1.05)']), 'vignette' => 0.12, 'tint' => null];
                $directives = ['intensity' => 0, 'sfx' => ['whoosh_soft', 'pop_a'], 'beats' => ['zoom'],
                    'stickers' => false, 'emoji' => false, 'cut_transition' => 'none', 'punch_on_peaks' => false];
                $progress = ['position' => $this->pick(['top', 'bottom']), 'color' => $accent, 'height' => 6];
                $drift = 1.02;
                $sticker = 'inter';
                break;

            case 'neon_gamer':
                $caption = [
                    'font' => $this->pick(['mono', 'grotesk']), 'weight' => 800, 'size' => $this->pick([76, 84]),
                    'uppercase' => true, 'wordsPerLine' => $this->pick([2, 3]), 'color' => $this->pick(['#e8fbff', '#ffffff']),
                    'highlight' => $this->pick(['#ff2bd6', '#00f0ff', '#b4ff00']), 'keyColor' => '#00f0ff',
                    'highlightMode' => $this->pick(['scale', 'color']), 'stroke' => 8, 'strokeColor' => '#07001a',
                    'shadow' => true, 'animation' => $this->pick(['slide', 'pop']),
                    'y' => $this->pick([0.6, 0.7], $recentY), 'rotate' => $this->pick([0, -2]),
                ];
                $hook = ['style' => 'tape', 'bg' => $caption['highlight'], 'color' => '#07001a', 'font' => 'mono', 'y' => 0.13];
                $grade = ['filter' => $this->pick(['saturate(1.45) contrast(1.1)', 'saturate(1.3) hue-rotate(-8deg) contrast(1.12)']), 'vignette' => 0.4, 'tint' => 'rgba(120,0,255,0.12)'];
                $directives = ['intensity' => 2, 'sfx' => ['glitch', 'bass_drop', 'whoosh_fast', 'riser', 'snare', 'boom'],
                    'beats' => ['zoom', 'zoom_big', 'shake', 'glitch', 'flash', 'sticker', 'slowmo'],
                    'stickers' => true, 'emoji' => false, 'cut_transition' => 'glitch', 'punch_on_peaks' => true];
                $progress = ['position' => 'bottom', 'color' => $caption['highlight'], 'height' => 12];
                $drift = 1.05;
                $sticker = 'mono';
                break;

            case 'storyteller':
                $caption = [
                    'font' => $this->pick(['fraunces', 'fraunces', 'inter']), 'weight' => 700, 'size' => $this->pick([70, 78]),
                    'uppercase' => false, 'wordsPerLine' => $this->pick([3, 4]), 'color' => '#fff6e5',
                    'highlight' => $this->pick(['#ffb347', '#ffd166', $accent]), 'keyColor' => '#ffb347',
                    'highlightMode' => $this->pick(['underline', 'color']), 'stroke' => 0, 'strokeColor' => '#000000',
                    'shadow' => true, 'animation' => $this->pick(['typewriter', 'fade']),
                    'y' => $twoPanel ? 0.5 : $this->pick([0.66, 0.74], $recentY),
                ];
                $hook = ['style' => $this->pick(['bubble', 'banner']), 'bg' => '#fff6e5', 'color' => '#2b1a0e', 'font' => 'fraunces', 'y' => 0.14];
                $grade = ['filter' => $this->pick(['sepia(0.18) contrast(1.05) saturate(1.05)', 'contrast(1.06) saturate(0.95)']), 'vignette' => 0.45, 'tint' => 'rgba(255,170,80,0.10)'];
                $directives = ['intensity' => 1, 'sfx' => ['whoosh_soft', 'riser', 'heartbeat', 'chime'],
                    'beats' => ['zoom', 'slowmo', 'bw'], 'stickers' => false, 'emoji' => false,
                    'cut_transition' => 'none', 'punch_on_peaks' => false];
                $progress = ['position' => 'none', 'color' => '#ffb347', 'height' => 6];
                $drift = 1.07;
                $sticker = 'fraunces';
                break;

            case 'comic_pop':
                $caption = [
                    'font' => 'bricolage', 'weight' => 900, 'size' => $this->pick([88, 96]),
                    'uppercase' => true, 'wordsPerLine' => $this->pick([2, 3]), 'color' => '#ffffff',
                    'highlight' => $this->pick(['#ffe600', '#ff7ad9', '#6cf7ff']), 'keyColor' => '#ff7ad9',
                    'highlightMode' => 'box', 'stroke' => 12, 'strokeColor' => '#1b1b1b',
                    'shadow' => true, 'animation' => 'bounce',
                    'y' => $this->pick([0.64, 0.72], $recentY), 'rotate' => $this->pick([-2, 0, 2]),
                ];
                $hook = ['style' => 'bubble', 'bg' => $caption['highlight'], 'color' => '#111111', 'font' => 'bricolage', 'y' => 0.15];
                $grade = ['filter' => 'saturate(1.3) contrast(1.05)', 'vignette' => 0.15, 'tint' => null];
                $directives = ['intensity' => 2, 'sfx' => ['boing', 'pop', 'ding', 'tada', 'wrong', 'swipe'],
                    'beats' => ['zoom', 'zoom_big', 'emoji', 'sticker', 'shake', 'freeze'],
                    'stickers' => true, 'emoji' => true, 'cut_transition' => 'zoom', 'punch_on_peaks' => true];
                $progress = ['position' => $this->pick(['bottom', 'none']), 'color' => $caption['highlight'], 'height' => 10];
                $drift = 1.03;
                $sticker = 'bricolage';
                break;

            case 'news_flash':
                $red = $this->pick(['#e11d2a', '#ff3b30', $accent]);
                $caption = [
                    'font' => 'grotesk', 'weight' => 700, 'size' => $this->pick([68, 74]),
                    'uppercase' => true, 'wordsPerLine' => $this->pick([3, 4]), 'color' => '#ffffff',
                    'highlight' => $red, 'keyColor' => '#ffd400', 'highlightMode' => 'box',
                    'stroke' => 0, 'strokeColor' => '#000000', 'shadow' => false, 'animation' => $this->pick(['slide', 'fade']),
                    'y' => $this->pick([0.72, 0.8], $recentY), 'background' => 'rgba(0,0,0,0.7)',
                ];
                $hook = ['style' => 'banner', 'bg' => $red, 'color' => '#ffffff', 'font' => 'grotesk', 'y' => 0.1];
                $grade = ['filter' => 'contrast(1.08)', 'vignette' => 0.2, 'tint' => null];
                $directives = ['intensity' => 1, 'sfx' => ['swipe', 'ding', 'shutter', 'whoosh_fast'],
                    'beats' => ['zoom', 'flash', 'sticker'], 'stickers' => true, 'emoji' => false,
                    'cut_transition' => 'flash', 'punch_on_peaks' => false];
                $progress = ['position' => 'top', 'color' => $red, 'height' => 10];
                $drift = 1.02;
                $sticker = 'grotesk';
                $accent = $red;
                break;

            case 'minimal':
            default:
                $family = 'minimal';
                $caption = [
                    'font' => $this->pick(['grotesk', 'inter']), 'weight' => 600, 'size' => $this->pick([54, 60]),
                    'uppercase' => false, 'lowercase' => $this->pick([true, false]), 'wordsPerLine' => $this->pick([4, 5]),
                    'color' => '#ffffff', 'highlight' => $this->pick(['#ffffff', $accent]), 'keyColor' => '#ffffff',
                    'highlightMode' => $this->pick(['none', 'color']), 'stroke' => 0, 'strokeColor' => '#000000',
                    'shadow' => true, 'animation' => 'fade',
                    'y' => $twoPanel ? 0.5 : $this->pick([0.8, 0.84], $recentY),
                ];
                $hook = ['style' => $this->pick(['none', 'bubble']), 'bg' => '#ffffff', 'color' => '#111111', 'font' => $caption['font'], 'y' => 0.12];
                $grade = ['filter' => $this->pick(['contrast(1.04) saturate(0.9)', 'grayscale(0.15) contrast(1.05)']), 'vignette' => 0.3, 'tint' => null];
                $directives = ['intensity' => 0, 'sfx' => [], 'beats' => ['zoom'], 'stickers' => false, 'emoji' => false,
                    'cut_transition' => 'none', 'punch_on_peaks' => false];
                $progress = ['position' => 'none', 'color' => '#ffffff', 'height' => 4];
                $drift = 1.03;
                $sticker = 'grotesk';
                break;
        }

        $caption += ['lowercase' => false, 'background' => null, 'rotate' => 0];

        [$caption, $hook, $grade] = $this->humanize($caption, $hook, $grade, $accent, $family);
        // Never a progress bar: the same strip on every short is a platform
        // fingerprint (the user spotted it first), and no human editor adds one.
        $progress = ['position' => 'none', 'color' => $accent, 'height' => 0];

        // How close the camera sits on the subject, and which way it drifts.
        //
        // Every other knob here is PAINT — a font, a colour, where the caption
        // sits. This one is the only one that changes the pixels of the
        // footage itself, and that matters twice over: two shorts framed
        // differently read as two edits rather than one edit with two fonts,
        // and a duplicate-content check comparing frames sees two different
        // videos. It multiplies across every family, so it is worth more
        // variety than any single new look would be.
        $framing = [
            'tightness' => $this->pick([0.80, 0.88, 1.0, 1.0, 1.12]),
            'drift_to' => $this->pick(['in', 'in', 'out', 'hold']),
        ];
        if ($framing['drift_to'] === 'out') {
            $drift = round(2 - $drift, 4);      // start close, ease wider
        } elseif ($framing['drift_to'] === 'hold') {
            $drift = 1.0;
        }

        $signature = implode('|', [
            $family, $accent, $caption['font'], $caption['wordsPerLine'], $caption['animation'],
            $caption['highlightMode'], $caption['y'], $caption['size'], $hook['style'], $hook['align'] ?? 'center', $grade['filter'],
            $framing['tightness'], $framing['drift_to'],
        ]);

        return [
            'family' => $family,
            'name' => self::FAMILY_LABELS[$family],
            'caption' => $caption,
            'hook' => $hook,
            'grade' => $grade,
            'progress' => $progress,
            'sfxVolume' => $directives['intensity'] >= 2 ? 0.9 : 0.6,
            'accent' => $accent,
            'drift' => $drift,
            'framing' => $framing,
            'stickerFont' => $sticker,
            'directives' => $directives,
            'signature' => $signature,
        ];
    }

    /**
     * An accent from the whole colour wheel, not a list of eight. A batch drawn
     * from eight swatches repeats them within a few shorts, and a channel that
     * always uses the same yellow reads as a template. Kept away (±40°) from
     * the last few hues so neighbours still look different.
     */
    private function accent(array $recent): string
    {
        $recentHues = array_map(fn ($hex) => $this->hue($hex), $recent);
        for ($try = 0; $try < 20; $try++) {
            $h = mt_rand(0, 359);
            $clear = true;
            foreach ($recentHues as $rh) {
                $d = abs($h - $rh);
                if (min($d, 360 - $d) < 40) {
                    $clear = false;
                    break;
                }
            }
            if ($clear) {
                break;
            }
        }

        return self::hsl($h, mt_rand(78, 100) / 100, mt_rand(52, 62) / 100);
    }

    /**
     * What makes a set of edits look like a PERSON made them rather than one
     * template: nothing sits in exactly the same place, at exactly the same
     * size, twice. Every value that was a fixed number becomes a small
     * continuous range, dealt per short, and the opening is not always a
     * designed title card — people editing on a phone use the app's own text
     * box, plain white text, or no hook at all.
     */
    private function humanize(array $caption, array $hook, array $grade, string $accent, string $family): array
    {
        $jit = fn (float $v, float $pct) => $v * (1 + (mt_rand(-1000, 1000) / 1000) * $pct);

        $caption['size'] = (int) round($jit((float) $caption['size'], 0.08));
        if ((float) $caption['y'] !== 0.5) {   // 0.5 is a seam; leave it on the seam
            $caption['y'] = round(max(0.5, min(0.86, $caption['y'] + mt_rand(-25, 25) / 1000)), 3);
        }
        if (!empty($caption['stroke'])) {
            $caption['stroke'] = max(4, (int) $caption['stroke'] + mt_rand(-2, 2));
        }

        // How the short opens.
        $roll = mt_rand(1, 100);
        $designed = !in_array($family, ['minimal', 'podcast_clean'], true);
        if ($roll <= ($designed ? 45 : 25)) {
            // the family's own designed card, as dealt
        } elseif ($roll <= 72) {
            $dark = mt_rand(0, 1) === 1;
            $hook = ['style' => 'native', 'bg' => $dark ? '#000000' : ($roll % 3 === 0 ? $accent : '#ffffff'),
                'color' => $dark ? '#ffffff' : '#111111', 'font' => $this->pick(['inter', 'grotesk']), 'y' => $hook['y']];
        } elseif ($roll <= 92) {
            $hook = ['style' => 'plain', 'bg' => '#000000', 'color' => '#ffffff', 'font' => $this->pick(['inter', 'grotesk', $hook['font']]), 'y' => $hook['y']];
        } else {
            $hook['style'] = 'none';   // cold open: straight into the moment
        }
        $casual = in_array($hook['style'], ['native', 'plain'], true);
        $hook['y'] = round(max(0.07, min(0.3, $hook['y'] + mt_rand(-30, 45) / 1000)), 3);
        $hook['scale'] = round($jit(1.0, 0.1), 3);
        $hook['rotate'] = $casual && mt_rand(0, 1) ? mt_rand(-40, 40) / 10 : 0;
        $hook['align'] = $casual && mt_rand(1, 100) <= 30 ? 'left' : 'center';

        // A grade no other short in the batch has exactly: the footage's own
        // pixels differ, not just the paint on top of them.
        $grade['filter'] = trim($grade['filter'] . sprintf(
            ' brightness(%.3f) hue-rotate(%ddeg)',
            1 + mt_rand(-30, 40) / 1000,
            mt_rand(-5, 5)
        ));
        $grade['vignette'] = round(max(0, $grade['vignette'] + mt_rand(-8, 8) / 100), 2);

        return [$caption, $hook, $grade];
    }

    private function hue(string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return 0;
        }
        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split($hex, 2));
        $max = max($r, $g, $b);
        $d = $max - min($r, $g, $b);
        if ($d == 0) {
            return 0;
        }
        $h = match ($max) {
            $r => fmod(($g - $b) / $d, 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return (int) round(fmod($h * 60 + 360, 360));
    }

    public static function hsl(int $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (intdiv($h % 360, 60)) {
            0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x],
            3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x],
        };

        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }

    /** The renderer's ShortStyle (drops planner-only keys). */
    public static function forRenderer(array $style): array
    {
        return [
            'id' => (string) ($style['id'] ?? $style['family']),
            'name' => (string) $style['name'],
            'caption' => $style['caption'],
            'hook' => $style['hook'],
            'grade' => $style['grade'],
            'progress' => $style['progress'],
            'sfxVolume' => (float) $style['sfxVolume'],
            'accent' => (string) $style['accent'],
            'drift' => (float) $style['drift'],
            'stickerFont' => (string) $style['stickerFont'],
        ];
    }
}
