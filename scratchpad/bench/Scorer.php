<?php

namespace Bench;

use Modules\Project\Support\CardSuitability;
use Modules\Project\Support\ExplainerRegistry;

/**
 * The bench scorer — pure functions over one composed storyboard.
 *
 * No LLM, no I/O, no framework state: a run is captured once (bench.php) and
 * can be re-scored offline for ever after (bench-report.php). That separation
 * is the point — the rubric will be argued with and edited, and re-running 30
 * scripts through three models every time an opinion changes is not a bench,
 * it is a bill.
 *
 * Seven dimensions, 100 points. The weights say what a bad video actually
 * feels like: the wrong CARD for a beat (25) hurts more than a flat 30 seconds
 * (15), and both hurt more than a lint warning nobody sees (10).
 */
class Scorer
{
    public const WEIGHTS = [
        'structure' => 15,
        'casting' => 25,
        'variety' => 15,
        'media' => 15,
        'pacing' => 15,
        'lint' => 10,
        'flights' => 5,
    ];

    /** Prompt-language a stock search box cannot use. */
    private const PROMPT_WORDS = [
        'cinematic', 'photorealistic', '4k', '8k', 'hyperrealistic', 'dramatic lighting',
        'lone figure', 'silhouetted', 'bokeh', 'depth of field', 'wide angle lens',
        'digital art', 'illustration of', 'render of', 'shot on', 'award winning',
    ];

    /**
     * @param  array $case  a corpus entry
     * @param  array $run   what bench.php captured (see bench.php::runCase)
     * @return array{scores: array<string,float>, total: float, findings: array<int,array>, metrics: array}
     */
    public static function score(array $case, array $run): array
    {
        $rubric = $case['rubric'] ?? [];
        $routeOnly = (bool) ($case['route_only'] ?? false);

        $scenes = $run['scenes'] ?? [];          // validated — what ships
        $raw = $run['raw_scenes'] ?? [];         // composer output — what the model did
        $findings = [];

        $metrics = [
            'validated' => self::measure($scenes),
            'composer' => self::measure($raw),
            'shape' => $run['shape'] ?? null,
            'phases' => count($run['skeleton'] ?? []),
            'math_mode' => (bool) ($run['math_mode'] ?? false),
            'target_seconds' => (int) ($case['target_seconds'] ?? 60),
            'attempts' => (int) ($run['attempts'] ?? 0),
            'composed_by_tree' => (bool) ($run['composed_by_tree'] ?? false),
            'validator_warnings' => count($run['validator_warnings'] ?? []),
        ];

        $scores = [];
        $scores['structure'] = self::scoreStructure($case, $run, $metrics, $findings);
        $scores['casting'] = self::scoreCasting($case, $run, $metrics, $findings, $routeOnly);
        $scores['variety'] = self::scoreVariety($case, $run, $metrics, $findings);
        $scores['media'] = self::scoreMedia($case, $run, $metrics, $findings, $routeOnly);
        $scores['pacing'] = self::scorePacing($case, $run, $metrics, $findings);
        $scores['lint'] = self::scoreLint($case, $run, $metrics, $findings);
        $scores['flights'] = self::scoreFlights($case, $run, $metrics, $findings);

        $total = 0.0;
        foreach ($scores as $dim => $frac) {
            $total += $frac * self::WEIGHTS[$dim];
        }

        return [
            'scores' => $scores,
            'total' => round($total, 1),
            'findings' => $findings,
            'metrics' => $metrics,
        ];
    }

    // ---------------------------------------------------------- dimensions --

