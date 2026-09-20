<?php

/**
 * cinematic-examples — the staging prompt's worked examples are what the
 * model learns "good" from, so they must themselves survive the sanitizer and
 * render well. This pulls them out of the LIVE prompt, sanitizes each with its
 * narration, and writes a probe spec for scripts/cinematic-probe.ts --spec.
 *
 *   docker compose exec app php scratchpad/cinematic-examples.php out.json
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\CinematicSceneService;
use Modules\Project\Support\CinematicScene;
use Modules\Project\Support\ExplainerRegistry;

$service = (new ReflectionClass(CinematicSceneService::class))->newInstanceWithoutConstructor();
$prompt = (new ReflectionMethod($service, 'systemPrompt'))->invoke($service, ExplainerRegistry::iconNames(), '16:9');

preg_match_all('/EXAMPLE \d\. Narration: "(.+?)"\r?\n(\{.+?\]\})\r?\n/s', $prompt . "\n", $m, PREG_SET_ORDER);
$icons = array_flip(ExplainerRegistry::iconNames());
$specs = [];
foreach ($m as $k => [$all, $narration, $json]) {
    $raw = json_decode($json, true);
    if (!is_array($raw)) {
        echo "example " . ($k + 1) . ": NOT JSON (" . json_last_error_msg() . ")\n";
        continue;
    }
    $r = CinematicScene::sanitize($raw, fn ($n) => isset($icons[$n]), $narration);
    echo 'example ' . ($k + 1) . ': ok=' . ($r['ok'] ? 'yes' : 'no') . ', parts=' . count($r['slot']['elements']) . ', links=' . count($r['slot']['links'] ?? [])
        . ', warnings=' . json_encode($r['warnings']) . ', weak=' . json_encode(CinematicSceneService::weaknesses($r['slot'])) . "\n";
    $specs[] = ['scene_id' => 'example-' . ($k + 1), 'narration' => $narration, 'slot' => $r['slot']];
}
file_put_contents($argv[1] ?? __DIR__ . '/cinematic-examples.json', json_encode($specs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'wrote ' . count($specs) . " example(s)\n";
