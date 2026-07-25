<?php

/**
 * preview-check — the live style preview end to end (loop iter 14).
 * Renders a real still for a project, proves the cache hits on a repeat,
 * and proves a look change busts it while a non-look change does not.
 *
 * RUN IT AS www-data — the preview is triggered by a WEB request, and root
 * does not reproduce the web process's permissions. Running this as root is
 * exactly how the "mkdir(): Permission denied" bug shipped: the storage
 * explainer/ tree is root-owned (the queue worker made it) and www-data may
 * only read it, so any PHP-side directory creation there fails in production
 * and passes under docker exec.
 *
 *   docker compose exec -u www-data app php scratchpad/preview-check.php [projectId]
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Services\ExplainerPreviewService;
use Modules\Project\Support\ExplainerSceneAssembler;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

$projectId = (int) ($argv[1] ?? 34);
$project = Project::find($projectId);
if (!$project) {
    echo "Project {$projectId} not found\n";
    exit(1);
}

echo "project {$projectId}: {$project->title}\n";

// 1. The shared assembler produces the scenes the renderer expects.
$autoVisuals = (bool) ($project->settings['auto_visuals'] ?? $project->settings['auto_visuals_auto'] ?? false);
$assembled = ExplainerSceneAssembler::assemble($project, $autoVisuals);
check('assembler returns scenes', count($assembled['scenes']) > 0, count($assembled['scenes']) . ' scenes');
check('scene shape intact', isset($assembled['scenes'][0]['scene_id'], $assembled['scenes'][0]['slots'], $assembled['scenes'][0]['layout_template']));

$svc = new ExplainerPreviewService();
$original = $project->settings ?? [];

// 2. Look hash is stable and reacts to look settings only.
$look1 = ExplainerPreviewService::lookHash($project);
$project->settings = array_merge($original, ['music_enabled' => !($original['music_enabled'] ?? true)]);
check('music toggle does NOT change the look', ExplainerPreviewService::lookHash($project) === $look1);
$project->settings = array_merge($original, ['font_pack' => 'editorial-x']);
check('font pack DOES change the look', ExplainerPreviewService::lookHash($project) !== $look1);
$project->settings = $original;

// 3. A real still render.
$t0 = microtime(true);
$res = $svc->preview($project);
$elapsed = round(microtime(true) - $t0, 1);

check('preview succeeded', ($res['success'] ?? false) === true, $res['error'] ?? '');
if (!($res['success'] ?? false)) {
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

check('still written to disk', Storage::disk('public')->exists($res['path']), $res['path']);
$size = Storage::disk('public')->size($res['path']);
check('still is a real PNG (>5KB)', $size > 5000, "{$size} bytes in {$elapsed}s");
check('reports the previewed scene', !empty($res['scene_id']), (string) ($res['scene_id'] ?? ''));

// 4. Repeat = cache hit (this is what makes flipping styles feel instant).
$t1 = microtime(true);
$again = $svc->preview($project, $res['scene_id']);
$cachedMs = round((microtime(true) - $t1) * 1000);
check('repeat is a cache hit', ($again['cached'] ?? false) === true, "{$cachedMs}ms");
check('cache hit returns the same file', ($again['path'] ?? '') === $res['path']);

// 5. A look change must produce a DIFFERENT fingerprint (new still).
$fp = $svc->fingerprint($project, $res['scene_id']);
$project->settings = array_merge($original, ['color_scheme' => 'definitely-not-the-current-one']);
$fpAfter = $svc->fingerprint($project, $res['scene_id']);
$project->settings = $original;
check('scheme change busts the still cache', $fp !== $fpAfter);

// 6. Frame lands INSIDE the previewed scene's own window, not scene 0.
$sceneIds = array_map(fn ($s) => (string) $s['scene_id'], $assembled['scenes']);
if (count($sceneIds) > 2) {
    $target = $sceneIds[2];
    $third = $svc->preview($project, $target);
    check('honours an explicit scene pick', ($third['scene_id'] ?? '') === $target, $target);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
