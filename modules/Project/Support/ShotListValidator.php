<?php

namespace Modules\Project\Support;

/**
 * ShotListValidator
 *
 * Implements Part 6 of the plan: the LLM output is never trusted. Every scene
 * is checked against {@see ExplainerRegistry} and, on failure, repaired or
 * replaced with a guaranteed-safe scene. An invalid scene can therefore never
 * reach the render service.
 *
 * Repair strategy (in order):
 *   (a) auto-repair trivial issues (drop undeclared slot keys, coerce types,
 *       fill defaults like camera_move / transition / dock / labels),
 *   (b) for a slot whose content_type is illegal or missing required fields,
 *       downgrade that slot to a safe default for what the slot allows,
 *   (c) if the whole scene is unusable, replace it with a single_focus +
 *       text_block fallback so the video still renders end to end.
 */
class ShotListValidator
{
    /** @var string[] */
    private array $warnings = [];

    private bool $changed = false;

    /** True only for a confirmed maths topic — raises the math-card caps. */
    private bool $mathMode = false;

    /** The video's aspect ("16:9" | "9:16" | "1:1") — steers camera defaults. */
    private string $aspectRatio = '16:9';

    /**
     * @param array $options Editorial packaging config from the caller:
     *   - outro_enabled (bool, default true) — append the closing end card
     *   - outro_title / outro (array{title?, cta?, handle?}) — outro copy
     *   - hook_enabled (bool, default true) — enforce the cold-open hook
     *   - math_mode (bool, default false) — the topic classifier confirmed a
     *     mathematical subject; math cards get their maths-video caps
     *
     * @return array{scenes: array<int, array>, warnings: string[], changed: bool}
     */
    public function validate(array $shotList, array $options = []): array
    {
        $this->warnings = [];
        $this->changed = false;
        $this->mathMode = ($options['math_mode'] ?? false) === true;
        $this->aspectRatio = (string) ($shotList['aspect_ratio'] ?? '16:9');

        $rawScenes = $shotList['scenes'] ?? [];
        if (!is_array($rawScenes) || empty($rawScenes)) {
            $this->warn('No scenes returned by the analyser; producing a single fallback scene.');
            $rawScenes = [['narration' => ['text' => $shotList['summary'] ?? '']]];
        }

        $scenes = [];
        $order = 1;
        foreach (array_values($rawScenes) as $rawScene) {
            $raw = is_array($rawScene) ? $rawScene : [];
            try {
                $scenes[] = $this->validateScene($raw, $order);
            } catch (\Throwable $e) {
                // The validator's ONE job is to make model output safe — a
                // clamp that throws on malformed input (gpt-5-nano's first
                // run sent a geometry `shape` as an array; PHP promoted the
                // string-cast warning to an exception) must cost ONE scene,
                // never the whole analyze job. The scene falls back to a
                // narration-built text card and the video ships.
                $sceneId = is_scalar($raw['scene_id'] ?? null) ? (string) $raw['scene_id'] : "scene_{$order}";
                $narr = '';
                if (isset($raw['narration'])) {
                    $narr = is_array($raw['narration']) ? (string) ($raw['narration']['text'] ?? '') : (string) $raw['narration'];
                }
                $this->warn("Scene {$sceneId}: validation threw ({$e->getMessage()}) -> safe text fallback.");
                $scenes[] = $this->fallbackScene($sceneId, $order, (float) ($raw['duration_seconds'] ?? 0) ?: ExplainerRegistry::defaultSceneSeconds(), $narr);
            }
            $order++;
        }

        // Editorial passes (copilot.md M1) — order matters: dead scenes merge
        // away BEFORE the hook looks at scene 1, variety runs on the final
        // body, and the outro is appended last so no pass ever reshuffles it.
        $scenes = $this->mergeDeadAir($scenes);
        $scenes = $this->absorbRuleLeadIns($scenes);
        $scenes = $this->foldAnswerEchoes($scenes);
        $scenes = $this->foldFigureEchoes($scenes);
        $scenes = $this->mergeMathChains($scenes);
        $scenes = $this->injectKnownRules($scenes);
        $scenes = $this->mergeEvolvingPlots($scenes);
        $scenes = $this->hoistScenarioSetup($scenes);
        $scenes = $this->balanceSplits($scenes);
        if (($options['hook_enabled'] ?? true) !== false) {
            $scenes = $this->ensureHook($scenes);
        }
        $scenes = $this->applySignatureTransitions($scenes);
        $scenes = $this->enforceVariety($scenes);
        $scenes = $this->enforceTransitionVariety($scenes);
        $scenes = $this->enforceCardSuitability($scenes);
        $scenes = $this->enforceTemplateCaps($scenes);
        $scenes = $this->enforceStockCap($scenes);
        $scenes = $this->ensurePeakCards($scenes);
        if (($options['outro_enabled'] ?? true) !== false) {
            $scenes = $this->appendOutro($scenes, $options);
        }
        $scenes = $this->reorder($scenes);

        return [
            'scenes' => $scenes,
            'warnings' => $this->warnings,
            'changed' => $this->changed,
        ];
    }

    /**
     * A rule stated on its own slide, one beat before the working that uses
     * it, belongs BESIDE that working — that is what the rule panel is for,
     * and it is what the viewer asked for ("if we applied some formula show it
     * on the side and explain it").
     *
     * The model keeps emitting the rule as a text scene instead ("Product
     * Rule" / "log_b(m) + log_b(n) = log_b(mn)" / "adds logs to multiply the
     * arguments") and then a bare card next to it, so the justification is
     * gone from the screen by the time the move happens. When that shape
     * appears, the text scene folds INTO the card as its rule panel, taking
     * its narration and its time along.
     *
     * Deliberately narrow: only a text-only scene, only directly before a
     * math_steps card that has no rule of its own, and only when one of its
     * lines is actually a statement of maths. A pep-talk beat ("Don't worry!
     * / We can simplify it!") states nothing and is left exactly where it is.
     */
    private function absorbRuleLeadIns(array $scenes): array
    {
        if (!$this->mathMode) {
            return $scenes;
        }

        $out = [];
        $count = count($scenes);

        for ($i = 0; $i < $count; $i++) {
            $scene = $scenes[$i];
            $next = $scenes[$i + 1] ?? null;

            $rule = $next !== null ? $this->ruleFromLeadIn($scene, $next) : null;
            if ($rule === null) {
                $out[] = $scene;
                continue;
            }

            $next['slots']['slot_math']['rule'] = $rule;
            $next['narration'] = ['text' => trim(
                (string) ($scene['narration']['text'] ?? '') . ' ' . (string) ($next['narration']['text'] ?? '')
            )];
            $next['duration_seconds'] = round(
                (float) ($scene['duration_seconds'] ?? 0) + (float) ($next['duration_seconds'] ?? 0),
                2
            );
            // The lead-in owns the opening of the beat, so the card inherits
            // its place in the cut.
            $next['relation'] = $scene['relation'] ?? $next['relation'] ?? null;
            $next['transition'] = $scene['transition'] ?? $next['transition'] ?? null;
            if ($next['relation'] === null) {
                unset($next['relation']);
            }
            if ($next['transition'] === null) {
                unset($next['transition']);
            }

            $this->warn("Scene {$scene['scene_id']}: rule slide folded into {$next['scene_id']}'s rule panel.");
            $this->changed = true;

            $scenes[$i + 1] = $next; // carry the edit forward; the loop emits it
        }

        return array_values($out);
    }

