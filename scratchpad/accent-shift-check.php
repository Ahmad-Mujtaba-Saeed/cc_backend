<?php

/**
 * accent-shift-check — route, defaults, hue math and look-hash coupling for
 * the per-chapter accent shift (§11.4, finished in loop iter 28: the ±14°
 * server-side computation shipped with the live-preview work but had no
 * endpoint and no UI; it is now ±20° per the original spec, toggleable, and
 * exposed in the storyboard payload).
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/accent-shift-check.php
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

// 1. The endpoint is routed.
$routed = collect(app('router')->getRoutes())->contains(
    fn ($r) => str_contains($r->uri(), 'explainer/projects/{project}/accent-shift')
        && in_array('POST', $r->methods(), true)
);
check('POST accent-shift route registered', $routed);

// 2. Hue math (reflection on the private helper). ±20° on a pure red:
//    +20 lands at HSL(20,100%,50%) = #FF5500, -20 at HSL(340) = #FF0055.
$svc = app(RemotionRenderService::class);
$m = new ReflectionMethod($svc, 'shiftHue');
$m->setAccessible(true);
check('+20 deg on #FF0000 = #FF5500', $m->invoke($svc, '#FF0000', 20) === '#FF5500');
check('-20 deg on #FF0000 = #FF0055', $m->invoke($svc, '#FF0000', -20) === '#FF0055');
check('achromatic grey is untouched', $m->invoke($svc, '#808080', 20) === '#808080');
check('junk hex passes through unharmed', $m->invoke($svc, '#XYZ', 20) === '#XYZ');
check('full rotation returns home', $m->invoke($svc, '#3E8FD8', 360) === '#3E8FD8');

// 3. The payload block: default off, first chapter untouched, ±20 alternating.
//    Wiring assertions against the source — the block needs a real Project +
//    chapter plan to run end-to-end, and the maths above is the risky part.
$src = file_get_contents(__DIR__ . '/../modules/Project/Services/RemotionRenderService.php');
check('accent shift is opt-in (?? false)', str_contains($src, "settings['accent_shift'] ?? false"));
check('first chapter keeps the scheme accent ($k > 0)', str_contains($src, 'if ($k > 0)'));
check('angle is the spec\'s 20, alternating', str_contains($src, '$k % 2 === 1 ? 20 : -20'));

// 4. Controller default is OFF (a bare toggle call without `enabled` must
//    not silently switch the feature on).
$ctl = file_get_contents(__DIR__ . '/../modules/Project/Http/Controllers/ExplainerController.php');
check('toggleAccentShift defaults enabled=false', (bool) preg_match(
    '/function toggleAccentShift.*?input\(\'enabled\', false\)/s',
    $ctl
));
check('storyboard payload exposes accent_shift', str_contains($ctl, "'accent_shift' => (bool) (\$project->settings['accent_shift'] ?? false)"));

// 5. The look hash moves with the toggle (preview refresh + staleness chip).
$project = new \Modules\Project\Models\Project();
$project->aspect_ratio = '16:9';
$project->settings = ['color_scheme' => 'glacier'];
$a = ExplainerPreviewService::lookHash($project);
$project->settings = ['color_scheme' => 'glacier', 'accent_shift' => true];
check('accent-shift toggle busts the look hash', ExplainerPreviewService::lookHash($project) !== $a);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
