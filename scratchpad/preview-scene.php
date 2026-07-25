<?php
/**
 * preview-scene — freeze one scene's real composition frame to a PNG via the
 * shared preview pipeline (same assembler + payload the MP4 render uses), so a
 * renderer change can be eyeballed without a full render. Clears the look-hash
 * cache first so a code-only change (which the fingerprint doesn't see) still
 * produces a fresh frame.
 *
 *   docker exec viralforgebackend-app-1 php scratchpad/preview-scene.php 133 scene_1
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Services\ExplainerPreviewService;

$projectId = (int) ($argv[1] ?? 0);
$sceneId = (string) ($argv[2] ?? '');
$project = Project::find($projectId);
if (!$project) { fwrite(STDERR, "no project {$projectId}\n"); exit(1); }

// Bust the frozen-preview cache: the fingerprint keys on LOOK settings, which a
// renderer code change does not touch, so old PNGs would be served.
foreach (Storage::disk('public')->files("explainer/{$projectId}/previews") as $f) {
    Storage::disk('public')->delete($f);
}

$res = (new ExplainerPreviewService())->preview($project, $sceneId ?: null);
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (($res['success'] ?? false) && !empty($res['path'])) {
    echo 'ABS: ' . Storage::disk('public')->path($res['path']) . "\n";
}
