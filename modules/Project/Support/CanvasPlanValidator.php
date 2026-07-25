<?php

namespace Modules\Project\Support;

/**
 * CanvasPlanValidator
 *
 * The canvas-journey counterpart of {@see ShotListValidator}: the Canvas
 * Director LLM pass is never trusted. Every scene region is clamped to legal
 * bounds, missing scenes are auto-placed, overlapping regions are pushed
 * apart, nested (zoom_nest) geometry is computed from the parent's FINAL
 * position, connectors are normalised, and the world is re-fitted. If the
 * plan is unusable it is replaced whole by a deterministic zigzag layout, so
 * a legal plan ALWAYS comes out.
 *
 * Plan shape v2 (world units, x/y = region CENTER, frameless — no rotation):
 * {
 *   "journey_pattern": "zigzag",
 *   "world": { "width": 6400, "height": 4200 },
 *   "items": [
 *     { "scene_id": "scene_1", "treatment": "hero_open",
 *       "x": 1400, "y": 1000, "w": 1560, "h": 1000,
 *       "emphasis": "hero", "hold_move": "breathe",
 *       "nest": { "fx": 0.62, "fy": 0.35 },           // zoom_nest only (focal point in PREVIOUS region)
 *       "props": [ { "prompt": "glowing compass", "animation": "float",
 *                    "x": 1.05, "y": 0.1, "size": 0.16 } ] }
 *   ],
 *   "connectors": [ { "from": "scene_1", "to": "scene_2", "style": "dotted", "label": "" } ]
 * }
 *
 * Output items additionally carry `depth` (0 = on the canvas, 1+ = nested)
 * and `parent_id` so the renderer needs no nesting math.
 */
class CanvasPlanValidator
{
    /** @var string[] */
    private array $warnings = [];

    /**
     * @param  array  $plan    Raw plan from the Canvas Director LLM (may be garbage).
     * @param  array  $scenes  Validated scenes (each has scene_id + order).
     * @param  string $aspectRatio  '16:9' | '9:16' | '1:1'
     * @return array{plan: array, warnings: string[]}
     */
    public function validate(array $plan, array $scenes, string $aspectRatio): array
    {
        $this->warnings = [];

        $sceneIds = array_map(fn ($s) => (string) $s['scene_id'], $scenes);
        if (empty($sceneIds)) {
            return ['plan' => $this->fallbackPlan([], $aspectRatio), 'warnings' => ['No scenes; empty plan.']];
        }

        $canvas = ExplainerRegistry::canvas();
        $base = ExplainerRegistry::canvasBaseCard($aspectRatio);
        $minSize = (float) ($canvas['min_card_size'] ?? 640);
        $maxSize = (float) ($canvas['max_card_size'] ?? 3000);
        $minGap = (float) ($canvas['min_gap'] ?? 160);

        // ---- Journey pattern -------------------------------------------------
        $patterns = $canvas['journey_patterns'] ?? ['zigzag'];
        $pattern = (string) ($plan['journey_pattern'] ?? '');
        if (!in_array($pattern, $patterns, true)) {
            $pattern = $canvas['default_journey_pattern'] ?? 'zigzag';
        }

        // ---- Items -----------------------------------------------------------
        $rawItems = is_array($plan['items'] ?? null) ? $plan['items'] : [];
        $byScene = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sid = (string) ($item['scene_id'] ?? '');
            if ($sid !== '' && in_array($sid, $sceneIds, true) && !isset($byScene[$sid])) {
                $byScene[$sid] = $item;
            }
        }

        // If the director covered under half the scenes, its layout is junk —
        // use the deterministic layout wholesale instead of patching holes.
        if (count($byScene) < max(1, (int) ceil(count($sceneIds) / 2))) {
            $this->warn('Canvas plan covered too few scenes -> deterministic fallback layout.');
            return ['plan' => $this->fallbackPlan($sceneIds, $aspectRatio), 'warnings' => $this->warnings];
        }

        $holdMoves = $canvas['hold_moves'] ?? ['breathe'];
        $treatments = ExplainerRegistry::treatmentNames();

