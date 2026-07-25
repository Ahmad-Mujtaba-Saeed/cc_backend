<?php

namespace Modules\Project\Support;

/**
 * SceneBudgetLinter (copilot.md §12.1–12.3) — cheap insurance.
 *
 * A pure, read-only audit of a validated storyboard: everything the
 * ShotListValidator could safely AUTO-FIX has already been fixed by the time
 * this runs, so surviving findings are surfaced as a report instead —
 * written to settings['lint_report'] at analyze completion and again
 * pre-render, and shown on the storyboard with severity chips. It never
 * blocks anything.
 *
 * Checks:
 *  - hook present (§10.1) / outro present (§10.2)
 *  - min text per scene (§7.2 leftovers)
 *  - static-hold cap: >8s with nothing progressive on screen (§7.6)
 *  - same-template streak ≤ 2 / ≥1 peak card per 45s window (§5)
 *  - theme contrast: text-on-panel and text-on-paper ≥ 4.5:1 (§12.2)
 *  - narration/visual sync: numbers + proper nouns spoken but never shown (§12.3)
 */
class SceneBudgetLinter
{
    /** Templates whose whole design is sparse type — exempt from word counts. */
    private const SPARSE_EXEMPT = [
        'stat_spotlight', 'quote_card', 'big_counter', 'chapter_cover', 'outro_card',
        'myth_fact', 'pictogram_percent', 'formula_anatomy', 'term_card', 'practice_card', 'common_mistake',
    ];

    /** The native math cards — they draw their own figures and may chain. */
    private const MATH_CARDS = ['math_steps', 'geometry_diagram', 'function_plot'];

    /** Templates that are intrinsically animated (never a static hold). */
    private const ANIMATED_CARDS = [
        'versus_card', 'animated_chart', 'big_counter', 'checklist_card', 'icon_grid',
        'timeline_card', 'step_flow', 'before_after', 'list_ranking', 'progress_meter',
        'quote_portrait', 'phone_mockup', 'photo_stack', 'map_card', 'headline_ticker',
        'labeled_diagram', 'myth_fact', 'pictogram_percent', 'cycle_diagram', 'spectrum_card', 'quadrant_map', 'proportion_flow', 'scale_comparison', 'evidence_card', 'layer_stack', 'hierarchy_card', 'venn_card', 'term_card', 'receipt_card', 'decision_tree', 'practice_card', 'common_mistake',
        'math_steps', 'geometry_diagram', 'function_plot', 'scenario_diagram', 'formula_anatomy',
        'chapter_cover', 'outro_card',
    ];

    /**
     * @param  array<int, array>  $scenes  Validated scenes (storyboard order).
     * @param  array  $options  ['theme' => resolved scheme array,
     *                           'hook_enabled' => bool, 'outro_enabled' => bool]
     * @return array{items: array<int, array{severity: string, code: string, scene_id: ?string, message: string}>,
     *               counts: array{error: int, warn: int, info: int},
     *               checked_at: string}
     */
    public static function lint(array $scenes, array $options = []): array
    {
        $items = [];

        if (!empty($scenes)) {
            if (($options['hook_enabled'] ?? true) !== false) {
                $items = array_merge($items, self::checkHook($scenes[0]));
            }
            if (($options['outro_enabled'] ?? true) !== false) {
                $items = array_merge($items, self::checkOutro($scenes));
            }
            $items = array_merge(
                $items,
                self::checkMinText($scenes),
                self::checkStaticHolds($scenes),
                self::checkStreaks($scenes),
                self::checkPeakPacing($scenes),
                self::checkSyncAudit($scenes),
                self::checkFlatPacing($scenes),
                self::checkEvidence($scenes)
            );
            if (($options['math_mode'] ?? false) === true) {
                $items = array_merge(
                    $items,
                    self::checkMathVisual($scenes),
                    self::checkStepLockstep($scenes),
                    self::checkUnitsSurvival($scenes)
                );
            }
        }

        if (is_array($options['theme'] ?? null)) {
            $items = array_merge($items, self::checkContrast($options['theme']));
        }

        $counts = ['error' => 0, 'warn' => 0, 'info' => 0];
        foreach ($items as $item) {
            $counts[$item['severity']] = ($counts[$item['severity']] ?? 0) + 1;
        }

        return [
            'items' => array_values($items),
            'counts' => $counts,
            'checked_at' => date('c'),
        ];
    }

