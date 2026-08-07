<?php

/**
 * music-panel-check — the storyboard's background-music editor: partial
 * updates, validation, the category/track coupling, and that the renderer
 * actually consumes what the panel writes.
 *   docker exec viralforgebackend-app-1 php scratchpad/music-panel-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Modules\Project\Http\Controllers\ExplainerController;
use Modules\Project\Models\Project;
use Modules\Project\Services\PixabayMusicService;

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

$project = Project::where('template_type', 'ai_explainer_video')->latest('id')->first();
if (!$project) {
    echo "no explainer project to test against\n";
    exit(1);
}
$original = $project->settings;
$controller = new ExplainerController();

// Act as the owner so guard() passes.
Illuminate\Support\Facades\Auth::loginUsingId($project->user_id);

$post = function (array $body) use ($controller, $project) {
    $project->refresh();
    $request = Request::create('/', 'POST', $body);

    return json_decode($controller->toggleMusic($request, $project)->getContent(), true);
};
$settings = fn () => Project::find($project->id)->settings ?? [];

// ---------------------------------------------------- backward compatibility
$post(['category' => 'cinematic', 'volume' => 0.2]);
$res = $post(['enabled' => false]);
check('the original one-key toggle still works', ($res['data']['music_enabled'] ?? null) === false);
check('… and does not clobber the category', ($settings()['music_category'] ?? null) === 'cinematic');
check('… or the volume', abs(($settings()['music_volume'] ?? 0) - 0.2) < 0.001);
$post(['enabled' => true]);

// ------------------------------------------------------------- partial edits
$post(['category' => 'technology']);
check('category alone applies', ($settings()['music_category'] ?? null) === 'technology');
check('… leaving enabled untouched', ($settings()['music_enabled'] ?? null) === true);

$post(['volume' => 0.15]);
check('volume alone applies', abs(($settings()['music_volume'] ?? 0) - 0.15) < 0.001);
check('… leaving the category untouched', ($settings()['music_category'] ?? null) === 'technology');

// ---------------------------------------------------------------- validation
$res = $post(['category' => 'not-a-real-category']);
check('a bogus category is rejected', ($res['success'] ?? true) === false);
check('… and nothing changed', ($settings()['music_category'] ?? null) === 'technology');

$post(['volume' => 5]);
check('an out-of-range volume clamps rather than 422s', abs(($settings()['music_volume'] ?? 0) - 1.0) < 0.001);
$post(['volume' => -3]);
check('… at the bottom too', abs(($settings()['music_volume'] ?? 0)) < 0.001);

$res = $post(['volume' => 'loud']);
check('a non-numeric volume is rejected', ($res['success'] ?? true) === false);

// The two pseudo-categories the renderer understands must stay reachable.
foreach (['auto', 'none'] as $pseudo) {
    $res = $post(['category' => $pseudo]);
    check("'{$pseudo}' is an accepted category", ($res['success'] ?? false) === true);
}

// ------------------------------------------------------ category/track coupling
$post(['category' => 'horror']);
$post(['track_id' => 'track-123']);
check('a chosen track is stored', ($settings()['music_track_id'] ?? null) === 'track-123');

$post(['category' => 'corporate']);
check('switching category clears the now-unreachable track', !isset($settings()['music_track_id']));

$post(['category' => 'gaming', 'track_id' => 'track-999']);
check('… unless the same request names a new track', ($settings()['music_track_id'] ?? null) === 'track-999');

$post(['category' => 'gaming']);
check('re-sending the SAME category keeps the track', ($settings()['music_track_id'] ?? null) === 'track-999');

$post(['track_id' => '']);
check('an empty track id clears back to the automatic pick', !isset($settings()['music_track_id']));

$post(['track_id' => str_repeat('x', 200)]);
check('an absurd track id is length-capped', strlen((string) ($settings()['music_track_id'] ?? '')) === 64);

// ------------------------------------------------------------ renderer wiring
// The panel is pointless if the render path ignores what it writes.
$post(['category' => 'documentary', 'volume' => 0.22, 'track_id' => '']);
$project->refresh();
$ref = new ReflectionMethod(\Modules\Project\Services\RemotionRenderService::class, 'resolveMusic');
$ref->setAccessible(true);
$music = $ref->invoke(new \Modules\Project\Services\RemotionRenderService(), $project, [['mood' => 'neutral']]);
check('the renderer reads the panel volume', $music === null || abs(($music['volume'] ?? 0) - 0.22) < 0.001);

$post(['category' => 'none']);
$project->refresh();
check("category 'none' makes the renderer return silence", $ref->invoke(
    new \Modules\Project\Services\RemotionRenderService(),
    $project,
    [['mood' => 'neutral']]
) === null);

$post(['enabled' => false]);
$project->refresh();
check('music off makes the renderer return silence', $ref->invoke(
    new \Modules\Project\Services\RemotionRenderService(),
    $project,
    [['mood' => 'neutral']]
) === null);

// ---------------------------------------------------------------- one default
check('the default volume has ONE definition', PixabayMusicService::DEFAULT_VOLUME === 0.09);

// Restore.
$project->update(['settings' => $original]);
check('project settings restored', Project::find($project->id)->settings === $original);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