    private static function scoreStructure(array $case, array $run, array $m, array &$findings): float
    {
        $rubric = $case['rubric'];
        $pts = 0.0;

        $shape = (string) ($run['shape'] ?? '');
        if (in_array($shape, (array) $rubric['shape'], true)) {
            $pts += 0.5;
        } else {
            $findings[] = self::f('warn', 'shape', sprintf(
                'planner picked "%s"; the script supports %s',
                $shape ?: 'nothing',
                implode(' or ', (array) $rubric['shape'])
            ));
        }

        [$lo, $hi] = $rubric['scenes'];
        $n = $m['validated']['count'];
        if ($n >= $lo && $n <= $hi) {
            $pts += 0.3;
        } else {
            $findings[] = self::f($n < $lo ? 'error' : 'warn', 'scene_count',
                "{$n} scenes; the script wants {$lo}-{$hi}");
        }

        // A dropped phase is a chunk of script that never appears on screen.
        $phases = count($run['skeleton'] ?? []);
        $composed = $m['composer']['count'];
        if ($phases === 0 || $composed >= $phases) {
            $pts += 0.2;
        } else {
            $findings[] = self::f('error', 'dropped_phase',
                ($phases - $composed) . " of {$phases} planned phases produced no scene");
        }

        return $pts;
    }

    private static function scoreCasting(array $case, array $run, array $m, array &$findings, bool $routeOnly): float
    {
        $rubric = $case['rubric'];
        $cast = $m['validated']['templates'];
        $pts = 0.0;

        // Routing first: a video on the wrong pipeline has already failed the
        // casting question, whatever it cast.
        $wantMath = (bool) ($rubric['math_mode'] ?? false);
        if ($wantMath !== $m['math_mode']) {
            $findings[] = self::f('error', 'routing', sprintf(
                'math_mode=%s; the rubric says %s',
                $m['math_mode'] ? 'true' : 'false',
                $wantMath ? 'true' : 'false'
            ));
        } else {
            $pts += 0.15;
        }

        // must: each group is a beat with a right answer.
        $must = $rubric['must'] ?? [];
        $hitMust = 0;
        foreach ($must as $g) {
            if (array_intersect($g['cards'], $cast) !== []) {
                $hitMust++;
            } else {
                $findings[] = self::f('error', 'missed_beat',
                    'no ' . implode('/', $g['cards']) . ' — ' . $g['why']);
            }
        }
        $pts += $must === [] ? 0.45 : 0.45 * ($hitMust / count($must));

        // should: partial credit, never a failure.
        $should = $rubric['should'] ?? [];
        $hitShould = 0;
        foreach ($should as $g) {
            if (array_intersect($g['cards'], $cast) !== []) {
                $hitShould++;
            } else {
                $findings[] = self::f('info', 'missed_upgrade',
                    'no ' . implode('/', $g['cards']) . ' — ' . $g['why']);
            }
        }
        $pts += $should === [] ? 0.2 : 0.2 * ($hitShould / count($should));

        // never: a card that contradicts the script.
        $bad = 0;
        foreach (($rubric['never'] ?? []) as $tpl => $why) {
            if (in_array($tpl, $cast, true)) {
                $bad++;
                $findings[] = self::f('error', 'wrong_card', "{$tpl} was cast — {$why}");
            }
        }
        $pts += $bad === 0 ? 0.1 : 0.0;

        // Content precondition: the validator's own suitability gate, re-run
        // here so a card that survived it is still reported.
        $unfit = 0;
        foreach (($run['scenes'] ?? []) as $s) {
            $reason = CardSuitability::unfitReason(
                (string) ($s['layout_template'] ?? ''),
                (array) ($s['slots'] ?? [])
            );
            if ($reason !== null) {
                $unfit++;
                $findings[] = self::f('error', 'unfit_card',
                    ($s['scene_id'] ?? '?') . ': ' . $reason);
            }
        }
        $pts += $unfit === 0 ? 0.1 : 0.0;

        return max(0.0, min(1.0, $pts));
    }

