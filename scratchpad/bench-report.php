<?php

/**
 * bench-report — read one or two run files and say what they prove.
 *
 *   docker compose exec -T app php scratchpad/bench-report.php baseline
 *   docker compose exec -T app php scratchpad/bench-report.php strong baseline   # A vs B
 *   docker compose exec -T app php scratchpad/bench-report.php baseline --full
 *
 * Re-scores from the stored storyboards, so editing a rubric costs nothing:
 * argue with bench/corpus.php, re-run this, and the numbers move without a
 * single LLM call.
 *
 * The bottom half answers the four questions item 5 was created to settle
 * (see EXPLAINER_LOOP_BACKLOG.md, the 2026-08-31 program):
 *   1. how often custom_card is actually cast where it belongs
 *   2. whether the composer's variety rule is obeyed or merely repaired
 *   3. how much camera life is left on a held frame now flights are rare
 *   4. whether the media brief tells the user anything they can act on
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/bench/Scorer.php';

use Bench\Scorer;

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
$flags = array_values(array_filter(array_slice($argv, 1), fn ($a) => str_starts_with($a, '--')));
$full = in_array('--full', $flags, true);
if ($args === []) {
    $files = glob(__DIR__ . '/bench/runs/*.json') ?: [];
    exit("usage: bench-report.php <tag> [<baseline tag>] [--full]\nruns: "
        . implode(', ', array_map(fn ($f) => basename($f, '.json'), $files)) . "\n");
}

$cases = require __DIR__ . '/bench/corpus.php';
$byId = [];
foreach ($cases as $c) {
    $byId[$c['id']] = $c;
}

$run = loadRun($args[0]);
$base = isset($args[1]) ? loadRun($args[1]) : null;

$rescored = rescore($run, $byId);
$baseScored = $base ? rescore($base, $byId) : null;

// ------------------------------------------------------------ the table ----

printf("%s — %d results, model %s%s\n\n",
    $run['tag'], count($rescored),
    $run['explainer_model'] ?? '?',
    $base ? '   vs ' . $base['tag'] . ' (' . ($base['explainer_model'] ?? '?') . ')' : '');

printf("%-22s %6s %5s %5s %5s %5s %5s %5s %5s %s\n",
    'case', 'total', 'strc', 'cast', 'vary', 'medi', 'pace', 'lint', 'flt', $base ? 'delta' : '');
echo str_repeat('-', $base ? 92 : 84) . "\n";

$dims = array_keys(Scorer::WEIGHTS);
$sum = array_fill_keys($dims, 0.0);
$totals = [];
foreach ($rescored as $id => $rows) {
    $avg = averageScores($rows);
    $totals[$id] = $avg['total'];
    foreach ($dims as $d) {
        $sum[$d] += $avg['scores'][$d];
    }
    $delta = '';
    if ($baseScored && isset($baseScored[$id])) {
        $b = averageScores($baseScored[$id]);
        $diff = $avg['total'] - $b['total'];
        $delta = sprintf('%+5.1f', $diff);
    }
    printf("%-22s %6.1f %5.0f %5.0f %5.0f %5.0f %5.0f %5.0f %5.0f %s\n",
        $id . (count($rows) > 1 ? ' x' . count($rows) : ''),
        $avg['total'],
        $avg['scores']['structure'] * 100, $avg['scores']['casting'] * 100,
        $avg['scores']['variety'] * 100, $avg['scores']['media'] * 100,
        $avg['scores']['pacing'] * 100, $avg['scores']['lint'] * 100,
        $avg['scores']['flights'] * 100, $delta);
}
$n = max(1, count($rescored));
echo str_repeat('-', $base ? 92 : 84) . "\n";
printf("%-22s %6.1f %5.0f %5.0f %5.0f %5.0f %5.0f %5.0f %5.0f\n",
    'MEAN', array_sum($totals) / $n,
    $sum['structure'] / $n * 100, $sum['casting'] / $n * 100, $sum['variety'] / $n * 100,
    $sum['media'] / $n * 100, $sum['pacing'] / $n * 100, $sum['lint'] / $n * 100,
    $sum['flights'] / $n * 100);

if ($baseScored) {
    $bt = array_map(fn ($rows) => averageScores($rows)['total'], $baseScored);
    printf("%-22s %6.1f  (baseline mean over %d shared cases)\n", 'MEAN ' . $base['tag'],
        array_sum(array_intersect_key($bt, $totals)) / max(1, count(array_intersect_key($bt, $totals))),
        count(array_intersect_key($bt, $totals)));
}

// ------------------------------------------------- what went wrong, ranked --

echo "\nMOST COMMON DEFECTS\n";
$codes = [];
foreach ($rescored as $id => $rows) {
    foreach ($rows as $r) {
        foreach ($r['score']['findings'] as $f) {
            if ($f['severity'] === 'info') {
                continue;
            }
            $codes[$f['code']]['n'] = ($codes[$f['code']]['n'] ?? 0) + 1;
            $codes[$f['code']]['cases'][$id] = true;
        }
    }
}
uasort($codes, fn ($a, $b) => $b['n'] <=> $a['n']);
foreach (array_slice($codes, 0, 14, true) as $code => $c) {
    printf("  %-22s %3d hits in %2d cases   %s\n", $code, $c['n'], count($c['cases']),
        implode(' ', array_slice(array_keys($c['cases']), 0, 6)));
}

if ($full) {
    echo "\nPER-CASE FINDINGS\n";
    foreach ($rescored as $id => $rows) {
        foreach ($rows as $r) {
            printf("\n  %s  %.1f  [%s]\n", $id, $r['score']['total'],
                implode(' > ', $r['score']['metrics']['validated']['order']));
            foreach ($r['score']['findings'] as $f) {
                printf("      %-5s %-20s %s\n", $f['severity'], $f['code'], $f['message']);
            }
        }
    }
}

// ------------------------------------------------- the four open questions --

echo "\n" . str_repeat('=', 84) . "\nTHE FOUR QUESTIONS ITEM 5 WAS BUILT TO ANSWER\n" . str_repeat('=', 84) . "\n";

// Q1 — custom_card casting rate where the rubric says it belongs.
echo "\n1. custom_card — is casting too rare to rely on?\n";
$q1 = ['want' => 0, 'got' => 0, 'runs' => 0, 'hits' => 0];
$demoShape = ['cast' => 0, 'runs' => 0];
foreach ($rescored as $id => $rows) {
    $case = $byId[$id] ?? null;
    if ($case === null) {
        continue;
    }
    $wantsCustom = false;
    foreach (array_merge($case['rubric']['must'] ?? [], $case['rubric']['should'] ?? []) as $g) {
        if (in_array('custom_card', $g['cards'], true)) {
            $wantsCustom = true;
        }
    }
    foreach ($rows as $r) {
        $cast = in_array('custom_card', $r['score']['metrics']['validated']['templates'], true);
        if ($wantsCustom) {
            $q1['runs']++;
            $q1['hits'] += $cast ? 1 : 0;
        } elseif ($cast) {
            printf("   ! %s cast custom_card where the rubric did not ask for it\n", $id);
        }
        if (($r['score']['metrics']['shape'] ?? '') === 'demo') {
            $demoShape['runs']++;
            $demoShape['cast'] += $cast ? 1 : 0;
        }
    }
}
printf("   cast on %d of %d runs of the scripts that need it (%.0f%%)\n",
    $q1['hits'], $q1['runs'], $q1['runs'] ? $q1['hits'] / $q1['runs'] * 100 : 0);
printf("   on demo-shape scripts (where phone_mockup is usually right): %d of %d runs\n",
    $demoShape['cast'], $demoShape['runs']);

// Q2 — is the variety rule obeyed, or repaired?
echo "\n2. variety — does the model obey the rule, or does the validator carry it?\n";
$q2 = ['runs' => 0, 'model_bad' => 0, 'ship_bad' => 0, 'rescued' => 0];
foreach ($rescored as $rows) {
    foreach ($rows as $r) {
        $c = $r['score']['metrics']['composer'];
        $v = $r['score']['metrics']['validated'];
        if ($c['count'] === 0) {
            continue;
        }
        $q2['runs']++;
        $modelBad = $c['max_streak'] > 2 || ($c['counts']['single_focus'] ?? 0) / max(1, $c['count']) > 0.34;
        $shipBad = $v['max_streak'] > 2 || ($v['counts']['single_focus'] ?? 0) / max(1, $v['count']) > 0.34;
        $q2['model_bad'] += $modelBad ? 1 : 0;
        $q2['ship_bad'] += $shipBad ? 1 : 0;
        $q2['rescued'] += ($modelBad && !$shipBad) ? 1 : 0;
    }
}
printf("   the composer's own draft broke it on %d of %d runs (%.0f%%)\n",
    $q2['model_bad'], $q2['runs'], $q2['runs'] ? $q2['model_bad'] / $q2['runs'] * 100 : 0);
printf("   it survived into the shipped storyboard %d times; the validator rescued %d\n",
    $q2['ship_bad'], $q2['rescued']);

// Q3 — how much camera life is left on a held frame.
echo "\n3. hold moves — with flights rare, how much motion is left?\n";
$q3 = ['scenes' => 0, 'holds' => 0, 'flights' => 0, 'moves' => []];
foreach ($rescored as $rows) {
    foreach ($rows as $r) {
        foreach (($r['plan_items'] ?? []) as $i => $it) {
            $q3['scenes']++;
            $t = (string) ($it['treatment'] ?? '');
            if ($t === 'same_frame') {
                $q3['holds']++;
            }
            if ($i > 0 && in_array($t, ['canvas_hop', 'pull_reveal', 'kinetic_break'], true)) {
                $q3['flights']++;
            }
            $mv = (string) ($it['camera_move'] ?? '');
            if ($mv !== '') {
                $q3['moves'][$mv] = ($q3['moves'][$mv] ?? 0) + 1;
            }
        }
    }
}
if ($q3['scenes'] === 0) {
    echo "   (no director data in this run — re-run without --no-director)\n";
} else {
    printf("   %d scenes: %d hold the frame (%.0f%%), %d fly (%.0f%% of cuts)\n",
        $q3['scenes'], $q3['holds'], $q3['holds'] / $q3['scenes'] * 100,
        $q3['flights'], $q3['flights'] / max(1, $q3['scenes']) * 100);
    arsort($q3['moves']);
    $parts = [];
    foreach (array_slice($q3['moves'], 0, 8, true) as $mv => $n2) {
        $parts[] = "{$mv} x{$n2}";
    }
    echo '   hold/camera moves: ' . ($parts ? implode(', ', $parts) : 'none recorded') . "\n";
    echo "   (the judgement itself needs a video watched end to end; this is the inventory)\n";
}

// Q4 — is the media brief usable?
echo "\n4. the media brief — can a user act on it?\n";
$q4 = ['slots' => 0, 'query' => 0, 'kind' => 0, 'avoid' => 0, 'video' => 0];
foreach ($rescored as $rows) {
    foreach ($rows as $r) {
        $b = $r['score']['metrics']['validated']['brief'];
        $q4['slots'] += $b['slots'];
        $q4['query'] += $b['query_ok'];
        $q4['kind'] += $b['kind_ok'];
        $q4['avoid'] += $b['guidance_with_avoid'];
        $q4['video'] += $b['video'];
    }
}
if ($q4['slots'] === 0) {
    echo "   (no media slots in this run)\n";
} else {
    printf("   %d media slots: %.0f%% have a usable stock search_query, %.0f%% a valid media_kind,\n"
        . "   %.0f%% of guidance lines say what to AVOID, %.0f%% of slots ask for video\n",
        $q4['slots'],
        $q4['query'] / $q4['slots'] * 100,
        $q4['kind'] / $q4['slots'] * 100,
        $q4['avoid'] / $q4['slots'] * 100,
        $q4['video'] / $q4['slots'] * 100);
}

// ------------------------------------------------------------- card usage --

echo "\nCARD USAGE ACROSS THE CORPUS (what the system reaches for, and what it never does)\n";
$usage = [];
$runsCount = 0;
foreach ($rescored as $rows) {
    foreach ($rows as $r) {
        $runsCount++;
        foreach ($r['score']['metrics']['validated']['counts'] as $tpl => $count) {
            $usage[$tpl] = ($usage[$tpl] ?? 0) + $count;
        }
    }
}
arsort($usage);
$line = '';
foreach ($usage as $tpl => $count) {
    $line .= sprintf('%s %d   ', $tpl, $count);
}
echo '  ' . wordwrap($line, 100, "\n  ", false) . "\n";
$never = array_values(array_diff(
    \Modules\Project\Support\ExplainerRegistry::templateNames(),
    array_keys($usage)
));
echo "\n  never cast in this run (" . count($never) . "): " . wordwrap(implode(', ', $never), 96, "\n  ") . "\n";

// ----------------------------------------------------------------- helpers --

function loadRun(string $tag): array
{
    $path = __DIR__ . '/bench/runs/' . $tag . '.json';
    if (!is_file($path)) {
        exit("no run file for '{$tag}'\n");
    }
    $run = json_decode((string) file_get_contents($path), true);
    if (!is_array($run)) {
        exit("run file '{$tag}' is not readable JSON\n");
    }

    return $run;
}

/** Re-score every stored result against the CURRENT rubric, grouped by case. */
function rescore(array $run, array $byId): array
{
    $out = [];
    foreach ($run['cases'] as $result) {
        $id = (string) ($result['id'] ?? '');
        if (isset($result['error']) || !isset($byId[$id])) {
            continue;
        }
        $result['score'] = Scorer::score($byId[$id], $result);
        $out[$id][] = $result;
    }

    return $out;
}

/** Mean of N passes of one case (repeat runs). */
function averageScores(array $rows): array
{
    $dims = array_keys(Scorer::WEIGHTS);
    $scores = array_fill_keys($dims, 0.0);
    $total = 0.0;
    foreach ($rows as $r) {
        foreach ($dims as $d) {
            $scores[$d] += $r['score']['scores'][$d];
        }
        $total += $r['score']['total'];
    }
    $n = max(1, count($rows));
    foreach ($dims as $d) {
        $scores[$d] /= $n;
    }

    return ['scores' => $scores, 'total' => $total / $n];
}