        $items = [];
        foreach ($sceneIds as $i => $sid) {
            $raw = $byScene[$sid] ?? null;
            if ($raw === null) {
                $this->warn("Canvas: scene {$sid} missing from plan -> auto-placed.");
                $items[] = $this->autoPlacedItem($sid, $i, $items, $base, $minGap);
                continue;
            }

            $w = $this->clampf((float) ($raw['w'] ?? $base['w']), $minSize, $maxSize);
            $h = $this->clampf((float) ($raw['h'] ?? $base['h']), $minSize, $maxSize);

            // Keep region aspect sane (0.45..2.2) so no strip-thin regions.
            $ratio = $w / max(1.0, $h);
            if ($ratio > 2.2) {
                $w = $h * 2.2;
            } elseif ($ratio < 0.45) {
                $h = $w / 0.45;
            }

            $holdMove = (string) ($raw['hold_move'] ?? '');
            if (!in_array($holdMove, $holdMoves, true)) {
                $holdMove = $canvas['default_hold_move'] ?? 'breathe';
            }

            // Treatment: scene 1 is always the hero open; zoom_nest is only
            // legal when there is a previous scene to nest into.
            $treatment = (string) ($raw['treatment'] ?? '');
            if (!in_array($treatment, $treatments, true)) {
                $treatment = $i === 0 ? 'hero_open' : ExplainerRegistry::defaultTreatment();
            }
            if ($i === 0) {
                $treatment = 'hero_open';
            } elseif ($treatment === 'hero_open') {
                $this->warn("Canvas: scene {$sid} used hero_open mid-journey -> canvas_hop.");
                $treatment = 'canvas_hop';
            }

            $relation = (string) ($raw['relation'] ?? '');
            if (!in_array($relation, ExplainerRegistry::relationNames(), true)) {
                $relation = '';
            }

            $item = [
                'scene_id' => $sid,
                'treatment' => $treatment,
                'relation' => $relation,
                'callback_to' => is_string($raw['callback_to'] ?? null) ? $raw['callback_to'] : null,
                'x' => round($this->clampf((float) ($raw['x'] ?? 0), -50000, 50000), 1),
                'y' => round($this->clampf((float) ($raw['y'] ?? 0), -50000, 50000), 1),
                'w' => round($w, 1),
                'h' => round($h, 1),
                'rotation' => 0,
                'emphasis' => ($raw['emphasis'] ?? '') === 'hero' ? 'hero' : 'normal',
                'hold_move' => $holdMove,
                'props' => $this->normalizeProps($raw['props'] ?? []),
                'depth' => 0,
                'parent_id' => null,
            ];

            if ($treatment === 'zoom_nest') {
                // Keep the focal point; geometry is computed AFTER push-apart
                // so it tracks the parent's final position.
                $item['nest'] = [
                    'fx' => $this->clampf((float) ($raw['nest']['fx'] ?? 0.5), 0.0, 1.0),
                    'fy' => $this->clampf((float) ($raw['nest']['fy'] ?? 0.5), 0.0, 1.0),
                ];
            }

            $items[] = $item;
        }

        // Narration + mood context, used to infer relations the LLM omitted
        // and to seed decoration for scenes it left bare.
        $context = [];
        foreach ($scenes as $scene) {
            $context[(string) $scene['scene_id']] = [
                'narration' => (string) ($scene['narration']['text'] ?? ''),
                'mood' => (string) ($scene['mood'] ?? 'neutral'),
            ];
        }

        // Story pass: normalise relations, derive treatments from them, break
        // monotone runs — the anti-"19 identical hops" guarantee.
        $items = $this->normalizeStory($items, $sceneIds, $context);

        // Every scene deserves at least a little life around it.
        $items = $this->ensureProps($items, $context);

        // Geometry pass: detect degenerate layouts (columns, rows, uniform
        // lattices — the LLM's favourite failure mode) and re-lay them out
        // along the journey pattern; bend over-straight paths; seat contrast
        // scenes beside their counterpart.
        $items = $this->repairGeometry($items, $pattern, $base, $minGap);

        // Push apart only top-level regions; nested ones follow their parent.
        $items = $this->resolveOverlaps($items, $minGap, onlyTopLevel: true);

        // Now anchor every zoom_nest region inside its (final) parent.
        $items = $this->applyNesting($items);

        // ---- Connectors (relation-driven) -------------------------------------
        $labelByPair = [];
        foreach ((array) ($plan['connectors'] ?? []) as $conn) {
            if (!is_array($conn)) {
                continue;
            }
            $key = ($conn['from'] ?? '') . '->' . ($conn['to'] ?? '');
            $labelByPair[$key] = mb_substr(trim((string) ($conn['label'] ?? '')), 0, 24);
        }
        $connectors = $this->buildConnectors($items, $sceneIds, $labelByPair);