    private static function scoreVariety(array $case, array $run, array $m, array &$findings): float
    {
        $v = $m['validated'];
        $c = $m['composer'];
        $n = max(1, $v['count']);
        $pts = 0.0;

        if ($v['max_streak'] <= 2) {
            $pts += 0.35;
        } else {
            $findings[] = self::f($v['max_streak'] >= 4 ? 'error' : 'warn', 'streak',
                "{$v['streak_template']} runs {$v['max_streak']} scenes in a row");
        }

        $singleShare = ($v['counts']['single_focus'] ?? 0) / $n;
        if ($singleShare <= 0.34 + 1e-9) {
            $pts += 0.3;
        } else {
            $findings[] = self::f('warn', 'single_focus',
                sprintf('%d of %d scenes are single_focus (%.0f%%)',
                    $v['counts']['single_focus'] ?? 0, $n, $singleShare * 100));
        }

        if ($v['top_share'] <= 0.5 + 1e-9) {
            $pts += 0.2;
        } else {
            $findings[] = self::f('warn', 'dominant_card',
                sprintf('"%s" carries %.0f%% of the video', $v['top_template'], $v['top_share'] * 100));
        }

        // Per-video caps are a registry guarantee; a breach here means the
        // degrade path did not fire.
        $overCap = [];
        foreach ($v['counts'] as $tpl => $count) {
            $cap = ExplainerRegistry::maxPerVideo($tpl);
            if ($cap !== null && $count > $cap) {
                $overCap[] = "{$tpl} x{$count} (cap {$cap})";
            }
        }
        if ($overCap === []) {
            $pts += 0.15;
        } else {
            $findings[] = self::f('error', 'over_cap', implode(', ', $overCap));
        }

        // Whether the MODEL obeyed, or the validator rescued it. Not scored —
        // reported, because it is the answer to deferred question 2.
        if ($c['count'] > 0 && ($c['max_streak'] > 2 || ($c['counts']['single_focus'] ?? 0) / max(1, $c['count']) > 0.34)) {
            $findings[] = self::f('info', 'model_ignored_variety', sprintf(
                'composer draft: streak %d, single_focus %d/%d — the variety rule is advisory and was not followed',
                $c['max_streak'],
                $c['counts']['single_focus'] ?? 0,
                $c['count']
            ));
        }

        return $pts;
    }

    private static function scoreMedia(array $case, array $run, array $m, array &$findings, bool $routeOnly): float
    {
        $v = $m['validated'];
        $want = (float) ($case['rubric']['media_min'] ?? 0.33);
        $pts = 0.0;

        // Half a scene of tolerance. The composer's own rule is "at least a
        // third", counted in whole scenes (ceil(n/3)), so a rubric asking for
        // 0.34 of a 9-scene video would fail a storyboard that obeyed the
        // system perfectly. The rubric states an editorial intent, not a
        // rounding mode.
        if ($want <= 0.0 || $v['media_scenes'] + 0.5 >= $want * $v['count']) {
            $pts += 0.55;
        } else {
            $findings[] = self::f('warn', 'thin_visuals', sprintf(
                '%d of %d scenes carry a picture (%.0f%%); this script wants %.0f%%',
                $v['media_scenes'], $v['count'], $v['media_share'] * 100, $want * 100
            ));
        }

        // The media brief (iter 51). A slot the user cannot judge is a slot
        // they will not fill.
        $b = $v['brief'];
        if ($b['slots'] === 0) {
            // No media slots at all: the share check above already said so.
            $pts += $want <= 0.0 ? 0.45 : 0.0;

            return $pts;
        }
        $pts += 0.2 * ($b['query_ok'] / $b['slots']);
        $pts += 0.1 * ($b['kind_ok'] / $b['slots']);
        $pts += 0.15 * ($b['guidance_with_avoid'] / $b['slots']);

        if ($b['query_ok'] < $b['slots']) {
            $findings[] = self::f('warn', 'brief_query',
                ($b['slots'] - $b['query_ok']) . " of {$b['slots']} media slots have a missing or prompt-shaped search_query");
        }
        if ($b['guidance_with_avoid'] < $b['slots']) {
            $findings[] = self::f('info', 'brief_guidance', sprintf(
                '%d of %d guidance lines never say what to AVOID (the prompt asks for it)',
                $b['slots'] - $b['guidance_with_avoid'], $b['slots']
            ));
        }
        if ($b['video_share'] > 0.34 + 1e-9) {
            $findings[] = self::f('info', 'brief_video_heavy',
                sprintf('%.0f%% of media slots ask for video; the prompt caps it at a third', $b['video_share'] * 100));
        }

        return min(1.0, $pts);
    }

