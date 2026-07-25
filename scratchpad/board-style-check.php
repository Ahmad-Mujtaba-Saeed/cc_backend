<?php

/**
 * board-style-check — registry, render resolution and route for the
 * math-board style picker (loop iter 13).
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/board-style-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

// 1. Registry exposes the three fixed styles, in order, with UI metadata.
$styles = ExplainerRegistry::boardStyles();
check('boardStyleNames = slate,chalk,notebook', ExplainerRegistry::boardStyleNames() === ['slate', 'chalk', 'notebook']);
check('every style has label + use_when', collect($styles)->every(
    fn ($s) => is_string($s['label'] ?? null) && is_string($s['use_when'] ?? null)
));

// 2. Render-time resolution (mirrors RemotionRenderService): explicit wins,
//    'auto'/junk falls to board_style_auto, empty corner lands on slate.
$resolve = fn (array $settings) => in_array($settings['board_style'] ?? null, ExplainerRegistry::boardStyleNames(), true)
    ? $settings['board_style']
    : (string) ($settings['board_style_auto'] ?? 'slate');
check('explicit chalk beats auto notebook', $resolve(['board_style' => 'chalk', 'board_style_auto' => 'notebook']) === 'chalk');
check("stored 'auto' falls to topic pick", $resolve(['board_style' => 'auto', 'board_style_auto' => 'notebook']) === 'notebook');
check('nothing stored → slate', $resolve([]) === 'slate');
check('junk explicit → topic pick', $resolve(['board_style' => 'neon', 'board_style_auto' => 'chalk']) === 'chalk');

// 3. The endpoint is routed.
$routed = collect(app('router')->getRoutes())->contains(
    fn ($r) => str_contains($r->uri(), 'explainer/projects/{project}/board-style')
        && in_array('POST', $r->methods(), true)
);
check('POST board-style route registered', $routed);

// 4. Registry bumped for the new block.
check('registry version >= 25', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 25);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