        return [
            'plan' => [
                'version' => 2,
                'journey_pattern' => $pattern,
                'world' => $this->fitWorld($items),
                'items' => $items,
                'connectors' => $connectors,
            ],
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Normalise every item's story relation and keep the edit varied:
     *  - scene 1 is always the "opening";
     *  - a missing/invalid relation is derived from the treatment;
     *  - callback_to must point at a scene EARLIER than the previous one;
     *  - treatments never run more than twice in a row (the 3rd in a run is
     *    re-cast as a pull_reveal or a dive so long scripts stay cinematic);
     *  - hold moves cycle instead of repeating forever.
     */
    private function normalizeStory(array $items, array $sceneIds, array $context = []): array
    {
        $relationByTreatment = [
            'hero_open' => 'opening',
            'zoom_nest' => 'elaborates',
            'pull_reveal' => 'new_chapter',
        ];

        // Budget for narration-inferred relations (keeps heuristics tasteful).
        $inferBudget = ['consequence' => ExplainerRegistry::maxArrows(), 'contrast' => 2, 'new_chapter' => 2];

        foreach ($items as $i => &$item) {
            if ($i === 0) {
                $item['relation'] = 'opening';
                $item['callback_to'] = null;
                continue;
            }

            if (($item['relation'] ?? '') === '' || $item['relation'] === 'opening') {
                // The LLM gave no relation — read the scene's own narration:
                // openers like "So ...", "But ...", "Now ..." are the story
                // telling us how this scene connects.
                $inferred = $this->inferRelation($context[$item['scene_id']]['narration'] ?? '');
                if ($inferred !== null && ($inferBudget[$inferred] ?? 0) > 0 && $item['treatment'] === 'canvas_hop') {
                    $inferBudget[$inferred]--;
                    $item['relation'] = $inferred;
                } else {
                    $item['relation'] = $relationByTreatment[$item['treatment']] ?? 'continues';
                }
            }

            // A dive is the only way "elaborates" reads on screen; without one,
            // downgrade the relation instead of forcing geometry the director
            // didn't lay out.
            if ($item['relation'] === 'elaborates' && $item['treatment'] !== 'zoom_nest') {
                $item['relation'] = 'continues';
            }

            if ($item['relation'] === 'callback') {
                $target = array_search($item['callback_to'], $sceneIds, true);
                if ($target === false || $target >= $i - 1) {
                    $this->warn("Canvas: scene {$item['scene_id']} callback_to invalid -> continues.");
                    $item['relation'] = 'continues';
                    $item['callback_to'] = null;
                }
            } else {
                $item['callback_to'] = null;
            }
        }
        unset($item);

        // ---- Break treatment runs (max 2 in a row) ----------------------------
        $maxRun = ExplainerRegistry::maxConsecutiveTreatment();
        $run = 0;
        $lastTreatment = '';
        $altToggle = 0;
        for ($i = 1; $i < count($items); $i++) {
            $t = $items[$i]['treatment'];
            $run = ($t === $lastTreatment) ? $run + 1 : 1;
            $lastTreatment = $t;

            if ($run <= $maxRun) {
                continue;
            }

            if ($t === 'canvas_hop') {
                // Alternate between a wide reveal and a dive so long stretches
                // of hops become rhythm instead of repetition.
                if ($altToggle++ % 2 === 0) {
                    $items[$i]['treatment'] = 'pull_reveal';
                } else {
                    $items[$i]['treatment'] = 'zoom_nest';
                    $items[$i]['relation'] = 'elaborates';
                    $items[$i]['nest'] = $items[$i]['nest'] ?? ['fx' => 0.5, 'fy' => 0.42];
                }
            } else {
                $items[$i]['treatment'] = 'canvas_hop';
            }
            $this->warn("Canvas: broke a {$t} run at {$items[$i]['scene_id']} -> {$items[$i]['treatment']}.");
            $lastTreatment = $items[$i]['treatment'];
            $run = 1;
        }

        // ---- Cycle repeated hold moves ----------------------------------------
        $holdCycle = ExplainerRegistry::canvas()['hold_moves'] ?? ['breathe', 'push_in', 'drift', 'orbit', 'rise'];
        $holdRun = 0;
        $lastHold = '';
        for ($i = 0; $i < count($items); $i++) {
            $hm = $items[$i]['hold_move'];
            $holdRun = ($hm === $lastHold) ? $holdRun + 1 : 1;
            $lastHold = $hm;
            if ($holdRun > 2) {
                $idx = array_search($hm, $holdCycle, true);
                $next = $holdCycle[(($idx === false ? 0 : $idx) + 1 + ($i % 2)) % count($holdCycle)];
                $items[$i]['hold_move'] = $next;
                $lastHold = $next;
                $holdRun = 1;
            }
        }

        return $items;
    }

    /**
     * Infer a story relation from how a scene's narration OPENS. Writers
     * signal structure in their first words — "So ..." is a consequence,
     * "But ..." a contrast, "Now ..." a chapter turn. Conservative on
     * purpose: only unmistakable markers, and the caller caps how many
     * inferences are accepted.
     */
    private function inferRelation(string $narration): ?string
    {
        $text = mb_strtolower(trim($narration));
        if ($text === '') {
            return null;
        }
        $head = mb_substr($text, 0, 90);

        if (preg_match('/^(but[ ,]|however|instead[ ,]|yet[ ,]|on the other hand|unlike )/u', $head)
            || str_contains($head, 'on the other hand') || str_contains($head, 'in contrast')) {
            return 'contrast';
        }
        if (preg_match('/^(so[ ,]|as a result|the result|that\'?s why|this (led|leads|means|meant)|because of (this|that)|which (led|means|meant)|in turn)/u', $head)
            || str_contains($head, 'as a result') || str_contains($head, 'this led to')) {
            return 'consequence';
        }
        if (preg_match('/^(now[ ,]|next[ ,]|moving on|meanwhile|fast[- ]forward|let\'?s (turn|move|switch))/u', $head)
            || str_contains($head, 'fast forward') || str_contains($head, 'moving on to')) {
            return 'new_chapter';
        }

        return null;
    }

    /**
     * Give every bare top-level scene one small mood-matched decoration.
     * Prompts come from a tiny curated pool, so the shared props library
     * (keyed by md5(prompt)) generates each at most once EVER — after warmup
     * this costs nothing and no scene floats in dead space.
     *
     * Disabled whenever the registry caps props at 0: floating AI cut-outs are
     * the opposite of a flat, uncluttered field. This ran unconditionally and
     * ignored the cap entirely, so it has to check it itself.
     */
    private function ensureProps(array $items, array $context): array
    {
        if (ExplainerRegistry::maxPropsPerScene() < 1) {
            return $items;
        }

        $pools = [
            'calm' => ['soft glowing orb', 'paper plane', 'feather quill', 'small brass compass'],
            'uplifting' => ['sun doodle', 'hot air balloon', 'trophy cup', 'confetti burst'],
            'energetic' => ['rocket doodle', 'lightning bolt icon', 'starburst spark', 'megaphone doodle'],
            'dramatic' => ['chess pawn', 'balance scale', 'hourglass', 'burning match'],
            'mysterious' => ['magnifying glass', 'old brass key', 'crystal ball', 'folded treasure map'],
        ];
        $default = ['glowing orb', 'paper plane', 'magnifying glass', 'compass rose', 'lightbulb doodle', 'hourglass'];
        $animations = ['float', 'drift', 'pulse', 'orbit'];

        foreach ($items as $i => &$item) {
            if (!empty($item['props']) || ($item['treatment'] ?? '') === 'zoom_nest') {
                continue;
            }
            $mood = $context[$item['scene_id']]['mood'] ?? 'neutral';
            $pool = $pools[$mood] ?? $default;
            $sx = $this->seeded($i, 8) > 0.5;
            $sy = $this->seeded($i, 9) > 0.5;
            $item['props'] = [[
                'prompt' => $pool[($i * 7 + 3) % count($pool)],
                'animation' => $animations[$i % count($animations)],
                'x' => $sx ? round(1.02 + $this->seeded($i, 8) * 0.12, 3) : round(-0.14 + $this->seeded($i, 8) * 0.12, 3),
                'y' => $sy ? round(0.72 + $this->seeded($i, 9) * 0.24, 3) : round(0.04 + $this->seeded($i, 9) * 0.24, 3),
                'size' => round(0.11 + $this->seeded($i, 10) * 0.08, 3),
            ]];
        }
        unset($item);

        return $items;
    }

    /**
     * Geometry repair. The director LLM's classic failure is a degenerate
     * layout — every region stacked in one straight column/row with uniform
     * spacing (project-killing: the camera repeats the identical move N
     * times). Detect it and re-lay the top-level regions out along the chosen
     * journey pattern (keeping every creative choice: sizes, treatments,
     * props, relations). Also bends any 3+ hops flying the same direction and
     * seats "contrast" scenes beside their counterpart.
     */
    private function repairGeometry(array $items, string $pattern, array $base, float $minGap): array
    {
        $top = [];
        foreach ($items as $i => $item) {
            if (($item['treatment'] ?? '') !== 'zoom_nest') {
                $top[] = $i;
            }
        }

        if (count($top) >= 4 && $this->isDegenerate($items, $top)) {
            $this->warn('Canvas: degenerate layout (column/row/monotone) -> re-laid out along journey pattern.');
            $items = $this->patternLayout($items, $top, $pattern, $base);
        }

        // Seat contrast scenes beside the scene they oppose (same scale, one
        // clean side-by-side read), unless the director already put them close.
        for ($k = 1; $k < count($top); $k++) {
            $i = $top[$k];
            $p = $top[$k - 1];
            if (($items[$i]['relation'] ?? '') !== 'contrast') {
                continue;
            }
            $dist = hypot($items[$i]['x'] - $items[$p]['x'], $items[$i]['y'] - $items[$p]['y']);
            $near = ($items[$p]['w'] + $items[$i]['w']) / 2 + $minGap * 2;
            if ($dist > $near * 1.6) {
                $side = $this->seeded($i, 5) > 0.5 ? 1 : -1;
                $items[$i]['x'] = round($items[$p]['x'] + $side * $near, 1);
                $items[$i]['y'] = round($items[$p]['y'] + ($this->seeded($i, 6) - 0.5) * 0.5 * $items[$p]['h'], 1);
            }
        }

        // Bend over-straight paths: three consecutive hops in the same compass
        // direction get the third endpoint deflected sideways.
        for ($k = 3; $k < count($top); $k++) {
            $a = $items[$top[$k - 3]];
            $b = $items[$top[$k - 2]];
            $c = $items[$top[$k - 1]];
            $d = $items[$top[$k]];
            $ang1 = atan2($b['y'] - $a['y'], $b['x'] - $a['x']);
            $ang2 = atan2($c['y'] - $b['y'], $c['x'] - $b['x']);
            $ang3 = atan2($d['y'] - $c['y'], $d['x'] - $c['x']);
            if ($this->angleSpread([$ang1, $ang2, $ang3]) < 0.55) {
                $side = ($k % 2 === 0) ? 1 : -1;
                // Perpendicular of the running direction.
                $items[$top[$k]]['x'] = round($d['x'] - sin($ang3) * $side * $d['h'] * 1.4, 1);
                $items[$top[$k]]['y'] = round($d['y'] + cos($ang3) * $side * $d['h'] * 1.4, 1);
            }
        }

        return $items;
    }

    /** True when top-level regions form a column, row or one monotone line. */
    private function isDegenerate(array $items, array $top): bool
    {
        $xs = array_map(fn ($i) => (float) $items[$i]['x'], $top);
        $ys = array_map(fn ($i) => (float) $items[$i]['y'], $top);
        $meanW = array_sum(array_map(fn ($i) => (float) $items[$i]['w'], $top)) / count($top);
        $meanH = array_sum(array_map(fn ($i) => (float) $items[$i]['h'], $top)) / count($top);

        if ($this->stddev($xs) < 0.5 * $meanW || $this->stddev($ys) < 0.5 * $meanH) {
            return true; // column / row
        }

        // Mean resultant length of hop directions: ~1 means every hop flies
        // the same way (a straight diagonal is as boring as a column).
        $sx = 0.0;
        $sy = 0.0;
        $n = 0;
        for ($k = 1; $k < count($top); $k++) {
            $dx = $items[$top[$k]]['x'] - $items[$top[$k - 1]]['x'];
            $dy = $items[$top[$k]]['y'] - $items[$top[$k - 1]]['y'];
            $len = max(1.0, hypot($dx, $dy));
            $sx += $dx / $len;
            $sy += $dy / $len;
            $n++;
        }

        return $n > 0 && hypot($sx, $sy) / $n > 0.86;
    }

    /**
     * Re-lay top-level regions along the journey pattern, preserving order,
     * sizes and all creative fields. Hop distance stretches for chapter
     * breaks and tightens for contrasts, with seeded jitter so no two videos
     * share a silhouette.
     */
    private function patternLayout(array $items, array $top, string $pattern, array $base): array
    {
        $w = (float) $base['w'];
        $h = (float) $base['h'];
        $n = count($top);

        $distFactor = function (int $k) use ($items, $top): float {
            $rel = $items[$top[$k]]['relation'] ?? 'continues';
            if ($rel === 'new_chapter') {
                return 1.8;
            }
            if ($rel === 'contrast') {
                return 1.0;
            }
            return 1.0 + $this->seeded($k, 3) * 0.45;
        };

        $positions = [];
        if ($pattern === 'arc' && $n >= 4) {
            // A sweeping circular arc: direction rotates continuously.
            $span = deg2rad(200);
            $step = $span / max(1, $n - 1);
            $radius = (2.6 * $w) / max(0.08, 2 * sin($step / 2));
            $start = -M_PI / 2 - $span / 2;
            $angle = $start;
            for ($k = 0; $k < $n; $k++) {
                if ($k > 0) {
                    $angle += $step * $distFactor($k);
                }
                $positions[] = [
                    cos($angle) * $radius + ($this->seeded($k, 1) - 0.5) * 0.3 * $w,
                    sin($angle) * $radius + ($this->seeded($k, 2) - 0.5) * 0.3 * $h,
                ];
            }
        } elseif ($pattern === 'hub' && $n >= 4) {
            // Scene 1 at the hub, the rest orbiting it — flights arc around.
            $positions[] = [0.0, 0.0];
            $angle = -M_PI / 2;
            for ($k = 1; $k < $n; $k++) {
                $radius = ($k % 2 === 0 ? 4.1 : 3.2) * $w * (0.9 + $this->seeded($k, 1) * 0.25);
                $positions[] = [cos($angle) * $radius, sin($angle) * $radius];
                $angle += deg2rad(58 + $this->seeded($k, 2) * 24);
            }
        } elseif ($pattern === 'timeline') {
            // Forward march with a tall sine wave — reads left-to-right but
            // never straight.
            $x = 0.0;
            for ($k = 0; $k < $n; $k++) {
                if ($k > 0) {
                    $x += 2.3 * $w * $distFactor($k);
                }
                $positions[] = [
                    $x,
                    sin($k * 1.1) * 1.3 * $h + ($this->seeded($k, 2) - 0.5) * 0.35 * $h,
                ];
            }
        } else {
            // zigzag (default): diagonal lanes that flip direction every few
            // scenes so the camera crosses its own path.
            $x = 0.0;
            $y = 0.0;
            $dir = 1;
            for ($k = 0; $k < $n; $k++) {
                if ($k > 0) {
                    if ($k % 4 === 0) {
                        $dir *= -1; // new lane
                        $y += 2.8 * $h;
                        $x += 0.5 * $w * $dir;
                    } else {
                        $x += 2.2 * $w * $distFactor($k) * $dir;
                        $y += (($k % 2 === 0) ? -0.85 : 0.85) * $h;
                    }
                }
                $positions[] = [
                    $x + ($this->seeded($k, 1) - 0.5) * 0.25 * $w,
                    $y + ($this->seeded($k, 2) - 0.5) * 0.25 * $h,
                ];
            }
        }

        foreach ($top as $k => $i) {
            $items[$i]['x'] = round($positions[$k][0], 1);
            $items[$i]['y'] = round($positions[$k][1], 1);
        }

        return $items;
    }

    /** Spread (max pairwise absolute difference) of a set of angles, radians. */
    private function angleSpread(array $angles): float
    {
        $spread = 0.0;
        for ($a = 0; $a < count($angles); $a++) {
            for ($b = $a + 1; $b < count($angles); $b++) {
                $d = abs($angles[$a] - $angles[$b]);
                $d = min($d, 2 * M_PI - $d);
                $spread = max($spread, $d);
            }
        }
        return $spread;
    }

    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return sqrt($sum / $n);
    }

