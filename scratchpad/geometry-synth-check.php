<?php

/**
 * geometry-synth-check — the Thales bug (project 105), verified end to end.
 *
 * Project 105's geometry scenes came back thin: {"shape":"circle"} and
 * {"shape":"triangle"} with no points/labels/marks, so the renderer drew a
 * lone circle + radius while the narration described an inscribed triangle.
 * This exercises the new geometry synthesis against the REAL LLM using those
 * exact narrations, then pushes the result through the validator to confirm it
 * survives and carries the labels the narration named.
 *
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/geometry-synth-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\MathVisualSynthesisService;
use Modules\Project\Support\ShotListValidator;

$svc = new MathVisualSynthesisService();

// --- 0. The thinness detector, on the exact slots project 105 stored --------
$thinCircle = ['shape' => 'circle', 'content_type' => 'geometry'];
$thinTriangle = ['shape' => 'triangle', 'content_type' => 'geometry'];
$richTriangle = [
    'shape' => 'triangle',
    'points' => [
        ['x' => 0.06, 'y' => 0.5, 'label' => 'A'],
        ['x' => 0.94, 'y' => 0.5, 'label' => 'B'],
        ['x' => 0.28, 'y' => 0.93, 'label' => 'C'],
    ],
    'circumcircle' => true,
];
echo "thinness detector:\n";
echo '  bare circle   thin? ' . var_export($svc->geometryIsThin($thinCircle), true) . " (want true)\n";
echo '  bare triangle thin? ' . var_export($svc->geometryIsThin($thinTriangle), true) . " (want true)\n";
echo '  rich triangle thin? ' . var_export($svc->geometryIsThin($richTriangle), true) . " (want false)\n";
echo '  number_line   thin? ' . var_export($svc->geometryIsThin(['shape' => 'number_line']), true) . " (want false — untouched)\n\n";

// --- 1. Real LLM synthesis from project 105's actual narrations -------------
$scenes = [
    [
        'layout_template' => 'geometry_diagram',
        'narration' => ['text' => 'We start with a circle and mark the endpoints A and B of a diameter and a third point C on the circle. This is the setup for the whole argument.'],
        'slots' => ['slot_geometry' => $thinCircle],
    ],
    [
        'layout_template' => 'geometry_diagram',
        'narration' => ['text' => 'Draw lines AC and BC to form the large triangle ABC. We now see two smaller triangles sharing the centre, each with two radii as equal sides. The angle at C is the one we care about.'],
        'slots' => ['slot_geometry' => $thinTriangle],
    ],
    [
        'layout_template' => 'geometry_diagram',
        'narration' => ['text' => 'Label the base angles of the two small isosceles triangles with a, a for the ones at A and b, b for the ones at B, since radii make each small triangle isosceles.'],
        'slots' => ['slot_geometry' => $thinTriangle],
    ],
];

$enriched = $svc->enrichThinGeometry($scenes, 'Thales theorem angle in a semicircle');

foreach ($enriched as $i => $scene) {
    $slot = $scene['slots']['slot_geometry'] ?? [];
    echo "=== scene " . ($i + 1) . " (rebuilt) ===\n";
    echo json_encode($slot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    // Push the whole scene through the validator to prove it survives.
    $raw = ['scenes' => [[
        'scene_id' => "s{$i}",
        'layout_template' => 'geometry_diagram',
        'duration_seconds' => 9,
        'narration' => $scene['narration'],
        'slots' => $scene['slots'],
    ]]];
    $out = (new ShotListValidator())->validate($raw, ['math_mode' => true, 'hook_enabled' => false, 'outro_enabled' => false]);
    $vslot = $out['scenes'][0]['slots']['slot_geometry'] ?? null;
    $tpl = $out['scenes'][0]['layout_template'] ?? '?';
    $labels = [];
    foreach ((array) ($vslot['points'] ?? []) as $p) {
        if (($p['label'] ?? '') !== '') {
            $labels[] = $p['label'];
        }
    }
    echo "  validated template: {$tpl}\n";
    echo '  survived as geometry_diagram: ' . var_export($tpl === 'geometry_diagram' && is_array($vslot), true) . "\n";
    echo '  vertex labels: ' . (implode(', ', $labels) ?: '(none)') . "\n";
    echo '  info score > 0: ' . var_export($svc->geometryIsThin($vslot ?? []) === false, true) . "\n\n";
}
