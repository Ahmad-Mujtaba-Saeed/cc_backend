<?php

/**
 * flow-visual-test — draw ONE flow card's parts with the image model and
 * write a probe spec for the renderer.
 *
 *   docker compose exec app php scratchpad/flow-visual-test.php [out.json]
 *
 * The flow is the one from the reference video the user gave: everyone hits a
 * single overloaded server, a load balancer goes in front, and the load
 * spreads over three. Each part is DRAWN by fal-ai/fast-lightning-sdxl and
 * keyed to an alpha stencil; the words (label, state, caption) are typeset by
 * the renderer, which also paints the stencil in the theme's ink.
 *
 * ~$0.001 per part.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\FlowVisualService;
use Modules\Project\Support\CinematicScene;

$narration = 'Everyone hits the same server, and it is drowning at ninety percent. '
    . 'So you put a load balancer in front. It picks one of three servers for every request, '
    . 'and the load spreads out evenly.';

$parts = [
    ['id' => 'crowd', 'subject' => 'a group of three simple standing person figures side by side',
        'title' => 'Everyone', 'note' => 'every visitor', 'place' => 'left', 'depth' => 'far', 'word' => 'everyone', 'camera' => 'push'],
    ['id' => 'server1', 'subject' => 'a tall server rack cabinet with stacked slots',
        'title' => 'Server 1', 'status' => '90%', 'tone' => 'bad', 'place' => 'top_right', 'depth' => 'mid', 'word' => 'drowning', 'camera' => 'push',
        'then' => [['word' => 'evenly', 'status' => '34%', 'tone' => 'accent']]],
    ['id' => 'balancer', 'subject' => 'a network router box with one input cable and three output cables fanning out',
        'title' => 'Balancer', 'note' => 'picks one', 'place' => 'center', 'depth' => 'near', 'word' => 'balancer', 'camera' => 'angle'],
    ['id' => 'server2', 'subject' => 'a tall server rack cabinet with stacked slots',
        'title' => 'Server 2', 'status' => '5%', 'tone' => 'muted', 'place' => 'right', 'depth' => 'mid', 'word' => 'three', 'camera' => 'rack',
        'then' => [['word' => 'evenly', 'status' => '33%', 'tone' => 'accent']]],
    ['id' => 'server3', 'subject' => 'a tall server rack cabinet with stacked slots',
        'title' => 'Server 3', 'status' => '5%', 'tone' => 'muted', 'place' => 'bottom_right', 'depth' => 'mid', 'word' => 'servers', 'camera' => 'rack',
        'then' => [['word' => 'evenly', 'status' => '33%', 'tone' => 'accent']]],
];

$service = new FlowVisualService();
if (!$service->available()) {
    exit("no fal token configured\n");
}

$elements = [];
foreach ($parts as $i => $part) {
    $t0 = microtime(true);
    $path = $service->draw($part['subject'], 'test-' . $part['id']);
    printf("%-9s %-5s %5.1fs  %s\n", $part['id'], $path ? 'ok' : 'FAIL', microtime(true) - $t0, $path ?? '');
    $elements[] = array_filter([
        'id' => $part['id'],
        'kind' => 'visual',
        'prompt' => $part['subject'],
        'image_path' => $path,
        'title' => $part['title'] ?? null,
        'status' => $part['status'] ?? null,
        'note' => $part['note'] ?? null,
        'tone' => $part['tone'] ?? null,
        'place' => $part['place'],
        'depth' => $part['depth'],
        'word' => $part['word'],
        'camera' => $part['camera'],
        'then' => $part['then'] ?? null,
    ], fn ($v) => $v !== null);
}

$raw = [
    'heading' => 'What a load balancer does',
    'elements' => $elements,
    'links' => [
        ['from' => 'crowd', 'to' => 'balancer', 'flow' => true],
        ['from' => 'balancer', 'to' => 'server1', 'flow' => true],
        ['from' => 'balancer', 'to' => 'server2', 'flow' => true],
        ['from' => 'balancer', 'to' => 'server3', 'flow' => true],
    ],
];

$result = CinematicScene::sanitize($raw, fn () => false, $narration);
echo 'sanitized: ok=' . ($result['ok'] ? 'yes' : 'no') . ', parts=' . count($result['slot']['elements'])
    . ', links=' . count($result['slot']['links'] ?? []) . ', warnings=' . json_encode($result['warnings']) . "\n";

// The probe renders from remotion-render/public, so the stencils are given
// as bundle-relative urls (the render payload does the same job with
// publicUrl for a real render).
foreach ($result['slot']['elements'] as $i => $el) {
    if (!empty($el['image_path'])) {
        $result['slot']['elements'][$i]['image_url'] = 'flow/' . basename($el['image_path']);
    }
}

$out = $argv[1] ?? __DIR__ . '/flow-visual-test.json';
file_put_contents($out, json_encode(
    [['scene_id' => 'flow-balancer', 'narration' => $narration, 'slot' => $result['slot']]],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
));
echo "wrote {$out}\n";