    private static function item(string $severity, string $code, ?string $sceneId, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'scene_id' => $sceneId, 'message' => $message];
    }

    /**
     * Retention pass (roadmap 3b): a run of same-template, media-free text
     * scenes is a flat span. The original rule warned on ANY 2-scene span
     * over 20s, but its own message ("nothing new on screen") was false for
     * scenes running sequential bullet reveals — new lines keep landing —
     * which made the warning structurally unresolvable on every proof-shaped
     * math video (hook + claim are both text by design; project 91). Now a
     * span warns when it is genuinely flat: more than 12s of it holds with
     * NO progressive reveal, or three-plus same cards run past 20s (that
     * monotony reads even when the bullets move).
     */
    private static function checkFlatPacing(array $scenes): array
    {
        $items = [];
        $spanSeconds = 0.0;
        $staticSeconds = 0.0;
        $spanStartId = null;
        $spanCount = 0;
        $prevTpl = null;

        $flush = function () use (&$items, &$spanSeconds, &$staticSeconds, &$spanStartId, &$spanCount): void {
            if (($spanCount >= 3 && $spanSeconds > 20.0) || ($spanCount >= 2 && $staticSeconds > 12.0)) {
                $items[] = self::item('warn', 'flat_pacing', $spanStartId, sprintf(
                    '%d same-template text scenes hold %.0fs (%.0fs with no reveal) — break the span with a data card, an image beat, or a merge.',
                    $spanCount,
                    $spanSeconds,
                    $staticSeconds
                ));
            }
            $spanSeconds = 0.0;
            $staticSeconds = 0.0;
            $spanStartId = null;
            $spanCount = 0;
        };

        foreach ($scenes as $scene) {
            $tpl = (string) ($scene['layout_template'] ?? '');
            $flat = !self::sceneHasMedia($scene)
                && in_array($tpl, ['single_focus', 'split_top_bottom', 'full_bleed_with_banner'], true);

            if ($flat && ($prevTpl === null || $tpl === $prevTpl || $spanCount === 0)) {
                if ($spanCount === 0) {
                    $spanStartId = (string) ($scene['scene_id'] ?? '');
                }
                $spanSeconds += (float) ($scene['duration_seconds'] ?? 0);
                if (!self::hasProgressiveReveal($scene)) {
                    $staticSeconds += (float) ($scene['duration_seconds'] ?? 0);
                }
                $spanCount++;
            } else {
                $flush();
                if ($flat) {
                    $spanStartId = (string) ($scene['scene_id'] ?? '');
                    $spanSeconds = (float) ($scene['duration_seconds'] ?? 0);
                    $staticSeconds = self::hasProgressiveReveal($scene) ? 0.0 : (float) ($scene['duration_seconds'] ?? 0);
                    $spanCount = 1;
                }
            }
            $prevTpl = $flat ? $tpl : null;
        }
        $flush();

        return $items;
    }

    /**
     * Evidence discipline (roadmap 3b): a chart is a CLAIM about the world
     * — charts that cite nothing read as invented. Info-level: the planner
     * has a source field and simply didn't use it.
     */
    private static function checkEvidence(array $scenes): array
    {
        $items = [];
        foreach ($scenes as $scene) {
            if (($scene['layout_template'] ?? '') !== 'animated_chart') {
                continue;
            }
            foreach ((array) ($scene['slots'] ?? []) as $slot) {
                if (is_array($slot) && ($slot['content_type'] ?? '') === 'chart'
                    && trim((string) ($slot['source'] ?? '')) === ''
                ) {
                    $items[] = self::item('info', 'no_source', (string) ($scene['scene_id'] ?? ''),
                        'Chart cites no source — numbers without provenance read as invented.');
                }
            }
        }

        return $items;
    }

    // -- §10.1 hook ----------------------------------------------------------

    private static function checkHook(array $first): array
    {
        if (in_array($first['layout_template'], ['stat_spotlight', 'big_counter', 'quote_card', 'chapter_cover'], true)) {
            return [];
        }
        if (self::sceneHasMedia($first)) {
            return []; // media hero open qualifies
        }
        $heading = self::primaryHeading($first);
        if ($heading !== '' && str_word_count($heading) <= 12) {
            return [];
        }

        return [self::item('warn', 'hook_missing', (string) $first['scene_id'],
            'Scene 1 is not a cold-open hook (no punchy ≤12-word opener, stat, quote or hero visual).')];
    }

    // -- §10.2 outro ---------------------------------------------------------

    private static function checkOutro(array $scenes): array
    {
        $last = end($scenes);
        if (($last['layout_template'] ?? '') === 'outro_card') {
            return [];
        }

        return [self::item('warn', 'outro_missing', (string) ($last['scene_id'] ?? ''),
            'The video has no closing outro card — it will stop instead of ending.')];
    }

    // -- §7.2 min text -------------------------------------------------------

    private static function checkMinText(array $scenes): array
    {
        $items = [];
        foreach ($scenes as $scene) {
            $tpl = (string) $scene['layout_template'];
            if (in_array($tpl, self::SPARSE_EXEMPT, true) || in_array($tpl, self::ANIMATED_CARDS, true)) {
                continue;
            }
            if (self::sceneHasMedia($scene)) {
                continue;
            }
            $words = self::sceneTextWords($scene);
            if ($words < 6) {
                $items[] = self::item('warn', 'min_text', (string) $scene['scene_id'],
                    "Only {$words} words of visible copy — the frame will feel empty for its whole duration.");
            }
        }

        return $items;
    }

    // -- §7.6 static hold cap --------------------------------------------------

    private static function checkStaticHolds(array $scenes): array
    {
        $items = [];
        foreach ($scenes as $scene) {
            $dur = (float) ($scene['duration_seconds'] ?? 0);
            if ($dur <= 8.0) {
                continue;
            }
            if (in_array((string) $scene['layout_template'], self::ANIMATED_CARDS, true)) {
                continue;
            }
            if (self::hasProgressiveReveal($scene)) {
                continue;
            }
            $items[] = self::item('warn', 'static_hold', (string) $scene['scene_id'],
                sprintf('%.1fs hold with no progressive reveal — split the beat or add bullets.', $dur));
        }

        return $items;
    }

    /** Anything that keeps releasing new information over the scene's life. */
    private static function hasProgressiveReveal(array $scene): bool
    {
        foreach (($scene['slots'] ?? []) as $slot) {
            $type = (string) ($slot['content_type'] ?? '');
            if ($type === 'text_block'
                && count((array) ($slot['bullets'] ?? [])) >= 2
                && ($slot['reveal'] ?? 'sequential') === 'sequential') {
                return true;
            }
            // Media pans/zooms for its whole window (plus the mid-hold push).
            if (in_array($type, ['image', 'video'], true) && ($slot['camera_move'] ?? 'static') !== 'static') {
                return true;
            }
        }

        return false;
    }

    // -- template streaks ------------------------------------------------------

    /**
     * A maths video with no figure and no graph is a wall of symbols. The
     * analyzer is told to draw one and usually does; when it doesn't, the
     * storyboard says so rather than the viewer finding out.
     *
     * @param  array<int, array>  $scenes
     */
    private static function checkMathVisual(array $scenes): array
    {
        foreach ($scenes as $scene) {
            if (in_array((string) $scene['layout_template'], ['geometry_diagram', 'function_plot', 'scenario_diagram', 'formula_anatomy'], true)) {
                return [];
            }
        }

        return [self::item('warn', 'math_no_visual', null,
            'This maths video never draws a figure or a graph — the working is never SHOWN, only stated. Re-generate the storyboard, or add a diagram/plot beat to the script.')];
    }

    private static function checkStreaks(array $scenes): array
    {
        $items = [];
        $run = 0;
        $prev = null;
        foreach ($scenes as $scene) {
            $tpl = (string) $scene['layout_template'];
            $run = $tpl === $prev ? $run + 1 : 1;
            // Math-card chains are an argument unfolding — a worked solution
            // stepping forward, or one figure gaining an element per beat.
            // That is a legitimate streak, not a flatline.
            if ($run === 3 && !in_array($tpl, self::MATH_CARDS, true)) {
                $items[] = self::item('warn', 'template_streak', (string) $scene['scene_id'],
                    "\"{$tpl}\" runs 3+ scenes in a row — the rhythm flatlines.");
            }
            $prev = $tpl;
        }

        return $items;
    }

    // -- peak pacing -----------------------------------------------------------

    private static function checkPeakPacing(array $scenes): array
    {
        $peaks = ExplainerRegistry::peakTemplates();
        $interval = max(15, ExplainerRegistry::peakIntervalSeconds());
        if (empty($peaks)) {
            return [];
        }

        $items = [];
        $windowStart = 0.0;
        $cursor = 0.0;
        $windowHasPeak = false;
        foreach ($scenes as $scene) {
            if ($cursor - $windowStart >= $interval) {
                if (!$windowHasPeak) {
                    $items[] = self::item('info', 'no_peak_card', null,
                        sprintf('No high-energy data card between %.0fs and %.0fs.', $windowStart, $cursor));
                }
                $windowStart = $cursor;
                $windowHasPeak = false;
            }
            if (in_array((string) $scene['layout_template'], $peaks, true)) {
                $windowHasPeak = true;
            }
            $cursor += (float) ($scene['duration_seconds'] ?? 0);
        }
        if ($cursor - $windowStart >= $interval * 0.6 && !$windowHasPeak) {
            $items[] = self::item('info', 'no_peak_card', null,
                sprintf('No high-energy data card in the final stretch (from %.0fs).', $windowStart));
        }

        return $items;
    }

    // -- §12.2 theme contrast ---------------------------------------------------

    /** WCAG relative luminance of a #rrggbb colour. */
    public static function luminance(string $hex): float
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) < 6) {
            return 0.0;
        }
        $chan = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $chan(substr($h, 0, 2))
            + 0.7152 * $chan(substr($h, 2, 2))
            + 0.0722 * $chan(substr($h, 4, 2));
    }

    /** WCAG contrast ratio between two #rrggbb colours (1..21). */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function checkContrast(array $theme): array
    {
        $items = [];
        $text = (string) ($theme['text'] ?? '');
        $pairs = [
            'paper' => (string) ($theme['bg_from'] ?? ''),
            'panel' => (string) ($theme['panel'] ?? ''),
        ];
        foreach ($pairs as $name => $bg) {
            if ($text === '' || $bg === '') {
                continue;
            }
            $ratio = self::contrastRatio($text, $bg);
            if ($ratio < 4.5) {
                $items[] = self::item('error', 'low_contrast', null, sprintf(
                    'Theme "%s": text on %s is %.2f:1 — below the 4.5:1 floor.',
                    (string) ($theme['name'] ?? '?'), $name, $ratio
                ));
            }
        }

        return $items;
    }

    // -- §12.3 narration/visual sync audit ---------------------------------------

    private static function checkSyncAudit(array $scenes): array
    {
        $items = [];
        foreach ($scenes as $scene) {
            $narration = trim((string) ($scene['narration']['text'] ?? ''));
            if ($narration === '') {
                continue;
            }
            $screen = mb_strtolower(self::sceneScreenText($scene));
            if ($screen === '') {
                continue; // full-bleed media scene: nothing textual to hold facts
            }

            $missing = [];

            // Spoken figures ("4.2 billion", "78%", "$12") that appear nowhere
            // on screen. The bare digits are compared, punctuation-insensitive.
            preg_match_all('/\d[\d,.]*\s*(?:%|percent|billion|million|thousand)?/iu', $narration, $nums);
            foreach (array_unique(array_map('trim', $nums[0] ?? [])) as $num) {
                $bare = rtrim(preg_replace('/[^0-9.]/', '', $num) ?? '', '.');
                if ($bare === '' || mb_strlen($bare) < 2) {
                    continue; // single digits ("one of 3 things") are noise
                }
                if (!str_contains($screen, $bare)) {
                    $missing[] = trim($num);
                }
            }

            // Proper nouns spoken mid-sentence but never shown. Sentence-initial
            // words are skipped — capitalization there means nothing.
            preg_match_all('/(?<![.!?]\s)(?<!^)\b([A-Z][a-z]{2,})\b/u', $narration, $nouns);
            $stop = ['The', 'This', 'That', 'They', 'Then', 'There', 'When', 'While', 'With', 'And', 'But', 'Its'];
            foreach (array_unique($nouns[1] ?? []) as $noun) {
                if (in_array($noun, $stop, true)) {
                    continue;
                }
                if (!str_contains($screen, mb_strtolower($noun))) {
                    $missing[] = $noun;
                }
            }

            if (!empty($missing)) {
                $shown = array_slice(array_values(array_unique($missing)), 0, 4);
                $items[] = self::item('info', 'sync_gap', (string) $scene['scene_id'],
                    'Spoken but never shown: ' . implode(', ', $shown) . '.');
            }
        }

        return $items;
    }

    // -- roadmap 3a: the working must track the voice -------------------------

    /**
     * Number-glued units ("20 km", "9.8 m/s^2", "45%"). Single-letter units
     * demand a separating space so algebra's "5s" (five times s) and "2m"
     * (two m) never read as seconds/metres; compound and multi-letter units
     * may sit tight against the digits.
     */
    private const UNIT_RE = '/\d[\d,.]*\s*(?:km\/h|km\/min|m\/s\^?2?|mph|km|cm|mm|kg|mg|ml|deg(?:rees)?|°|%)(?![A-Za-z])|\d[\d,.]* (?:m|s|g|h|L|N|J|W|V|A)(?![A-Za-z\/])/u';

    /** Unit WORDS an answer's note may carry instead ("seconds", "metres"). */
    private const UNIT_WORDS = '/\b(seconds?|minutes?|hours?|days?|years?|met(?:er|re)s?|kilomet(?:er|re)s?|centimet(?:er|re)s?|kilograms?|grams?|lit(?:er|re)s?|joules?|newtons?|watts?|volts?|amps?|percent|degrees?|dollars?|euros?)\b/iu';

    /** Sentences of a narration, in spoken order. */
    private static function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    /** The numeric atoms of one expression ("t = -28 / -9.8" → 28, 9.8). */
    private static function exprNumbers(string $expr): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', $expr, $m);

        return array_values(array_unique($m[0] ?? []));
    }

    /**
     * Lockstep audit (roadmap 3a): the MATH MODE contract is one narration
     * sentence per step, in step order — that is what makes lines land with
     * the voice (the renderer paces steps across the narration, so text
     * order here IS land order there). Two text-side checks per chain:
     *   - enough sentences to walk the steps (fewer = two moves per breath);
     *   - each step's NEW multi-digit number is spoken within ±1 sentence of
     *     its own step, after subtracting the chain's common offset (a
     *     one-sentence intro shifts everything and is not a sync fault).
     * A number never spoken at all is tolerated ("…and simplify").
     */
    private static function checkStepLockstep(array $scenes): array
    {
        $items = [];
        foreach ($scenes as $scene) {
            if (($scene['layout_template'] ?? '') !== 'math_steps') {
                continue;
            }
            $slot = $scene['slots']['slot_math'] ?? (array_values($scene['slots'] ?? [])[0] ?? []);
            $steps = array_values(array_filter(
                (array) ($slot['steps'] ?? []),
                fn ($s) => is_array($s) && trim((string) ($s['expr'] ?? '')) !== ''
            ));
            if (count($steps) < 2) {
                continue;
            }
            $sentences = self::sentences((string) ($scene['narration']['text'] ?? ''));
            if ($sentences === []) {
                continue;
            }

            $problems = [];
            if (count($sentences) < count($steps)) {
                $problems[] = sprintf(
                    '%d steps share %d sentences — each step needs its own sentence to land with the voice',
                    count($steps),
                    count($sentences)
                );
            }

            // Where is each step's new number actually spoken?
            $spoken = array_map(fn ($s) => (string) preg_replace('/,(?=\d)/', '', mb_strtolower($s)), $sentences);
            $pairs = [];
            $prev = [];
            foreach ($steps as $i => $step) {
                $nums = self::exprNumbers((string) $step['expr']);
                foreach (array_diff($nums, $prev) as $num) {
                    if (mb_strlen($num) < 2) {
                        continue; // single digits (exponents, indices) are noise
                    }
                    foreach ($spoken as $j => $sentence) {
                        if (str_contains($sentence, $num)) {
                            $pairs[] = ['num' => $num, 'step' => $i, 'sentence' => $j];
                            break;
                        }
                    }
                }
                $prev = $nums;
            }
            if (count($pairs) >= 2) {
                $drifts = array_map(fn ($p) => $p['sentence'] - $p['step'], $pairs);
                sort($drifts);
                $offset = $drifts[intdiv(count($drifts), 2)];
                foreach ($pairs as $p) {
                    if (abs($p['sentence'] - $p['step'] - $offset) > 1) {
                        $problems[] = sprintf(
                            '"%s" lands on step %d but is spoken in sentence %d',
                            $p['num'],
                            $p['step'] + 1,
                            $p['sentence'] + 1
                        );
                    }
                }
            }

            if ($problems !== []) {
                $items[] = self::item('warn', 'steps_narration_lockstep', (string) $scene['scene_id'],
                    'Narration and working out of lockstep: ' . implode('; ', array_slice($problems, 0, 3)) . '.');
            }
        }

        return $items;
    }

    /**
     * Units survival (roadmap 3a): when the problem's given side carries real
     * units (a scenario's "v = 20 km/min", a first chain stated in metres),
     * the STAMPED answer — the last step of the last chain — should still
     * say what kind of thing it is, in its expression or its note. Info, not
     * warn: a legitimately dimensionless result (a slope, a ratio) can start
     * from unit-carrying givens.
     */
    private static function checkUnitsSurvival(array $scenes): array
    {
        $mathScenes = array_values(array_filter(
            $scenes,
            fn ($s) => ($s['layout_template'] ?? '') === 'math_steps'
        ));
        if ($mathScenes === []) {
            return [];
        }

        // The given side: every scenario value/question plus the FIRST chain.
        $given = '';
        foreach ($scenes as $scene) {
            if (($scene['layout_template'] ?? '') !== 'scenario_diagram') {
                continue;
            }
            $slot = $scene['slots']['slot_scenario'] ?? (array_values($scene['slots'] ?? [])[0] ?? []);
            foreach ((array) ($slot['entities'] ?? []) as $e) {
                $given .= ' ' . (string) (is_array($e) ? ($e['value'] ?? '') : '');
            }
            $given .= ' ' . (string) ($slot['question'] ?? '');
        }
        $firstSlot = $mathScenes[0]['slots']['slot_math'] ?? (array_values($mathScenes[0]['slots'] ?? [])[0] ?? []);
        foreach ((array) ($firstSlot['steps'] ?? []) as $step) {
            $given .= ' ' . (string) (is_array($step) ? ($step['expr'] ?? '') : '') . ' ' . (string) (is_array($step) ? ($step['note'] ?? '') : '');
        }

        if (!preg_match(self::UNIT_RE, $given, $unitHit)) {
            return []; // a unit-free working owes no unit back
        }

        $lastSlot = $mathScenes[count($mathScenes) - 1]['slots']['slot_math']
            ?? (array_values($mathScenes[count($mathScenes) - 1]['slots'] ?? [])[0] ?? []);
        $steps = array_values(array_filter(
            (array) ($lastSlot['steps'] ?? []),
            fn ($s) => is_array($s) && trim((string) ($s['expr'] ?? '')) !== ''
        ));
        if ($steps === []) {
            return [];
        }
        $answer = (string) ($steps[count($steps) - 1]['expr'] ?? '') . ' ' . (string) ($steps[count($steps) - 1]['note'] ?? '');

        if (preg_match(self::UNIT_RE, $answer) || preg_match(self::UNIT_WORDS, $answer)) {
            return [];
        }

        return [self::item('info', 'units_dropped',
            (string) ($mathScenes[count($mathScenes) - 1]['scene_id'] ?? ''),
            sprintf(
                'The working starts in units ("%s") but the stamped answer carries none — put the unit on the final line or its note ("t = 2.86 s", note "seconds").',
                trim($unitHit[0])
            ))];
    }

    // -- shared helpers -----------------------------------------------------------

    private static function sceneHasMedia(array $scene): bool
    {
        foreach (($scene['slots'] ?? []) as $slot) {
            if (in_array($slot['content_type'] ?? '', ['image', 'video'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function primaryHeading(array $scene): string
    {
        foreach (($scene['slots'] ?? []) as $slot) {
            if (($slot['content_type'] ?? '') === 'text_block') {
                return trim((string) ($slot['heading'] ?? ''));
            }
        }

        return '';
    }

    private static function sceneTextWords(array $scene): int
    {
        $words = 0;
        foreach (($scene['slots'] ?? []) as $slot) {
            $words += str_word_count((string) ($slot['heading'] ?? ''));
            foreach ((array) ($slot['bullets'] ?? []) as $b) {
                $words += str_word_count((string) $b);
            }
            $words += str_word_count((string) ($slot['body'] ?? ''));
        }

        return $words;
    }

    /**
     * Everything a viewer could READ in the scene, across every content type
     * (structured card fields included), for the sync audit.
     */
    private static function sceneScreenText(array $scene): string
    {
        $parts = [];
        $push = function ($v) use (&$parts, &$push): void {
            if (is_string($v) || is_numeric($v)) {
                $s = trim((string) $v);
                if ($s !== '') {
                    $parts[] = $s;
                }
            } elseif (is_array($v)) {
                foreach ($v as $vv) {
                    $push($vv);
                }
            }
        };

        foreach (($scene['slots'] ?? []) as $slot) {
            foreach (['heading', 'bullets', 'body', 'label', 'verdict', 'caption', 'unit', 'source',
                'term', 'phonetic', 'part_of_speech', 'definition', 'overlap_label',
                'total', 'total_label', 'question', 'outcome',
                'prompt', 'answer', 'hint', 'wrong', 'correct', 'why',
                'pros', 'cons', 'pros_label', 'cons_label', 'value_pct',
                'expression', 'side_labels', 'radius_label', 'center_label'] as $key) {
                $push($slot[$key] ?? null);
            }
            foreach ((array) ($slot['steps'] ?? []) as $s) {
                $push(is_array($s) ? array_intersect_key($s, array_flip(['expr', 'note'])) : $s);
            }
            foreach ((array) ($slot['angle_marks'] ?? []) as $m) {
                $push(is_array($m) ? ($m['label'] ?? null) : null);
            }
            foreach ((array) ($slot['marks'] ?? []) as $m) {
                $push(is_array($m) ? ($m['label'] ?? null) : null);
            }
            foreach ((array) ($slot['points'] ?? []) as $p) {
                $push(is_array($p) ? ($p['label'] ?? null) : null);
            }
            foreach ((array) ($slot['values'] ?? []) as $v) {
                $push($v);
            }
            $push($slot['labels'] ?? null);
            foreach ((array) ($slot['items'] ?? []) as $it) {
                $push(is_array($it) ? array_intersect_key($it, array_flip(['label', 'text', 'source'])) : $it);
            }
            // venn sets and spectrum items keep their labels under their OWN
            // keys, so the generic `items` walk above never saw them — the sync
            // audit was blind to text the viewer can plainly read.
            foreach ((array) ($slot['rows'] ?? []) as $r) {
                $push(is_array($r) ? array_intersect_key($r, array_flip(['label', 'value'])) : $r);
            }
            // A decision tree nests one level, and the OUTCOMES are the words
            // the viewer most needs the narration to match.
            foreach ((array) ($slot['branches'] ?? []) as $br) {
                if (!is_array($br)) {
                    continue;
                }
                $push(array_intersect_key($br, array_flip(['label', 'outcome', 'question'])));
                foreach ((array) ($br['branches'] ?? []) as $leaf) {
                    $push(is_array($leaf) ? array_intersect_key($leaf, array_flip(['label', 'outcome'])) : $leaf);
                }
            }
            foreach ((array) ($slot['sets'] ?? []) as $s) {
                $push(is_array($s) ? array_intersect_key($s, array_flip(['label', 'caption'])) : $s);
            }
            foreach ((array) ($slot['spectrum_items'] ?? []) as $it) {
                $push(is_array($it) ? ($it['label'] ?? null) : null);
            }
            foreach ((array) ($slot['quadrant_items'] ?? []) as $it) {
                $push(is_array($it) ? ($it['label'] ?? null) : null);
            }
            // A proportion's branches print their label, their figure and their
            // note — all of it readable, so all of it counts for sync.
            // A scale comparison prints each thing's own measurement — the
            // figures the narration is speaking are all on screen.
            foreach ((array) ($slot['scale_items'] ?? []) as $it) {
                $push(is_array($it) ? array_intersect_key($it, array_flip(['label', 'value', 'note'])) : $it);
            }
            // An evidence card prints its finding and its provenance — the whole
            // claim the narration is speaking is on screen, so all of it counts.
            $push($slot['finding'] ?? null);
            $push($slot['source'] ?? null);
            $push($slot['year'] ?? null);
            $push($slot['sample'] ?? null);
            foreach ((array) ($slot['slices'] ?? []) as $b) {
                $push(is_array($b) ? array_intersect_key($b, array_flip(['label', 'value', 'note'])) : $b);
            }
            $push($slot['source_label'] ?? null);
            if (is_array($slot['zones'] ?? null)) {
                $push($slot['zones']);
            }
            foreach (['x_axis', 'y_axis'] as $ax) {
                if (is_array($slot[$ax] ?? null)) {
                    $push(array_intersect_key($slot[$ax], array_flip(['left_label', 'right_label', 'bottom_label', 'top_label'])));
                }
            }
            foreach ((array) ($slot['layers'] ?? []) as $l) {
                $push(is_array($l) ? array_intersect_key($l, array_flip(['label', 'caption'])) : $l);
            }
            // A hierarchy prints its root and every branch label (with its
            // grandchildren) — the whole structure the narration walks is on
            // screen, so all of it counts for sync and word budget.
            $push($slot['root'] ?? null);
            foreach ((array) ($slot['children'] ?? []) as $c) {
                if (!is_array($c)) {
                    $push($c);
                    continue;
                }
                $push(array_intersect_key($c, array_flip(['label', 'caption'])));
                foreach ((array) ($c['children'] ?? []) as $g) {
                    $push(is_array($g) ? ($g['label'] ?? null) : $g);
                }
            }
            if (is_array($slot['axis'] ?? null)) {
                $push(array_intersect_key($slot['axis'], array_flip(['left_label', 'right_label'])));
            }
            foreach ((array) ($slot['nodes'] ?? []) as $n) {
                $push(is_array($n) ? array_intersect_key($n, array_flip(['date', 'label'])) : null);
            }
            foreach ((array) ($slot['pins'] ?? []) as $p) {
                $push(is_array($p) ? ($p['label'] ?? null) : null);
            }
            $sides = ['left', 'right'];
            foreach ($sides as $side) {
                $s = $slot[$side] ?? null;
                if (is_array($s)) {
                    $push($s['label'] ?? null);
                    $push($s['stats'] ?? null);
                }
            }
        }

        return implode(' | ', $parts);
    }
}
