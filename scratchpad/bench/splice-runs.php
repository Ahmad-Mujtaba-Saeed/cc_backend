<?php

/**
 * splice-runs — one run file with the newer result for every case.
 *
 *   docker compose exec -T app php scratchpad/bench/splice-runs.php \
 *       baseline after-routing --out=baseline-post
 *
 * Why this exists: after a fix, only the AFFECTED cases are re-run — re-running
 * all thirty to move three numbers is 18 minutes and ~120 calls for nothing.
 * But an A/B against a mixed baseline is dishonest unless the mixing is
 * explicit, so the splice is a real file with its own tag and a `spliced_from`
 * field, and later tags win case by case.
 *
 * A spliced file is a fair comparison target only for the cases the fix did
 * not touch plus the re-run ones — which is the whole corpus, by construction.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
$out = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--out=')) {
        $out = substr($a, 6);
    }
}
if (count($args) < 2 || $out === null) {
    exit("usage: splice-runs.php <base tag> <newer tag> [<newer tag>…] --out=<tag>\n");
}

$dir = __DIR__ . '/runs/';
$merged = null;
$byId = [];
$sources = [];
foreach ($args as $tag) {
    $path = $dir . $tag . '.json';
    if (!is_file($path)) {
        exit("no run file for '{$tag}'\n");
    }
    $run = json_decode((string) file_get_contents($path), true);
    if (!is_array($run['cases'] ?? null)) {
        exit("run '{$tag}' has no cases\n");
    }
    $merged = $merged ?? $run;
    $sources[] = $tag;
    foreach ($run['cases'] as $case) {
        // Later tags win; repeats of the same id inside one tag are all kept.
        $byId[(string) ($case['id'] ?? '')][$tag][] = $case;
    }
}

$cases = [];
foreach ($byId as $id => $perTag) {
    $newest = array_key_last($perTag);
    foreach ($perTag[$newest] as $case) {
        $cases[] = $case;
    }
}

$merged['tag'] = $out;
$merged['cases'] = $cases;
$merged['spliced_from'] = $sources;
$merged['spliced_at'] = date('c');

file_put_contents($dir . $out . '.json', json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

printf("%s: %d cases from %s\n", $out, count($cases), implode(' + ', $sources));