    /**
     * The {name, formula, why} a lead-in scene is really stating, or null when
     * this pair is not that shape.
     */
    private function ruleFromLeadIn(array $scene, array $next): ?array
    {
        if (($next['layout_template'] ?? '') !== 'math_steps') {
            return null;
        }
        if (!empty($next['slots']['slot_math']['rule'])) {
            return null; // the card already says why; leave the scene alone
        }
        if (($scene['layout_template'] ?? '') !== 'single_focus') {
            return null;
        }
        $slot = $scene['slots']['slot_main'] ?? null;
        if (!is_array($slot) || ($slot['content_type'] ?? '') !== 'text_block') {
            return null;
        }

        $heading = trim((string) ($slot['heading'] ?? ''));
        $bullets = array_values(array_filter(array_map(
            fn ($b) => trim((string) $b),
            (array) ($slot['bullets'] ?? [])
        ), fn ($b) => $b !== ''));

        // A rule has a NAME (a heading short enough to be one) and at most a
        // couple of supporting lines. Anything longer is a real content beat.
        if ($heading === '' || mb_strlen($heading) > 40 || count($bullets) > 2 || empty($bullets)) {
            return null;
        }

        // One of those lines must actually state some maths — that is what
        // separates "Product Rule / log_b(m) + log_b(n) = log_b(mn)" from a
        // slide of encouragement.
        $formula = null;
        $why = null;
        foreach ($bullets as $b) {
            if ($formula === null && $this->looksLikeMath($b)) {
                $formula = $b;
                continue;
            }
            if ($why === null) {
                $why = $b;
            }
        }
        if ($formula === null) {
            return null;
        }

        return array_filter([
            'name' => mb_substr($heading, 0, 40),
            'formula' => mb_substr($formula, 0, 60),
            'why' => $why !== null ? mb_substr($why, 0, 120) : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * The model keeps exiling a computed result onto its own big_counter /
     * stat_spotlight scene right after the working that produced it (project
     * 34: "t = -28 / -9.8" on the math card, then a big_counter reading
     * "2.86 seconds"). That restates the answer the math card's accent chip
     * exists to stamp, and it splits the chain so mergeMathChains can no
     * longer fuse the working — the echoes sit BETWEEN the math cards. Fold
     * the echo back in: its number becomes the card's final answer line
     * (stamped automatically), its narration and time ride along, and the
     * echo scene disappears. A counter that is NOT directly after working is
     * left alone — a real-world statistic is exactly what those cards are for.
     */
    private function foldAnswerEchoes(array $scenes): array
    {
        if (!$this->mathMode) {
            return $scenes;
        }

        $out = [];
        foreach ($scenes as $scene) {
            // The chain the echo concludes can be separated from it by a
            // figure — the model (or the plot synthesizer) puts the
            // SEE-the-answer graph between the last working card and the
            // trailing counter. Walk back over up to two figure scenes to
            // find the math card the number belongs to.
            $lastKey = array_key_last($out);
            $skipped = 0;
            while (
                $lastKey !== null && $lastKey >= 0 && $skipped < 2
                && in_array($out[$lastKey]['layout_template'] ?? '', ['function_plot', 'geometry_diagram', 'scenario_diagram', 'formula_anatomy'], true)
            ) {
                $lastKey--;
                $skipped++;
            }
            if ($lastKey !== null && $lastKey < 0) {
                $lastKey = null;
            }
            $echo = $lastKey !== null ? $this->answerEchoOf($out[$lastKey], $scene) : null;
            if ($echo === null) {
                $out[] = $scene;
                continue;
            }

            $prev = $out[$lastKey];
            $steps = (array) (($prev['slots']['slot_math'] ?? [])['steps'] ?? []);
            $lastExpr = (string) ((end($steps) ?: [])['expr'] ?? '');

            // Only add a line when the chain doesn't already end on the value.
            if ($lastExpr === '' || !str_contains($lastExpr, $echo['value'])) {
                $lhs = trim((string) strstr($lastExpr, '=', true));
                $step = ['expr' => mb_substr(
                    ($lhs !== '' && mb_strlen($lhs) <= 12 ? "{$lhs} = " : '') . $echo['value'],
                    0,
                    60
                )];
                if ($echo['unit'] !== '') {
                    $step['note'] = mb_substr($echo['unit'], 0, 36);
                }
                $steps[] = $step;
                $prev['slots']['slot_math']['steps'] = $steps;
            }

            $prev['narration'] = ['text' => trim(
                (string) ($prev['narration']['text'] ?? '') . ' ' . (string) ($scene['narration']['text'] ?? '')
            )];
            $prev['duration_seconds'] = round(
                (float) ($prev['duration_seconds'] ?? 0) + (float) ($scene['duration_seconds'] ?? 0),
                2
            );
            $out[$lastKey] = $prev;

            $this->warn("Scene {$scene['scene_id']}: answer echo folded into {$prev['scene_id']}'s final step.");
            $this->changed = true;
        }

        return array_values($out);
    }

    /**
     * {value, unit} when $scene is a counter/stat card restating the result
     * of the math card directly before it; null otherwise.
     */
    private function answerEchoOf(array $prev, array $scene): ?array
    {
        if (($prev['layout_template'] ?? '') !== 'math_steps') {
            return null;
        }
        if ($this->mathStepCount($prev) < 1) {
            return null; // no chain to conclude
        }
        if (!in_array($scene['layout_template'] ?? '', ['big_counter', 'stat_spotlight'], true)) {
            return null;
        }

        $slot = $scene['slots']['slot_counter'] ?? $scene['slots']['slot_stat'] ?? null;
        if (!is_array($slot)) {
            return null;
        }

        // The number is the heading ("2.86 seconds") — or, on the inverted
        // rolls, the heading is a title and the number sits alone in a
        // bullet ("Maximum Height Time" / "2.86 seconds"). Either way the
        // card IS one number wearing a layout.
        $heading = trim((string) ($slot['heading'] ?? ''));
        if (!preg_match('/^[~≈]?\s*-?\d[\d,.]*\s*(.{0,24})$/u', $heading, $m)) {
            $m = null;
            foreach ((array) ($slot['bullets'] ?? []) as $b) {
                if (preg_match('/^[~≈]?\s*-?\d[\d,.]*\s*(.{0,24})$/u', trim((string) $b), $bm)) {
                    $m = $bm;
                    $heading = trim((string) $b);
                    break;
                }
            }
            if ($m === null) {
                return null;
            }
        }
        $unit = trim((string) ($m[1] ?? ''));

        // Two ways to qualify: the beat SAYS it is a computation landing
        // ("calculating this gives us...") — or the heading's tail is a plain
        // lowercase unit ("meters", "km/s"), which is what a measured answer
        // looks like and what a number-led TITLE never does ("3 Golden Tips").
        // Project 34 second run: "42.18 meters / This is the maximum height"
        // said nothing calculational and survived on the first rule alone.
        $spoken = $heading . ' ' . implode(' ', (array) ($slot['bullets'] ?? []))
            . ' ' . (string) ($scene['narration']['text'] ?? '');
        $saysComputed = (bool) preg_match(
            '/calculat|comput|gives us|we (?:find|get)|equals|approximately|result|answer|this is the|\broot\b|solv/i',
            $spoken
        );
        $plainUnit = $unit === '' || (bool) preg_match('/^[a-z][a-z0-9\/%°²³\s.]{0,15}$/u', $unit);
        if (!$saysComputed && !$plainUnit) {
            return null;
        }

        $value = trim((string) strtok($heading, ' '));
        $value = str_replace(',', '', $value);

        return [
            'value' => $value,
            'unit' => $unit,
        ];
    }

    /**
     * A text card that only points at the figure beside it ("Here is the
     * function plot showing height over time" — project 34 scene_15) is
     * narration wearing a layout: the words belong ON the figure scene, not
     * on a card the viewer reads INSTEAD of the picture. Fold the card's
     * narration and time into the adjacent figure and drop it. Tightly
     * scoped: text-only, short, names the figure kind, states no maths of
     * its own, and sits directly next to a figure scene.
     */
    private function foldFigureEchoes(array $scenes): array
    {
        if (!$this->mathMode) {
            return $scenes;
        }

        $figures = ['function_plot', 'geometry_diagram', 'scenario_diagram', 'formula_anatomy'];
        $out = [];
        $count = count($scenes);

        for ($i = 0; $i < $count; $i++) {
            $scene = $scenes[$i];
            if (!$this->isFigureEcho($scene)) {
                $out[] = $scene;
                continue;
            }

            $lastKey = array_key_last($out);
            $narr = trim((string) ($scene['narration']['text'] ?? ''));
            $dur = (float) ($scene['duration_seconds'] ?? 0);

            // Prefer folding back ("here is the plot" follows its plot); a
            // lead-in ("let's draw the situation") folds forward instead.
            if ($lastKey !== null && in_array($out[$lastKey]['layout_template'] ?? '', $figures, true)) {
                $fig = $out[$lastKey];
                $fig['narration'] = ['text' => trim((string) ($fig['narration']['text'] ?? '') . ' ' . $narr)];
                $fig['duration_seconds'] = round((float) ($fig['duration_seconds'] ?? 0) + $dur, 2);
                $out[$lastKey] = $fig;
                $this->warn("Scene {$scene['scene_id']}: figure commentary folded into {$fig['scene_id']}.");
                $this->changed = true;
                continue;
            }

            if ($i + 1 < $count && in_array($scenes[$i + 1]['layout_template'] ?? '', $figures, true)) {
                $fig = $scenes[$i + 1];
                $fig['narration'] = ['text' => trim($narr . ' ' . (string) ($fig['narration']['text'] ?? ''))];
                $fig['duration_seconds'] = round((float) ($fig['duration_seconds'] ?? 0) + $dur, 2);
                // The lead-in owned the opening of the beat.
                if (!empty($scene['relation'])) {
                    $fig['relation'] = $scene['relation'];
                }
                if (!empty($scene['transition'])) {
                    $fig['transition'] = $scene['transition'];
                }
                $scenes[$i + 1] = $fig;
                $this->warn("Scene {$scene['scene_id']}: figure lead-in folded into {$fig['scene_id']}.");
                $this->changed = true;
                continue;
            }

            $out[] = $scene; // no figure neighbour after all — leave it
        }

        return array_values($out);
    }

    /** True for a short text-only scene whose whole job is describing a figure. */
    private function isFigureEcho(array $scene): bool
    {
        if (($scene['layout_template'] ?? '') !== 'single_focus') {
            return false;
        }
        $slot = $scene['slots']['slot_main'] ?? null;
        if (!is_array($slot) || ($slot['content_type'] ?? '') !== 'text_block') {
            return false;
        }

        $heading = trim((string) ($slot['heading'] ?? ''));
        $bullets = array_map(fn ($b) => trim((string) $b), (array) ($slot['bullets'] ?? []));
        $text = trim($heading . ' ' . implode(' ', $bullets));

        if (str_word_count($text) > 14) {
            return false; // a real content beat, not a caption
        }
        if (!preg_match('/\b(plot|graph|diagram|chart|figure|visuali[sz]\w*|drawing|drawn)\b/i', $text)) {
            return false;
        }
        foreach ($bullets as $b) {
            if ($this->looksLikeMath($b)) {
                return false; // it states maths of its own — keep it
            }
        }

        return true;
    }

    /** Does this line state a relationship rather than describe one? */
    private function looksLikeMath(string $s): bool
    {
        // An equality/inequality is the giveaway — a rule relates two things.
        // Prose that merely mentions a number ("Check x - 1 > 0") is caught by
        // the same test, which is fine: that IS a stated condition.
        return (bool) preg_match('/[=<>≤≥≠→]/u', $s)
            && (bool) preg_match('/[A-Za-z0-9]/', $s);
    }

    /**
     * The same curve drawn twice is a detour, not a lesson. When consecutive
     * function_plot scenes plot the SAME expression (project 26 wanted: draw
     * x^2, then its tangent, then the shaded area — three separate cards),
     * they merge into ONE scene whose card EVOLVES: the renderer already
     * stages curve → marks → tangent → shade on one clock, so a merged scene
     * is precisely the "figure the narration keeps pointing back at".
     * Math mode only, like every math merge.
     */
    private function mergeEvolvingPlots(array $scenes): array
    {
        if (!$this->mathMode) {
            return $scenes;
        }

        $exprOf = function (array $scene): string {
            $slot = $scene['slots']['slot_plot'] ?? [];
            return strtolower((string) preg_replace('/\s+/', '', (string) ($slot['expression'] ?? '')));
        };

        $out = [];
        foreach ($scenes as $scene) {
            $lastKey = array_key_last($out);
            if (
                $lastKey !== null
                && ($scene['layout_template'] ?? '') === 'function_plot'
                && ($out[$lastKey]['layout_template'] ?? '') === 'function_plot'
                && $exprOf($scene) !== ''
                && $exprOf($scene) === $exprOf($out[$lastKey])
            ) {
                $a = &$out[$lastKey];
                $sa = $a['slots']['slot_plot'];
                $sb = $scene['slots']['slot_plot'] ?? [];

                // Union of the stages: whichever scene brought the tangent,
                // the shade, the second curve or extra marks, the merged
                // figure carries them all (B wins ties — it is the later beat).
                foreach (['tangent_at', 'shade', 'expression2', 'caption'] as $k) {
                    if (isset($sb[$k])) {
                        $sa[$k] = $sb[$k];
                    }
                }
                $marks = array_merge((array) ($sa['marks'] ?? []), (array) ($sb['marks'] ?? []));
                $seen = [];
                $sa['marks'] = array_values(array_filter($marks, function ($m) use (&$seen) {
                    $key = (string) ($m['x'] ?? '');
                    if ($key === '' || isset($seen[$key])) {
                        return false;
                    }
                    $seen[$key] = true;
                    return true;
                }));
                if ($sa['marks'] === []) {
                    unset($sa['marks']);
                } else {
                    $sa['marks'] = array_slice($sa['marks'], 0, 3);
                }

                $a['slots']['slot_plot'] = $sa;
                $a['narration'] = ['text' => trim(
                    (string) ($a['narration']['text'] ?? '') . ' ' . (string) ($scene['narration']['text'] ?? '')
                )];
                $a['duration_seconds'] = round(
                    (float) ($a['duration_seconds'] ?? 0) + (float) ($scene['duration_seconds'] ?? 0),
                    2
                );
                unset($a);
                $this->warn('Consecutive plots of the same curve merged into one evolving figure.');
                $this->changed = true;
                continue;
            }
            $out[] = $scene;
        }

        return array_values($out);
    }

    /** How many usable lines a math_steps scene carries (0 for anything else). */
    private function mathStepCount(array $scene): int
    {
        if (($scene['layout_template'] ?? '') !== 'math_steps') {
            return 0;
        }

        return count((array) (($scene['slots']['slot_math'] ?? [])['steps'] ?? []));
    }

    /**
     * math_steps cards must hold a CHAIN — 2-4 consecutive lines of the same
     * working, each the previous one after a move. The card is built to show a
     * line TURNING INTO the next (it accents exactly what changed, and stamps
     * the last as the result), so a card holding a single expression wastes the
     * whole mechanism and the working reads as a slideshow of disconnected
     * lines.
     *
     * The prompt asks for chains and the model still emits one line per beat,
     * because a script paragraph maps so naturally onto a scene. So this is
     * enforced rather than requested: a thin card absorbs the math_steps scenes
     * next to it until it has a real chain, taking their narration and their
     * time with it. Math videos only — nothing else has math_steps.
     */
    private function mergeMathChains(array $scenes): array
    {
        if (!$this->mathMode) {
            return $scenes;
        }

        $out = [];
        $i = 0;
        $count = count($scenes);

        while ($i < $count) {
            $scene = $scenes[$i];
            if (($scene['layout_template'] ?? '') !== 'math_steps') {
                $out[] = $scene;
                $i++;
                continue;
            }

            // Absorb forward while this card is still thin — but only when the
            // combined chain FITS: a merge must never have to discard a line
            // of the working (the narration keeps talking about it).
            $j = $i + 1;
            while (
                $this->mathStepCount($scene) < 2
                && $j < $count
                && ($scenes[$j]['layout_template'] ?? '') === 'math_steps'
                && $this->mathStepCount($scene) + $this->mathStepCount($scenes[$j]) <= 4
            ) {
                $scene = $this->mergeMathScenes($scene, $scenes[$j]);
                $j++;
            }

            // Still thin (nothing after it) — fold back into the card before it
            // if that one has room, rather than leaving a lone line on screen.
            $lastKey = array_key_last($out);
            if (
                $this->mathStepCount($scene) < 2
                && $lastKey !== null
                && ($out[$lastKey]['layout_template'] ?? '') === 'math_steps'
                && $this->mathStepCount($out[$lastKey]) + $this->mathStepCount($scene) <= 4
            ) {
                $out[$lastKey] = $this->mergeMathScenes($out[$lastKey], $scene);
            } else {
                $out[] = $scene;
            }
            $i = $j;
        }

        $before = count(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'math_steps'));
        $after = count(array_filter($out, fn ($s) => ($s['layout_template'] ?? '') === 'math_steps'));
        if ($after !== $before) {
            $this->warn("Math working merged into chains: {$before} math cards -> {$after}.");
            $this->changed = true;
        }

        return array_values($out);
    }

    /**
     * The rule panel is the WHY of a move, and the model fills it only
     * sometimes (0 panels one run, 3 the next — v21). For the moves whose
     * written shape is unmistakable, recognise the rule deterministically
     * and inject the panel the model forgot. The model's own rule always
     * wins, and a rule name is never injected twice in one video — a
     * repeated panel is noise, the first appearance is the lesson.
     */
    private function injectKnownRules(array $scenes): array
    {
        if (!$this->mathMode) {
            return $scenes;
        }

        $used = [];
        foreach ($scenes as $scene) {
            $name = (string) ((($scene['slots']['slot_math'] ?? [])['rule'] ?? [])['name'] ?? '');
            if ($name !== '') {
                $used[mb_strtolower($name)] = true;
            }
        }

        foreach ($scenes as &$scene) {
            if (($scene['layout_template'] ?? '') !== 'math_steps') {
                continue;
            }
            if (!empty($scene['slots']['slot_math']['rule'])) {
                continue; // the model said why; keep its words
            }
            $exprs = array_map(
                fn ($s) => (string) (is_array($s) ? ($s['expr'] ?? '') : ''),
                (array) (($scene['slots']['slot_math'] ?? [])['steps'] ?? [])
            );
            $hit = MathRuleLibrary::detect($exprs);
            if ($hit === null || isset($used[mb_strtolower($hit['name'])])) {
                continue;
            }
            $scene['slots']['slot_math']['rule'] = $hit;
            $used[mb_strtolower($hit['name'])] = true;
            $this->warn("Scene {$scene['scene_id']}: recognised move -> '{$hit['name']}' rule panel injected.");
            $this->changed = true;
        }
        unset($scene);

        return array_values($scenes);
    }

    /**
     * The scenario schematic is the GIVEN/FIND picture — it only teaches
     * BEFORE the working starts (draw the car, the road, the question mark,
     * THEN derive). The model keeps banking it at the end anyway (project 33:
     * scenario at position 8 of 10, after all the algebra), so enforce the
     * placement deterministically: a scenario_diagram that appears after the
     * first math card moves to just before it. Math videos only.
     */
    private function hoistScenarioSetup(array $scenes): array
    {
        if (!$this->mathMode) {
            return $scenes;
        }

        $firstMath = null;
        $scenarioIdx = null;
        foreach ($scenes as $i => $s) {
            $tpl = $s['layout_template'] ?? '';
            if ($firstMath === null && in_array($tpl, ['math_steps', 'geometry_diagram', 'function_plot'], true)) {
                $firstMath = $i;
            }
            if ($scenarioIdx === null && $tpl === 'scenario_diagram') {
                $scenarioIdx = $i;
            }
        }
        if ($scenarioIdx === null || $firstMath === null || $scenarioIdx <= $firstMath) {
            return $scenes;
        }

        $scenario = $scenes[$scenarioIdx];
        array_splice($scenes, $scenarioIdx, 1);
        array_splice($scenes, $firstMath, 0, [$scenario]);
        $this->warn('Scenario setup hoisted before the working — the situation is drawn FIRST.');
        $this->changed = true;

        return array_values($scenes);
    }

    /**
     * MATH BOARD phase consolidation — run by the analyze job AFTER the
     * composition mode is stamped (the validator itself cannot know the mode;
     * it is decided from the validated scenes).
     *
     * On the board, the teaching unit is the PHASE: one section of the board
     * carrying 4-8 lines of working under ONE heading, written continuously.
     * The general merge pass only guarantees 2-4 line cards — good for a
     * full-screen slide, but on the board it still means a camera stop every
     * few lines and a heading over every chunk (project 26: one slope
     * calculation = three camera stops). Here consecutive math_steps scenes
     * fuse until a phase holds up to 8 lines; later chunks' headings drop
     * (the phase heading covers them), narration and time concatenate so the
     * camera writes straight down one section while the voice carries on.
     */
    public function consolidateBoardPhases(array $scenes): array
    {
        $out = [];
        foreach ($scenes as $scene) {
            $lastKey = array_key_last($out);
            if (
                $lastKey !== null
                && ($scene['layout_template'] ?? '') === 'math_steps'
                && ($out[$lastKey]['layout_template'] ?? '') === 'math_steps'
                // Only THIN cards fuse. This pass exists to stop a phase that
                // came back as one or two lines from occupying a board section
                // of its own — not to weld a whole solve into a single card.
                // Unconditional merging turned five headed phases ("Apply the
                // Laplace transform", "Solve for Y", "Invert") into one
                // heading-less 70-second card, because absorbing a chunk drops
                // its heading.
                && $this->mathStepCount($scene) < 3
                && $this->mathStepCount($out[$lastKey]) + $this->mathStepCount($scene) <= 12
            ) {
                // One heading per phase — the absorbed chunk's own heading
                // would repeat mid-section.
                unset($scene['slots']['slot_math']['heading']);
                $out[$lastKey] = $this->mergeMathScenes($out[$lastKey], $scene);
                continue;
            }
            $out[] = $scene;
        }

        foreach ($out as $i => &$scene) {
            $scene['order'] = $i + 1;
        }
        unset($scene);

        return array_values($out);
    }

    /**
     * Fold scene B's working into scene A: their lines join into one chain,
     * and A takes B's narration and B's time so the voice still matches what
     * is on screen. Callers must check capacity BEFORE merging — this joins
     * everything it is given. (It used to slice at a cap, which silently
     * deleted lines of the working while the concatenated narration kept
     * describing them — project 33 lost its "t = 2.86" result line that way.)
     */
    private function mergeMathScenes(array $a, array $b): array
    {
        $stepsA = (array) (($a['slots']['slot_math'] ?? [])['steps'] ?? []);
        $stepsB = (array) (($b['slots']['slot_math'] ?? [])['steps'] ?? []);

        // Chained cards repeat the boundary line — B restates A's last line as
        // its starting point. Kept, it would render as a step that changed
        // nothing, and the diff highlight would find nothing to accent.
        if (
            !empty($stepsA) && !empty($stepsB)
            && (string) (end($stepsA)['expr'] ?? '') === (string) ($stepsB[0]['expr'] ?? '')
        ) {
            // The incoming note is the better one: it names the move ABOUT to
            // happen, where A's trailing line was just a resting point.
            $noteB = trim((string) ($stepsB[0]['note'] ?? ''));
            if ($noteB !== '') {
                $lastKey = array_key_last($stepsA);
                $stepsA[$lastKey]['note'] = $noteB;
            }
            array_shift($stepsB);
        }

        $steps = array_merge($stepsA, $stepsB);

        $narrA = trim((string) ($a['narration']['text'] ?? ''));
        $narrB = trim((string) ($b['narration']['text'] ?? ''));
        $narration = trim($narrA . ' ' . $narrB);

        $a['slots']['slot_math']['steps'] = $steps;
        // The rule panel: whichever card named a rule wins, A first.
        if (empty($a['slots']['slot_math']['rule']) && !empty($b['slots']['slot_math']['rule'])) {
            $a['slots']['slot_math']['rule'] = $b['slots']['slot_math']['rule'];
        }
        if ((string) ($a['slots']['slot_math']['heading'] ?? '') === '') {
            $heading = (string) ($b['slots']['slot_math']['heading'] ?? '');
            if ($heading !== '') {
                $a['slots']['slot_math']['heading'] = $heading;
            }
        }
        $a['narration'] = ['text' => $narration];
        $a['duration_seconds'] = round(
            (float) ($a['duration_seconds'] ?? 0) + (float) ($b['duration_seconds'] ?? 0),
            2
        );

        return $a;
    }

    /** Rebuild 1-based `order` after merges/inserts so it always matches position. */
    private function reorder(array $scenes): array
    {
        foreach ($scenes as $i => &$scene) {
            $scene['order'] = $i + 1;
            unset($scene['_transition_explicit']); // internal flag, never shipped
        }
        unset($scene);

        return array_values($scenes);
    }

    /**
     * Relation → signature transition (copilot.md §3.2) — the single
     * highest-leverage editing change: the MEANING of a cut decides its look.
     * Scene 1 is always the "opening" (a cold open has no incoming cut);
     * later scenes that carry a relation but only a generic/defaulted
     * transition get that relation's signature. A deliberate non-generic
     * choice by the planner is respected.
     */
    private function applySignatureTransitions(array $scenes): array
    {
        foreach ($scenes as $i => &$scene) {
            if ($i === 0) {
                $scene['relation'] = 'opening';
                $scene['transition'] = 'none';
                continue;
            }

            // "opening" only means anything on scene 1.
            if (($scene['relation'] ?? null) === 'opening') {
                $scene['relation'] = ExplainerRegistry::defaultRelation();
            }

            $relation = $scene['relation'] ?? null;
            if (!is_string($relation) || $relation === '') {
                continue;
            }

            $explicit = (bool) ($scene['_transition_explicit'] ?? true);
            $generic = !$explicit
                || in_array($scene['transition'], ['fade', ExplainerRegistry::defaultTransition()], true);

            if ($generic) {
                $signature = ExplainerRegistry::signatureTransition($relation);
                if ($signature !== null && $signature !== 'none' && $signature !== $scene['transition']) {
                    $scene['transition'] = $signature;
                    $this->warn("Scene {$scene['scene_id']}: '{$relation}' relation -> signature transition '{$signature}'.");
                }
            }
        }
        unset($scene);

        return $scenes;
    }

    /** Total words of VISIBLE copy in a scene (headings + bullets + bodies). */
    private function sceneTextWords(array $scene): int
    {
        $words = 0;
        foreach (($scene['slots'] ?? []) as $slot) {
            $words += str_word_count((string) ($slot['heading'] ?? ''));
            foreach ((array) ($slot['bullets'] ?? []) as $bullet) {
                $words += str_word_count((string) $bullet);
            }
            $words += str_word_count((string) ($slot['body'] ?? ''));
        }

        return $words;
    }

    /** Does any slot carry (or request) user/AI media? */
    private function sceneHasMedia(array $scene): bool
    {
        foreach (($scene['slots'] ?? []) as $slot) {
            if (in_array($slot['content_type'] ?? '', ['image', 'video'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dead-air rule (copilot.md §7.2): a text-only scene with fewer than 6
     * words of visible copy is not a scene, it is a caption looking for a
     * home — its words and narration merge into the PREVIOUS scene instead of
     * holding an almost-empty frame for seconds. Typography showpieces
     * (stat_spotlight, quote_card) and the structured data cards are exempt:
     * their content lives outside heading/bullets, so the word count is
     * meaningless there — sparse IS their design.
     */
    private function mergeDeadAir(array $scenes): array
    {
        $out = [];
        foreach ($scenes as $scene) {
            $exempt = in_array($scene['layout_template'], [
                'stat_spotlight', 'quote_card',
                'versus_card', 'animated_chart', 'big_counter', 'checklist_card', 'icon_grid',
                'timeline_card', 'step_flow', 'before_after', 'list_ranking', 'progress_meter', 'quote_portrait',
                'phone_mockup', 'photo_stack', 'map_card', 'headline_ticker', 'myth_fact', 'pictogram_percent',
                'cycle_diagram', 'spectrum_card', 'quadrant_map', 'proportion_flow', 'scale_comparison', 'evidence_card', 'layer_stack', 'hierarchy_card', 'venn_card', 'term_card', 'receipt_card', 'decision_tree',
                'practice_card', 'common_mistake',
                'math_steps', 'geometry_diagram', 'function_plot', 'scenario_diagram', 'formula_anatomy',
                'chapter_cover', 'outro_card',
            ], true);
            if (empty($out) || $exempt || $this->sceneHasMedia($scene) || $this->sceneTextWords($scene) >= 6) {
                $out[] = $scene;
                continue;
            }

            $prev = &$out[count($out) - 1];

            $addText = trim((string) ($scene['narration']['text'] ?? ''));
            if ($addText !== '') {
                $prevText = trim((string) ($prev['narration']['text'] ?? ''));
                $prev['narration']['text'] = trim($prevText . ' ' . $addText);
            }

            // Donate the dead scene's copy as bullets to the previous scene's
            // first text block (validator-generated filler headings excluded).
            $donor = [];
            foreach (($scene['slots'] ?? []) as $slot) {
                if (($slot['content_type'] ?? '') === 'text_block') {
                    $heading = trim((string) ($slot['heading'] ?? ''));
                    if ($heading !== '' && !in_array(strtolower($heading), ['key point', 'key points'], true)) {
                        $donor[] = $heading;
                    }
                    foreach ((array) ($slot['bullets'] ?? []) as $bullet) {
                        $bullet = trim((string) $bullet);
                        if ($bullet !== '') {
                            $donor[] = $bullet;
                        }
                    }
                }
                if (($slot['content_type'] ?? '') === 'explanation_box') {
                    $body = trim((string) ($slot['body'] ?? ''));
                    if ($body !== '') {
                        $donor[] = $body;
                    }
                }
            }
            if (!empty($donor)) {
                foreach ($prev['slots'] as &$prevSlot) {
                    if (($prevSlot['content_type'] ?? '') === 'text_block') {
                        $merged = array_values(array_unique(array_merge($prevSlot['bullets'] ?? [], $donor)));
                        $prevSlot['bullets'] = array_slice($merged, 0, 5);
                        break;
                    }
                }
                unset($prevSlot);
            }

            $prev['duration_seconds'] = round(
                min(14.0, (float) $prev['duration_seconds'] + min(4.0, (float) $scene['duration_seconds'] * 0.6)),
                2
            );
            unset($prev);

            $this->warn("Scene {$scene['scene_id']}: under 6 words of visible copy -> merged into the previous scene.");
        }

        return $out;
    }

    /**
     * Layout balance (copilot.md §7.3): a split where one side is media and
     * the other is 1–2 short bullets used to leave ~half the frame nearly
     * empty. Promote the media slot to 65% of the axis; the text side's own
     * container already centers vertically.
     */
    private function balanceSplits(array $scenes): array
    {
        $pairs = [
            'split_side_by_side' => ['slot_left', 'slot_right'],
            'split_top_bottom' => ['slot_top', 'slot_bottom'],
        ];

        foreach ($scenes as &$scene) {
            $keys = $pairs[$scene['layout_template']] ?? null;
            if ($keys === null) {
                continue;
            }

            $media = [];
            $text = [];
            foreach ($keys as $key) {
                $type = $scene['slots'][$key]['content_type'] ?? '';
                if (in_array($type, ['image', 'video'], true)) {
                    $media[] = $key;
                } elseif ($type === 'text_block') {
                    $text[] = $key;
                }
            }
            if (count($media) !== 1 || count($text) !== 1) {
                continue;
            }

            $textSlot = $scene['slots'][$text[0]];
            $bullets = (array) ($textSlot['bullets'] ?? []);
            $words = str_word_count(($textSlot['heading'] ?? '') . ' ' . implode(' ', $bullets));
            if (count($bullets) <= 2 && $words <= 14) {
                $scene['slots'][$media[0]]['emphasis_pct'] = 65;
                $this->warn("Scene {$scene['scene_id']}: sparse text beside media -> media promoted to 65%.");
            }
        }
        unset($scene);

        return $scenes;
    }

    /** First text_block heading in the scene, if any. */
    private function primaryHeading(array $scene): string
    {
        foreach (($scene['slots'] ?? []) as $slot) {
            if (($slot['content_type'] ?? '') === 'text_block') {
                return trim((string) ($slot['heading'] ?? ''));
            }
        }

        return '';
    }

    /** A ≤12-word punch line built VERBATIM from a sentence (never invented). */
    private function punchLine(string $sentence): string
    {
        $clean = trim(preg_replace('/[.?!]+$/u', '', trim($sentence)) ?? $sentence);
        $words = preg_split('/\s+/u', $clean) ?: [];
        if (count($words) <= 12) {
            return $clean;
        }

        return implode(' ', array_slice($words, 0, 12)) . '…';
    }

    /**
     * Cold-open hook (copilot.md §10.1): scene 1 must open the video, not
     * warm it up. Typography showpieces and media hero opens already qualify
     * (never displace a user's visual). Otherwise the opening SENTENCE of the
     * narration is LIFTED into a new hook scene — content moved, never
     * invented — set as giant type (stat_spotlight when it carries a figure).
     * A single-sentence opener is promoted in place instead of duplicated.
     */
    private function ensureHook(array $scenes): array
    {
        if (empty($scenes)) {
            return $scenes;
        }

        $first = $scenes[0];
        if (in_array($first['layout_template'], ['stat_spotlight', 'quote_card'], true)) {
            return $scenes;
        }
        if ($this->sceneHasMedia($first)) {
            return $scenes;
        }

        $narration = trim((string) ($first['narration']['text'] ?? ''));
        $heading = $this->primaryHeading($first);
        $alreadyPunchy = $heading !== ''
            && str_word_count($heading) <= 12
            && $this->sceneTextWords($first) <= 16
            && str_word_count($narration) <= 22;
        if ($alreadyPunchy) {
            return $scenes;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $narration) ?: [];
        $lead = trim((string) ($sentences[0] ?? ''));
        if ($lead === '' || str_word_count($lead) < 3) {
            return $scenes;
        }

        $punch = $this->punchLine($lead);
        $template = preg_match('/\d/', $punch) ? 'stat_spotlight' : 'single_focus';
        $slotKey = $template === 'stat_spotlight' ? 'slot_stat' : 'slot_main';
        $hookSlots = [$slotKey => [
            'content_type' => 'text_block',
            'heading' => $punch,
            'bullets' => [],
            'reveal' => 'all_at_once',
        ]];

        $rest = trim(implode(' ', array_slice($sentences, 1)));
        if ($rest === '') {
            // Single-sentence opener: promote in place, never leave scene 2 empty.
            $scenes[0]['layout_template'] = $template;
            $scenes[0]['slots'] = $hookSlots;
            $scenes[0]['transition'] = 'none';
            $this->warn('Scene 1 promoted in place to a cold-open hook.');

            return $scenes;
        }

        $scenes[0]['narration']['text'] = $rest;
        $scenes[0]['duration_seconds'] = round($this->paceDuration(0, $rest, $scenes[0]['slots']), 2);

        array_unshift($scenes, [
            'scene_id' => 'scene_hook',
            'order' => 0,
            'duration_seconds' => round(max(3.0, min(6.0, str_word_count($lead) / 2.5 + 0.8)), 2),
            'narration' => ['text' => $lead],
            'layout_template' => $template,
            'slots' => $hookSlots,
            'transition' => 'none',
            'mood' => $first['mood'] ?? ExplainerRegistry::defaultMood(),
        ]);
        $this->warn('Cold-open hook scene lifted from the opening sentence.');

        return $scenes;
    }

    /**
     * Auto outro (copilot.md §10.2): the video must END, not stop. A short
     * flat end card — title recap + follow CTA (+ optional handle) — carried
     * as a plain text_block so storage, uploads and the storyboard UI treat
     * it like any other text scene. Always appended AFTER the payoff; it
     * never replaces or absorbs a content scene.
     */
    private function appendOutro(array $scenes, array $options): array
    {
        if (empty($scenes) || end($scenes)['layout_template'] === 'outro_card') {
            return $scenes;
        }

        $conf = is_array($options['outro'] ?? null) ? $options['outro'] : [];
        $title = trim((string) ($conf['title'] ?? $options['outro_title'] ?? ''));
        $cta = trim((string) ($conf['cta'] ?? 'Follow for more'));
        $handle = trim((string) ($conf['handle'] ?? ''));
        $last = end($scenes);

        $scenes[] = [
            'scene_id' => 'scene_outro',
            'order' => count($scenes) + 1,
            'duration_seconds' => 3.2,
            'narration' => ['text' => ''],
            'layout_template' => 'outro_card',
            'slots' => ['slot_outro' => [
                'content_type' => 'text_block',
                'heading' => $title !== '' ? mb_substr($title, 0, 80) : 'Thanks for watching',
                'bullets' => array_values(array_filter(
                    [$cta !== '' ? $cta : 'Follow for more', $handle],
                    fn ($v) => $v !== ''
                )),
                'reveal' => 'sequential',
            ]],
            'transition' => 'fade',
            'mood' => $last['mood'] ?? ExplainerRegistry::defaultMood(),
        ];
        $this->warn('Outro card appended.');

        return $scenes;
    }

    /**
     * Backstop for monotone cuts: when the model omits transitions (they all
     * default) or repeats one, any run longer than two is broken by cycling a
     * quiet, meaning-neutral pool. Deterministic per position so re-validation
     * is stable; scene 1 (no incoming transition rendered) is left alone.
     */
    private function enforceTransitionVariety(array $scenes): array
    {
        $pool = array_values(array_intersect(
            ['fade', 'mask_wipe_diagonal', 'push_left', 'wipe', 'stack_push', 'push_up', 'zoom_out_in', 'push_right'],
            ExplainerRegistry::transitions()
        ));
        if (count($pool) < 2) {
            return $scenes;
        }

        $run = 0;
        $prev = null;
        foreach ($scenes as $i => $scene) {
            $t = $scene['transition'];
            $run = $t === $prev ? $run + 1 : 1;

            if ($i > 0 && $run > 2 && $t !== 'none') {
                $idx = array_search($t, $pool, true);
                $next = $pool[(($idx === false ? 0 : $idx) + 1 + ($i % 2)) % count($pool)];
                $scenes[$i]['transition'] = $next;
                $this->warn("Scene {$scene['scene_id']}: broke a run of '{$t}' transitions -> '{$next}'.");
                $run = 1;
            }

            $prev = $scenes[$i]['transition'];
        }

        return $scenes;
    }

    /**
     * Per-video caps (registry max_per_video): a versus card is an
     * exclamation mark — three of them is shouting. Scenes past a template's
     * cap degrade through the same per-card rules as broken content, keeping
     * their information as ordinary typography.
     */
    /**
     * Recast cards the beat cannot actually support (project 42: a timeline of
     * non-dates, a counter with no number). Runs BEFORE the cap pass so a
     * degraded card frees its slot in the per-template counts, and reuses
     * degradeCappedCard so there is exactly ONE fallback table per card —
     * a timeline becomes the bulleted list it always was, a counter becomes a
     * stat_spotlight.
     *
     * Suitability is judged on the CLAMPED content, so this sees what the
     * viewer would have seen, not what the model claimed.
     */
    private function enforceCardSuitability(array $scenes): array
    {
        foreach ($scenes as $i => $scene) {
            $template = (string) ($scene['layout_template'] ?? '');
            $reason = CardSuitability::unfitReason($template, (array) ($scene['slots'] ?? []));
            if ($reason === null) {
                continue;
            }

            $this->warn("Scene {$scene['scene_id']}: '{$template}' unsuitable — {$reason} -> recast.");
            $this->changed = true;
            $scenes[$i] = $this->degradeCappedCard($scene);
        }

        return $scenes;
    }

    private function enforceTemplateCaps(array $scenes): array
    {
        $counts = [];
        foreach ($scenes as $i => $scene) {
            $template = (string) $scene['layout_template'];
            $max = $this->mathMode
                ? ExplainerRegistry::mathModeMaxPerVideo($template)
                : ExplainerRegistry::maxPerVideo($template);
            $counts[$template] = ($counts[$template] ?? 0) + 1;
            if ($max === null || $counts[$template] <= $max) {
                continue;
            }

            $this->warn("Scene {$scene['scene_id']}: '{$template}' over its cap of {$max} -> degraded.");
            $this->changed = true;
            $scenes[$i] = $this->degradeCappedCard($scene);
        }

        return $scenes;
    }

    /** The native technical templates (drawn, never uploaded). */
    public const MATH_TEMPLATES = ['math_steps', 'geometry_diagram', 'function_plot', 'scenario_diagram', 'formula_anatomy'];

    /**
     * Should this storyboard run on the math board? A classifier-confirmed
     * worked_problem boards on any real math spine (>=2 math cards — v23's
     * lesson: never re-derive the subject from the card mix). The RATIO
     * safety net (classifier-missed solves, proof topics) now ALSO demands a
     * WORKED spine of >=2 math_steps chains: since the technical cards went
     * global, a science explainer legitimately carries two plots and a
     * formula anatomy — figures alone are illustration, not a derivation,
     * and must never drag a normal video onto the board.
     */
    public static function routesToMathBoard(array $scenes, ?string $kind): bool
    {
        $content = array_filter(
            $scenes,
            fn ($s) => !in_array((string) ($s['layout_template'] ?? ''), ['outro_card', 'chapter_cover'], true)
        );
        $math = array_filter(
            $content,
            fn ($s) => in_array((string) ($s['layout_template'] ?? ''), self::MATH_TEMPLATES, true)
        );
        if (count($math) < 2) {
            return false;
        }
        if ($kind === 'worked_problem') {
            return true;
        }
        $worked = array_filter(
            $content,
            fn ($s) => (string) ($s['layout_template'] ?? '') === 'math_steps'
        );

        return count($worked) >= 2 && count($math) >= (int) ceil(count($content) * 0.4);
    }

    /**
     * A storyboard headed for the math board must carry NO media slots.
     *
     * `ExplainerVideoProcessor::generateSceneVisuals()` early-returns for
     * math_board — the board draws every visual itself and deliberately spends
     * no image budget — so an `image`/`video` slot there is never filled by
     * anything: not by AI, not by stock. It renders as an empty panel, and it
     * is the reason a maths video showed "upload media" boxes in the dashboard
     * at all. Project 99 (a Pythagoras PROOF) came back from the giant-call
     * fallback with a split_side_by_side photo panel.
     *
     * So the media slot becomes a text block built from the narration — the
     * same degrade every other unusable slot gets — and a scene left with
     * nothing but that becomes a plain single_focus.
     *
     * PUBLIC and called by the JOB, for the same reason consolidateBoardPhases
     * is: only the job knows the mode. Predicting it here from the card mix
     * looked equivalent and was not — project 99 shipped a board carrying two
     * photo panels because the prediction and the job's own decision disagreed
     * about the same storyboard. Ask the authority instead of re-deriving.
     */
    public function stripBoardMediaSlots(array $scenes): array
    {
        foreach ($scenes as &$scene) {
            $slots = $scene['slots'] ?? [];
            $touched = false;
            foreach ($slots as $key => $slot) {
                if (!in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                    continue;
                }
                $narr = (string) ($scene['narration']['text'] ?? '');
                $heading = trim((string) ($slot['heading'] ?? ''));
                $slots[$key] = $this->genericTextBlock($narr, $heading !== '' ? $heading : null);
                $touched = true;
            }
            if (!$touched) {
                continue;
            }
            $scene['slots'] = $slots;
            $this->warn("Scene {$scene['scene_id']}: media slot on the maths board -> nothing would ever fill it, replaced with text.");
            $this->changed = true;

            // A two-panel layout whose picture just became prose is two blocks
            // of text side by side; one focused card reads better.
            if (in_array((string) $scene['layout_template'], ['split_side_by_side', 'split_top_bottom', 'full_bleed_with_side_panel', 'full_bleed_with_banner'], true)) {
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => reset($slots) ?: $this->genericTextBlock((string) ($scene['narration']['text'] ?? ''))];
            }
        }
        unset($scene);

        return $scenes;
    }

    /** The over-cap fallback for each Tier A card. */
    private function degradeCappedCard(array $scene): array
    {
        $slots = $scene['slots'] ?? [];

        switch ($scene['layout_template']) {
            case 'versus_card':
                $scene['layout_template'] = 'split_side_by_side';
                $scene['slots'] = [
                    'slot_left' => $slots['slot_left'] ?? $this->genericTextBlock(''),
                    'slot_right' => $slots['slot_right'] ?? $this->genericTextBlock(''),
                ];
                return $scene;

            case 'big_counter': {
                $slot = $slots['slot_counter'] ?? [];
                $heading = ($slot['content_type'] ?? '') === 'chart'
                    ? $this->counterFigure($slot)
                    : (string) ($slot['heading'] ?? '');
                $scene['layout_template'] = 'stat_spotlight';
                $scene['slots'] = ['slot_stat' => [
                    'content_type' => 'text_block',
                    'heading' => $heading !== '' ? $heading : 'The number',
                    'bullets' => array_slice((array) ($slot['bullets'] ?? []), 0, 2),
                    'reveal' => 'all_at_once',
                ]];
                return $scene;
            }

            case 'animated_chart': {
                $slot = $slots['slot_chart'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['values'] ?? []) as $i => $v) {
                    $label = (string) (($slot['labels'] ?? [])[$i] ?? '');
                    $bullets[] = trim(($label !== '' ? "{$label} — " : '') . $v . (string) ($slot['unit'] ?? ''));
                    if (count($bullets) >= 5) {
                        break;
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['caption'] ?? '') ?: 'The numbers',
                    'bullets' => $bullets,
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'checklist_card': {
                $slot = $slots['slot_checklist'] ?? [];
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The checklist',
                    'bullets' => array_slice(array_merge((array) ($slot['pros'] ?? []), (array) ($slot['cons'] ?? [])), 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'icon_grid': {
                $slot = $slots['slot_icons'] ?? [];
                $labels = [];
                foreach ((array) ($slot['items'] ?? []) as $it) {
                    $l = trim((string) ($it['label'] ?? ''));
                    if ($l !== '') {
                        $labels[] = $l;
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The pieces',
                    'bullets' => array_slice($labels, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'timeline_card': {
                $slot = $slots['slot_timeline'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['nodes'] ?? []) as $n) {
                    $bullets[] = trim(((string) ($n['date'] ?? '') !== '' ? "{$n['date']} — " : '') . (string) ($n['label'] ?? ''));
                    if (count($bullets) >= 5) {
                        break;
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The timeline',
                    'bullets' => array_values(array_filter($bullets)),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'step_flow': {
                $slot = $slots['slot_steps'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['items'] ?? []) as $i => $it) {
                    $l = trim((string) ($it['label'] ?? ''));
                    if ($l !== '') {
                        $bullets[] = $l;
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'How it works',
                    'bullets' => array_slice($bullets, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'list_ranking': {
                $slot = $slots['slot_ranking'] ?? [];
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The ranking',
                    'bullets' => array_slice(array_reverse(array_map('strval', (array) ($slot['items'] ?? []))), 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'progress_meter': {
                $slot = $slots['slot_meter'] ?? [];
                $scene['layout_template'] = 'stat_spotlight';
                $scene['slots'] = ['slot_stat' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['value_pct'] ?? '') . (string) ($slot['unit'] ?? '%'),
                    'bullets' => array_values(array_filter([(string) ($slot['label'] ?? '')])),
                    'reveal' => 'all_at_once',
                ]];
                return $scene;
            }

            case 'before_after': {
                $scene['layout_template'] = 'split_side_by_side';
                $scene['slots'] = [
                    'slot_left' => $slots['slot_before'] ?? $this->genericTextBlock(''),
                    'slot_right' => $slots['slot_after'] ?? $this->genericTextBlock(''),
                ];
                return $scene;
            }

            case 'quote_portrait': {
                $scene['layout_template'] = 'quote_card';
                $scene['slots'] = ['slot_quote' => $slots['slot_quote'] ?? $this->genericTextBlock('')];
                return $scene;
            }

            case 'phone_mockup': {
                // Keep the screen media as an honest full-frame visual.
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => $slots['slot_screen'] ?? $this->genericTextBlock('')];
                return $scene;
            }

            case 'photo_stack': {
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => $slots['slot_photo_1'] ?? $this->genericTextBlock('')];
                return $scene;
            }

            case 'map_card': {
                $slot = $slots['slot_map'] ?? [];
                $labels = [];
                foreach ((array) ($slot['pins'] ?? []) as $pin) {
                    $l = trim((string) ($pin['label'] ?? ''));
                    if ($l !== '') {
                        $labels[] = $l;
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The places',
                    'bullets' => array_slice($labels, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'math_steps': {
                $slot = $slots['slot_math'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['steps'] ?? []) as $s) {
                    $e = trim((string) (is_array($s) ? ($s['expr'] ?? '') : $s));
                    if ($e !== '') {
                        // A bullet does NOT typeset — the raw notation would
                        // show as literal "frac{-2 pm sqrt{48}}{2}". Project the
                        // expression into real glyphs on the way out.
                        $bullets[] = MathPlain::toPlain($e);
                    }
                    if (count($bullets) >= 5) {
                        break;
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The working',
                    'bullets' => $bullets,
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'scenario_diagram': {
                $slot = $slots['slot_scenario'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['entities'] ?? []) as $e) {
                    $label = trim((string) (is_array($e) ? ($e['label'] ?? '') : ''));
                    $value = trim((string) (is_array($e) ? ($e['value'] ?? '') : ''));
                    if ($label !== '') {
                        $bullets[] = $label . ($value !== '' ? ' — ' . MathPlain::toPlain($value) : '');
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The setup',
                    'bullets' => array_slice($bullets, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'geometry_diagram': {
                $slot = $slots['slot_geometry'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['side_labels'] ?? []) as $l) {
                    $l = trim((string) $l);
                    if ($l !== '') {
                        $bullets[] = $l;
                    }
                }
                foreach ((array) ($slot['angle_marks'] ?? []) as $m) {
                    $l = trim((string) (is_array($m) ? ($m['label'] ?? '') : ''));
                    if ($l !== '') {
                        $bullets[] = $l;
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: ucfirst(str_replace('_', ' ', (string) ($slot['shape'] ?? 'The figure'))),
                    'bullets' => array_slice($bullets, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'function_plot': {
                $slot = $slots['slot_plot'] ?? [];
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => trim('y = ' . (string) ($slot['expression'] ?? '')),
                    'bullets' => array_values(array_filter([(string) ($slot['caption'] ?? '')])),
                    'reveal' => 'all_at_once',
                ]];
                return $scene;
            }

            case 'headline_ticker': {
                $slot = $slots['slot_headlines'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['items'] ?? []) as $it) {
                    $t = trim((string) ($it['text'] ?? ''));
                    $s = trim((string) ($it['source'] ?? ''));
                    if ($t !== '') {
                        $bullets[] = $t . ($s !== '' ? " — {$s}" : '');
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The reaction',
                    'bullets' => array_slice($bullets, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'labeled_diagram': {
                // Keep the image — the labels go, the visual stays.
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => $slots['slot_diagram'] ?? $this->genericTextBlock('')];
                return $scene;
            }

            case 'decision_tree': {
                $slot = $slots['slot_decision'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['branches'] ?? []) as $br) {
                    if (!is_array($br)) {
                        continue;
                    }
                    $label = trim((string) ($br['label'] ?? ''));
                    if (isset($br['branches']) && is_array($br['branches'])) {
                        // A nested path flattens into one line per ending so
                        // the reader can still follow which answers lead where.
                        $sub = trim((string) ($br['question'] ?? ''));
                        foreach ($br['branches'] as $leaf) {
                            if (!is_array($leaf)) {
                                continue;
                            }
                            $bullets[] = trim($label . ' + ' . trim((string) ($leaf['label'] ?? ''))
                                . ($sub !== '' ? " ({$sub})" : '') . ' -> ' . trim((string) ($leaf['outcome'] ?? '')));
                        }
                        continue;
                    }
                    $bullets[] = trim($label . ' -> ' . trim((string) ($br['outcome'] ?? '')));
                }
                $scene['layout_template'] = 'checklist_card';
                $scene['slots'] = ['slot_checklist' => [
                    'content_type' => 'proscons',
                    'heading' => (string) ($slot['question'] ?? '') ?: 'The decision',
                    'pros' => array_slice(array_values(array_filter($bullets)), 0, 4),
                    'cons' => [],
                ]];
                return $scene;
            }

            case 'hierarchy_card': {
                $slot = $slots['slot_hierarchy'] ?? [];
                // The STRUCTURE is the payload — the root becomes the heading and
                // each branch a bullet, its sub-parts folded in after a dash so
                // the parent-of-children relationship survives losing the chart.
                $bullets = [];
                foreach ((array) ($slot['children'] ?? []) as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $l = trim((string) ($c['label'] ?? ''));
                    if ($l === '') {
                        continue;
                    }
                    $sub = [];
                    foreach ((array) ($c['children'] ?? []) as $g) {
                        $gl = trim((string) (is_array($g) ? ($g['label'] ?? '') : $g));
                        if ($gl !== '') {
                            $sub[] = $gl;
                        }
                    }
                    $bullets[] = $l . (count($sub) ? ' — ' . implode(', ', array_slice($sub, 0, 3)) : '');
                }
                $root = trim((string) ($slot['root'] ?? ''));
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: ($root !== '' ? $root : 'The structure'),
                    'bullets' => array_slice($bullets, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'receipt_card': {
                $slot = $slots['slot_receipt'] ?? [];
                $unit = trim((string) ($slot['unit'] ?? ''));
                $money = $unit !== '' && !preg_match('/[a-z]/i', $unit);
                // The sign sits OUTSIDE the currency symbol: "-$2400", never
                // "$-2400" (same rule the renderer follows).
                $show = function ($v) use ($money, $unit) {
                    $n = (float) $v;
                    $sign = $n < 0 ? '-' : '';
                    $mag = (string) abs($n);

                    return $money ? $sign . $unit . $mag : trim($sign . $mag . ' ' . $unit);
                };
                $bullets = [];
                foreach ((array) ($slot['rows'] ?? []) as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $bullets[] = trim((string) ($r['label'] ?? '')) . ' — ' . $show((string) ($r['value'] ?? ''));
                }
                $bullets[] = (trim((string) ($slot['total_label'] ?? '')) ?: 'Total')
                    . ': ' . $show((string) ($slot['total'] ?? ''));
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The breakdown',
                    'bullets' => array_slice(array_values(array_filter($bullets)), 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'common_mistake': {
                // Over cap, the strike-through cannot be staged — but the
                // pairing is the content, so it survives as two labelled
                // lines. Both were checked before they got here.
                $slot = $slots['slot_mistake'] ?? [];
                $bullets = [];
                $wrong = trim((string) ($slot['wrong'] ?? ''));
                if ($wrong !== '') {
                    $bullets[] = 'Wrong: ' . MathPlain::toPlain($wrong);
                }
                $correct = trim((string) ($slot['correct'] ?? ''));
                if ($correct !== '') {
                    $bullets[] = 'Right: ' . MathPlain::toPlain($correct);
                }
                $why = trim((string) ($slot['why'] ?? ''));
                if ($why !== '') {
                    $bullets[] = $why;
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => trim((string) ($slot['heading'] ?? '')) ?: 'The trap',
                    'bullets' => array_slice(array_values(array_filter($bullets)), 0, 3),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'practice_card': {
                // Over cap the pause cannot be staged, so the beat becomes a
                // stat_spotlight: the problem as the headline, the hint and the
                // answer as lines under it. The answer still ships — it was
                // verified upstream, and a second practice beat that silently
                // lost its answer would be the cliffhanger the card forbids.
                $slot = $slots['slot_practice'] ?? [];
                $bullets = [];
                $hint = trim((string) ($slot['hint'] ?? ''));
                if ($hint !== '') {
                    $bullets[] = $hint;
                }
                $answer = trim((string) ($slot['answer'] ?? ''));
                if ($answer !== '') {
                    $bullets[] = 'Answer: ' . MathPlain::toPlain($answer);
                }
                $caption = trim((string) ($slot['caption'] ?? ''));
                if ($caption !== '') {
                    $bullets[] = $caption;
                }
                $scene['layout_template'] = 'stat_spotlight';
                $scene['slots'] = ['slot_stat' => [
                    'content_type' => 'text_block',
                    'heading' => MathPlain::toPlain(trim((string) ($slot['prompt'] ?? ''))) ?: 'Your turn',
                    'bullets' => array_slice(array_values(array_filter($bullets)), 0, 3),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'term_card': {
                $slot = $slots['slot_term'] ?? [];
                $term = trim((string) ($slot['term'] ?? ''));
                $bullets = [];
                $sound = trim((string) ($slot['phonetic'] ?? ''));
                $pos = trim((string) ($slot['part_of_speech'] ?? ''));
                if ($sound !== '' || $pos !== '') {
                    $bullets[] = trim($sound . ($sound !== '' && $pos !== '' ? ' · ' : '') . $pos);
                }
                $bullets[] = trim((string) ($slot['definition'] ?? ''));
                $caption = trim((string) ($slot['caption'] ?? ''));
                if ($caption !== '') {
                    $bullets[] = $caption;
                }
                $scene['layout_template'] = 'stat_spotlight';
                $scene['slots'] = ['slot_stat' => [
                    'content_type' => 'text_block',
                    'heading' => $term !== '' ? $term : 'The term',
                    'bullets' => array_slice(array_values(array_filter($bullets)), 0, 3),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'venn_card': {
                $slot = $slots['slot_venn'] ?? [];
                $labels = [];
                foreach ((array) ($slot['sets'] ?? []) as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    $l = trim((string) ($s['label'] ?? ''));
                    if ($l === '') {
                        continue;
                    }
                    $cap = trim((string) ($s['caption'] ?? ''));
                    $labels[] = $l . ($cap !== '' ? ' — ' . $cap : '');
                }
                // The overlap is the point of the card, so it survives the
                // degrade as its own line rather than being dropped.
                $overlap = trim((string) ($slot['overlap_label'] ?? ''));
                if ($overlap !== '') {
                    $labels[] = 'Shared: ' . $overlap;
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'What they share',
                    'bullets' => array_slice($labels, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'spectrum_card': {
                $slot = $slots['slot_spectrum'] ?? [];
                $axis = is_array($slot['axis'] ?? null) ? $slot['axis'] : [];
                $bullets = [];
                foreach ((array) ($slot['spectrum_items'] ?? []) as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $l = trim((string) ($it['label'] ?? ''));
                    if ($l === '') {
                        continue;
                    }
                    $p = (float) ($it['position'] ?? 0.5);
                    $toward = $p < 0.4
                        ? (string) ($axis['left_label'] ?? '')
                        : ($p > 0.6 ? (string) ($axis['right_label'] ?? '') : 'the middle');
                    $bullets[] = $l . ($toward !== '' ? ' — toward ' . $toward : '');
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '')
                        ?: trim((string) ($axis['left_label'] ?? '') . ' vs ' . (string) ($axis['right_label'] ?? ''), ' vs'),
                    'bullets' => array_slice($bullets, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'quadrant_map': {
                $slot = $slots['slot_quadrant'] ?? [];
                $x = is_array($slot['x_axis'] ?? null) ? $slot['x_axis'] : [];
                $y = is_array($slot['y_axis'] ?? null) ? $slot['y_axis'] : [];
                $zones = is_array($slot['zones'] ?? null) ? $slot['zones'] : [];
                $bullets = [];
                foreach ((array) ($slot['quadrant_items'] ?? []) as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $l = trim((string) ($it['label'] ?? ''));
                    if ($l === '') {
                        continue;
                    }
                    // Which box it sits in IS the finding, so the text keeps
                    // it: the zone's own name when the card named it, else the
                    // two poles that corner is made of.
                    $ix = (float) ($it['x'] ?? 0.5) >= 0.5 ? 'right' : 'left';
                    $iy = (float) ($it['y'] ?? 0.5) >= 0.5 ? 'top' : 'bottom';
                    $corner = $iy . '_' . $ix;
                    $name = trim((string) ($zones[$corner] ?? ''));
                    if ($name === '') {
                        $poleX = trim((string) ($ix === 'right' ? ($x['right_label'] ?? '') : ($x['left_label'] ?? '')));
                        $poleY = trim((string) ($iy === 'top' ? ($y['top_label'] ?? '') : ($y['bottom_label'] ?? '')));
                        $name = trim($poleY . ', ' . $poleX, ', ');
                    }
                    $bullets[] = $l . ($name !== '' ? ' — ' . $name : '');
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '')
                        ?: trim(trim((string) ($x['left_label'] ?? '')) . ' vs ' . trim((string) ($y['top_label'] ?? '')), ' vs'),
                    'bullets' => array_slice($bullets, 0, 6),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'scale_comparison': {
                $slot = $slots['slot_scale'] ?? [];
                $unit = trim((string) ($slot['unit'] ?? ''));
                $bullets = [];
                foreach ((array) ($slot['scale_items'] ?? []) as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $l = trim((string) ($it['label'] ?? ''));
                    if ($l === '') {
                        continue;
                    }
                    // The SIZE is the finding, so the text keeps the figure —
                    // and the ratio for everything that is not the biggest,
                    // because "40 times smaller" is the sentence the drawn card
                    // was making.
                    $line = $l;
                    if (isset($it['value'])) {
                        $line .= ' — ' . rtrim(rtrim(number_format((float) $it['value'], 2, '.', ','), '0'), '.')
                            . ($unit !== '' ? ' ' . $unit : '');
                    }
                    $ratio = (float) ($it['ratio'] ?? 1);
                    if ($ratio > 1.05) {
                        $line .= ', ' . rtrim(rtrim(number_format($ratio, 1, '.', ','), '0'), '.') . 'x smaller';
                    }
                    $bullets[] = $line;
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'Side by side',
                    'bullets' => array_slice($bullets, 0, 3),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'evidence_card': {
                $slot = $slots['slot_evidence'] ?? [];
                // The FINDING is the payload, so the text keeps it verbatim and
                // cites its source underneath — the same claim, minus the frame.
                $source = trim((string) ($slot['source'] ?? ''));
                $year = trim((string) ($slot['year'] ?? ''));
                $sample = trim((string) ($slot['sample'] ?? ''));
                $attribution = $source
                    . ($year !== '' ? ', ' . $year : '')
                    . ($sample !== '' ? ' (' . $sample . ')' : '');
                $bullets = array_values(array_filter([
                    trim((string) ($slot['finding'] ?? '')),
                    $attribution !== '' ? '— ' . $attribution : '',
                ], fn ($b) => $b !== ''));
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '')
                        ?: ($source !== '' ? 'According to ' . $source : 'The evidence'),
                    'bullets' => $bullets,
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'proportion_flow': {
                $slot = $slots['slot_proportion'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['slices'] ?? $slot['branches'] ?? []) as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $l = trim((string) ($it['label'] ?? ''));
                    if ($l === '') {
                        continue;
                    }
                    // The SHARE is the finding, so the text keeps it — already
                    // recomputed by the clamp, so the sentence cannot lie
                    // either. It reads as a percentage even when the card
                    // printed raw units, because a bar is gone by now.
                    $share = isset($it['share']) ? round(((float) $it['share']) * 100) : null;
                    $bullets[] = $l . ($share !== null ? ' — ' . $share . '%' : '');
                }
                $source = trim((string) ($slot['source_label'] ?? ''));
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '')
                        ?: ($source !== '' ? 'Where ' . lcfirst($source) . ' goes' : 'How it splits'),
                    'bullets' => array_slice($bullets, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'layer_stack': {
                $slot = $slots['slot_layers'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['layers'] ?? []) as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $l = trim((string) ($it['label'] ?? ''));
                    if ($l === '') {
                        continue;
                    }
                    $cap = trim((string) ($it['caption'] ?? ''));
                    // Top-first order kept — the vertical order IS the point,
                    // so the list reads top to bottom exactly as the stack.
                    $bullets[] = $l . ($cap !== '' ? ' — ' . $cap : '');
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The layers, top to bottom',
                    'bullets' => array_slice($bullets, 0, 5),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'cycle_diagram': {
                $slot = $slots['slot_cycle'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['items'] ?? []) as $it) {
                    $l = trim((string) (is_array($it) ? ($it['label'] ?? '') : $it));
                    if ($l !== '') {
                        $bullets[] = $l;
                    }
                }
                // The loop reads as a list with its return step spelled out.
                if (count($bullets) >= 2) {
                    $bullets[] = '… and back to ' . $bullets[0];
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The loop',
                    'bullets' => array_slice($bullets, 0, 6),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'formula_anatomy': {
                $slot = $slots['slot_formula'] ?? [];
                $bullets = [];
                foreach ((array) ($slot['parts'] ?? []) as $p) {
                    $m = trim((string) (is_array($p) ? ($p['match'] ?? '') : ''));
                    $l = trim((string) (is_array($p) ? ($p['label'] ?? '') : ''));
                    if ($l !== '') {
                        $bullets[] = ($m !== '' ? MathPlain::toPlain($m) . ' — ' : '') . $l;
                    }
                }
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => mb_substr(MathPlain::toPlain((string) ($slot['formula'] ?? '')), 0, 60) ?: 'The formula',
                    'bullets' => array_slice($bullets, 0, 4),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            case 'pictogram_percent': {
                $slot = $slots['slot_pictogram'] ?? [];
                $of = max(1, (int) round((float) ($slot['of'] ?? 10)));
                $filled = (float) ($slot['filled'] ?? 0);
                $heading = ($slot['unit'] ?? '') === '%'
                    ? round($filled / $of * 100) . '%'
                    : rtrim(rtrim(number_format($filled, 1, '.', ''), '0'), '.') . ' in ' . $of;
                $scene['layout_template'] = 'stat_spotlight';
                $scene['slots'] = ['slot_stat' => [
                    'content_type' => 'text_block',
                    'heading' => $heading,
                    'bullets' => array_values(array_filter([trim((string) ($slot['label'] ?? ''))])),
                    'reveal' => 'all_at_once',
                ]];
                return $scene;
            }

            case 'myth_fact': {
                $slot = $slots['slot_myth_fact'] ?? [];
                $scene['layout_template'] = 'single_focus';
                $scene['slots'] = ['slot_main' => [
                    'content_type' => 'text_block',
                    'heading' => (string) ($slot['heading'] ?? '') ?: 'The truth',
                    'bullets' => array_values(array_filter([
                        trim('Myth: ' . (string) ($slot['myth'] ?? ''), ': '),
                        trim('Fact: ' . (string) ($slot['fact'] ?? ''), ': '),
                    ], fn ($b) => !in_array($b, ['Myth', 'Fact'], true))),
                    'reveal' => 'sequential',
                ]];
                return $scene;
            }

            default:
                return $scene;
        }
    }

    /** The display figure of a counter-chart slot ("42%", "3.5B"). */
    private function counterFigure(array $slot): string
    {
        $values = (array) ($slot['values'] ?? []);
        if (empty($values)) {
            return '';
        }
        $idx = $slot['highlight_index'] ?? (count($values) - 1);
        $idx = is_numeric($idx) && isset($values[(int) $idx]) ? (int) $idx : count($values) - 1;

        return $values[$idx] . (string) ($slot['unit'] ?? '');
    }

    /**
     * Stock b-roll cap (§8): at most 3 auto-fetched clips per video. Excess
     * slots keep their video shape but lose the stock marker — they become
     * ordinary upload requests instead of silently multiplying API pulls.
     */
    private function enforceStockCap(array $scenes): array
    {
        $max = ExplainerRegistry::maxStockVideos();
        $count = 0;
        foreach ($scenes as $i => $scene) {
            // NB: no by-reference foreach over `$scene['slots'] ?? []` — a
            // reference into a ??-temporary is silently discarded.
            $slots = is_array($scene['slots'] ?? null) ? $scene['slots'] : [];
            foreach ($slots as $slotKey => $slot) {
                if (trim((string) ($slot['stock_query'] ?? '')) === '') {
                    continue;
                }
                $count++;
                if ($count > $max) {
                    unset($slots[$slotKey]['stock_query']);
                    $this->warn("Scene {$scene['scene_id']}/{$slotKey}: over the stock b-roll cap of {$max} -> plain upload slot.");
                }
            }
            $scenes[$i]['slots'] = $slots;
        }

        return $scenes;
    }

    /**
     * Peak pacing (copilot.md §5 planner integration): every ~45s window of
     * runtime should land at least one high-energy data card. When a window
     * has none, the safest upgrade is a stat_spotlight that already carries a
     * figure — it becomes a big_counter (same content, more drama). Windows
     * with nothing upgradeable just warn; inventing data is not an option.
     */
    private function ensurePeakCards(array $scenes): array
    {
        $peaks = ExplainerRegistry::peakTemplates();
        $interval = max(15, ExplainerRegistry::peakIntervalSeconds());
        if (empty($peaks)) {
            return $scenes;
        }

        $counterCap = ExplainerRegistry::maxPerVideo('big_counter');
        $counters = count(array_filter($scenes, fn ($s) => $s['layout_template'] === 'big_counter'));

        $windowStart = 0.0;
        $cursor = 0.0;
        $window = [];
        $flush = function (array $window) use (&$scenes, $peaks, &$counters, $counterCap, &$windowStart): void {
            $hasPeak = false;
            foreach ($window as $i) {
                if (in_array($scenes[$i]['layout_template'], $peaks, true)) {
                    $hasPeak = true;
                    break;
                }
            }
            if ($hasPeak) {
                return;
            }

            foreach ($window as $i) {
                $scene = $scenes[$i];
                if ($scene['layout_template'] !== 'stat_spotlight') {
                    continue;
                }
                if ($counterCap !== null && $counters >= $counterCap) {
                    break;
                }
                $slot = $scene['slots']['slot_stat'] ?? null;
                if (!is_array($slot) || !preg_match('/\d/', (string) ($slot['heading'] ?? ''))) {
                    continue;
                }
                $scenes[$i]['layout_template'] = 'big_counter';
                $scenes[$i]['slots'] = ['slot_counter' => $slot];
                $counters++;
                $this->changed = true;
                $this->warn("Scene {$scene['scene_id']}: promoted stat_spotlight -> big_counter (peak beat for the {$windowStart}s window).");
                return;
            }

            $this->warn(sprintf('No peak card in the window starting at %.0fs (nothing safely upgradeable).', $windowStart));
        };

        foreach ($scenes as $i => $scene) {
            if ($cursor - $windowStart >= $interval) {
                $flush($window);
                $window = [];
                $windowStart = $cursor;
            }
            $window[] = $i;
            $cursor += (float) ($scene['duration_seconds'] ?? 0);
        }
        // The trailing partial window only counts when it is most of a full
        // one — a 10-second tail doesn't deserve its own exclamation mark.
        if ($cursor - $windowStart >= $interval * 0.6) {
            $flush($window);
        }

        return $scenes;
    }

    /**
     * Pacing rule: never let the same layout_template run more than twice in a
     * row. Safely swaps split layouts (which share slot shapes) to break runs;
     * other repeats are left to the prompt to avoid unsafe slot remapping.
     */
    private function enforceVariety(array $scenes): array
    {
        $run = 0;
        $prev = null;

        foreach ($scenes as $i => $scene) {
            $tpl = $scene['layout_template'];
            $run = $tpl === $prev ? $run + 1 : 1;

            if ($run > 2) {
                $swapped = $this->swapForVariety($scene);
                if ($swapped !== null) {
                    $this->warn("Scene {$scene['scene_id']}: broke a run of '{$tpl}' -> '{$swapped['layout_template']}' for variety.");
                    $scenes[$i] = $swapped;
                    $run = 1;
                }
            }

            $prev = $scenes[$i]['layout_template'];
        }

        return $scenes;
    }

    /**
     * Swap between the two split layouts (compatible slot shapes) to add
     * variety without breaking any slot content. Returns null if no safe swap.
     */
    private function swapForVariety(array $scene): ?array
    {
        if ($scene['layout_template'] === 'split_side_by_side') {
            $scene['layout_template'] = 'split_top_bottom';
            $scene['slots'] = [
                'slot_top' => $scene['slots']['slot_left'] ?? $this->genericTextBlock(''),
                'slot_bottom' => $scene['slots']['slot_right'] ?? $this->genericTextBlock(''),
            ];
            return $scene;
        }

        if ($scene['layout_template'] === 'split_top_bottom') {
            $scene['layout_template'] = 'split_side_by_side';
            $scene['slots'] = [
                'slot_left' => $scene['slots']['slot_top'] ?? $this->genericTextBlock(''),
                'slot_right' => $scene['slots']['slot_bottom'] ?? $this->genericTextBlock(''),
            ];
            return $scene;
        }

        return null;
    }

    private function validateScene(array $scene, int $order): array
    {
        $sceneId = is_string($scene['scene_id'] ?? null) && $scene['scene_id'] !== ''
            ? $scene['scene_id']
            : "scene_{$order}";

        $narrationText = '';
        if (isset($scene['narration'])) {
            $narrationText = is_array($scene['narration'])
                ? (string) ($scene['narration']['text'] ?? '')
                : (string) $scene['narration'];
        }

        $duration = (float) ($scene['duration_seconds'] ?? 0);
        if ($duration <= 0) {
            $duration = ExplainerRegistry::defaultSceneSeconds();
        }

        $template = (string) ($scene['layout_template'] ?? '');
        // outro_card is appended by appendOutro() only — a model that picks it
        // mid-video gets the safe fallback instead of a premature end card.
        // chapter_cover likewise: only the chapter planner inserts act breaks.
        if ($template === 'outro_card' || $template === 'chapter_cover') {
            $this->warn("Scene {$sceneId}: {$template} is auto-inserted, never selected -> single_focus.");
            return $this->fallbackScene($sceneId, $order, $duration, $narrationText);
        }
        if (!ExplainerRegistry::hasTemplate($template)) {
            $this->warn("Scene {$sceneId}: unknown layout_template \"{$template}\" -> single_focus.");
            return $this->fallbackScene($sceneId, $order, $duration, $narrationText);
        }

        // Tier A data cards live or die by their structured content — clamp
        // it here, or rewrite the scene to a safe template when it's broken.
        $scene = $this->normalizeCardScene($template, $sceneId, $scene, $narrationText);
        $template = (string) $scene['layout_template'];

        $declaredSlots = ExplainerRegistry::slotKeys($template);
        $rawSlots = is_array($scene['slots'] ?? null) ? $scene['slots'] : [];

        // Drop slot keys the LLM invented.
        foreach (array_keys($rawSlots) as $slotKey) {
            if (!in_array($slotKey, $declaredSlots, true)) {
                $this->warn("Scene {$sceneId}: slot \"{$slotKey}\" not part of \"{$template}\" -> dropped.");
                unset($rawSlots[$slotKey]);
            }
        }

        $cleanSlots = [];
        foreach ($declaredSlots as $slotKey) {
            $meta = ExplainerRegistry::slotMeta($template, $slotKey);
            $rawSlot = is_array($rawSlots[$slotKey] ?? null) ? $rawSlots[$slotKey] : null;

            // Optional slots (photo_stack's trailing prints) simply don't
            // exist when unfilled — no filler upload request is invented.
            if ($rawSlot === null && !empty($meta['optional'])) {
                continue;
            }

            if ($rawSlot === null) {
                $this->warn("Scene {$sceneId}: slot \"{$slotKey}\" missing -> safe default.");
                $content = $this->fillMissingSlot($meta, $narrationText);
            } else {
                $content = $this->validateSlot($sceneId, $template, $slotKey, $rawSlot, $narrationText);
            }

            // Floating panel / banner slots carry dock + width config.
            if (!empty($meta['dock_options'])) {
                $content = $this->applyDock($content, $meta, $rawSlot ?? []);
            }

            $cleanSlots[$slotKey] = $content;
        }

        $transition = (string) ($scene['transition'] ?? '');
        // Track whether the planner made a real choice: a missing/unknown
        // transition is "defaulted" and open to the relation's signature cut.
        $transitionExplicit = in_array($transition, ExplainerRegistry::transitions(), true);
        if (!$transitionExplicit) {
            $transition = ExplainerRegistry::defaultTransition();
        }

        // Story relation to the previous scene (copilot.md §3.2). Unknown
        // values are dropped, not defaulted — a wrong relation would force a
        // wrong signature cut.
        $relation = (string) ($scene['relation'] ?? '');
        if (!in_array($relation, ExplainerRegistry::relationNames(), true)) {
            $relation = '';
        }

        // Pace the scene to its content: long narration / many bullets need
        // more time. Never shorter than the LLM asked, clamped to a sane range.
        $duration = $this->paceDuration($duration, $narrationText, $cleanSlots);

        $mood = (string) ($scene['mood'] ?? ExplainerRegistry::defaultMood());
        if (!in_array($mood, ExplainerRegistry::moods(), true)) {
            $mood = ExplainerRegistry::defaultMood();
        }

        return [
            'scene_id' => $sceneId,
            'order' => $order,
            'duration_seconds' => round($duration, 2),
            'narration' => ['text' => $narrationText],
            'layout_template' => $template,
            'slots' => $cleanSlots,
            'transition' => $transition,
            '_transition_explicit' => $transitionExplicit,
            'relation' => $relation !== '' ? $relation : null,
            'mood' => $mood,
        ];
    }

    /**
     * Derive a content-aware duration. ~2.5 narration words/sec, plus reading
     * time per bullet, blended with the LLM's own estimate and clamped.
     */
    private function paceDuration(float $llmDuration, string $narrationText, array $slots): float
    {
        $words = str_word_count($narrationText);
        $bullets = 0;
        $mathSeconds = 0.0;
        $isMath = false;
        foreach ($slots as $slot) {
            if (($slot['content_type'] ?? null) === 'text_block') {
                $bullets += count($slot['bullets'] ?? []);
            }
            // Worked-math lines are not bullets. A step is a line of algebra
            // the viewer has to READ, follow from the line above, and (when it
            // carries a citation) reconcile against a formula in the margin.
            // Budgeted at the old 1.15s/bullet the working flew past faster
            // than anyone could think, which is exactly how a "detailed" solve
            // still felt rushed.
            if (($slot['content_type'] ?? null) === 'math_steps') {
                $isMath = true;
                foreach ((array) ($slot['steps'] ?? []) as $step) {
                    $mathSeconds += 2.4;
                    if (trim((string) (is_array($step) ? ($step['ref'] ?? '') : '')) !== '') {
                        $mathSeconds += 1.4; // reading the "as we know" formula
                    }
                }
                if (trim((string) ($slot['rule']['name'] ?? '')) !== '') {
                    $mathSeconds += 1.5; // the rule strip lands before line one
                }
            }
        }

        $narrationSeconds = $words / 2.5;
        $bulletSeconds = $bullets * 1.15 + ($bullets > 0 ? 1.2 : 0);
        $estimate = max($narrationSeconds, $bulletSeconds, $mathSeconds, 3.0);

        // A math card earns a longer ceiling than an ordinary slide: six lines
        // of working plus their citations cannot be taught inside 14 seconds,
        // and the board is a continuous surface built to hold them.
        $ceiling = $isMath ? 34.0 : 14.0;

        return max(3.0, min($ceiling, max($llmDuration, $estimate)));
    }

    private function validateSlot(string $sceneId, string $template, string $slotKey, array $slot, string $narrationText): array
    {
        $allowed = ExplainerRegistry::allowedContentTypes($template, $slotKey);
        $contentType = (string) ($slot['content_type'] ?? '');

        // Stock b-roll (§8): "stock_video" is a VIDEO slot the system fills
        // itself. Normalise it here so downstream (uploads, assembly, the
        // renderer) sees an ordinary video slot carrying `stock_query`.
        if ($contentType === 'stock_video' && in_array('video', $allowed, true)) {
            $query = trim((string) ($slot['query'] ?? $slot['stock_query'] ?? ''));
            if ($query !== '') {
                $slot['content_type'] = $contentType = 'video';
                $slot['stock_query'] = mb_substr($query, 0, 60);
                $slot['asset_request'] = ['description' => 'Stock b-roll: ' . $slot['stock_query']];
                $this->warn("Scene {$sceneId}/{$slotKey}: stock b-roll \"{$slot['stock_query']}\".");
            }
        }

        if (!in_array($contentType, $allowed, true)) {
            $this->warn("Scene {$sceneId}/{$slotKey}: content_type \"{$contentType}\" not allowed -> defaulting.");
            return $this->fillMissingSlot(ExplainerRegistry::slotMeta($template, $slotKey), $narrationText, $slot);
        }

        return match ($contentType) {
            'image', 'video' => $this->validateMediaSlot($sceneId, $slotKey, $contentType, $slot, $narrationText),
            'text_block' => $this->validateTextSlot($sceneId, $slotKey, $slot, $narrationText),
            'explanation_box' => $this->validateExplanationSlot($slot, $narrationText),
            // Structured card contents were already clamped by
            // normalizeCardScene; re-clamping here is idempotent and keeps
            // this the single gate every slot passes through.
            'versus' => $this->clampVersusContent($slot) ?? $this->genericTextBlock($narrationText),
            'chart' => $this->clampChartContent($slot) ?? $this->genericTextBlock($narrationText),
            'proscons' => $this->clampProsconsContent($slot) ?? $this->genericTextBlock($narrationText),
            'icons' => $this->clampIconsContent($slot) ?? $this->genericTextBlock($narrationText),
            'timeline_nodes' => $this->clampTimelineContent($slot) ?? $this->genericTextBlock($narrationText),
            'steps' => $this->clampStepsContent($slot) ?? $this->genericTextBlock($narrationText),
            'ranking' => $this->clampRankingContent($slot) ?? $this->genericTextBlock($narrationText),
            'meter' => $this->clampMeterContent($slot) ?? $this->genericTextBlock($narrationText),
            'map' => $this->clampMapContent($slot) ?? $this->genericTextBlock($narrationText),
            'headlines' => $this->clampHeadlinesContent($slot) ?? $this->genericTextBlock($narrationText),
            'myth_fact' => $this->clampMythFactContent($slot) ?? $this->genericTextBlock($narrationText),
            'pictogram' => $this->clampPictogramContent($slot) ?? $this->genericTextBlock($narrationText),
            'formula' => $this->clampFormulaAnatomyContent($slot) ?? $this->genericTextBlock($narrationText),
            'cycle' => $this->clampCycleContent($slot) ?? $this->genericTextBlock($narrationText),
            'spectrum' => $this->clampSpectrumContent($slot) ?? $this->genericTextBlock($narrationText),
            'quadrant' => $this->clampQuadrantContent($slot) ?? $this->genericTextBlock($narrationText),
            'layers' => $this->clampLayerStackContent($slot) ?? $this->genericTextBlock($narrationText),
            'venn' => $this->clampVennContent($slot) ?? $this->genericTextBlock($narrationText),
            'term' => $this->clampTermContent($slot) ?? $this->genericTextBlock($narrationText),
            'practice' => $this->clampPracticeContent($slot) ?? $this->genericTextBlock($narrationText),
            'mistake' => $this->clampMistakeContent($slot) ?? $this->genericTextBlock($narrationText),
            'receipt' => $this->clampReceiptContent($slot) ?? $this->genericTextBlock($narrationText),
            'proportion' => $this->clampProportionContent($slot) ?? $this->genericTextBlock($narrationText),
            'scale' => $this->clampScaleContent($slot) ?? $this->genericTextBlock($narrationText),
            'evidence' => $this->clampEvidenceContent($slot) ?? $this->genericTextBlock($narrationText),
            'decision' => $this->clampDecisionContent($slot) ?? $this->genericTextBlock($narrationText),
            'hierarchy' => $this->clampHierarchyContent($slot) ?? $this->genericTextBlock($narrationText),
            'math_steps' => $this->clampMathStepsContent($slot) ?? $this->genericTextBlock($narrationText),
            'geometry' => $this->clampGeometryContent($slot) ?? $this->genericTextBlock($narrationText),
            'function_plot' => $this->clampFunctionPlotContent($slot) ?? $this->genericTextBlock($narrationText),
            'scenario' => $this->clampScenarioContent($slot, $narrationText) ?? $this->genericTextBlock($narrationText),
            default => $this->genericTextBlock($narrationText),
        };
    }

    /**
     * Clamp a pictogram people-share; null unless the numbers make a share.
     * Forgiving of a lazy model: a bare percentage in `filled` (of missing or
     * of=100) is redrawn as X in 10 with the clean "%" display kept.
     */
    private function clampPictogramContent(array $slot): ?array
    {
        $filled = $slot['filled'] ?? null;
        if (!is_numeric($filled)) {
            return null;
        }
        $filled = (float) $filled;
        $of = $slot['of'] ?? null;
        $unit = trim((string) ($slot['unit'] ?? ''));
        if (!is_numeric($of)) {
            if ($filled > 20 && $filled <= 100) {
                $filled /= 10;
                $unit = '%';
            }
            $of = 10;
        }
        $of = (int) round((float) $of);
        if ($of === 100) {
            // A percentage denominator reads as 10 people, not 100 specks.
            $of = 10;
            $filled /= 10;
            $unit = '%';
        }
        if ($of < 2 || $of > 20) {
            return null;
        }
        $filled = round(max(0.0, min((float) $of, $filled)), 1);
        if ($filled <= 0) {
            return null;
        }

        $clean = [
            'content_type' => 'pictogram',
            'filled' => $filled,
            'of' => $of,
        ];
        if ($unit === '%') {
            $clean['unit'] = '%';
        }
        $label = trim((string) ($slot['label'] ?? $slot['caption'] ?? ''));
        if ($label !== '') {
            $clean['label'] = mb_substr($label, 0, 80);
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }

        return $clean;
    }

    /**
     * Clamp a formula-anatomy payload; null unless the formula carries at
     * least 2 labelled parts whose `match` is literally IN the formula. The
     * renderer anchors each label to its match's typeset atoms — a match the
     * formula does not contain (the model paraphrased or simplified it) is
     * unanchorable and is dropped HERE, never drawn wrong there. Formula and
     * matches are linearized together so the substring test survives ± √ ².
     */
    private function clampFormulaAnatomyContent(array $slot): ?array
    {
        $formula = $this->linearizeMathSymbols((string) ($slot['formula'] ?? ''), true);
        if ($formula === '' || mb_strlen($formula) > 80) {
            return null;
        }

        $parts = [];
        $seen = [];
        foreach ((array) ($slot['parts'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $match = $this->linearizeMathSymbols((string) ($raw['match'] ?? ''), true);
            // The label is a sentence ABOUT the piece ("radical term under
            // sqrt"), so it takes the prose pass — otherwise the chip prints
            // notation the reader is not supposed to be reading.
            $label = $this->linearizeMathSymbols(trim((string) ($raw['label'] ?? '')));
            if ($match === '' || $label === '' || isset($seen[$match])) {
                continue;
            }
            if (!str_contains($formula, $match)) {
                continue;
            }
            // A match made of nothing but STRUCTURE ("sqrt", "frac{}{}") has no
            // atoms once it is typeset — the radical becomes a drawn glyph, so
            // the renderer's atom-run lookup finds nothing and drops the chip
            // without a word. Project 91 spent one of its four parts on a
            // "square root" label that could never appear. Drop it here so the
            // < 2 parts rule can degrade the card honestly instead.
            if (preg_replace('/sqrt|frac|[{}()\s]/', '', $match) === '') {
                continue;
            }
            $seen[$match] = true;
            // A prefix of a substring is still a substring, so the length cap
            // cannot un-anchor the match.
            $parts[] = ['match' => mb_substr($match, 0, 30), 'label' => mb_substr($label, 0, 48)];
            if (count($parts) >= 4) {
                break;
            }
        }
        if (count($parts) < 2) {
            return null;
        }

        $clean = [
            'content_type' => 'formula',
            'formula' => $formula,
            'parts' => $parts,
        ];
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }

        return $clean;
    }

    /**
     * Clamp a myth/fact pair; null unless both sides carry real text. The
     * model's own quote marks are stripped — the card typesets its own.
     */
    private function clampMythFactContent(array $slot): ?array
    {
        $strip = fn (string $s): string => trim(trim($s), "\"'\u{201C}\u{201D}\u{2018}\u{2019} ");
        $myth = $strip((string) ($slot['myth'] ?? ''));
        $fact = $strip((string) ($slot['fact'] ?? ''));
        if ($myth === '' || $fact === '') {
            return null;
        }
        $clean = [
            'content_type' => 'myth_fact',
            'myth' => mb_substr($myth, 0, 140),
            'fact' => mb_substr($fact, 0, 140),
        ];
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }

        return $clean;
    }

    /** Legal map framings — must match the renderer's REGIONS in geo/world.ts. */
    private const MAP_REGIONS = ['world', 'europe', 'asia', 'africa', 'north_america', 'south_america', 'oceania'];

    /** Clamp a map payload; null without at least one usable pin. */
    private function clampMapContent(array $slot): ?array
    {
        $pins = [];
        foreach ((array) ($slot['pins'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 24);
            $lat = $raw['lat'] ?? null;
            $lon = $raw['lon'] ?? ($raw['lng'] ?? null);
            if ($label === '' || !is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }
            $pins[] = [
                'label' => $label,
                'lat' => max(-85.0, min(85.0, round((float) $lat, 3))),
                'lon' => max(-180.0, min(180.0, round((float) $lon, 3))),
            ];
            if (count($pins) >= 2) {
                break;
            }
        }
        if (empty($pins)) {
            return null;
        }

        $region = strtolower(trim((string) ($slot['region'] ?? '')));
        if (!in_array($region, self::MAP_REGIONS, true)) {
            $region = 'world';
        }

        return array_filter([
            'content_type' => 'map',
            'pins' => $pins,
            'region' => $region,
            'route' => count($pins) === 2 && filter_var($slot['route'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Clamp a headlines payload; null below 2 usable chips. */
    private function clampHeadlinesContent(array $slot): ?array
    {
        $items = [];
        foreach ((array) ($slot['items'] ?? []) as $raw) {
            $text = is_array($raw) ? trim((string) ($raw['text'] ?? '')) : trim((string) $raw);
            if ($text === '') {
                continue;
            }
            $items[] = [
                'text' => mb_substr($text, 0, 70),
                'source' => is_array($raw) ? mb_substr(trim((string) ($raw['source'] ?? '')), 0, 20) : '',
            ];
            if (count($items) >= 3) {
                break;
            }
        }
        if (count($items) < 2) {
            return null;
        }

        return array_filter([
            'content_type' => 'headlines',
            'items' => $items,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Clamp a timeline payload; null below 2 usable nodes. */
    private function clampTimelineContent(array $slot): ?array
    {
        $nodes = [];
        foreach ((array) ($slot['nodes'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $date = mb_substr(trim((string) ($raw['date'] ?? '')), 0, 14);
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 44);
            if ($date === '' && $label === '') {
                continue;
            }
            $nodes[] = ['date' => $date, 'label' => $label];
            if (count($nodes) >= 6) {
                break;
            }
        }
        if (count($nodes) < 2) {
            return null;
        }

        return array_filter([
            'content_type' => 'timeline_nodes',
            'nodes' => $nodes,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Clamp a steps payload; null below 3 usable steps. */
    private function clampStepsContent(array $slot): ?array
    {
        $whitelist = ExplainerRegistry::iconNames();
        $items = [];
        foreach ((array) ($slot['items'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 30);
            if ($label === '') {
                continue;
            }
            $icon = trim((string) ($raw['icon'] ?? ''));
            if ($icon !== '' && !empty($whitelist) && !in_array($icon, $whitelist, true)) {
                $icon = '';
            }
            $items[] = ['label' => $label, 'icon' => $icon];
            if (count($items) >= 5) {
                break;
            }
        }
        if (count($items) < 3) {
            return null;
        }

        return array_filter([
            'content_type' => 'steps',
            'items' => $items,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Clamp a decision payload; null unless the root question and BOTH
     * branches resolve.
     *
     * The card's contract is a tree at most two levels deep, and depth is not
     * something a model reliably respects — it will happily nest a third
     * question that the layout has nowhere to put. So depth is enforced here
     * rather than hoped for: a branch is read as EITHER a leaf (an outcome) or
     * one more question with exactly two leaves, and anything deeper is cut
     * off at that boundary.
     *
     * A dangling branch is fatal to the whole card — a flowchart with a path
     * that goes nowhere is worse than a bulleted list — so both branches must
     * resolve or the payload is rejected and the scene degrades to text.
     */
    private function clampDecisionContent(array $slot): ?array
    {
        $question = mb_substr(trim((string) ($slot['question'] ?? $slot['heading'] ?? '')), 0, 64);
        if ($question === '') {
            return null;
        }

        $rawBranches = array_values(array_filter(
            (array) ($slot['branches'] ?? []),
            fn ($b) => is_array($b)
        ));
        if (count($rawBranches) < 2) {
            return null;
        }

        $defaults = ['Yes', 'No'];
        $branches = [];
        foreach (array_slice($rawBranches, 0, 2) as $i => $raw) {
            $label = mb_substr(trim((string) ($raw['label'] ?? $raw['answer'] ?? '')), 0, 14);
            if ($label === '') {
                $label = $defaults[$i];
            }

            // A second-level question needs two real leaves to be worth the
            // extra row; with fewer, the branch collapses onto whatever single
            // outcome survived so the path still ENDS somewhere.
            $subQuestion = mb_substr(trim((string) ($raw['question'] ?? '')), 0, 48);
            $leaves = [];
            foreach ((array) ($raw['branches'] ?? []) as $j => $leaf) {
                if (!is_array($leaf)) {
                    continue;
                }
                $leafOutcome = mb_substr(trim((string) ($leaf['outcome'] ?? '')), 0, 36);
                if ($leafOutcome === '') {
                    continue;
                }
                $leafLabel = mb_substr(trim((string) ($leaf['label'] ?? $leaf['answer'] ?? '')), 0, 14);
                $leaves[] = [
                    'label' => $leafLabel !== '' ? $leafLabel : ($defaults[count($leaves)] ?? 'Yes'),
                    'outcome' => $leafOutcome,
                ];
                if (count($leaves) >= 2) {
                    break;
                }
            }

            if ($subQuestion !== '' && count($leaves) === 2) {
                $branches[] = ['label' => $label, 'question' => $subQuestion, 'branches' => $leaves];
                continue;
            }

            $outcome = mb_substr(trim((string) ($raw['outcome'] ?? '')), 0, 40);
            if ($outcome === '' && count($leaves) === 1) {
                $outcome = $leaves[0]['outcome'];
            }
            if ($outcome === '') {
                return null; // a path that goes nowhere
            }
            $branches[] = ['label' => $label, 'outcome' => $outcome];
        }

        if (count($branches) !== 2) {
            return null;
        }

        $clean = [
            'content_type' => 'decision',
            'question' => $question,
            'branches' => $branches,
        ];
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '' && $heading !== $question) {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Clamp a hierarchy payload; null unless there is a root and at least TWO
     * usable branches — one branch is not a structure, it is a single_focus.
     *
     * The tree is capped at two levels below the root (decision_tree's depth
     * lesson): each branch may carry its own children (grandchildren, LABEL
     * ONLY — an org chart's leaves), and anything deeper is flattened away. A
     * lone grandchild is dropped rather than drawn, because one leaf hanging off
     * a spine is not a sub-level; the branch then simply has none.
     */
    private function clampHierarchyContent(array $slot): ?array
    {
        $root = mb_substr(trim((string) ($slot['root'] ?? $slot['title'] ?? $slot['name'] ?? '')), 0, 28);
        if ($root === '') {
            return null;
        }

        $rawChildren = array_values(array_filter(
            (array) ($slot['children'] ?? $slot['branches'] ?? $slot['items'] ?? []),
            fn ($c) => is_array($c) || is_string($c)
        ));

        $children = [];
        foreach ($rawChildren as $raw) {
            if (is_string($raw)) {
                $raw = ['label' => $raw];
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? $raw['name'] ?? '')), 0, 22);
            if ($label === '') {
                continue;
            }
            $child = ['label' => $label];
            $caption = trim((string) ($raw['caption'] ?? $raw['note'] ?? ''));
            if ($caption !== '') {
                $child['caption'] = mb_substr($caption, 0, 40);
            }

            $grand = [];
            foreach ((array) ($raw['children'] ?? $raw['items'] ?? []) as $g) {
                $gl = mb_substr(trim((string) (is_array($g) ? ($g['label'] ?? $g['name'] ?? '') : $g)), 0, 18);
                if ($gl === '') {
                    continue;
                }
                $grand[] = ['label' => $gl];
                if (count($grand) >= 4) {
                    break;
                }
            }
            if (count($grand) >= 2) {
                $child['children'] = $grand;
            }

            $children[] = $child;
            if (count($children) >= 4) {
                break;
            }
        }

        if (count($children) < 2) {
            return null;
        }

        $clean = [
            'content_type' => 'hierarchy',
            'root' => $root,
            'children' => $children,
        ];
        // Highlight is validated against the FINAL branch list — a stale index
        // (the model counted a branch we dropped) is discarded, not guessed.
        $highlight = $slot['highlight_index'] ?? null;
        if (is_numeric($highlight) && isset($children[(int) $highlight])) {
            $clean['highlight_index'] = (int) $highlight;
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '' && $heading !== $root) {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Parse a receipt figure. The model is told to send plain numbers, but it
     * reliably sends "$1,200" or "1 200" anyway, so the currency symbols,
     * separators and stray spaces come off before the cast. Parenthesised
     * figures are accounting negatives: "(20)" is -20.
     */
    private function receiptNumber($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (!is_string($raw)) {
            return null;
        }

        $s = trim($raw);
        $negative = (bool) preg_match('/^\((.*)\)$/', $s, $m);
        if ($negative) {
            $s = $m[1];
        }
        // Strip everything that is not a digit, sign or decimal point.
        $s = preg_replace('/[^0-9.\-]/u', '', $s);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }

        return (float) $s * ($negative ? -1 : 1);
    }

    /**
     * Clamp a receipt payload; null below 2 usable rows.
     *
     * The card's whole promise is that the figures ADD UP, and that is the one
     * thing a language model cannot be trusted to do: it writes a plausible
     * list and a plausible total that disagree by seven. So the total is not
     * taken on faith — the ROWS are the evidence and the total is recomputed
     * from them. A supplied total that disagrees is replaced (and reported),
     * never printed. This is the same guarantees-over-prompts rule the maths
     * path uses for substitute-back checking.
     */
    private function clampReceiptContent(array $slot, ?string &$note = null): ?array
    {
        $rows = [];
        foreach ((array) ($slot['rows'] ?? $slot['items'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 28);
            $value = $this->receiptNumber($raw['value'] ?? null);
            if ($label === '' || $value === null) {
                continue;
            }
            $rows[] = ['label' => $label, 'value' => round($value, 2)];
            if (count($rows) >= 8) {
                break;
            }
        }
        if (count($rows) < 2) {
            return null;
        }

        $sum = round(array_sum(array_column($rows, 'value')), 2);
        $claimed = $this->receiptNumber($slot['total'] ?? null);
        if ($claimed !== null && abs($claimed - $sum) > 0.011) {
            $note = "receipt total {$claimed} did not match its rows ({$sum}) -> corrected";
        }

        $clean = [
            'content_type' => 'receipt',
            'rows' => $rows,
            'total' => $sum,
        ];

        $totalLabel = trim((string) ($slot['total_label'] ?? ''));
        if ($totalLabel !== '') {
            $clean['total_label'] = mb_substr($totalLabel, 0, 20);
        }
        $unit = trim((string) ($slot['unit'] ?? ''));
        if ($unit !== '') {
            $clean['unit'] = mb_substr($unit, 0, 6);
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Clamp a term payload; null without BOTH the word and its definition —
     * a term card with no meaning is just a big word, and a definition with
     * no term has nothing to define. Everything else is decoration.
     *
     * `phonetic` is left alone apart from length: IPA is full of characters a
     * sanitiser would happily destroy (ˈ ː ə ɜ), and the card only ever prints
     * it, so there is nothing to protect against.
     */
    private function clampTermContent(array $slot): ?array
    {
        $term = mb_substr(trim((string) ($slot['term'] ?? $slot['heading'] ?? '')), 0, 28);
        $definition = mb_substr(trim((string) ($slot['definition'] ?? $slot['body'] ?? '')), 0, 120);
        if ($term === '' || $definition === '') {
            return null;
        }

        $clean = [
            'content_type' => 'term',
            'term' => $term,
            'definition' => $definition,
        ];

        $phonetic = trim((string) ($slot['phonetic'] ?? ''));
        if ($phonetic !== '') {
            $clean['phonetic'] = mb_substr($phonetic, 0, 32);
        }
        $pos = trim((string) ($slot['part_of_speech'] ?? ''));
        if ($pos !== '') {
            $clean['part_of_speech'] = mb_substr($pos, 0, 16);
        }
        // `heading` doubles as the term source above, so only keep it as a
        // heading when it is genuinely something else.
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '' && $heading !== $term) {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Clamp a mistake payload; null without BOTH lines.
     *
     * The card prints one line labelled WRONG and one labelled CORRECT, and
     * those labels are claims — to a viewer who is watching precisely because
     * they are unsure which is which. Two ways to get it catastrophically
     * wrong, both of which a model does fluently:
     *
     * (1) THE LABELS SWAPPED, or a "wrong" line that is simply true. Where the
     *     arithmetic can be settled ({@see MathTruth}) a TRUE line labelled
     *     wrong rejects the payload outright.
     * (2) A "CORRECT" LINE THAT IS FALSE — the video teaching a second error
     *     while correcting the first. Rejected too, but only when the line is
     *     pure arithmetic: a line WITH an unknown may be a conditional
     *     equation ("x^2 = 2x" is a fine step of working that is false at most
     *     x), and rejecting those would throw away honest cards.
     *
     * Either way the refusal degrades to text carrying the heading and the
     * explanation but NEITHER line — a card that could not be trusted to
     * label them must not print them unlabelled and hope.
     *
     * Two identical lines are refused as well: nothing is being corrected, and
     * the viewer is left hunting for a difference that does not exist.
     */
    private function clampMistakeContent(array $slot, ?string &$note = null): ?array
    {
        // A mistake card exists to put two EQUATIONS side by side, so its two
        // lines need the same notation pass the working gets — otherwise the
        // wrong/right pair was the one place a raw "sqrt(20)" or a stray ±
        // still reached the screen as literal source (projects 98/100/101).
        $wrong = mb_substr($this->linearizeMathSymbols(trim((string) ($slot['wrong'] ?? '')), true), 0, 60);
        $correct = mb_substr($this->linearizeMathSymbols(trim((string) ($slot['correct'] ?? $slot['right'] ?? $slot['fix'] ?? '')), true), 0, 60);
        if ($wrong === '' || $correct === '') {
            return null;
        }

        $flat = fn (string $s): string => (string) preg_replace('/\s+/', '', mb_strtolower($s));
        if ($flat($wrong) === $flat($correct)) {
            $note = 'mistake card shows the same line twice';

            return null;
        }

        if (MathTruth::holds($wrong) === true) {
            $note = "mistake card labels a TRUE line as wrong ('{$wrong}')";

            return null;
        }
        if (MathTruth::holds($correct) === false && MathTruth::unknowns($correct) === 0) {
            $note = "mistake card's corrected line is arithmetically false ('{$correct}')";

            return null;
        }

        $clean = [
            'content_type' => 'mistake',
            'wrong' => $wrong,
            'correct' => $correct,
        ];

        $why = $this->linearizeMathSymbols(trim((string) ($slot['why'] ?? '')));
        if ($why !== '') {
            $clean['why'] = mb_substr($why, 0, 100);
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        // Same as the practice card: the kicker already reads COMMON MISTAKE.
        if ($heading !== '' && mb_strtolower($heading) !== 'common mistake') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Clamp a practice payload; null without BOTH a problem and an answer.
     *
     * Two invariants live here rather than in the prompt, because both are
     * things a model gets wrong confidently:
     *
     * (1) A PROBLEM WITH NO ANSWER IS A CLIFFHANGER. The viewer is asked to
     *     work and then abandoned; that is worse than not asking. So the
     *     answer is required and the scene degrades to plain text without it.
     * (2) A WRONG ANSWER IS THE ONE UNFORGIVABLE FAILURE — it teaches the
     *     error. Where the arithmetic can be settled ({@see PracticeCheck})
     *     it IS settled, and an answer that provably fails substitution
     *     rejects the whole payload: the problem survives as a text beat and
     *     the wrong figure is printed nowhere at all. The composer runs the
     *     same check while the model is still on the line and retries; this
     *     is the backstop for a card that arrived any other way.
     *
     * A prompt that already contains the answer verbatim ("solve x = 7")
     * gives the game away and is refused for the same reason — the card's
     * pause is meaningless if the answer is on screen throughout.
     */
    private function clampPracticeContent(array $slot, ?string &$note = null): ?array
    {
        // Same reason as the mistake card: a practice prompt IS an equation
        // most of the time, so it gets the notation pass before anything else
        // reads it (PracticeCheck evaluates braces fine — TinyExpr treats them
        // as parentheses).
        $prompt = mb_substr($this->linearizeMathSymbols(trim((string) ($slot['prompt'] ?? $slot['question'] ?? $slot['body'] ?? ''))), 0, 90);
        $answer = mb_substr($this->linearizeMathSymbols(trim((string) ($slot['answer'] ?? '')), true), 0, 40);
        if ($prompt === '' || $answer === '') {
            return null;
        }

        $flat = fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', mb_strtolower($s)));
        if (str_contains($answer, '=') && str_contains($flat($prompt), $flat($answer))) {
            $note = 'practice prompt already showed its own answer';

            return null;
        }

        $why = null;
        if (PracticeCheck::disproves($prompt, $answer, $why)) {
            $note = "practice {$why}";

            return null;
        }

        $clean = [
            'content_type' => 'practice',
            'prompt' => $prompt,
            'answer' => $answer,
        ];

        $hint = $this->linearizeMathSymbols(trim((string) ($slot['hint'] ?? '')));
        if ($hint !== '') {
            $clean['hint'] = mb_substr($hint, 0, 70);
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        // The card already prints YOUR TURN as its kicker, so a heading that
        // just says it again stacks the same two words twice down the frame.
        if ($heading !== '' && $heading !== $prompt && mb_strtolower($heading) !== 'your turn') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Clamp a venn payload; null below 2 usable sets. Duplicate set labels
     * collapse to the first (two circles with the same name is not a Venn),
     * and a 4th set is dropped rather than crammed in — the card's geometry
     * only arranges 2 or 3 circles.
     *
     * `overlap_label` is optional but load-bearing: without it the card still
     * draws (the tinted middle carries meaning on its own), so it is never a
     * reason to reject the payload.
     */
    private function clampVennContent(array $slot): ?array
    {
        $sets = [];
        $seen = [];
        foreach ((array) ($slot['sets'] ?? $slot['items'] ?? []) as $raw) {
            $label = mb_substr(trim((string) (is_array($raw) ? ($raw['label'] ?? '') : $raw)), 0, 20);
            if ($label === '' || isset($seen[mb_strtolower($label)])) {
                continue;
            }
            $seen[mb_strtolower($label)] = true;

            $set = ['label' => $label];
            $caption = is_array($raw) ? mb_substr(trim((string) ($raw['caption'] ?? '')), 0, 32) : '';
            if ($caption !== '') {
                $set['caption'] = $caption;
            }
            $sets[] = $set;
            if (count($sets) >= 3) {
                break;
            }
        }
        if (count($sets) < 2) {
            return null;
        }

        $clean = [
            'content_type' => 'venn',
            'sets' => $sets,
        ];
        $overlap = trim((string) ($slot['overlap_label'] ?? ''));
        if ($overlap !== '') {
            $clean['overlap_label'] = mb_substr($overlap, 0, 28);
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Clamp a scale payload; null below 2 positive items — you cannot compare
     * a thing with nothing.
     *
     * Each item's `scale` is the LINEAR fraction of the largest (value over
     * max), computed here, so the drawn size is never a model's guess. Linear
     * and not by area: this card answers "how big is it", and a viewer reads a
     * square's SIDE, so scaling area would quietly halve every ratio it
     * printed.
     *
     * The refusal that gives the card its name: past a 40x spread the smallest
     * thing is under 2.5% of the largest — a speck that reads as nothing at all
     * — so `to_scale` goes false, the renderer draws only the largest and
     * states each ratio as a chip instead. Drawing a "roughly proportional"
     * speck would be the lie; saying "1,200 times smaller" is the truth the
     * frame can actually hold.
     */
    private function clampScaleContent(array $slot): ?array
    {
        $items = [];
        $seen = [];
        foreach ((array) ($slot['scale_items'] ?? $slot['items'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 24);
            $value = $this->receiptNumber($raw['value'] ?? $raw['size'] ?? null);
            if ($label === '' || $value === null || $value <= 0 || isset($seen[mb_strtolower($label)])) {
                continue;
            }
            $seen[mb_strtolower($label)] = true;
            $item = ['label' => $label, 'value' => round($value, 4)];
            $note = mb_substr(trim((string) ($raw['note'] ?? $raw['caption'] ?? '')), 0, 40);
            if ($note !== '') {
                $item['note'] = $note;
            }
            $items[] = $item;
            if (count($items) >= 3) {
                break;
            }
        }
        if (count($items) < 2) {
            return null;
        }

        $max = max(array_column($items, 'value'));
        $min = min(array_column($items, 'value'));
        $toScale = $max / $min <= 40.0;
        foreach ($items as $i => $item) {
            $items[$i]['scale'] = round($item['value'] / $max, 5);
            // The ratio is printed, so it is rounded the way a person would say
            // it: "1,200 times" and not "1,203.4 times".
            $ratio = $max / $item['value'];
            $items[$i]['ratio'] = $ratio < 10 ? round($ratio, 1) : round($ratio);
        }

        $clean = [
            'content_type' => 'scale',
            'scale_items' => $items,
            'to_scale' => $toScale,
        ];

        $shape = mb_strtolower(trim((string) ($slot['shape'] ?? '')));
        $clean['shape'] = in_array($shape, ['square', 'circle'], true) ? $shape : 'square';
        $unit = trim((string) ($slot['unit'] ?? ''));
        if ($unit !== '') {
            $clean['unit'] = mb_substr($unit, 0, 8);
        }
        $highlight = $slot['highlight_index'] ?? null;
        if (is_numeric($highlight) && isset($items[(int) $highlight])) {
            $clean['highlight_index'] = (int) $highlight;
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Clamp an evidence payload — the "according to..." beat. Null (→ text)
     * unless there is BOTH a real finding AND a NAMEABLE source, because this
     * card's whole job is to lend a claim the authority of its provenance, and a
     * formal citation frame around "studies show" or "experts say" fabricates
     * exactly the authority the no_source lint warns about. A finding with no one
     * behind it degrades to a plain assertion, which is its honest shape.
     */
    private function clampEvidenceContent(array $slot): ?array
    {
        $finding = trim((string) ($slot['finding'] ?? $slot['claim'] ?? $slot['fact'] ?? ''));
        $source = trim((string) (
            $slot['source'] ?? $slot['study'] ?? $slot['institution'] ?? $slot['publication'] ?? ''
        ));
        if ($finding === '' || $source === '' || $this->isVagueSource($source)) {
            return null;
        }

        $clean = [
            'content_type' => 'evidence',
            'finding' => mb_substr($finding, 0, 160),
            'source' => mb_substr($source, 0, 48),
        ];
        $year = trim((string) ($slot['year'] ?? $slot['date'] ?? ''));
        if ($year !== '') {
            $clean['year'] = mb_substr($year, 0, 12);
        }
        $sample = trim((string) ($slot['sample'] ?? $slot['sample_size'] ?? $slot['n'] ?? ''));
        if ($sample !== '') {
            $clean['sample'] = mb_substr($sample, 0, 40);
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * A source is only provenance if it NAMES someone. A bare vague attribution
     * ("studies show", "research", "experts") names no one, so it is the very
     * thing the evidence card must NOT dress up as a citation — it is refused
     * and the beat degrades to text. Trailing punctuation and an attribution
     * verb are peeled so "Studies show:" and "studies" collapse to one word.
     */
    private function isVagueSource(string $source): bool
    {
        $s = mb_strtolower(trim($source));
        $s = preg_replace('/[\s\p{P}]+$/u', '', $s) ?? $s;
        $s = preg_replace(
            '/\s+(show|shows|showed|say|says|said|find|finds|found|suggest|suggests|report|reports|confirm|confirms|prove|proves|reveal|reveals|claim|claims)$/u',
            '',
            $s
        ) ?? $s;
        $s = trim($s);
        if (mb_strlen($s) < 3) {
            return true;
        }
        $vague = [
            'studies', 'study', 'a study', 'the study', 'one study', 'research',
            'the research', 'researchers', 'science', 'the science', 'scientists',
            'experts', 'expert', 'data', 'the data', 'evidence', 'sources',
            'source', 'reports', 'a report', 'the report', 'a survey', 'surveys',
            'analysts', 'critics', 'history', 'some', 'they', 'people',
            'some people', 'many people', 'most people', 'everyone', 'many', 'most',
        ];

        return in_array($s, $vague, true);
    }

    /**
     * Clamp a proportion payload; null below 2 positive branches — one branch
     * is not a split, it is a big_counter.
     *
     * The card's whole promise is that the WIDTHS ARE THE NUMBERS, so the
     * shares are never taken from the model: each branch's `share` is computed
     * here from its value over the sum, and any percentage the model sent is
     * overwritten (and reported). This is receipt_card's recomputed-total rule
     * applied to a picture — a drawn proportion that disagrees with the figure
     * printed beside it is the one failure this template cannot survive.
     *
     * A negative branch is dropped rather than clamped: a ribbon cannot have
     * negative width, and a discount belongs on a receipt. A stated `total`
     * larger than the branches is honoured by drawing the LEFTOVER as its own
     * "Other" branch — otherwise every part silently inflates to fill a whole
     * it does not actually cover.
     */
    private function clampProportionContent(array $slot, ?string &$note = null): ?array
    {
        $branches = [];
        $seen = [];
        foreach ((array) ($slot['branches'] ?? $slot['slices'] ?? $slot['items'] ?? $slot['rows'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 24);
            $value = $this->receiptNumber($raw['value'] ?? $raw['amount'] ?? null);
            if ($label === '' || $value === null || $value <= 0 || isset($seen[mb_strtolower($label)])) {
                continue;
            }
            $seen[mb_strtolower($label)] = true;
            $branch = ['label' => $label, 'value' => round($value, 3)];
            $bnote = mb_substr(trim((string) ($raw['note'] ?? $raw['caption'] ?? '')), 0, 40);
            if ($bnote !== '') {
                $branch['note'] = $bnote;
            }
            $sent = $raw['share'] ?? $raw['percent'] ?? $raw['percentage'] ?? null;
            if (is_numeric($sent)) {
                // Carried only as far as the share pass below, which reports
                // the disagreement and then throws it away.
                $branch['sent_share'] = (float) $sent > 1 ? (float) $sent / 100 : (float) $sent;
            }
            $branches[] = $branch;
            if (count($branches) >= 5) {
                break;
            }
        }
        if (count($branches) < 2) {
            return null;
        }

        $sum = array_sum(array_column($branches, 'value'));
        $claimed = $this->receiptNumber($slot['total'] ?? null);
        // A whole the branches do not cover is drawn as a leftover, not
        // absorbed. Below 2% it is rounding, and above the 5-branch cap there
        // is nowhere to put it — in both cases the branches ARE the whole.
        if ($claimed !== null && $claimed > $sum && count($branches) < 5
            && ($claimed - $sum) / $claimed >= 0.02) {
            $branches[] = ['label' => 'Other', 'value' => round($claimed - $sum, 3)];
            $sum = $claimed;
            $note = 'proportion branches covered only part of the stated total -> leftover drawn as "Other"';
        }
        if ($sum <= 0) {
            return null;
        }

        foreach ($branches as $i => $branch) {
            // The share is DERIVED. Nothing the model wrote survives into it —
            // a sent percentage only ever earns itself a note.
            $branches[$i]['share'] = round($branch['value'] / $sum, 5);
            $sent = $branch['sent_share'] ?? null;
            unset($branches[$i]['sent_share']);
            if ($sent !== null && abs($sent - $branches[$i]['share']) > 0.02 && $note === null) {
                $note = 'proportion share for "' . $branch['label'] . '" disagreed with its value -> recomputed';
            }
        }

        // Emitted as `slices`, and the name took two collisions to find:
        // decision_tree owns `branches` on the renderer's slot type (a nested
        // yes/no leaf) and formula_anatomy owns `parts` (a labelled atom run) —
        // a slot field cannot be two shapes at once, the lesson iter 22 learned
        // from `right`. The model still writes `branches`, which is the natural
        // word for it, and this is where that is renamed.
        $clean = [
            'content_type' => 'proportion',
            'slices' => $branches,
            'total' => round($sum, 3),
        ];

        $source = mb_substr(trim((string) ($slot['source_label'] ?? $slot['source'] ?? '')), 0, 24);
        if ($source !== '') {
            $clean['source_label'] = $source;
        }
        $unit = trim((string) ($slot['unit'] ?? ''));
        if ($unit !== '') {
            $clean['unit'] = mb_substr($unit, 0, 6);
        }
        $highlight = $slot['highlight_index'] ?? null;
        if (is_numeric($highlight) && isset($branches[(int) $highlight])) {
            $clean['highlight_index'] = (int) $highlight;
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 80);
        }

        return $clean;
    }

    /**
     * Clamp a quadrant payload; null without all FOUR poles and 3 placeable
     * items — a 2x2 matrix with two items is a comparison, and versus_card
     * draws that better.
     *
     * Coordinates clamp into 0..1 (y measured UP from the bottom pole, the way
     * the beat says it), duplicate labels collapse to their first placement,
     * and a highlight index that no longer points at a surviving item is
     * dropped rather than guessed at — the same rules spectrum_card settled on.
     * Zone names are optional and independently droppable: the matrix is
     * readable without them, so a junk zone never sinks the card.
     */
    private function clampQuadrantContent(array $slot): ?array
    {
        $xAxis = is_array($slot['x_axis'] ?? null) ? $slot['x_axis'] : [];
        $yAxis = is_array($slot['y_axis'] ?? null) ? $slot['y_axis'] : [];
        $xLeft = mb_substr(trim((string) ($xAxis['left_label'] ?? '')), 0, 18);
        $xRight = mb_substr(trim((string) ($xAxis['right_label'] ?? '')), 0, 18);
        $yBottom = mb_substr(trim((string) ($yAxis['bottom_label'] ?? '')), 0, 18);
        $yTop = mb_substr(trim((string) ($yAxis['top_label'] ?? '')), 0, 18);
        if ($xLeft === '' || $xRight === '' || $yBottom === '' || $yTop === '') {
            return null;
        }

        $items = [];
        $seen = [];
        foreach ((array) ($slot['quadrant_items'] ?? $slot['items'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 20);
            $x = $raw['x'] ?? null;
            $y = $raw['y'] ?? null;
            if ($label === '' || !is_numeric($x) || !is_numeric($y) || isset($seen[mb_strtolower($label)])) {
                continue;
            }
            $seen[mb_strtolower($label)] = true;
            $items[] = [
                'label' => $label,
                'x' => round(max(0.0, min(1.0, (float) $x)), 3),
                'y' => round(max(0.0, min(1.0, (float) $y)), 3),
            ];
            if (count($items) >= 6) {
                break;
            }
        }
        if (count($items) < 3) {
            return null;
        }

        $clean = [
            'content_type' => 'quadrant',
            'x_axis' => ['left_label' => $xLeft, 'right_label' => $xRight],
            'y_axis' => ['bottom_label' => $yBottom, 'top_label' => $yTop],
            'quadrant_items' => $items,
        ];

        $zones = [];
        foreach (['top_left', 'top_right', 'bottom_left', 'bottom_right'] as $corner) {
            $name = mb_substr(trim((string) (is_scalar($slot['zones'][$corner] ?? null) ? $slot['zones'][$corner] : '')), 0, 16);
            if ($name !== '') {
                $zones[$corner] = $name;
            }
        }
        if ($zones !== []) {
            $clean['zones'] = $zones;
        }

        $highlight = $slot['highlight_index'] ?? null;
        if (is_numeric($highlight) && isset($items[(int) $highlight])) {
            $clean['highlight_index'] = (int) $highlight;
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 60);
        }

        return $clean;
    }

    /**
     * Clamp a spectrum payload; null without both poles and 2 placeable
     * items. Positions clamp into 0..1; duplicate labels collapse to their
     * first placement; a highlight index that no longer points at an item
     * is dropped rather than guessed.
     */
    private function clampSpectrumContent(array $slot): ?array
    {
        $axis = is_array($slot['axis'] ?? null) ? $slot['axis'] : [];
        $left = mb_substr(trim((string) ($axis['left_label'] ?? '')), 0, 18);
        $right = mb_substr(trim((string) ($axis['right_label'] ?? '')), 0, 18);
        if ($left === '' || $right === '') {
            return null;
        }

        $items = [];
        $seen = [];
        foreach ((array) ($slot['spectrum_items'] ?? $slot['items'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 20);
            $position = $raw['position'] ?? null;
            if ($label === '' || !is_numeric($position) || isset($seen[mb_strtolower($label)])) {
                continue;
            }
            $seen[mb_strtolower($label)] = true;
            $items[] = ['label' => $label, 'position' => round(max(0.0, min(1.0, (float) $position)), 3)];
            if (count($items) >= 5) {
                break;
            }
        }
        if (count($items) < 2) {
            return null;
        }

        $clean = [
            'content_type' => 'spectrum',
            'axis' => ['left_label' => $left, 'right_label' => $right],
            'spectrum_items' => $items,
        ];
        $highlight = $slot['highlight_index'] ?? null;
        if (is_numeric($highlight) && isset($items[(int) $highlight])) {
            $clean['highlight_index'] = (int) $highlight;
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 60);
        }

        return $clean;
    }

    /**
     * Clamp a layer-stack payload; null below 3 usable layers (two things on
     * top of each other are before/after or a split — a stack needs depth).
     * ORDER IS THE CONTENT: layers arrive top-first and are never rearranged
     * or deduplicated — real stacks legitimately alternate the same material
     * (electrode / separator / electrode). A highlight index that no longer
     * points at a layer is dropped rather than guessed.
     */
    private function clampLayerStackContent(array $slot): ?array
    {
        $layers = [];
        foreach ((array) ($slot['layers'] ?? $slot['items'] ?? []) as $raw) {
            if (is_string($raw)) {
                $raw = ['label' => $raw];
            }
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 24);
            if ($label === '') {
                continue;
            }
            $layer = ['label' => $label];
            $cap = mb_substr(trim((string) ($raw['caption'] ?? '')), 0, 44);
            if ($cap !== '') {
                $layer['caption'] = $cap;
            }
            $layers[] = $layer;
            if (count($layers) >= 6) {
                break;
            }
        }
        if (count($layers) < 3) {
            return null;
        }

        $clean = [
            'content_type' => 'layers',
            'layers' => $layers,
        ];
        $highlight = $slot['highlight_index'] ?? null;
        if (is_numeric($highlight) && isset($layers[(int) $highlight])) {
            $clean['highlight_index'] = (int) $highlight;
        }
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }
        $caption = trim((string) ($slot['caption'] ?? ''));
        if ($caption !== '') {
            $clean['caption'] = mb_substr($caption, 0, 60);
        }

        return $clean;
    }

    /**
     * Clamp a cycle payload; null below 3 usable stages (two boxes and an
     * arrow are step_flow's job — a ring needs at least a triangle).
     */
    private function clampCycleContent(array $slot): ?array
    {
        $whitelist = ExplainerRegistry::iconNames();
        $items = [];
        foreach ((array) ($slot['items'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 26);
            if ($label === '') {
                continue;
            }
            $icon = trim((string) ($raw['icon'] ?? ''));
            if ($icon !== '' && !empty($whitelist) && !in_array($icon, $whitelist, true)) {
                $icon = '';
            }
            $items[] = ['label' => $label, 'icon' => $icon];
            if (count($items) >= 6) {
                break;
            }
        }
        if (count($items) < 3) {
            return null;
        }

        return array_filter([
            'content_type' => 'cycle',
            'items' => $items,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Clamp a ranking payload; null below 3 rows. Rows stay BEST LAST. */
    private function clampRankingContent(array $slot): ?array
    {
        $items = [];
        foreach ((array) ($slot['items'] ?? []) as $raw) {
            $r = trim((string) $raw);
            if ($r !== '') {
                $items[] = mb_substr($r, 0, 44);
            }
            if (count($items) >= 6) {
                break;
            }
        }
        if (count($items) < 3) {
            return null;
        }

        return array_filter([
            'content_type' => 'ranking',
            'items' => $items,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Clamp a meter payload; null without a usable percentage. */
    private function clampMeterContent(array $slot): ?array
    {
        $pct = $slot['value_pct'] ?? null;
        if (!is_numeric($pct)) {
            return null;
        }
        $pct = max(1.0, min(100.0, round((float) $pct, 1)));
        $label = mb_substr(trim((string) ($slot['label'] ?? '')), 0, 70);
        if ($label === '') {
            return null;
        }

        return [
            'content_type' => 'meter',
            'value_pct' => $pct,
            'label' => $label,
            'unit' => mb_substr(trim((string) ($slot['unit'] ?? '')), 0, 6) ?: '%',
        ];
    }

    // -------------------------------------------------------------------------
    // Tier A data cards (copilot.md §5, M4): content schemas + degrade rules.
    // -------------------------------------------------------------------------

    /** Clamp a versus payload; null when it can't carry a head-to-head. */
    private function clampVersusContent(array $slot): ?array
    {
        $side = function ($raw): array {
            $raw = is_array($raw) ? $raw : [];
            $stats = [];
            foreach ((array) ($raw['stats'] ?? []) as $s) {
                $s = trim((string) $s);
                if ($s !== '') {
                    $stats[] = mb_substr($s, 0, 48);
                }
                if (count($stats) >= 3) {
                    break;
                }
            }
            return [
                'label' => mb_substr(trim((string) ($raw['label'] ?? '')), 0, 24),
                'stats' => $stats,
            ];
        };

        $left = $side($slot['left'] ?? null);
        $right = $side($slot['right'] ?? null);
        if ($left['label'] === '' || $right['label'] === '') {
            return null;
        }

        return [
            'content_type' => 'versus',
            'left' => $left,
            'right' => $right,
            'verdict' => mb_substr(trim((string) ($slot['verdict'] ?? '')), 0, 80),
        ];
    }

    /** Clamp a chart payload; null when there is no usable series. */
    private function clampChartContent(array $slot): ?array
    {
        $type = (string) ($slot['chart_type'] ?? 'bar');
        if (!in_array($type, ['bar', 'line', 'area', 'donut', 'pie', 'scatter', 'radar', 'counter'], true)) {
            $type = 'bar';
        }

        $values = [];
        foreach ((array) ($slot['values'] ?? []) as $v) {
            if (is_numeric($v)) {
                $values[] = (float) $v;
            }
            if (count($values) >= 8) {
                break;
            }
        }
        if (count($values) < ($type === 'counter' ? 1 : 2)) {
            return null;
        }
        // A radar below 3 axes is not a shape; bars tell the same numbers honestly.
        if ($type === 'radar' && count($values) < 3) {
            $type = 'bar';
        }

        $labels = [];
        foreach ((array) ($slot['labels'] ?? []) as $l) {
            $labels[] = mb_substr(trim((string) $l), 0, 16);
            if (count($labels) >= count($values)) {
                break;
            }
        }

        $highlight = $slot['highlight_index'] ?? null;
        $highlight = is_numeric($highlight) && (int) $highlight >= 0 && (int) $highlight < count($values)
            ? (int) $highlight
            : null;

        return [
            'content_type' => 'chart',
            'chart_type' => $type,
            'values' => $values,
            'labels' => $labels,
            'unit' => mb_substr(trim((string) ($slot['unit'] ?? '')), 0, 8),
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 80),
            'highlight_index' => $highlight,
            'source' => mb_substr(trim((string) ($slot['source'] ?? '')), 0, 60),
        ];
    }

    /** Clamp a pros/cons payload; null when there are no rows at all. */
    private function clampProsconsContent(array $slot): ?array
    {
        $rows = function ($raw): array {
            $out = [];
            foreach ((array) $raw as $r) {
                $r = trim((string) $r);
                if ($r !== '') {
                    $out[] = mb_substr($r, 0, 48);
                }
                if (count($out) >= 4) {
                    break;
                }
            }
            return $out;
        };

        $pros = $rows($slot['pros'] ?? []);
        $cons = $rows($slot['cons'] ?? []);
        if (empty($pros) && !empty($cons)) {
            // Cons-only payload still renders — as the single checklist.
            [$pros, $cons] = [$cons, []];
        }
        if (empty($pros)) {
            return null;
        }

        return array_filter([
            'content_type' => 'proscons',
            'pros' => $pros,
            'cons' => $cons,
            'pros_label' => mb_substr(trim((string) ($slot['pros_label'] ?? '')), 0, 14) ?: null,
            'cons_label' => mb_substr(trim((string) ($slot['cons_label'] ?? '')), 0, 14) ?: null,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Clamp an icon-grid payload; null when fewer than 3 usable cells. */
    private function clampIconsContent(array $slot): ?array
    {
        $whitelist = ExplainerRegistry::iconNames();
        $items = [];
        foreach ((array) ($slot['items'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $icon = trim((string) ($raw['icon'] ?? ''));
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 18);
            if ($icon === '' && $label === '') {
                continue;
            }
            if ($icon !== '' && !empty($whitelist) && !in_array($icon, $whitelist, true)) {
                // Unknown icon renders as the generic dot — keep the label.
                $icon = '';
            }
            $items[] = ['icon' => $icon, 'label' => $label];
            if (count($items) >= 9) {
                break;
            }
        }
        if (count($items) < 3) {
            return null;
        }

        $highlight = $slot['highlight_index'] ?? null;
        $highlight = is_numeric($highlight) && (int) $highlight >= 0 && (int) $highlight < count($items)
            ? (int) $highlight
            : null;

        return array_filter([
            'content_type' => 'icons',
            'items' => $items,
            'highlight_index' => $highlight,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Clamp a worked-math payload; null when no usable step line survives. */
    /**
     * The model is told to write maths symbols as words (+-, sqrt, ^2) but
     * keeps emitting the unicode glyphs anyway — and in transport those bytes
     * sometimes arrive mangled into control characters (project 33's quadratic
     * formula reached the DB as "t = -b \x1a \x1a(b\x17...)"). Transliterate
     * whatever arrived intact into the linear notation the typesetter speaks,
     * then scrub any control garbage so a broken ± never renders as a box.
     */
    /**
     * @param bool $isExpression True for fields that are pure notation (a step
     *                           expr, a rule formula, a mistake card's two
     *                           lines). In an expression a bare word after the
     *                           radical IS the radicand ("sigma = sqrt var"),
     *                           while in prose it is the next word of the
     *                           sentence ("the sqrt of both sides") — the same
     *                           string has to be read differently in the two
     *                           places, so the caller says which it is.
     */
    private function linearizeMathSymbols(string $s, bool $isExpression = false): string
    {
        $s = strtr($s, [
            '±' => ' +- ', '∓' => ' -+ ', '√' => ' sqrt ', '−' => '-', '×' => ' * ',
            '÷' => ' / ', '≤' => ' <= ', '≥' => ' >= ', '≠' => ' != ', '→' => ' -> ',
            '·' => ' * ', 'π' => ' pi ', 'θ' => ' theta ', '∞' => ' infinity ',
            '²' => '^2', '³' => '^3', '⁴' => '^4', '½' => ' 1/2 ', '¼' => ' 1/4 ', '¾' => ' 3/4 ',
        ]);
        // A control char directly before a digit is a superscript mark that
        // lost its high bits in transport ("at\x1B2 + bt + c" was "at² ..."
        // in project 34) — in maths text nothing else puts a control glyph
        // hard against a digit, so repair it instead of scrubbing the power.
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F](?=\d)/u', '^', $s);
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $s);
        $s = self::canonicalizeRadicals($s, $isExpression);
        // Whatever is still spelled "sqrt" here is English, not notation — a
        // step ref reading "sqrt simplification" or a hint saying "take the
        // sqrt of both sides". The word belongs on screen as words.
        $s = (string) preg_replace('/(?<![A-Za-z])sqrt(?![A-Za-z{])/', 'square root', $s);

        return trim((string) preg_replace('/ {2,}/', ' ', $s));
    }

    /**
     * Rewrites every radical the models actually emit into the ONE form the
     * typesetter understands: `sqrt{body}`.
     *
     * The renderer's parser only ever accepted `sqrt{...}`, so the two other
     * spellings fell straight through and were drawn as the literal letters
     * "sqrt" next to the radicand — the distance formula in project 91 read
     * "d = sqrt(run^2 + rise^2)" on screen. Both spellings are common:
     * models write `sqrt(x)` far more often than the braced form, and the ±/√
     * transliteration above turns a unicode "√(x)" into "sqrt (x)" itself.
     *
     * The bare form ("sqrt 2", "sqrt x") is deliberately narrow — a digit run
     * or a lone letter — so that prose which merely mentions the word ("take
     * the sqrt of both sides") is left completely alone instead of being
     * typeset as the square root of "of".
     */
    private static function canonicalizeRadicals(string $s, bool $isExpression = false): string
    {
        if (stripos($s, 'sqrt') === false) {
            return $s;
        }

        $out = '';
        $i = 0;
        $len = strlen($s);

        while ($i < $len) {
            $isRadical = strtolower(substr($s, $i, 4)) === 'sqrt'
                && ($i === 0 || preg_match('/[A-Za-z]/', $s[$i - 1]) !== 1);
            if (!$isRadical) {
                $out .= $s[$i];
                $i++;
                continue;
            }

            // The radicand may be pushed away from the keyword by the spaces
            // the symbol transliteration inserts ("√" => " sqrt ").
            $j = $i + 4;
            while ($j < $len && $s[$j] === ' ') {
                $j++;
            }
            $open = $s[$j] ?? '';

            if ($open === '{' || $open === '(') {
                [$body, $after] = self::readBalanced($s, $j, $open, $open === '{' ? '}' : ')');
                $out .= 'sqrt{' . self::canonicalizeRadicals($body, $isExpression) . '}';
                $i = $after;
                continue;
            }

            // A number or a variable, with its exponent if it has one:
            // "sqrt 2", "sqrt x^2". In prose a letter followed by more letters
            // is a WORD, not a radicand ("the sqrt of both sides"); inside an
            // expression there is no prose to protect, so a named quantity
            // ("sigma = sqrt var") is a radicand like any other.
            $bare = $isExpression
                ? '/^(?:\d+\.?\d*|[A-Za-z][A-Za-z0-9]*)(?:\^(?:\{[^}]*\}|[A-Za-z0-9.]+))?/'
                : '/^(?:\d+\.?\d*|[A-Za-z](?![A-Za-z]))(?:\^(?:\{[^}]*\}|[A-Za-z0-9.]+))?/';
            if (preg_match($bare, substr($s, $j), $m)) {
                $out .= 'sqrt{' . $m[0] . '}';
                $i = $j + strlen($m[0]);
                continue;
            }

            // Nothing root-able follows — keep the word as written.
            $out .= substr($s, $i, 4);
            $i += 4;
        }

        return $out;
    }

    /** @return array{0: string, 1: int} [contentBetweenDelimiters, indexAfterClose] */
    private static function readBalanced(string $s, int $open, string $lp, string $rp): array
    {
        $depth = 0;
        for ($i = $open, $len = strlen($s); $i < $len; $i++) {
            if ($s[$i] === $lp) {
                $depth++;
            } elseif ($s[$i] === $rp) {
                $depth--;
                if ($depth === 0) {
                    return [substr($s, $open + 1, $i - $open - 1), $i + 1];
                }
            }
        }

        // Unbalanced: take the rest, so garbage still renders as legible text.
        return [substr($s, $open + 1), $len];
    }

    private function clampMathStepsContent(array $slot): ?array
    {
        $steps = [];
        foreach ((array) ($slot['steps'] ?? []) as $raw) {
            if (is_string($raw)) {
                $raw = ['expr' => $raw];
            }
            if (!is_array($raw)) {
                continue;
            }
            $expr = trim((string) ($raw['expr'] ?? ''));
            // Models raised on LaTeX add \frac and $...$ out of habit — strip
            // the delimiters and backslashes so the linear notation survives.
            $expr = trim((string) preg_replace('/^\$+|\$+$/', '', $expr));
            $expr = $this->linearizeMathSymbols(str_replace('\\', '', $expr), true);
            if ($expr === '') {
                continue;
            }
            // 80, not 60: a transform line ("L{y'} = sY(s) - y(0) = sY - 1.5")
            // is legitimately long, and clipping it mid-expression put half an
            // equation on the board.
            $step = ['expr' => mb_substr($expr, 0, 80)];
            $note = $this->linearizeMathSymbols(trim((string) ($raw['note'] ?? '')));
            if ($note !== '') {
                $step['note'] = mb_substr($note, 0, 36);
            }

            // The "as we know…" citation for THIS line: the formula or law that
            // authorises the move, written in the margin beside it. The card's
            // `rule` panel names the ONE idea of the whole phase; this names the
            // specific identity a single line leans on, which is what a lecturer
            // writes to the right of the step.
            $ref = $this->linearizeMathSymbols(trim((string) ($raw['ref'] ?? '')));
            if ($ref !== '') {
                $step['ref'] = mb_substr($ref, 0, 48);
            }

            // Operation arrows: the pen strokes from atoms of the PREVIOUS
            // line to atoms of THIS line (a term changing sides, a product
            // distributing). Same de-LaTeXing as the exprs they point into.
            $arrows = [];
            foreach ((array) ($raw['arrows'] ?? []) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $from = $this->linearizeMathSymbols(str_replace('\\', '', trim((string) (is_scalar($a['from'] ?? null) ? $a['from'] : ''))), true);
                $to = $this->linearizeMathSymbols(str_replace('\\', '', trim((string) (is_scalar($a['to'] ?? null) ? $a['to'] : ''))), true);
                if ($from === '' || $to === '') {
                    continue;
                }
                $arrows[] = ['from' => mb_substr($from, 0, 12), 'to' => mb_substr($to, 0, 12)];
                if (count($arrows) >= 3) {
                    break;
                }
            }
            if ($arrows !== [] && count($steps) > 0) {
                // An arrow on the FIRST line has no previous line to come from.
                $step['arrows'] = $arrows;
            }

            $steps[] = $step;
            // 12, not 6. The old cap DISCARDED the tail of the working while
            // the narration kept describing it — a fully-shown Laplace solve
            // runs past six lines and the board is a continuous surface that
            // can carry them. The board's own height budget (boardStepCount /
            // consolidateBoardPhases) is sized to the same number.
            if (count($steps) >= 12) {
                break;
            }
        }
        if (empty($steps)) {
            return null;
        }
        $steps = $this->fuseFragmentSteps($steps);

        // The final step is stamped as the answer in an accent chip — a note
        // restating it ("slope is 1") says the same thing a third time (chip +
        // narration already carry it). The move-notes on earlier steps stay.
        if (count($steps) > 1) {
            unset($steps[array_key_last($steps)]['note']);
        }

        return array_filter([
            'content_type' => 'math_steps',
            'steps' => array_values($steps),
            'rule' => $this->clampMathRule($slot['rule'] ?? null),
            // The heading quotes the working it introduces ("compute
            // c = sqrt(c^2)", "x equals (6 +- sqrt20)/2"), so it needs the same
            // notation pass as the steps — it was the last field on this card
            // still printing raw source. Prose mode: a heading is a sentence,
            // and the renderer typesets it with InlineMathText.
            'heading' => mb_substr($this->linearizeMathSymbols(trim((string) ($slot['heading'] ?? ''))), 0, 60) ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * A real derivation line is a complete statement — "Δx = 5 - 1 = 4". The
     * model instead often writes the working as keystrokes: step "5 - 1"
     * followed by step "4". Rendered, that spends a whole line (and the answer
     * chip!) on a bare number, and the step-diff highlight has nothing
     * meaningful to compare. Repair: a step that is just a VALUE fuses onto
     * the previous step as its "= value", keeping whichever note names the
     * result.
     */
    private function fuseFragmentSteps(array $steps): array
    {
        $isBareValue = fn (string $expr): bool =>
            // A number, optionally signed, optionally with a short unit word —
            // and NOT already part of an equation/inequality.
            (bool) preg_match('/^-?\d+(\.\d+)?(\s*[A-Za-z%°]{1,6})?$/u', trim($expr));
        $hasRelation = fn (string $expr): bool => (bool) preg_match('/[=<>≤≥≠]|<=|>=|!=/u', $expr);

        $out = [];
        foreach ($steps as $step) {
            $prev = $out !== [] ? $out[array_key_last($out)] : null;
            if (
                $prev !== null
                && $isBareValue($step['expr'])
                && !$hasRelation($prev['expr'])
                && mb_strlen($prev['expr']) + mb_strlen($step['expr']) + 3 <= 60
            ) {
                $k = array_key_last($out);
                $out[$k]['expr'] = $prev['expr'] . ' = ' . trim($step['expr']);
                // The fragment's note usually names the result; the fused line
                // shows the result, so prefer the note that names the MOVE.
                if (empty($out[$k]['note']) && !empty($step['note'])) {
                    $out[$k]['note'] = $step['note'];
                }
                $this->changed = true;
                continue;
            }
            $out[] = $step;
        }

        return $out;
    }

    /**
     * The word-problem setup drawing: 2-4 labelled actors with their given
     * values, connectors riding the gaps between adjacent pairs (positional,
     * like side_labels), and the problem's question as a chip. The sketch's
     * SHAPE (layout: line|arc|climb|fall) is the model's choice when legal,
     * else inferred from the problem's own words — a projectile must never
     * be drawn as a flat chain again. Null when fewer than two named
     * entities survive — one box is not a scenario.
     */
    private function clampScenarioContent(array $slot, string $narrationText = ''): ?array
    {
        $icons = ExplainerRegistry::iconNames();

        $entities = [];
        foreach ((array) ($slot['entities'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $label = trim((string) (is_scalar($raw['label'] ?? null) ? $raw['label'] : ''));
            if ($label === '') {
                continue;
            }
            $entity = ['label' => mb_substr($label, 0, 16)];
            $icon = trim((string) (is_scalar($raw['icon'] ?? null) ? $raw['icon'] : ''));
            if ($icon !== '' && in_array($icon, $icons, true)) {
                $entity['icon'] = $icon;
            }
            $value = str_replace('\\', '', trim((string) (is_scalar($raw['value'] ?? null) ? $raw['value'] : '')));
            if ($value !== '') {
                $entity['value'] = mb_substr($value, 0, 24);
            }
            // Drawable subject for the AI cut-out sprite — replaces the icon
            // box with the object itself when generation succeeds.
            $sprite = trim((string) (is_scalar($raw['sprite'] ?? null) ? $raw['sprite'] : ''));
            if ($sprite !== '') {
                $entity['sprite'] = mb_substr($sprite, 0, 70);
            }
            // Emphasis lifts one actor out of the crowd (the FIND, the winner,
            // the pay-off branch) with an accent frame in the renderer.
            $emphasis = strtolower(trim((string) (is_scalar($raw['emphasis'] ?? null) ? $raw['emphasis'] : '')));
            if (in_array($emphasis, ['key', 'accent'], true)) {
                $entity['emphasis'] = $emphasis;
            }
            $entities[] = $entity;
            if (count($entities) >= 4) {
                break;
            }
        }
        if (count($entities) < 2) {
            return null;
        }

        // Positional per gap — connector i sits between entity i and i+1, so
        // empties are kept (dropping one would shift every later connector).
        $connectors = [];
        foreach ((array) ($slot['connectors'] ?? []) as $raw) {
            $raw = is_array($raw) ? $raw : [];
            $style = (string) ($raw['style'] ?? 'arrow');
            $connectors[] = array_filter([
                'style' => in_array($style, ['arrow', 'line', 'both'], true) ? $style : 'arrow',
                'label' => mb_substr(str_replace('\\', '', trim((string) (is_scalar($raw['label'] ?? null) ? $raw['label'] : ''))), 0, 24) ?: null,
                'sub' => mb_substr(str_replace('\\', '', trim((string) (is_scalar($raw['sub'] ?? null) ? $raw['sub'] : ''))), 0, 20) ?: null,
            ], fn ($v) => $v !== null);
            if (count($connectors) >= count($entities) - 1) {
                break;
            }
        }

        $question = mb_substr(str_replace('\\', '', trim((string) ($slot['question'] ?? ''))), 0, 24);
        $heading = mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60);

        // The sketch's shape: explicit when legal, else inferred from every
        // word the scenario carries (plus the narration — "reaches its peak"
        // usually lives in the spoken line, not a 16-char label).
        $layoutText = implode(' ', array_merge(
            [$heading, $question, $narrationText],
            array_map(fn ($e) => ($e['label'] ?? '') . ' ' . ($e['value'] ?? '') . ' ' . ($e['sprite'] ?? ''), $entities),
            array_map(fn ($c) => ($c['label'] ?? '') . ' ' . ($c['sub'] ?? ''), $connectors)
        ));
        $layout = ScenarioLayout::resolve(
            is_scalar($slot['layout'] ?? null) ? (string) $slot['layout'] : null,
            $layoutText
        );

        return array_filter([
            'content_type' => 'scenario',
            'entities' => $entities,
            'connectors' => $connectors ?: null,
            'question' => $question ?: null,
            'heading' => $heading ?: null,
            'layout' => $layout,
        ], fn ($v) => $v !== null);
    }

    /**
     * The named rule a card applies, shown in a panel beside the working.
     * `name` carries it — a rule with no name is nothing to put in a panel, so
     * the whole field drops. formula/why are optional enrichments.
     */
    private function clampMathRule($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $name = $this->linearizeMathSymbols(trim((string) (is_scalar($raw['name'] ?? null) ? $raw['name'] : '')));
        if ($name === '') {
            return null;
        }

        $rule = ['name' => mb_substr($name, 0, 40)];

        // Same LaTeX habits as the step exprs — the panel typesets the same
        // linear notation, so it needs the same de-LaTeXing.
        $formula = trim((string) (is_scalar($raw['formula'] ?? null) ? $raw['formula'] : ''));
        $formula = trim((string) preg_replace('/^\$+|\$+$/', '', $formula));
        $formula = $this->linearizeMathSymbols(str_replace('\\', '', $formula), true);
        if ($formula !== '') {
            $rule['formula'] = mb_substr($formula, 0, 60);
        }

        $why = $this->linearizeMathSymbols(trim((string) (is_scalar($raw['why'] ?? null) ? $raw['why'] : '')));
        if ($why !== '') {
            $rule['why'] = mb_substr($why, 0, 120);
        }

        return $rule;
    }

    /** Legal figure shapes — must match the renderer's GeometryDiagram. */
    private const GEOMETRY_SHAPES = [
        'triangle', 'right_triangle', 'rectangle', 'square', 'polygon', 'circle', 'angle',
        'number_line', 'coordinate_plane', 'fraction_bar', 'unit_circle', 'area_model',
    ];

    /** Clamp a geometry figure; null when the shape can't stand. */
    private function clampGeometryContent(array $slot): ?array
    {
        // is_scalar guard: gpt-5-nano's first tree run sent `shape` as an
        // ARRAY and the bare (string) cast threw, sinking the whole analyze
        // job (project 91). Same guard formula/why above always had.
        $shape = strtolower(trim((string) (is_scalar($slot['shape'] ?? null) ? $slot['shape'] : '')));
        if (!in_array($shape, self::GEOMETRY_SHAPES, true)) {
            return null;
        }

        // These three figures live on their own coordinate systems — clamp
        // them separately from the polygon/circle/angle machine below.
        if ($shape === 'number_line') {
            return $this->clampNumberLine($slot);
        }
        if ($shape === 'coordinate_plane') {
            return $this->clampCoordinatePlane($slot);
        }
        if ($shape === 'fraction_bar') {
            return $this->clampFractionBar($slot);
        }
        if ($shape === 'unit_circle') {
            return $this->clampUnitCircle($slot);
        }
        if ($shape === 'area_model') {
            return $this->clampAreaModel($slot);
        }

        $points = [];
        if ($shape !== 'circle') {
            foreach ((array) ($slot['points'] ?? []) as $raw) {
                if (!is_array($raw) || !is_numeric($raw['x'] ?? null) || !is_numeric($raw['y'] ?? null)) {
                    continue;
                }
                $pt = [
                    'x' => round(max(0.0, min(1.0, (float) $raw['x'])), 4),
                    'y' => round(max(0.0, min(1.0, (float) $raw['y'])), 4),
                ];
                $label = trim((string) ($raw['label'] ?? ''));
                if ($label !== '') {
                    $pt['label'] = mb_substr($label, 0, 12);
                }
                $points[] = $pt;
                if (count($points) >= 8) {
                    break;
                }
            }
        }
        if ($shape === 'angle') {
            $points = array_slice($points, 0, 3);
        }

        // How many vertices/edges the figure will actually have: real points
        // when given, the renderer's clean default outline otherwise. A bare
        // polygon/angle has no default to fall back on.
        if (count($points) < 3) {
            if (in_array($shape, ['polygon', 'angle'], true)) {
                return null;
            }
            $points = [];
        }
        $vertexCount = count($points) ?: match ($shape) {
            'rectangle', 'square' => 4,
            'circle' => 0,
            default => 3,
        };
        $edgeCount = $shape === 'angle' ? 2 : $vertexCount;

        $sideLabels = [];
        foreach ((array) ($slot['side_labels'] ?? []) as $l) {
            $sideLabels[] = mb_substr(trim((string) $l), 0, 14);
            if (count($sideLabels) >= max(1, $edgeCount)) {
                break;
            }
        }
        // Positional per edge — never filtered (that would shift edges); the
        // field only drops when no label carries text at all.
        $hasSideLabels = array_filter($sideLabels, fn ($l) => $l !== '') !== [];

        $marks = [];
        foreach ((array) ($slot['angle_marks'] ?? []) as $m) {
            if (!is_array($m) || !is_numeric($m['at'] ?? null)) {
                continue;
            }
            $at = (int) $m['at'];
            if ($at < 0 || $at >= max(1, $vertexCount)) {
                continue;
            }
            $mark = ['at' => $at];
            $label = trim((string) ($m['label'] ?? ''));
            if ($label !== '') {
                $mark['label'] = mb_substr($label, 0, 10);
            }
            if (!empty($m['right'])) {
                $mark['right'] = true;
            }
            $marks[] = $mark;
            if (count($marks) >= 4) {
                break;
            }
        }

        $highlight = $slot['highlight_side'] ?? null;
        $highlight = is_numeric($highlight) && (int) $highlight >= 0 && (int) $highlight < max(1, $edgeCount)
            ? (int) $highlight
            : null;

        // Equal-side ticks / parallel arrows: positional per edge (0..edgeCount
        // long), clamped to 0-3 / 0-2 respectively; dropped when all zero.
        $perEdge = function ($raw, int $cap) use ($edgeCount): ?array {
            $out = [];
            foreach ((array) $raw as $v) {
                $out[] = max(0, min($cap, (int) round((float) $v)));
                if (count($out) >= max(1, $edgeCount)) {
                    break;
                }
            }
            return array_sum($out) > 0 ? $out : null;
        };
        $sideTicks = $perEdge($slot['side_ticks'] ?? [], 3);
        $sideArrows = $perEdge($slot['side_arrows'] ?? [], 2);

        // Squares erected outward on an edge, labelled with their area — the
        // Pythagoras figure. Positional per edge like side_labels (an empty
        // entry means no square, and is never filtered out — that would shift
        // every square onto the wrong edge). An angle has no interior, so no
        // outward side to stand one on.
        $sideSquares = [];
        if ($shape !== 'angle') {
            foreach ((array) ($slot['side_squares'] ?? []) as $s) {
                $sideSquares[] = mb_substr(trim((string) (is_scalar($s) ? $s : '')), 0, 10);
                if (count($sideSquares) >= max(1, $edgeCount)) {
                    break;
                }
            }
        }
        $hasSideSquares = array_filter($sideSquares, fn ($s) => $s !== '') !== [];

        // Internal segments between named points (a cevian, a median, a diagonal,
        // the parallel line that carves a similar triangle). Endpoints are a
        // vertex label, a vertex index, or an extra_point label — kept as given.
        $segments = [];
        foreach ((array) ($slot['segments'] ?? []) as $seg) {
            if (!is_array($seg)) {
                continue;
            }
            $from = is_scalar($seg['from'] ?? null) ? trim((string) $seg['from']) : '';
            $to = is_scalar($seg['to'] ?? null) ? trim((string) $seg['to']) : '';
            if ($from === '' || $to === '') {
                continue;
            }
            $line = ['from' => mb_substr($from, 0, 12), 'to' => mb_substr($to, 0, 12)];
            $lbl = trim((string) ($seg['label'] ?? ''));
            if ($lbl !== '') {
                $line['label'] = mb_substr($lbl, 0, 14);
            }
            if (!empty($seg['dashed'])) {
                $line['dashed'] = true;
            }
            $segments[] = $line;
            if (count($segments) >= 6) {
                break;
            }
        }

        // Evolving-figure reveal plan (the composer fuses a drawn proof onto
        // one slide): whether the squares rise with the narration, in what
        // edge order, and how much of the narration opens on the bare figure.
        // Only meaningful alongside erected squares.
        $progressive = $hasSideSquares && !empty($slot['progressive']);
        $revealOrder = [];
        if ($progressive) {
            foreach ((array) ($slot['reveal_order'] ?? []) as $e) {
                if (!is_numeric($e)) {
                    continue;
                }
                $ei = (int) $e;
                if ($ei >= 0 && $ei < max(1, $edgeCount) && !in_array($ei, $revealOrder, true)) {
                    $revealOrder[] = $ei;
                }
            }
        }
        $revealStartFrac = $progressive && is_numeric($slot['reveal_start_frac'] ?? null)
            ? round(max(0.0, min(0.5, (float) $slot['reveal_start_frac'])), 3)
            : null;
        $revealFracs = [];
        if ($progressive) {
            foreach ((array) ($slot['reveal_fracs'] ?? []) as $f) {
                if (is_numeric($f)) {
                    $revealFracs[] = round(max(0.0, min(1.0, (float) $f)), 3);
                }
            }
            // One fraction per erected square, or it can't be trusted to line up.
            if (count($revealFracs) !== count(array_filter($sideSquares, fn ($s) => $s !== ''))) {
                $revealFracs = [];
            }
        }

        // Named points ON an edge (midpoints, feet): {on_side, t 0..1, label}.
        $extraPoints = [];
        foreach ((array) ($slot['extra_points'] ?? []) as $ep) {
            if (!is_array($ep) || !is_numeric($ep['on_side'] ?? null)) {
                continue;
            }
            $onSide = (int) $ep['on_side'];
            if ($onSide < 0 || $onSide >= max(1, $edgeCount)) {
                continue;
            }
            $point = [
                'on_side' => $onSide,
                't' => round(max(0.0, min(1.0, is_numeric($ep['t'] ?? null) ? (float) $ep['t'] : 0.5)), 4),
            ];
            $label = trim((string) ($ep['label'] ?? ''));
            if ($label !== '') {
                $point['label'] = mb_substr($label, 0, 12);
            }
            $extraPoints[] = $point;
            if (count($extraPoints) >= 6) {
                break;
            }
        }

        return array_filter([
            'content_type' => 'geometry',
            'shape' => $shape,
            'points' => $points ?: null,
            'side_labels' => $hasSideLabels ? $sideLabels : null,
            'angle_marks' => $marks ?: null,
            'side_ticks' => $sideTicks,
            'side_arrows' => $sideArrows,
            'side_squares' => $hasSideSquares ? $sideSquares : null,
            'segments' => $segments !== [] ? $segments : null,
            'circumcircle' => !empty($slot['circumcircle']) ? true : null,
            'progressive' => $progressive ? true : null,
            'reveal_order' => $revealOrder !== [] ? $revealOrder : null,
            'reveal_start_frac' => $revealStartFrac,
            'reveal_fracs' => $revealFracs !== [] ? $revealFracs : null,
            'extra_points' => $extraPoints ?: null,
            'radius_label' => $shape === 'circle' ? (mb_substr(trim((string) ($slot['radius_label'] ?? '')), 0, 14) ?: null) : null,
            'center_label' => $shape === 'circle' ? (mb_substr(trim((string) ($slot['center_label'] ?? '')), 0, 6) ?: null) : null,
            'fill' => !empty($slot['fill']) ? true : null,
            'highlight_side' => $highlight,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 80) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** number_line: a value axis with marks and an optional [from,to] segment.
     *  Null only when there is nothing to place at all. */
    private function clampNumberLine(array $slot): ?array
    {
        $marks = [];
        foreach ((array) ($slot['marks'] ?? []) as $m) {
            if (is_numeric($m)) {
                $m = ['x' => $m];
            }
            if (!is_array($m) || !is_numeric($m['x'] ?? null)) {
                continue;
            }
            $mark = ['x' => round((float) $m['x'], 4)];
            $label = trim((string) ($m['label'] ?? ''));
            if ($label !== '') {
                $mark['label'] = mb_substr($label, 0, 16);
            }
            $marks[] = $mark;
            if (count($marks) >= 6) {
                break;
            }
        }

        $seg = is_array($slot['segment'] ?? null) ? $slot['segment'] : null;
        $segment = null;
        if ($seg && (is_numeric($seg['from'] ?? null) || is_numeric($seg['to'] ?? null))) {
            $from = is_numeric($seg['from'] ?? null) ? (float) $seg['from'] : null;
            $to = is_numeric($seg['to'] ?? null) ? (float) $seg['to'] : null;
            if ($from !== null && $to !== null) {
                $segment = ['from' => round(min($from, $to), 4), 'to' => round(max($from, $to), 4)];
            }
        }

        if (empty($marks) && $segment === null) {
            return null;
        }

        $xMin = is_numeric($slot['x_min'] ?? null) ? round((float) $slot['x_min'], 4) : null;
        $xMax = is_numeric($slot['x_max'] ?? null) ? round((float) $slot['x_max'], 4) : null;
        if ($xMin !== null && $xMax !== null && $xMax <= $xMin) {
            $xMin = $xMax = null;
        }

        return array_filter([
            'content_type' => 'geometry',
            'shape' => 'number_line',
            'marks' => $marks ?: null,
            'segment' => $segment,
            'x_min' => $xMin,
            'x_max' => $xMax,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 80) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** coordinate_plane: real-value points + an optional line through two. */
    private function clampCoordinatePlane(array $slot): ?array
    {
        $coords = [];
        foreach ((array) ($slot['coords'] ?? []) as $c) {
            if (!is_array($c) || !is_numeric($c['x'] ?? null) || !is_numeric($c['y'] ?? null)) {
                continue;
            }
            $point = ['x' => round((float) $c['x'], 4), 'y' => round((float) $c['y'], 4)];
            $label = trim((string) ($c['label'] ?? ''));
            if ($label !== '') {
                $point['label'] = mb_substr($label, 0, 16);
            }
            $coords[] = $point;
            if (count($coords) >= 6) {
                break;
            }
        }
        if (empty($coords)) {
            return null;
        }

        $lineThrough = null;
        $lt = [];
        foreach ((array) ($slot['line_through'] ?? []) as $i) {
            if (is_numeric($i) && (int) $i >= 0 && (int) $i < count($coords)) {
                $lt[] = (int) $i;
            }
            if (count($lt) >= 2) {
                break;
            }
        }
        if (count($lt) === 2 && $lt[0] !== $lt[1]) {
            $lineThrough = $lt;
        }

        return array_filter([
            'content_type' => 'geometry',
            'shape' => 'coordinate_plane',
            'coords' => $coords,
            'line_through' => $lineThrough,
            // The slope triangle: dashed Δx/Δy legs between the two line
            // points — only meaningful when there IS a line through two.
            'rise_run' => $lineThrough !== null && !empty($slot['rise_run']) ? true : null,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 80) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** fraction_bar: numerator of denominator cells. Null without a real den. */
    /**
     * unit_circle: the trig/complex-number figure — a radius swung to an angle
     * on the unit circle, optionally a SECOND radius at another angle so a beat
     * can show one angle becoming another (what "raising to the n multiplies
     * the angle" actually looks like). Degrees in, because that is what a model
     * writes reliably; the renderer works in radians.
     *
     * Always stands: with no angle at all it still draws a labelled unit circle
     * with its axes, which is a legitimate figure.
     */
    private function clampUnitCircle(array $slot): ?array
    {
        // Wrapped, not clamped: an angle is periodic, so 400° IS 40° and -90°
        // is 270°. Clamping to a range would silently lie about the maths.
        $wrap = function ($v): ?float {
            if (!is_numeric($v)) {
                return null;
            }
            $deg = fmod((float) $v, 360.0);
            if ($deg < 0) {
                $deg += 360.0;
            }
            return round($deg, 2);
        };

        return array_filter([
            'content_type' => 'geometry',
            'shape' => 'unit_circle',
            'angle_deg' => $wrap($slot['angle_deg'] ?? null),
            'angle2_deg' => $wrap($slot['angle2_deg'] ?? null),
            'angle_label' => mb_substr(trim((string) (is_scalar($slot['angle_label'] ?? null) ? $slot['angle_label'] : '')), 0, 14) ?: null,
            'angle2_label' => mb_substr(trim((string) (is_scalar($slot['angle2_label'] ?? null) ? $slot['angle2_label'] : '')), 0, 14) ?: null,
            'point_label' => mb_substr(trim((string) (is_scalar($slot['point_label'] ?? null) ? $slot['point_label'] : '')), 0, 24) ?: null,
            'show_coords' => !empty($slot['show_coords']) ? true : null,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 80) ?: null,
        ], fn ($v) => $v !== null);
    }

    private function clampFractionBar(array $slot): ?array
    {
        if (!is_numeric($slot['denominator'] ?? null)) {
            return null;
        }
        $den = max(1, min(24, (int) round((float) $slot['denominator'])));
        $num = is_numeric($slot['numerator'] ?? null) ? (int) round((float) $slot['numerator']) : 0;
        $num = max(0, min($den, $num));

        return array_filter([
            'content_type' => 'geometry',
            'shape' => 'fraction_bar',
            'numerator' => $num,
            'denominator' => $den,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 80) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** area_model: the subdivided square/rectangle that proves an area identity
     *  ((a+b)^2, (a+b)(c+d)). Null when there aren't ≥2 row terms to split. */
    private function clampAreaModel(array $slot): ?array
    {
        $clean = function ($arr): array {
            $out = [];
            foreach ((array) $arr as $t) {
                $s = mb_substr(trim((string) (is_scalar($t) ? $t : '')), 0, 10);
                if ($s !== '') {
                    $out[] = $s;
                }
                if (count($out) >= 4) {
                    break;
                }
            }

            return $out;
        };
        $terms = $clean($slot['terms'] ?? []);
        if (count($terms) < 2) {
            return null;
        }
        $cols = $clean($slot['col_terms'] ?? []);

        return array_filter([
            'content_type' => 'geometry',
            'shape' => 'area_model',
            'terms' => $terms,
            'col_terms' => $cols !== [] ? $cols : null,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 80) ?: null,
        ], fn ($v) => $v !== null);
    }

    /** Normalise + validate one f(x) expression against the grammar the
     *  renderer's evaluator accepts; null when it can't be plotted. */
    private function sanitizePlotExpression(string $raw): ?string
    {
        $expr = trim((string) preg_replace('/^y\s*=\s*/i', '', trim($raw)));
        $expr = str_replace(['×', '−', '·', '\\'], ['*', '-', '*', ''], $expr);
        if ($expr === '' || mb_strlen($expr) > 60 || stripos($expr, 'x') === false) {
            return null;
        }
        $stripped = (string) preg_replace('/\b(sin|cos|tan|asin|acos|atan|sqrt|abs|log|ln|exp|floor|ceil|pi|e)\b/i', '', $expr);
        if (!preg_match('/^[0-9x+\-*\/^().\s]*$/i', $stripped)) {
            return null;
        }
        if (substr_count($expr, '(') !== substr_count($expr, ')')) {
            return null;
        }
        return $expr;
    }

    /** Clamp a function plot; null when the expression fails the grammar the
     *  renderer's evaluator accepts (it re-validates anyway — belt & braces). */
    private function clampFunctionPlotContent(array $slot): ?array
    {
        $expr = $this->sanitizePlotExpression((string) ($slot['expression'] ?? ''));
        if ($expr === null) {
            return null;
        }
        // Optional second curve (comparisons/intersections) — dropped if it
        // doesn't parse rather than failing the whole card.
        $expr2 = isset($slot['expression2']) ? $this->sanitizePlotExpression((string) $slot['expression2']) : null;

        $xMin = is_numeric($slot['x_min'] ?? null) ? max(-1000.0, min(1000.0, (float) $slot['x_min'])) : -5.0;
        $xMax = is_numeric($slot['x_max'] ?? null) ? max(-1000.0, min(1000.0, (float) $slot['x_max'])) : 5.0;
        if ($xMax <= $xMin) {
            [$xMin, $xMax] = [min($xMin, $xMax) - 1.0, max($xMin, $xMax) + 1.0];
        }

        $marks = [];
        foreach ((array) ($slot['marks'] ?? $slot['points'] ?? []) as $m) {
            if (!is_array($m) || !is_numeric($m['x'] ?? null)) {
                continue;
            }
            $x = (float) $m['x'];
            if ($x < $xMin || $x > $xMax) {
                continue;
            }
            $mark = ['x' => round($x, 4)];
            $label = trim((string) ($m['label'] ?? ''));
            if ($label !== '') {
                $mark['label'] = mb_substr($label, 0, 18);
            }
            $marks[] = $mark;
            if (count($marks) >= 3) {
                break;
            }
        }

        // Tangent touch point — only kept when inside the domain.
        $tangent = null;
        if (is_numeric($slot['tangent_at'] ?? null)) {
            $t = (float) $slot['tangent_at'];
            if ($t >= $xMin && $t <= $xMax) {
                $tangent = round($t, 4);
            }
        }

        // Under-curve shaded region — clamped into the domain.
        $shade = null;
        $sh = is_array($slot['shade'] ?? null) ? $slot['shade'] : null;
        if ($sh && (is_numeric($sh['from'] ?? null) || is_numeric($sh['to'] ?? null))) {
            $from = is_numeric($sh['from'] ?? null) ? max($xMin, (float) $sh['from']) : $xMin;
            $to = is_numeric($sh['to'] ?? null) ? min($xMax, (float) $sh['to']) : $xMax;
            if ($to > $from) {
                $shade = ['from' => round($from, 4), 'to' => round($to, 4)];
            }
        }

        return array_filter([
            'content_type' => 'function_plot',
            'expression' => $expr,
            'expression2' => $expr2,
            'x_min' => $xMin,
            'x_max' => $xMax,
            'marks' => $marks ?: null,
            'tangent_at' => $tangent,
            'shade' => $shade,
            'heading' => mb_substr(trim((string) ($slot['heading'] ?? '')), 0, 60) ?: null,
            'caption' => mb_substr(trim((string) ($slot['caption'] ?? '')), 0, 80) ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Validate a Tier A card's structured content up front and, when it can't
     * carry the card, REWRITE the scene to a safe template (the per-card
     * degrade rules of copilot.md §5) before the generic slot pass runs.
     */
    private function normalizeCardScene(string $template, string $sceneId, array $scene, string $narrationText): array
    {
        $slots = is_array($scene['slots'] ?? null) ? $scene['slots'] : [];

        $degradeToText = function (string $reason, ?string $heading = null, array $bullets = []) use (&$scene, $sceneId, $narrationText) {
            $this->warn("Scene {$sceneId}: {$reason} -> single_focus.");
            $this->changed = true;
            $scene['layout_template'] = 'single_focus';
            $scene['slots'] = ['slot_main' => array_filter([
                'content_type' => 'text_block',
                'heading' => $heading,
                'bullets' => $bullets,
            ], fn ($v) => $v !== null)];
            return $scene;
        };

        switch ($template) {
            case 'versus_card': {
                $versus = $this->clampVersusContent(is_array($slots['slot_versus'] ?? null) ? $slots['slot_versus'] : []);
                if ($versus === null) {
                    // Keep the two media panels — as an honest comparison split.
                    $this->warn("Scene {$sceneId}: versus data unusable -> split_side_by_side.");
                    $this->changed = true;
                    $scene['layout_template'] = 'split_side_by_side';
                    $scene['slots'] = [
                        'slot_left' => is_array($slots['slot_left'] ?? null) ? $slots['slot_left'] : [],
                        'slot_right' => is_array($slots['slot_right'] ?? null) ? $slots['slot_right'] : [],
                    ];
                    return $scene;
                }
                $scene['slots']['slot_versus'] = $versus;
                return $scene;
            }

            case 'animated_chart': {
                $chart = $this->clampChartContent(is_array($slots['slot_chart'] ?? null) ? $slots['slot_chart'] : []);
                if ($chart === null) {
                    return $degradeToText('chart data unusable', null, []);
                }
                $scene['slots']['slot_chart'] = $chart;
                return $scene;
            }

            case 'big_counter': {
                $raw = is_array($slots['slot_counter'] ?? null) ? $slots['slot_counter'] : [];
                if (($raw['content_type'] ?? '') === 'chart') {
                    $chart = $this->clampChartContent($raw);
                    if ($chart !== null) {
                        $chart['chart_type'] = 'counter';
                        $scene['slots']['slot_counter'] = $chart;
                        return $scene;
                    }
                }
                // text_block path: the heading must actually carry a figure.
                $heading = trim((string) ($raw['heading'] ?? ''));
                if (preg_match('/\d/', $heading)) {
                    return $scene;
                }
                return $degradeToText('big_counter without a figure', $heading !== '' ? $heading : null);
            }

            case 'checklist_card': {
                $list = $this->clampProsconsContent(is_array($slots['slot_checklist'] ?? null) ? $slots['slot_checklist'] : []);
                if ($list === null) {
                    return $degradeToText('checklist without rows');
                }
                $scene['slots']['slot_checklist'] = $list;
                return $scene;
            }

            case 'icon_grid': {
                $icons = $this->clampIconsContent(is_array($slots['slot_icons'] ?? null) ? $slots['slot_icons'] : []);
                if ($icons === null) {
                    $labels = [];
                    foreach ((array) (($slots['slot_icons'] ?? [])['items'] ?? []) as $it) {
                        $l = trim((string) (is_array($it) ? ($it['label'] ?? '') : ''));
                        if ($l !== '') {
                            $labels[] = $l;
                        }
                    }
                    return $degradeToText('icon grid below 3 cells', null, array_slice($labels, 0, 5));
                }
                $scene['slots']['slot_icons'] = $icons;
                return $scene;
            }

            case 'timeline_card': {
                $timeline = $this->clampTimelineContent(is_array($slots['slot_timeline'] ?? null) ? $slots['slot_timeline'] : []);
                if ($timeline === null) {
                    return $degradeToText('timeline below 2 nodes');
                }
                $scene['slots']['slot_timeline'] = $timeline;
                return $scene;
            }

            case 'step_flow': {
                $steps = $this->clampStepsContent(is_array($slots['slot_steps'] ?? null) ? $slots['slot_steps'] : []);
                if ($steps === null) {
                    return $degradeToText('step flow below 3 steps');
                }
                $scene['slots']['slot_steps'] = $steps;
                return $scene;
            }

            case 'list_ranking': {
                $ranking = $this->clampRankingContent(is_array($slots['slot_ranking'] ?? null) ? $slots['slot_ranking'] : []);
                if ($ranking === null) {
                    return $degradeToText('ranking below 3 rows');
                }
                $scene['slots']['slot_ranking'] = $ranking;
                return $scene;
            }

            case 'progress_meter': {
                $meter = $this->clampMeterContent(is_array($slots['slot_meter'] ?? null) ? $slots['slot_meter'] : []);
                if ($meter === null) {
                    return $degradeToText('meter without a percentage');
                }
                $scene['slots']['slot_meter'] = $meter;
                return $scene;
            }

            case 'phone_mockup': {
                // The frame kind lives on the slot; whitelist it (default phone).
                $screen = is_array($slots['slot_screen'] ?? null) ? $slots['slot_screen'] : [];
                $frame = strtolower(trim((string) ($screen['frame'] ?? '')));
                $screen['frame'] = in_array($frame, ['phone', 'browser'], true) ? $frame : 'phone';
                $scene['slots'] = ['slot_screen' => $screen];
                return $scene;
            }

            case 'photo_stack': {
                // Collect the provided prints in order and renumber them into a
                // contiguous run (the two trailing slots are optional). Fewer
                // than 2 prints is not a stack.
                $photos = [];
                for ($i = 1; $i <= 4; $i++) {
                    $p = $slots["slot_photo_{$i}"] ?? null;
                    if (is_array($p) && in_array($p['content_type'] ?? '', ['image', 'video'], true)) {
                        $p['content_type'] = 'image';
                        $photos[] = $p;
                    }
                }
                if (count($photos) < 2) {
                    if (count($photos) === 1) {
                        $this->warn("Scene {$sceneId}: photo stack with one print -> single_focus.");
                        $this->changed = true;
                        $scene['layout_template'] = 'single_focus';
                        $scene['slots'] = ['slot_main' => $photos[0]];
                        return $scene;
                    }
                    return $degradeToText('photo stack without prints');
                }
                $renumbered = [];
                foreach ($photos as $i => $p) {
                    $renumbered['slot_photo_' . ($i + 1)] = $p;
                }
                $scene['slots'] = $renumbered;
                return $scene;
            }

            case 'map_card': {
                $map = $this->clampMapContent(is_array($slots['slot_map'] ?? null) ? $slots['slot_map'] : []);
                if ($map === null) {
                    return $degradeToText('map without a usable pin');
                }
                $scene['slots']['slot_map'] = $map;
                return $scene;
            }

            case 'math_steps': {
                $math = $this->clampMathStepsContent(is_array($slots['slot_math'] ?? null) ? $slots['slot_math'] : []);
                if ($math === null) {
                    return $degradeToText('math steps without a usable line');
                }
                $scene['slots']['slot_math'] = $math;
                return $scene;
            }

            case 'geometry_diagram': {
                $geo = $this->clampGeometryContent(is_array($slots['slot_geometry'] ?? null) ? $slots['slot_geometry'] : []);
                if ($geo === null) {
                    return $degradeToText('geometry figure unusable');
                }
                $scene['slots']['slot_geometry'] = $geo;
                return $scene;
            }

            case 'function_plot': {
                $plot = $this->clampFunctionPlotContent(is_array($slots['slot_plot'] ?? null) ? $slots['slot_plot'] : []);
                if ($plot === null) {
                    $expr = trim((string) ((is_array($slots['slot_plot'] ?? null) ? $slots['slot_plot'] : [])['expression'] ?? ''));
                    return $degradeToText(
                        'function plot expression unusable',
                        $expr !== '' ? 'y = ' . mb_substr($expr, 0, 40) : null
                    );
                }
                $scene['slots']['slot_plot'] = $plot;
                return $scene;
            }

            case 'scenario_diagram': {
                // A maths-video card only: on an ordinary explainer the labels
                // survive as a text scene, so a normal video's behaviour never
                // changes because a new template exists in the registry.
                $raw = is_array($slots['slot_scenario'] ?? null) ? $slots['slot_scenario'] : [];

                // A "scenario" whose actors are literally coordinate points —
                // entities labelled "Point A (1, 2)" — is a COORDINATE PLANE
                // wearing the wrong costume (project 26 drew two map pins for
                // a slope problem). Convert: the points go on a real plane
                // with the line through them and the slope triangle.
                if ($this->mathMode) {
                    $coords = [];
                    foreach ((array) ($raw['entities'] ?? []) as $e) {
                        $label = trim((string) (is_array($e) ? ($e['label'] ?? '') : ''));
                        if (preg_match('/\(\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*\)/', $label, $m)) {
                            $name = trim((string) preg_replace('/\(.*$/', '', $label));
                            $coords[] = array_filter([
                                'x' => (float) $m[1],
                                'y' => (float) $m[2],
                                'label' => $name !== '' ? $name : null,
                            ], fn ($v) => $v !== null);
                        }
                    }
                    if (count($coords) >= 2) {
                        $plane = $this->clampCoordinatePlane([
                            'coords' => $coords,
                            'line_through' => [0, 1],
                            'rise_run' => count($coords) === 2,
                            'heading' => $raw['heading'] ?? null,
                            'caption' => $raw['question'] ?? null,
                        ]);
                        if ($plane !== null) {
                            $this->warn("Scene {$sceneId}: scenario of coordinate points -> coordinate_plane with the line through them.");
                            $this->changed = true;
                            $scene['layout_template'] = 'geometry_diagram';
                            $scene['slots'] = ['slot_geometry' => $plane];
                            return $scene;
                        }
                    }
                }

                $scenario = $this->mathMode ? $this->clampScenarioContent($raw, $narrationText) : null;
                if ($scenario === null) {
                    $labels = [];
                    foreach ((array) ($raw['entities'] ?? []) as $e) {
                        $label = trim((string) (is_array($e) ? ($e['label'] ?? '') : ''));
                        $value = trim((string) (is_array($e) ? ($e['value'] ?? '') : ''));
                        if ($label !== '') {
                            $labels[] = $label . ($value !== '' ? ' — ' . MathPlain::toPlain($value) : '');
                        }
                    }
                    return $degradeToText(
                        $this->mathMode ? 'scenario needs at least 2 named entities' : 'scenario_diagram outside a maths video',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: 'The setup',
                        array_slice($labels, 0, 4)
                    );
                }
                $scene['slots']['slot_scenario'] = $scenario;
                return $scene;
            }

            case 'headline_ticker': {
                $headlines = $this->clampHeadlinesContent(is_array($slots['slot_headlines'] ?? null) ? $slots['slot_headlines'] : []);
                if ($headlines === null) {
                    $texts = [];
                    foreach ((array) (($slots['slot_headlines'] ?? [])['items'] ?? []) as $it) {
                        $t = trim((string) (is_array($it) ? ($it['text'] ?? '') : $it));
                        if ($t !== '') {
                            $texts[] = $t;
                        }
                    }
                    return $degradeToText('headline ticker below 2 chips', null, array_slice($texts, 0, 3));
                }
                $scene['slots']['slot_headlines'] = $headlines;
                return $scene;
            }

            case 'pictogram_percent': {
                $pict = $this->clampPictogramContent(is_array($slots['slot_pictogram'] ?? null) ? $slots['slot_pictogram'] : []);
                if ($pict === null) {
                    return $degradeToText('pictogram without a usable share');
                }
                $scene['slots'] = ['slot_pictogram' => $pict];
                return $scene;
            }

            case 'decision_tree': {
                $raw = is_array($slots['slot_decision'] ?? null) ? $slots['slot_decision'] : [];
                $decision = $this->clampDecisionContent($raw);
                if ($decision === null) {
                    $lines = [];
                    foreach ((array) ($raw['branches'] ?? []) as $br) {
                        if (!is_array($br)) { continue; }
                        $l = trim((string) ($br['label'] ?? ''));
                        $o = trim((string) ($br['outcome'] ?? $br['question'] ?? ''));
                        if ($l !== '' || $o !== '') { $lines[] = trim($l . ($l !== '' && $o !== '' ? ' -> ' : '') . $o); }
                    }
                    return $degradeToText(
                        'decision tree without two resolved branches',
                        mb_substr(trim((string) ($raw['question'] ?? $raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($lines, 0, 5)
                    );
                }
                $scene['slots'] = ['slot_decision' => $decision];
                return $scene;
            }

            case 'hierarchy_card': {
                $raw = is_array($slots['slot_hierarchy'] ?? null) ? $slots['slot_hierarchy'] : [];
                $hierarchy = $this->clampHierarchyContent($raw);
                if ($hierarchy === null) {
                    // Below a root and two branches it is not a structure — the
                    // branch labels (with their sub-parts folded in) become a
                    // plain list under the root as the heading.
                    $lines = [];
                    foreach ((array) ($raw['children'] ?? $raw['branches'] ?? $raw['items'] ?? []) as $c) {
                        $l = trim((string) (is_array($c) ? ($c['label'] ?? '') : $c));
                        if ($l === '') {
                            continue;
                        }
                        $sub = [];
                        foreach ((array) (is_array($c) ? ($c['children'] ?? $c['items'] ?? []) : []) as $g) {
                            $gl = trim((string) (is_array($g) ? ($g['label'] ?? '') : $g));
                            if ($gl !== '') {
                                $sub[] = $gl;
                            }
                        }
                        $lines[] = $l . (count($sub) ? ' — ' . implode(', ', array_slice($sub, 0, 3)) : '');
                    }
                    return $degradeToText(
                        'hierarchy without a root and two branches',
                        mb_substr(trim((string) ($raw['root'] ?? $raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($lines, 0, 5)
                    );
                }
                $scene['slots'] = ['slot_hierarchy' => $hierarchy];
                return $scene;
            }

            case 'receipt_card': {
                $raw = is_array($slots['slot_receipt'] ?? null) ? $slots['slot_receipt'] : [];
                $note = null;
                $receipt = $this->clampReceiptContent($raw, $note);
                if ($receipt === null) {
                    $lines = [];
                    foreach ((array) ($raw['rows'] ?? $raw['items'] ?? []) as $r) {
                        if (!is_array($r)) {
                            continue;
                        }
                        $l = trim((string) ($r['label'] ?? ''));
                        if ($l !== '') {
                            $lines[] = $l . (isset($r['value']) ? ' — ' . (string) $r['value'] : '');
                        }
                    }
                    return $degradeToText(
                        'receipt below 2 numeric rows',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($lines, 0, 5)
                    );
                }
                if ($note !== null) {
                    // The viewer never sees the wrong figure, but the operator
                    // should know the model's arithmetic was off.
                    $this->warn("Scene {$sceneId}: {$note}.");
                    $this->changed = true;
                }
                $scene['slots'] = ['slot_receipt' => $receipt];
                return $scene;
            }

            case 'common_mistake': {
                $raw = is_array($slots['slot_mistake'] ?? null) ? $slots['slot_mistake'] : [];
                $note = null;
                $mistake = $this->clampMistakeContent($raw, $note);
                if ($mistake === null) {
                    // NEITHER line survives a refusal: the card could not be
                    // trusted to say which was which, so printing them without
                    // labels would just relocate the problem.
                    $why = trim((string) ($raw['why'] ?? ''));
                    return $degradeToText(
                        $note ?? 'mistake card without both lines',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        $why !== '' ? [mb_substr($why, 0, 100)] : []
                    );
                }
                $scene['slots'] = ['slot_mistake' => $mistake];
                return $scene;
            }

            case 'practice_card': {
                $raw = is_array($slots['slot_practice'] ?? null) ? $slots['slot_practice'] : [];
                $note = null;
                $practice = $this->clampPracticeContent($raw, $note);
                if ($practice === null) {
                    // The problem still teaches as a text beat — but a refused
                    // answer is printed NOWHERE, which is the whole point of
                    // refusing it. Only the hint rides along.
                    $prompt = trim((string) ($raw['prompt'] ?? $raw['question'] ?? ''));
                    $hint = trim((string) ($raw['hint'] ?? ''));
                    return $degradeToText(
                        $note !== null
                            ? "{$note} -> answer withheld"
                            : 'practice without both a problem and its answer',
                        $prompt !== '' ? mb_substr($prompt, 0, 60) : null,
                        $hint !== '' ? [mb_substr($hint, 0, 70)] : []
                    );
                }
                $scene['slots'] = ['slot_practice' => $practice];
                return $scene;
            }

            case 'term_card': {
                $raw = is_array($slots['slot_term'] ?? null) ? $slots['slot_term'] : [];
                $term = $this->clampTermContent($raw);
                if ($term === null) {
                    // Whichever half survived still carries the beat as text.
                    $word = trim((string) ($raw['term'] ?? ''));
                    $meaning = trim((string) ($raw['definition'] ?? ''));
                    return $degradeToText(
                        'term without both the word and its definition',
                        $word !== '' ? mb_substr($word, 0, 60) : null,
                        $meaning !== '' ? [mb_substr($meaning, 0, 120)] : []
                    );
                }
                $scene['slots'] = ['slot_term' => $term];
                return $scene;
            }

            case 'venn_card': {
                $raw = is_array($slots['slot_venn'] ?? null) ? $slots['slot_venn'] : [];
                $venn = $this->clampVennContent($raw);
                if ($venn === null) {
                    $labels = [];
                    foreach ((array) ($raw['sets'] ?? $raw['items'] ?? []) as $s) {
                        $l = trim((string) (is_array($s) ? ($s['label'] ?? '') : $s));
                        if ($l !== '') {
                            $labels[] = $l;
                        }
                    }
                    return $degradeToText(
                        'venn below 2 sets',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($labels, 0, 5)
                    );
                }
                $scene['slots'] = ['slot_venn' => $venn];
                return $scene;
            }

            case 'spectrum_card': {
                $raw = is_array($slots['slot_spectrum'] ?? null) ? $slots['slot_spectrum'] : [];
                $spectrum = $this->clampSpectrumContent($raw);
                if ($spectrum === null) {
                    $labels = [];
                    foreach ((array) ($raw['spectrum_items'] ?? $raw['items'] ?? []) as $it) {
                        $l = trim((string) (is_array($it) ? ($it['label'] ?? '') : $it));
                        if ($l !== '') {
                            $labels[] = $l;
                        }
                    }
                    return $degradeToText(
                        'spectrum without both poles and 2 items',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($labels, 0, 5)
                    );
                }
                $scene['slots'] = ['slot_spectrum' => $spectrum];
                return $scene;
            }

            case 'quadrant_map': {
                $raw = is_array($slots['slot_quadrant'] ?? null) ? $slots['slot_quadrant'] : [];
                $quadrant = $this->clampQuadrantContent($raw);
                if ($quadrant === null) {
                    $labels = [];
                    foreach ((array) ($raw['quadrant_items'] ?? $raw['items'] ?? []) as $it) {
                        $l = trim((string) (is_array($it) ? ($it['label'] ?? '') : $it));
                        if ($l !== '') {
                            $labels[] = $l;
                        }
                    }
                    return $degradeToText(
                        'quadrant without all four poles and 3 items',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($labels, 0, 6)
                    );
                }
                $scene['slots'] = ['slot_quadrant' => $quadrant];
                return $scene;
            }

            case 'scale_comparison': {
                $raw = is_array($slots['slot_scale'] ?? null) ? $slots['slot_scale'] : [];
                $scale = $this->clampScaleContent($raw);
                if ($scale === null) {
                    $lines = [];
                    foreach ((array) ($raw['scale_items'] ?? $raw['items'] ?? []) as $it) {
                        if (!is_array($it)) {
                            continue;
                        }
                        $l = trim((string) ($it['label'] ?? ''));
                        if ($l !== '') {
                            $lines[] = $l . (isset($it['value']) ? ' — ' . (string) $it['value'] : '');
                        }
                    }
                    return $degradeToText(
                        'scale comparison below 2 measurable things',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($lines, 0, 5)
                    );
                }
                $scene['slots'] = ['slot_scale' => $scale];
                return $scene;
            }

            case 'evidence_card': {
                $raw = is_array($slots['slot_evidence'] ?? null) ? $slots['slot_evidence'] : [];
                $evidence = $this->clampEvidenceContent($raw);
                if ($evidence === null) {
                    // The refusal the card is named for: a finding with no
                    // nameable source is just a claim, so it degrades to a plain
                    // text beat rather than a fabricated citation.
                    $finding = trim((string) ($raw['finding'] ?? $raw['claim'] ?? $raw['fact'] ?? ''));
                    return $degradeToText(
                        'evidence without a nameable source',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        $finding !== '' ? [mb_substr($finding, 0, 160)] : []
                    );
                }
                $scene['slots'] = ['slot_evidence' => $evidence];
                return $scene;
            }

            case 'proportion_flow': {
                $raw = is_array($slots['slot_proportion'] ?? null) ? $slots['slot_proportion'] : [];
                $note = null;
                $proportion = $this->clampProportionContent($raw, $note);
                if ($proportion === null) {
                    $lines = [];
                    foreach ((array) ($raw['branches'] ?? $raw['items'] ?? []) as $b) {
                        if (!is_array($b)) {
                            continue;
                        }
                        $l = trim((string) ($b['label'] ?? ''));
                        if ($l !== '') {
                            $lines[] = $l . (isset($b['value']) ? ' — ' . (string) $b['value'] : '');
                        }
                    }
                    return $degradeToText(
                        'proportion below 2 positive branches',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($lines, 0, 5)
                    );
                }
                if ($note !== null) {
                    // Same rule as the receipt: the drawn split is always the
                    // honest one, and the operator hears about the difference.
                    $this->warn("Scene {$sceneId}: {$note}.");
                    $this->changed = true;
                }
                $scene['slots'] = ['slot_proportion' => $proportion];
                return $scene;
            }

            case 'layer_stack': {
                $raw = is_array($slots['slot_layers'] ?? null) ? $slots['slot_layers'] : [];
                $stack = $this->clampLayerStackContent($raw);
                if ($stack === null) {
                    $labels = [];
                    foreach ((array) ($raw['layers'] ?? $raw['items'] ?? []) as $it) {
                        $l = trim((string) (is_array($it) ? ($it['label'] ?? '') : $it));
                        if ($l !== '') {
                            $labels[] = $l;
                        }
                    }
                    // Order preserved even in the degrade — it is the content.
                    return $degradeToText(
                        'stack below 3 layers',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        array_slice($labels, 0, 5)
                    );
                }
                $scene['slots'] = ['slot_layers' => $stack];
                return $scene;
            }

            case 'cycle_diagram': {
                $raw = is_array($slots['slot_cycle'] ?? null) ? $slots['slot_cycle'] : [];
                $cycle = $this->clampCycleContent($raw);
                if ($cycle === null) {
                    $labels = [];
                    foreach ((array) ($raw['items'] ?? []) as $it) {
                        $l = trim((string) (is_array($it) ? ($it['label'] ?? '') : $it));
                        if ($l !== '') {
                            $labels[] = $l;
                        }
                    }
                    return $degradeToText(
                        'cycle below 3 stages',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: 'The loop',
                        array_slice($labels, 0, 4)
                    );
                }
                $scene['slots'] = ['slot_cycle' => $cycle];
                return $scene;
            }

            case 'formula_anatomy': {
                $raw = is_array($slots['slot_formula'] ?? null) ? $slots['slot_formula'] : [];
                $clean = $this->clampFormulaAnatomyContent($raw);
                if ($clean === null) {
                    $bullets = [];
                    foreach ((array) ($raw['parts'] ?? []) as $p) {
                        $m = trim((string) (is_array($p) ? ($p['match'] ?? '') : ''));
                        $l = trim((string) (is_array($p) ? ($p['label'] ?? '') : ''));
                        if ($l !== '') {
                            $bullets[] = ($m !== '' ? MathPlain::toPlain($m) . ' — ' : '') . $l;
                        }
                    }
                    return $degradeToText(
                        'formula anatomy without an anchorable formula and 2 parts',
                        mb_substr(MathPlain::toPlain(trim((string) ($raw['formula'] ?? ''))), 0, 60) ?: null,
                        array_slice($bullets, 0, 4)
                    );
                }
                $scene['slots'] = ['slot_formula' => $clean];
                return $scene;
            }

            case 'myth_fact': {
                $raw = is_array($slots['slot_myth_fact'] ?? null) ? $slots['slot_myth_fact'] : [];
                $pair = $this->clampMythFactContent($raw);
                if ($pair === null) {
                    $myth = trim((string) ($raw['myth'] ?? ''));
                    $fact = trim((string) ($raw['fact'] ?? ''));
                    return $degradeToText(
                        'myth/fact pair incomplete',
                        mb_substr(trim((string) ($raw['heading'] ?? '')), 0, 60) ?: null,
                        array_values(array_filter([
                            $myth !== '' ? 'Myth: ' . $myth : '',
                            $fact !== '' ? 'Fact: ' . $fact : '',
                        ]))
                    );
                }
                $scene['slots'] = ['slot_myth_fact' => $pair];
                return $scene;
            }

            case 'labeled_diagram': {
                $raw = is_array($slots['slot_diagram'] ?? null) ? $slots['slot_diagram'] : null;
                if ($raw === null) {
                    // The model used its own slot key — accept any image slot.
                    foreach ($slots as $s) {
                        if (is_array($s) && ($s['content_type'] ?? '') === 'image') {
                            $raw = $s;
                            break;
                        }
                    }
                }
                if (!is_array($raw)) {
                    return $degradeToText('labeled diagram without an image');
                }
                $raw['content_type'] = 'image';
                $labels = count($this->normalizeCallouts($raw['callouts'] ?? []));
                foreach ((array) ($raw['callout_suggestions'] ?? []) as $s) {
                    if (trim((string) $s) !== '') {
                        $labels++;
                    }
                }
                if ($labels < 2) {
                    // One label is a caption, not a diagram — the image
                    // survives as a plain focus scene.
                    $this->warn("Scene {$sceneId}: labeled diagram with fewer than 2 labels -> single_focus.");
                    $this->changed = true;
                    $scene['layout_template'] = 'single_focus';
                    $scene['slots'] = ['slot_main' => $raw];
                    return $scene;
                }
                $scene['slots'] = ['slot_diagram' => $raw];
                return $scene;
            }

            default:
                return $scene;
        }
    }

    /**
     * Resolve an image's camera move, respecting the model but upgrading the
     * cases it leaves neutral:
     *  - a DELIBERATE, valid, non-default move is always kept;
     *  - an ABSENT or INVALID move takes a content/aspect-aware pick, falling
     *    back to the registry default when the subject gives no signal;
     *  - the neutral default (ken_burns) is upgraded ONLY when the subject
     *    clearly calls for a specific move — otherwise the classic drift stands.
     */
    private function resolveCameraMove($given, string $description): string
    {
        $default = ExplainerRegistry::defaultCameraMove();
        $valid = is_string($given) && in_array($given, ExplainerRegistry::cameraMoves(), true);
        if (!$valid) {
            return $this->pickCameraDefault($description) ?? $default;
        }
        if ($given === $default) {
            return $this->pickCameraDefault($description) ?? $given;
        }
        return (string) $given;
    }

    /**
     * A content- and aspect-aware camera move for an image whose SUBJECT gives
     * a clear signal — a tall thing climbs, a wide vista sweeps, a face pushes
     * in. Returns null when nothing matches, so the caller keeps the neutral
     * default rather than guess. Whole-word matching only, so "seasonal" never
     * reads as "sea". This is where iter 40's new moves (arc_pan / pedestal_up)
     * are actually DEPLOYED — before this, they fired only if the model named
     * them.
     */
    private function pickCameraDefault(string $description): ?string
    {
        $tokens = preg_split('/[^a-z]+/', mb_strtolower($description)) ?: [];
        $words = array_flip(array_filter($tokens));
        $has = static fn (array $set): bool => (bool) array_intersect_key($words, array_flip($set));
        $portrait = $this->aspectRatio === '9:16';

        // A person / face is the subject -> a gentle, intimate push (never the
        // aggressive push_in as an unasked-for default).
        if ($has(['portrait', 'headshot', 'face', 'closeup', 'ceo', 'founder', 'person', 'man', 'woman', 'selfie'])) {
            return 'slow_zoom_in';
        }
        // A tall, VERTICAL subject -> climb it (a pedestal dolly on portrait,
        // a pan+zoom on landscape).
        if ($has([
            'skyscraper', 'skyscrapers', 'tower', 'towers', 'waterfall', 'rocket', 'cliff', 'redwood',
            'mountain', 'statue', 'monument', 'lighthouse', 'spire', 'vertical', 'staircase', 'stairs',
            'ladder', 'giraffe', 'tree', 'pillar', 'column', 'totem', 'tall',
        ])) {
            return $portrait ? 'pedestal_up' : 'pan_up_zoom_in';
        }
        // A wide, open vista -> a filmic arced sweep across it.
        if ($has([
            'landscape', 'vista', 'panorama', 'horizon', 'aerial', 'field', 'fields', 'ocean', 'sea',
            'desert', 'valley', 'cityscape', 'coastline', 'savanna', 'prairie', 'countryside', 'wide',
            'plains', 'skyline', 'canyon', 'mountains',
        ])) {
            return 'arc_pan';
        }

        return null;
    }

    private function validateMediaSlot(string $sceneId, string $slotKey, string $contentType, array $slot, string $narrationText): array
    {
        $description = '';
        if (isset($slot['asset_request']) && is_array($slot['asset_request'])) {
            $description = (string) ($slot['asset_request']['description'] ?? '');
        }
        if ($description === '') {
            $description = (string) ($slot['description'] ?? ($slot['label'] ?? 'Visual for this scene'));
            $this->warn("Scene {$sceneId}/{$slotKey}: {$contentType} had no asset_request.description -> inferred.");
        }

        $cameraMove = $this->resolveCameraMove($slot['camera_move'] ?? null, $description);

        $suggestions = [];
        if (is_array($slot['callout_suggestions'] ?? null)) {
            foreach ($slot['callout_suggestions'] as $s) {
                $s = trim((string) $s);
                if ($s !== '') {
                    $suggestions[] = mb_substr($s, 0, 80);
                }
                if (count($suggestions) >= 4) {
                    break;
                }
            }
        }

        $clean = [
            'content_type' => $contentType,
            'label' => (string) ($slot['label'] ?? ''),
            'camera_move' => $cameraMove,
            'asset_request' => ['description' => $description],
            'asset_ref' => $slot['asset_ref'] ?? null,
            'callouts' => $this->normalizeCallouts($slot['callouts'] ?? []),
            'callout_suggestions' => $suggestions,
        ];

        // Device frame kind (phone_mockup, §5.13) — whitelisted upstream by
        // normalizeCardScene; carried through the generic slot rebuild here.
        if (in_array($slot['frame'] ?? null, ['phone', 'browser'], true)) {
            $clean['frame'] = $slot['frame'];
        }

        // Short title over the image (labeled_diagram's headline; harmless on
        // other media slots, which simply never render it).
        $heading = trim((string) ($slot['heading'] ?? ''));
        if ($heading !== '') {
            $clean['heading'] = mb_substr($heading, 0, 60);
        }

        // Auto-fetched stock b-roll marker (§8) — survives re-validation.
        if (trim((string) ($slot['stock_query'] ?? '')) !== '') {
            $clean['stock_query'] = mb_substr(trim((string) $slot['stock_query']), 0, 60);
        }

        return $clean;
    }

    /**
     * Normalise user/AI callout pins: clamp coordinates to [0,1], trim text,
     * validate anchor, and cap the count so an image can never be cluttered.
     *
     * @return array<int, array{x: float, y: float, text: string, anchor: string}>
     */
    private function normalizeCallouts($callouts): array
    {
        if (!is_array($callouts)) {
            return [];
        }

        $clean = [];
        foreach ($callouts as $callout) {
            if (!is_array($callout)) {
                continue;
            }
            $text = trim((string) ($callout['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $anchor = (string) ($callout['anchor'] ?? 'auto');
            if (!in_array($anchor, ['auto', 'left', 'right', 'top', 'bottom'], true)) {
                $anchor = 'auto';
            }
            $clean[] = [
                'x' => max(0.0, min(1.0, (float) ($callout['x'] ?? 0.5))),
                'y' => max(0.0, min(1.0, (float) ($callout['y'] ?? 0.5))),
                'text' => mb_substr($text, 0, 80),
                'anchor' => $anchor,
            ];
            if (count($clean) >= 6) {
                break;
            }
        }

        return $clean;
    }

    /**
     * A short heading derived from the narration's opening: its first
     * sentence, clipped to <=40 chars at a word boundary with trailing
     * punctuation dropped. Used when a heading had to be discarded (e.g. a
     * prompt-constraint echo) — a derived title beats an empty band.
     */
    private function headingFromNarration(string $narrationText): string
    {
        $s = trim($this->firstSentence($narrationText));
        $s = rtrim($s, " \t.!?,;:");
        if ($s !== '' && mb_strlen($s) > 40) {
            $clip = mb_substr($s, 0, 41);
            $cut = mb_strrpos($clip, ' ');
            $s = rtrim(mb_substr($clip, 0, $cut !== false && $cut > 12 ? $cut : 40), " \t.!?,;:");
        }
        // A clip that lands on a connector ("…is essential for") reads as a
        // typo on screen — peel trailing stopwords until a content word ends
        // the line.
        while (preg_match('/\s+(?:for|and|or|of|to|the|a|an|with|in|on|at|is|are|was|were|its|his|her|their|our|your|by|from|as|that|this)$/iu', $s, $m)) {
            $s = rtrim(mb_substr($s, 0, mb_strlen($s) - mb_strlen($m[0])), " \t.!?,;:");
        }

        return $s;
    }

    /**
     * True for a bullet that is a prompt-constraint token the model copied into
     * the content ("≤40", "<= 60 chars", "2-3 sentences", "40 words") rather
     * than real copy. Deliberately narrow: a real bullet ("42 meters", "3
     * golden rules", "a^2 + b^2 = c^2") never leads with a bare comparison
     * operator+number and never is JUST a count-of-chars/words phrase.
     */
    private function looksLikeConstraintArtifact(string $b): bool
    {
        $b = trim($b);
        if ($b === '') {
            return false;
        }
        if (preg_match('/^(?:[<>]=?|[≤≥])\s*\d+/u', $b)) {
            return true;
        }
        if (preg_match('/^\d+\s*(?:[-–]|to)\s*\d+\s*(?:sentences?|words?|chars?|characters?|bullets?|points?)\b/iu', $b)) {
            return true;
        }
        if (preg_match('/^\d+\s*(?:chars?|characters?|words?)\b$/iu', $b)) {
            return true;
        }

        return false;
    }

    private function validateTextSlot(string $sceneId, string $slotKey, array $slot, string $narrationText): array
    {
        $heading = trim((string) ($slot['heading'] ?? ''));
        // The bullet scrub below has guarded against echoed prompt constraints
        // since v26 — but only for bullets. Project 91's claim card shipped a
        // literal "≤40" HEADING (the model echoed composeProof's own schema
        // hint), so the same guard now covers the heading, with a real
        // replacement derived from the narration instead of a dropped field.
        if ($heading !== '' && $this->looksLikeConstraintArtifact($heading)) {
            $derived = $this->headingFromNarration($narrationText);
            $this->warn("Scene {$sceneId}/{$slotKey}: heading was a prompt-constraint echo (\"{$heading}\") -> " . ($derived !== '' ? "derived \"{$derived}\" from narration." : 'dropped.'));
            $heading = $derived;
        }
        $bullets = $slot['bullets'] ?? [];

        if (!is_array($bullets)) {
            $bullets = preg_split('/\r?\n/', (string) $bullets) ?: [];
        }
        $bullets = array_values(array_filter(array_map(fn ($b) => trim((string) $b), $bullets), fn ($b) => $b !== ''));
        // Drop prompt-constraint tokens the model echoes as if they were copy
        // ("≤40", "<=60 chars", "2-3 sentences") — project 37's claim card
        // carried a literal "≤40" bullet that pushed the real formula off frame.
        $bullets = array_values(array_filter($bullets, fn ($b) => !$this->looksLikeConstraintArtifact($b)));
        $bullets = array_slice($bullets, 0, 5);

        if ($heading === '' && empty($bullets)) {
            $this->warn("Scene {$sceneId}/{$slotKey}: text_block empty -> built from narration.");
            return $this->genericTextBlock($narrationText);
        }

        $reveal = (string) ($slot['reveal'] ?? 'sequential');
        if (!in_array($reveal, ['sequential', 'all_at_once'], true)) {
            $reveal = 'sequential';
        }

        $bullets = !empty($bullets) ? $bullets : [$this->firstSentence($narrationText)];

        // A text_block does NOT typeset. The prompt forbids putting maths in a
        // bullet, and on a maths video the model does it anyway — a bullet
        // reading "log_2((x - 1)(x + 3)) = 3" or "x = frac{-2 pm sqrt{48}}{2}"
        // then renders as that literal source on screen. Project the notation
        // into real glyphs on the way past; it is a no-op on ordinary prose.
        // Linearize FIRST: the same transport mangling that hit the math cards
        // hits bullets too (project 34: "h(t) = at\x1B2 + bt + c" — a ² that
        // arrived as a control char), and MathPlain cannot scrub what it
        // cannot recognise.
        if ($this->mathMode) {
            $heading = MathPlain::toPlain($this->linearizeMathSymbols($heading));
            $bullets = array_map(fn ($b) => MathPlain::toPlain($this->linearizeMathSymbols((string) $b)), $bullets);
        }

        return [
            'content_type' => 'text_block',
            'heading' => $heading !== '' ? $heading : 'Key Points',
            'bullets' => $bullets,
            'reveal' => $reveal,
        ];
    }

    private function validateExplanationSlot(array $slot, string $narrationText): array
    {
        $heading = trim((string) ($slot['heading'] ?? ''));
        $body = trim((string) ($slot['body'] ?? ''));

        if ($body === '') {
            $body = $this->firstSentence($narrationText) ?: 'Here is the key detail.';
        }

        // Same as a text_block: the box shows its body verbatim, so stray
        // notation has to become glyphs before it gets there (and control-char
        // mangled symbols have to be scrubbed first, same as bullets).
        if ($this->mathMode) {
            $heading = MathPlain::toPlain($this->linearizeMathSymbols($heading));
            $body = MathPlain::toPlain($this->linearizeMathSymbols($body));
        }

        return [
            'content_type' => 'explanation_box',
            'heading' => $heading !== '' ? $heading : 'Note',
            'body' => mb_substr($body, 0, 220),
        ];
    }

    /**
     * Build a safe default for a slot, respecting what the slot allows: prefer
     * text, then explanation, otherwise an image asset request (so the user can
     * still upload). Used for missing or illegal slots.
     */
    private function fillMissingSlot(array $meta, string $narrationText, array $original = []): array
    {
        $allowed = $meta['allowed'] ?? ['text_block'];

        if (in_array('text_block', $allowed, true)) {
            return $this->genericTextBlock($narrationText, $original['heading'] ?? null);
        }
        if (in_array('explanation_box', $allowed, true)) {
            return $this->validateExplanationSlot($original, $narrationText);
        }

        // Media-only slot (e.g. slot_background): request an upload.
        $type = in_array('image', $allowed, true) ? 'image' : ($allowed[0] ?? 'image');
        $this->changed = true;

        $desc = trim((string) ($original['asset_request']['description'] ?? 'Background visual for this scene'));

        return [
            'content_type' => $type,
            'label' => (string) ($original['label'] ?? ''),
            'camera_move' => $this->resolveCameraMove($original['camera_move'] ?? null, $desc),
            'asset_request' => ['description' => $desc],
            'asset_ref' => null,
        ];
    }

    /**
     * Attach normalised dock + width to a floating slot.
     */
    private function applyDock(array $content, array $meta, array $raw): array
    {
        $options = $meta['dock_options'];
        $dock = $raw['dock'] ?? ($meta['default_dock'] ?? $options[0]);
        if (!in_array($dock, $options, true)) {
            $dock = $meta['default_dock'] ?? $options[0];
        }
        $content['dock'] = $dock;

        if (isset($meta['default_width_pct'])) {
            $width = (float) ($raw['width_pct'] ?? $meta['default_width_pct']);
            $min = (float) ($meta['min_width_pct'] ?? 25);
            $max = (float) ($meta['max_width_pct'] ?? 50);
            $content['width_pct'] = max($min, min($max, $width));
        }

        return $content;
    }

    private function fallbackScene(string $sceneId, int $order, float $duration, string $narrationText): array
    {
        $this->changed = true;

        return [
            'scene_id' => $sceneId,
            'order' => $order,
            'duration_seconds' => round($duration, 2),
            'narration' => ['text' => $narrationText],
            'layout_template' => 'single_focus',
            'slots' => ['slot_main' => $this->genericTextBlock($narrationText)],
            'transition' => ExplainerRegistry::defaultTransition(),
        ];
    }

    private function genericTextBlock(string $narrationText, ?string $heading = null): array
    {
        $this->changed = true;

        return [
            'content_type' => 'text_block',
            'heading' => $heading !== null && $heading !== '' ? $heading : 'Key Point',
            'bullets' => [$this->firstSentence($narrationText) ?: 'Continue...'],
            'reveal' => 'all_at_once',
        ];
    }

    private function firstSentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/(?<=[.!?])\s+/', $text);
        return mb_substr($parts[0] ?? $text, 0, 120);
    }

    private function warn(string $message): void
    {
        $this->changed = true;
        $this->warnings[] = $message;
    }
}