    /**
     * One connector per hop, styled by the STORY relation of the scene being
     * entered: consequences draw a bold arrow the camera rides, continues
     * leaves a dotted breadcrumb, dives/chapter breaks draw nothing, and
     * callbacks run their line from the scene being called back to.
     */
    private function buildConnectors(array $items, array $sceneIds, array $labelByPair): array
    {
        $styleByRelation = [
            'continues' => 'dotted',
            'elaborates' => 'none',
            'consequence' => 'arrow',
            'contrast' => 'dotted',
            'callback' => 'dotted',
            'new_chapter' => 'none',
        ];
        $maxArrows = ExplainerRegistry::maxArrows();
        $arrows = 0;

        $connectors = [];
        for ($i = 0; $i < count($sceneIds) - 1; $i++) {
            $to = $items[$i + 1];
            $relation = $to['relation'] ?? 'continues';
            $fromId = $relation === 'callback' && !empty($to['callback_to'])
                ? $to['callback_to']
                : $sceneIds[$i];

            $style = $styleByRelation[$relation] ?? 'dotted';
            if (in_array($to['treatment'] ?? '', ['zoom_nest', 'pull_reveal'], true)) {
                $style = 'none'; // the camera move IS the connection
            }
            if ($style === 'arrow' && ++$arrows > $maxArrows) {
                $style = 'dotted';
            }

            $label = $labelByPair[$fromId . '->' . $sceneIds[$i + 1]]
                ?? $labelByPair[$sceneIds[$i] . '->' . $sceneIds[$i + 1]]
                ?? '';

            $connectors[] = [
                'from' => $fromId,
                'to' => $sceneIds[$i + 1],
                'style' => $style,
                'label' => $label,
                'relation' => $relation,
            ];
        }

        return $connectors;
    }

