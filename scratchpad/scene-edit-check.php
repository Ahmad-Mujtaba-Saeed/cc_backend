<?php

/**
 * scene-edit-check — hand-editing a scene's words.
 *
 * The storyboard now lets the user rewrite the spoken line and the words on a
 * text card directly, which is only safe because nothing has been recorded
 * yet: the voiceover is synthesised at RENDER time under a hash of the spoken
 * text. This drives the real controller against a throwaway project and
 * checks the consequences of that — the re-estimated duration, the sanitising,
 * and the two things `storyboard_rev` has to move (the preview still and the
 * "your video is out of date" signal).
 *
 *   docker compose exec app php scratchpad/scene-edit-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Project\Http\Controllers\ExplainerController;
use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Services\ExplainerPreviewService;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

$userId = Project::where('template_type', 'ai_explainer_video')->value('user_id');
if (!$userId) {
    echo "no explainer project to borrow a user from\n";
    exit(1);
}
Auth::loginUsingId($userId);

$project = Project::create([
    'user_id' => $userId,
    'title' => 'Scene edit probe',
    'template_type' => 'ai_explainer_video',
    'aspect_ratio' => '16:9',
    'status' => 'storyboard_ready',
    'progress' => 100,
    'settings' => ['script' => 'x'],
]);

$long = 'Roasting is where the flavour is actually made, because the heat drives the '
    . 'Maillard reaction and caramelises the sugars locked inside the green bean, and that '
    . 'is the whole reason two roasts of the same coffee taste nothing alike.';

ExplainerScene::create([
    'project_id' => $project->id, 'scene_id' => 'scene_1', 'order' => 1, 'duration_seconds' => 12.0,
    'narration' => $long, 'layout_template' => 'single_focus',
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Roasting',
        'bullets' => ['heat makes the flavour'], 'reveal' => 'sequential']],
    'transition' => 'fade', 'mood' => 'neutral',
]);
ExplainerScene::create([
    'project_id' => $project->id, 'scene_id' => 'scene_2', 'order' => 2, 'duration_seconds' => 8.0,
    'narration' => 'Grind size decides how fast the water gets through.', 'layout_template' => 'single_focus',
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Grind',
        'bullets' => ['finer is slower'], 'reveal' => 'sequential']],
    'transition' => 'fade', 'mood' => 'neutral',
]);
// The cached voiceover for scene 2, so we can prove an edit elsewhere leaves it be.
ExplainerAsset::create([
    'project_id' => $project->id, 'scene_id' => 'scene_2', 'slot_key' => '__narration__',
    'type' => 'audio', 'path' => "projects/{$project->id}/explainer/narration_scene_2.wav",
    'original_name' => 'cached-hash',
]);

$controller = new ExplainerController();
$patch = function (string $sceneId, array $body) use ($controller, $project) {
    $request = Request::create('/', 'PATCH', $body);

    return $controller->updateScene($request, $project->fresh(), $sceneId);
};
$sceneOf = fn (string $id) => $project->explainerScenes()->where('scene_id', $id)->first();

echo "\n=== the spoken line is editable ===\n";
$lookBefore = ExplainerPreviewService::lookHash($project->fresh());
$response = $patch('scene_1', ['narration' => 'Roasting is where the flavour is made.']);
check('the edit is accepted', $response->getStatusCode() === 200);
check('the new line is stored', $sceneOf('scene_1')->narration === 'Roasting is where the flavour is made.');
check('the neighbouring scene is untouched',
    $sceneOf('scene_2')->narration === 'Grind size decides how fast the water gets through.');
check('its cached voiceover is untouched too',
    ExplainerAsset::where('project_id', $project->id)->where('scene_id', 'scene_2')->exists());

echo "\n=== timing follows the words ===\n";
check('a shorter line shortens the scene', (float) $sceneOf('scene_1')->duration_seconds < 12.0,
    (string) $sceneOf('scene_1')->duration_seconds);
check('...to what the content actually needs',
    abs((float) $sceneOf('scene_1')->duration_seconds
        - (new ShotListValidator())->paceScene('Roasting is where the flavour is made.', $sceneOf('scene_1')->slots)) < 0.01);
$patch('scene_1', ['narration' => $long]);
check('a longer line lengthens it again', (float) $sceneOf('scene_1')->duration_seconds > 12.0,
    (string) $sceneOf('scene_1')->duration_seconds);

$patch('scene_1', ['narration' => 'Short again.', 'duration_seconds' => 9]);
check('a duration set in the same breath wins over the estimate',
    abs((float) $sceneOf('scene_1')->duration_seconds - 9.0) < 0.01,
    (string) $sceneOf('scene_1')->duration_seconds);

echo "\n=== the render knows it is out of date ===\n";
check('editing the words moves the look hash',
    ExplainerPreviewService::lookHash($project->fresh()) !== $lookBefore);
$afterText = ExplainerPreviewService::lookHash($project->fresh());
$patch('scene_1', ['transition' => 'fade']);
check('...but changing only the cut does not',
    ExplainerPreviewService::lookHash($project->fresh()) === $afterText);

echo "\n=== what a hand-typed line may contain ===\n";
$patch('scene_1', ['narration' => "Line one.\nLine two.\tSpaced   out.\x07"]);
check('newlines, tabs and control characters are flattened',
    $sceneOf('scene_1')->narration === 'Line one. Line two. Spaced out.',
    json_encode($sceneOf('scene_1')->narration));

$patch('scene_1', ['narration' => str_repeat('word ', 500)]);
check('the length is bounded', mb_strlen((string) $sceneOf('scene_1')->narration) <= 1500,
    (string) mb_strlen((string) $sceneOf('scene_1')->narration));

$patch('scene_1', ['narration' => '']);
check('clearing it is allowed — that is a deliberate silent beat',
    (string) $sceneOf('scene_1')->narration === '');

$before = $sceneOf('scene_2')->duration_seconds;
$patch('scene_2', ['narration' => 'Grind size decides how fast the water gets through.']);
check('re-saving the same line changes nothing', (float) $sceneOf('scene_2')->duration_seconds === (float) $before);

echo "\n=== the words ON the card ===\n";
$slotPatch = function (array $body) use ($controller, $project) {
    return $controller->updateSlot(Request::create('/', 'PATCH', $body), $project->fresh(), 'scene_2', 'slot_main');
};
$response = $slotPatch(['heading' => 'Grind size', 'bullets' => ['finer is slower', 'coarser is faster']]);
check('a text card can be rewritten', $response->getStatusCode() === 200);
check('the heading and lines are stored',
    ($sceneOf('scene_2')->slots['slot_main']['heading'] ?? '') === 'Grind size'
    && count($sceneOf('scene_2')->slots['slot_main']['bullets'] ?? []) === 2);

$slotPatch(['bullets' => ['a', 'b', 'c', 'd', 'e', 'f', 'g']]);
check('lines are capped at the five the renderer lays out',
    count($sceneOf('scene_2')->slots['slot_main']['bullets'] ?? []) === 5);

$slotPatch(['bullets' => ['', '   ', 'the only real line']]);
check('blank lines are dropped', $sceneOf('scene_2')->slots['slot_main']['bullets'] === ['the only real line']);

$response = $slotPatch(['heading' => '', 'bullets' => []]);
check('a card cannot be emptied into a blank frame', $response->getStatusCode() === 422);
check('...and the old content survives the refusal',
    ($sceneOf('scene_2')->slots['slot_main']['heading'] ?? '') === 'Grind size');

echo "\n=== a revision in flight locks the scene editor ===\n";
$settings = $project->fresh()->settings;
$settings['revision'] = ['state' => 'running', 'started_at' => now()->toIso8601String()];
$project->update(['settings' => $settings]);
check('editing a line is refused mid-revision', $patch('scene_2', ['narration' => 'nope'])->getStatusCode() === 409);
check('editing a card is refused mid-revision', $slotPatch(['heading' => 'nope'])->getStatusCode() === 409);
check('...and neither landed', $sceneOf('scene_2')->narration !== 'nope'
    && ($sceneOf('scene_2')->slots['slot_main']['heading'] ?? '') === 'Grind size');

$project->explainerScenes()->delete();
$project->explainerAssets()->delete();
$project->delete();

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
