<?php

/**
 * backdrop-check — payload flag, toggle route, look-hash coupling and mood
 * passthrough for the mood backdrop field (loop iter 27). The renderer only
 * obeys an explicit true, so the server-side default (ON unless the setting
 * says false) is the behaviour under test here.
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/backdrop-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\ExplainerPreviewService;
use Modules\Project\Services\RemotionRenderService;

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

// 1. Payload flag: default ON, explicit false wins, junk truthy stays on.
//    (buildRenderPayload needs a Project; the flag logic is the expression
//    under test, asserted against the source so a drive-by rewrite fails
//    loudly here.)
$flag = fn (array $settings): bool => ($settings['backdrop_enabled'] ?? true) !== false;
check('unset → on (new videos get the field)', $flag([]) === true);
check('explicit false → off', $flag(['backdrop_enabled' => false]) === false);
check('explicit true → on', $flag(['backdrop_enabled' => true]) === true);
$src = file_get_contents(__DIR__ . '/../modules/Project/Services/RemotionRenderService.php');
check('buildRenderPayload ships the backdrop block', str_contains($src, "'backdrop' => ["));
check("payload uses the default-on expression", str_contains($src, "(\$settings['backdrop_enabled'] ?? true) !== false"));

// 2. Toggle endpoint routed.
$routed = collect(app('router')->getRoutes())->contains(
    fn ($r) => str_contains($r->uri(), 'explainer/projects/{project}/backdrop')
        && in_array('POST', $r->methods(), true)
);
check('POST backdrop route registered', $routed);

// 3. The look hash must move when the toggle moves (the preview still and
//    the render-staleness chip both key off it).
$project = new \Modules\Project\Models\Project();
$project->aspect_ratio = '16:9';
$project->settings = ['color_scheme' => 'glacier'];
$a = ExplainerPreviewService::lookHash($project);
$project->settings = ['color_scheme' => 'glacier', 'backdrop_enabled' => false];
$b = ExplainerPreviewService::lookHash($project);
check('backdrop toggle busts the look hash', $a !== $b);
$project->settings = ['color_scheme' => 'glacier', 'music_enabled' => false];
check('music toggle still leaves it alone', ExplainerPreviewService::lookHash($project) === $a);

// 4. Scene mood reaches the renderer: resolveSceneAssets passes unknown keys
//    through (the field keys its pattern off scene.mood).
$m = new ReflectionMethod(RemotionRenderService::class, 'resolveSceneAssets');
$m->setAccessible(true);
$out = $m->invoke(app(RemotionRenderService::class), ['scene_id' => 's1', 'mood' => 'dramatic', 'slots' => []]);
check('scene mood survives resolveSceneAssets', ($out['mood'] ?? null) === 'dramatic');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