    /**
     * Deterministic zigzag layout — the guaranteed-legal plan used when the
     * director fails entirely. Mirrors autoLayout() on the Remotion side.
     *
     * @param string[] $sceneIds
     */
    public function fallbackPlan(array $sceneIds, string $aspectRatio): array
    {
        $base = ExplainerRegistry::canvasBaseCard($aspectRatio);
        $items = [];
        foreach (array_values($sceneIds) as $i => $sid) {
            $items[] = $this->autoPlacedItem($sid, $i, $items, $base, 160);
        }

        // Even the deterministic fallback gets story rhythm: every 5th hop
        // becomes a wide reveal so the journey breathes.
        foreach ($items as $i => &$item) {
            if ($i > 0 && $i % 5 === 4) {
                $item['treatment'] = 'pull_reveal';
                $item['relation'] = 'new_chapter';
            }
        }
        unset($item);

        $connectors = [];
        for ($i = 0; $i < count($sceneIds) - 1; $i++) {
            $toTreatment = $items[$i + 1]['treatment'] ?? 'canvas_hop';
            $connectors[] = [
                'from' => $sceneIds[$i],
                'to' => $sceneIds[$i + 1],
                'style' => $toTreatment === 'pull_reveal' ? 'none' : 'dotted',
                'label' => '',
                'relation' => $items[$i + 1]['relation'] ?? 'continues',
            ];
        }

        return [
            'version' => 2,
            'journey_pattern' => 'zigzag',
            'world' => $this->fitWorld($items),
            'items' => $items,
            'connectors' => $connectors,
        ];
    }