    private static function scorePacing(array $case, array $run, array $m, array &$findings): float
    {
        $v = $m['validated'];
        $target = (int) ($case['target_seconds'] ?? 60);
        $pts = 0.0;

        if ($v['spread'] >= 3.0) {
            $pts += 0.35;
        } else {
            $findings[] = self::f('warn', 'flat_pacing',
                sprintf('every scene runs %.1f-%.1fs', $v['min_seconds'], $v['max_seconds']));
        }

        $ratio = $target > 0 ? $v['total_seconds'] / $target : 1.0;
        if ($ratio >= 0.8 && $ratio <= 1.2) {
            $pts += 0.4;
        } elseif ($ratio >= 0.65 && $ratio <= 1.4) {
            $pts += 0.2;
            $findings[] = self::f('warn', 'runtime', sprintf(
                '%.0fs against a %ds target (%.0f%%)', $v['total_seconds'], $target, $ratio * 100));
        } else {
            $findings[] = self::f('error', 'runtime', sprintf(
                '%.0fs against a %ds target (%.0f%%)', $v['total_seconds'], $target, $ratio * 100));
        }

        // A beat nobody can read, or one that outstays its welcome.
        if ($v['max_seconds'] <= 16.0 && $v['min_seconds'] >= 2.4) {
            $pts += 0.25;
        } else {
            $findings[] = self::f('warn', 'scene_length',
                sprintf('shortest %.1fs, longest %.1fs', $v['min_seconds'], $v['max_seconds']));
        }

        return $pts;
    }

    private static function scoreLint(array $case, array $run, array $m, array &$findings): float
    {
        $counts = $run['lint']['counts'] ?? ['error' => 0, 'warn' => 0, 'info' => 0];
        $pts = 0.0;
        $pts += ($counts['error'] ?? 0) === 0 ? 0.6 : max(0.0, 0.6 - 0.3 * (int) $counts['error']);
        $pts += ($counts['warn'] ?? 0) <= 2 ? 0.4 : max(0.0, 0.4 - 0.1 * ((int) $counts['warn'] - 2));

        foreach (($run['lint']['items'] ?? []) as $item) {
            if (($item['severity'] ?? '') === 'info') {
                continue;
            }
            $findings[] = self::f($item['severity'], 'lint:' . ($item['code'] ?? '?'),
                trim(($item['scene_id'] ? $item['scene_id'] . ': ' : '') . ($item['message'] ?? '')));
        }

        return max(0.0, min(1.0, $pts));
    }

    private static function scoreFlights(array $case, array $run, array $m, array &$findings): float
    {
        $items = $run['plan_items'] ?? [];
        if ($items === []) {
            return 0.5; // director not run this pass — neither credit nor blame
        }
        $n = count($items);
        $flights = 0;
        $holds = 0;
        foreach ($items as $i => $it) {
            $t = (string) ($it['treatment'] ?? '');
            if ($i > 0 && in_array($t, ['canvas_hop', 'pull_reveal', 'kinetic_break'], true)) {
                $flights++;
            }
            if ($t === 'same_frame') {
                $holds++;
            }
        }
        $budget = ExplainerRegistry::maxFlights($n);
        $pts = 0.0;
        if ($flights <= $budget) {
            $pts += 0.6;
        } else {
            $findings[] = self::f('error', 'flight_budget',
                "{$flights} flights over a budget of {$budget} — applyFlightBudget did not hold");
        }
        if ($n < 5 || $flights >= 1) {
            $pts += 0.4;
        } else {
            $findings[] = self::f('warn', 'no_flight',
                "{$n} scenes and not one flight — the journey never moves");
        }

        return $pts;
    }

    // ------------------------------------------------------------ measuring --