    /**
     * Place scene index $i on a deterministic zigzag path: regions step right
     * with an alternating vertical swing and a small seeded jitter so even the
     * fallback reads as a designed journey rather than a grid.
     */
    private function autoPlacedItem(string $sceneId, int $i, array $placed, array $base, float $minGap): array
    {
        $w = (float) $base['w'];
        $h = (float) $base['h'];

        $jx = $this->seeded($i, 1) * 0.12 - 0.06; // -6%..6%
        $jy = $this->seeded($i, 2) * 0.12 - 0.06;
        $swing = ($i % 2 === 0) ? -0.85 : 0.85;   // alternate above/below the lane

        // Wide steps: every scene lives alone in its own stretch of space
        // (the isolation look) with real travel between stops.
        $item = [
            'scene_id' => $sceneId,
            'treatment' => $i === 0 ? 'hero_open' : 'canvas_hop',
            'relation' => $i === 0 ? 'opening' : 'continues',
            'callback_to' => null,
            'x' => round($i * $w * 2.2 + $jx * $w, 1),
            'y' => round($swing * $h + $jy * $h, 1),
            'w' => $w,
            'h' => $h,
            'rotation' => 0,
            'emphasis' => $i === 0 ? 'hero' : 'normal',
            'hold_move' => ['breathe', 'push_in', 'drift', 'orbit', 'rise', 'sway', 'settle_back'][$i % 7],
            'props' => [],
            'depth' => 0,
            'parent_id' => null,
        ];

        // Nudge until clear of already placed regions.
        foreach ($placed as $other) {
            while ($this->overlaps($item, $other, $minGap)) {
                $item['x'] += $w * 0.35;
                $item['y'] += $h * 0.2;
            }
        }

        return $item;
    }

    /**
     * Clamp AI-requested decorative props: enum animation, bounded scatter
     * position (may sit slightly outside the region for a collage feel),
     * bounded size, capped count.
     */
    private function normalizeProps($props): array
    {
        $max = ExplainerRegistry::maxPropsPerScene();
        // The cap used to be enforced only AFTER a prop was appended, so a cap
        // of 0 still let exactly one through. The flat design ships with 0.
        if (!is_array($props) || $max < 1) {
            return [];
        }

        $animations = ExplainerRegistry::propAnimations();
        $clean = [];
        foreach ($props as $prop) {
            if (count($clean) >= $max) {
                break;
            }
            if (!is_array($prop)) {
                continue;
            }
            $prompt = trim((string) ($prop['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $animation = (string) ($prop['animation'] ?? '');
            if (!in_array($animation, $animations, true)) {
                $animation = ExplainerRegistry::defaultPropAnimation();
            }
            $clean[] = [
                'prompt' => mb_substr($prompt, 0, 120),
                'animation' => $animation,
                'x' => round($this->clampf((float) ($prop['x'] ?? 0.9), -0.18, 1.18), 3),
                'y' => round($this->clampf((float) ($prop['y'] ?? 0.1), -0.18, 1.18), 3),
                'size' => round($this->clampf((float) ($prop['size'] ?? 0.16), 0.08, 0.3), 3),
            ];
        }

        return $clean;
    }

    /**
     * Anchor every zoom_nest region inside its parent (the previous scene) at
     * the requested focal point, sized by the registry nest scale. Runs after
     * push-apart so children track their parent's FINAL position. Chained
     * nests work because parents always come earlier in the list.
     */
    private function applyNesting(array $items): array
    {
        $ns = ExplainerRegistry::nestScale();

        for ($i = 1; $i < count($items); $i++) {
            if (($items[$i]['treatment'] ?? '') !== 'zoom_nest') {
                continue;
            }

            $parent = $items[$i - 1];
            $nest = $items[$i]['nest'] ?? ['fx' => 0.5, 'fy' => 0.5];

            $w = $parent['w'] * $ns;
            $h = $parent['h'] * $ns;

            // Keep the child fully inside the parent with a small margin.
            $fx = $this->clampf((float) $nest['fx'], $ns / 2 + 0.05, 1 - $ns / 2 - 0.05);
            $fy = $this->clampf((float) $nest['fy'], $ns / 2 + 0.05, 1 - $ns / 2 - 0.05);

            $items[$i]['x'] = round($parent['x'] - $parent['w'] / 2 + $fx * $parent['w'], 1);
            $items[$i]['y'] = round($parent['y'] - $parent['h'] / 2 + $fy * $parent['h'], 1);
            $items[$i]['w'] = round($w, 1);
            $items[$i]['h'] = round($h, 1);
            $items[$i]['depth'] = ($parent['depth'] ?? 0) + 1;
            $items[$i]['parent_id'] = $parent['scene_id'];
            unset($items[$i]['nest']);
        }

        return $items;
    }

    /**
     * Push-apart pass: separates any pair of intersecting regions along the
     * vector between their centers. A few sweeps are plenty for ≤ 15 regions.
     */
    private function resolveOverlaps(array $items, float $minGap, bool $onlyTopLevel = false): array
    {
        $movable = fn (array $it): bool => !$onlyTopLevel || ($it['treatment'] ?? '') !== 'zoom_nest';

        $moved = false;
        for ($sweep = 0; $sweep < 6; $sweep++) {
            $moved = false;
            for ($a = 0; $a < count($items); $a++) {
                if (!$movable($items[$a])) {
                    continue;
                }
                for ($b = $a + 1; $b < count($items); $b++) {
                    if (!$movable($items[$b])) {
                        continue;
                    }
                    if (!$this->overlaps($items[$a], $items[$b], $minGap)) {
                        continue;
                    }
                    $dx = $items[$b]['x'] - $items[$a]['x'];
                    $dy = $items[$b]['y'] - $items[$a]['y'];
                    $len = max(1.0, sqrt($dx * $dx + $dy * $dy));
                    // Required separation along each axis, take the shortfall.
                    $needX = ($items[$a]['w'] + $items[$b]['w']) / 2 + $minGap - abs($dx);
                    $needY = ($items[$a]['h'] + $items[$b]['h']) / 2 + $minGap - abs($dy);
                    $push = max(60.0, min($needX, $needY) / 2 + 20);
                    $items[$b]['x'] = round($items[$b]['x'] + ($dx / $len) * $push, 1);
                    $items[$b]['y'] = round($items[$b]['y'] + ($dy / $len) * $push, 1);
                    $moved = true;
                }
            }
            if (!$moved) {
                break;
            }
        }

        if ($moved) {
            $this->warn('Canvas: overlap resolution hit sweep limit (dense layout).');
        }

        return $items;
    }

    private function overlaps(array $a, array $b, float $gap): bool
    {
        return abs($a['x'] - $b['x']) < ($a['w'] + $b['w']) / 2 + $gap
            && abs($a['y'] - $b['y']) < ($a['h'] + $b['h']) / 2 + $gap;
    }

    /**
     * World = bounding box of all regions plus a generous margin, normalised
     * so the top-left region sits at positive coordinates (shifts every item
     * uniformly, which preserves nesting).
     */
    private function fitWorld(array &$items): array
    {
        if (empty($items)) {
            return ['width' => 4000, 'height' => 3000];
        }

        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($items as $item) {
            $minX = min($minX, $item['x'] - $item['w'] / 2);
            $maxX = max($maxX, $item['x'] + $item['w'] / 2);
            $minY = min($minY, $item['y'] - $item['h'] / 2);
            $maxY = max($maxY, $item['y'] + $item['h'] / 2);
        }

        $margin = 900.0;
        foreach ($items as &$item) {
            $item['x'] = round($item['x'] - $minX + $margin, 1);
            $item['y'] = round($item['y'] - $minY + $margin, 1);
        }
        unset($item);

        return [
            'width' => (int) ceil($maxX - $minX + 2 * $margin),
            'height' => (int) ceil($maxY - $minY + 2 * $margin),
        ];
    }

    private function seeded(int $i, int $salt): float
    {
        // Deterministic pseudo-random in [0,1) — same on every run/machine.
        $v = fmod(sin($i * 127.1 + $salt * 311.7) * 43758.5453, 1.0);
        return $v < 0 ? $v + 1 : $v;
    }

    private function clampf(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