    /**
     * Everything measurable about a list of scenes, in one pass. Used for both
     * the composer's draft and the validated storyboard so the report can say
     * WHO fixed a problem.
     */
    public static function measure(array $scenes): array
    {
        $counts = [];
        $order = [];
        $secs = [];
        $mediaScenes = 0;
        $brief = ['slots' => 0, 'query_ok' => 0, 'kind_ok' => 0, 'guidance_with_avoid' => 0, 'video' => 0];

        foreach ($scenes as $s) {
            $tpl = (string) ($s['layout_template'] ?? '?');
            $counts[$tpl] = ($counts[$tpl] ?? 0) + 1;
            $order[] = $tpl;
            $secs[] = (float) ($s['duration_seconds'] ?? 0);

            $hasMedia = false;
            foreach ((array) ($s['slots'] ?? []) as $slot) {
                if (!is_array($slot) || !in_array((string) ($slot['content_type'] ?? ''), ['image', 'video'], true)) {
                    continue;
                }
                $hasMedia = true;
                $brief['slots']++;
                $req = (array) ($slot['asset_request'] ?? []);
                if (self::queryUsable((string) ($req['search_query'] ?? ''))) {
                    $brief['query_ok']++;
                }
                $kind = (string) ($req['media_kind'] ?? '');
                if (in_array($kind, ['image', 'video', 'either'], true)) {
                    $brief['kind_ok']++;
                }
                if ($kind === 'video') {
                    $brief['video']++;
                }
                if (self::guidanceUsable((string) ($req['guidance'] ?? ''))) {
                    $brief['guidance_with_avoid']++;
                }
            }
            if ($hasMedia) {
                $mediaScenes++;
            }
        }

        $n = count($scenes);
        $maxStreak = 0;
        $streakTpl = '';
        $streak = 0;
        $prev = null;
        foreach ($order as $tpl) {
            $streak = $tpl === $prev ? $streak + 1 : 1;
            if ($streak > $maxStreak) {
                $maxStreak = $streak;
                $streakTpl = $tpl;
            }
            $prev = $tpl;
        }
        arsort($counts);
        $topTpl = $n > 0 ? (string) array_key_first($counts) : '';
        $positive = array_values(array_filter($secs, fn ($v) => $v > 0));

        $brief['video_share'] = $brief['slots'] > 0 ? $brief['video'] / $brief['slots'] : 0.0;

        return [
            'count' => $n,
            'templates' => array_values(array_unique($order)),
            'order' => $order,
            'counts' => $counts,
            'top_template' => $topTpl,
            'top_share' => $n > 0 ? ($counts[$topTpl] ?? 0) / $n : 0.0,
            'max_streak' => $maxStreak,
            'streak_template' => $streakTpl,
            'media_scenes' => $mediaScenes,
            'media_share' => $n > 0 ? $mediaScenes / $n : 0.0,
            'total_seconds' => array_sum($secs),
            'min_seconds' => $positive === [] ? 0.0 : min($positive),
            'max_seconds' => $positive === [] ? 0.0 : max($positive),
            'spread' => $positive === [] ? 0.0 : max($positive) - min($positive),
            'brief' => $brief,
        ];
    }

    /** 2-5 plain nouns, nothing a stock search box would choke on. */
    public static function queryUsable(string $q): bool
    {
        $q = trim(mb_strtolower($q));
        if ($q === '') {
            return false;
        }
        $words = preg_split('/\s+/', $q) ?: [];
        if (count($words) < 2 || count($words) > 5) {
            return false;
        }
        foreach (self::PROMPT_WORDS as $bad) {
            if (str_contains($q, $bad)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A guidance line earns its place only when it tells the user what NOT to
     * pick — "a wide shot of a newsroom" is a description; "…not a posed stock
     * handshake" is a decision they can act on. Deferred question 4.
     */
    public static function guidanceUsable(string $g): bool
    {
        $g = trim(mb_strtolower($g));
        if (str_word_count($g) < 8) {
            return false;
        }
        foreach ([' not ', 'avoid', 'never', 'nothing with', 'no ', "don't", 'do not', 'rather than', 'instead of'] as $marker) {
            if (str_contains($g, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function f(string $severity, string $code, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message];
    }
}
