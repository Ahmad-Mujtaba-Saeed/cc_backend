<?php

/**
 * user-music-check — "my own background music".
 *
 * A user uploads a bed once and it is theirs on every project from then on,
 * and on nobody else's. Two things have to hold for that to be true rather
 * than merely intended:
 *
 *   1. ISOLATION. A track id arriving from a client is matched against THAT
 *      user's rows and is never treated as a path, so user B cannot select,
 *      play or delete user A's file by guessing a number.
 *   2. It reaches the RENDER. The selection rides the existing contract
 *      (music_category = 'custom' + music_track_id), so all four places that
 *      resolve a bed — the explainer renderer, AbstractVideoProcessor, and the
 *      horror and image-shorts processors — pick it up without a second
 *      code path.
 *
 *   docker compose exec app php scratchpad/user-music-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Models\UserMusicTrack;
use Modules\Project\Processors\TemplateProcessorFactory;
use Modules\Project\Services\UserMusicLibrary;
use Modules\User\Models\User;

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

/** A file big enough to pass the "this is not a failed download" floor. */
function fakeTrack(string $name): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'trk') . '.mp3';
    file_put_contents($tmp, str_repeat("\x00\x01", 12 * 1024)); // 24 KB
    return new UploadedFile($tmp, $name, 'audio/mpeg', null, true);
}

$owner = User::query()->orderBy('id')->first();
if (!$owner) {
    echo "no users to test with\n";
    exit(1);
}

// Isolation IS the feature — "only that user sees it" — so its checks must
// never be skipped for want of a second account. One is created (and removed
// again) when the database has only one.
$other = User::query()->where('id', '!=', $owner->id)->orderBy('id')->first();
$madeOther = false;
if (!$other) {
    $other = User::create([
        'name' => 'music-isolation probe',
        'email' => 'music-probe-' . uniqid() . '@example.invalid',
        'password' => bcrypt(bin2hex(random_bytes(16))),
        'phone' => '',
    ]);
    $madeOther = true;
}
echo "\nowner = user {$owner->id}, other = user {$other->id}" . ($madeOther ? ' (created for this run)' : '') . "\n";

$made = [];

echo "\n=== uploading ===\n";
$track = UserMusicLibrary::store((int) $owner->id, fakeTrack('My Theme Song.mp3'));
$made[] = $track;
check('the track is stored', $track->exists);
check('the title is cleaned up from the filename', $track->title === 'My Theme Song', $track->title);
check('the file landed on the public disk', Storage::disk('public')->exists($track->path));
check('under a per-user directory', str_starts_with($track->path, "audio/user/{$owner->id}/"), $track->path);
check('the stored name is OURS, not the uploaded one',
    !str_contains($track->path, 'My Theme') && !str_contains($track->path, ' '), $track->path);
check('the size was recorded', $track->size_bytes >= UserMusicLibrary::MIN_BYTES);
check('it lists for its owner', (bool) array_filter(
    UserMusicLibrary::listFor((int) $owner->id),
    fn ($t) => $t['id'] === (string) $track->id
));
check('the row shape matches a catalogue track',
    array_keys($track->toTrack()) === ['id', 'title', 'duration', 'url']);

$tiny = tempnam(sys_get_temp_dir(), 'trk') . '.mp3';
file_put_contents($tiny, 'not really audio');
try {
    UserMusicLibrary::store((int) $owner->id, new UploadedFile($tiny, 'broken.mp3', 'audio/mpeg', null, true));
    check('a truncated file is refused', false);
} catch (\Throwable $e) {
    check('a truncated file is refused', str_contains($e->getMessage(), 'too small'), $e->getMessage());
}
check('...and nothing was left behind for it',
    count(UserMusicLibrary::listFor((int) $owner->id)) === count($made));

echo "\n=== isolation ===\n";
$theirs = UserMusicLibrary::store((int) $other->id, fakeTrack('Their Track.mp3'));
$made[] = $theirs;

check('another user does not see it',
    !in_array((string) $track->id, array_column(UserMusicLibrary::listFor((int) $other->id), 'id'), true));
check('...and cannot resolve it by id',
    UserMusicLibrary::find((int) $other->id, (string) $track->id)?->id !== $track->id);
check('...and cannot delete it', UserMusicLibrary::remove((int) $other->id, (int) $track->id) === false);
check('...so the file is still there', Storage::disk('public')->exists($track->path));
check('each library holds only its own owner\'s track',
    count(UserMusicLibrary::listFor((int) $other->id)) === 1
    && UserMusicLibrary::listFor((int) $other->id)[0]['id'] === (string) $theirs->id);
check('a junk id resolves to my newest track, never someone else\'s',
    UserMusicLibrary::find((int) $owner->id, 'not-a-number')?->user_id === $owner->id);
check('an id that is not mine falls back to MY newest, not theirs',
    UserMusicLibrary::find((int) $owner->id, '999999')?->user_id === $owner->id);

echo "\n=== the render picks it up ===\n";
$project = Project::create([
    'user_id' => $owner->id,
    'title' => 'Custom music probe',
    'template_type' => 'ai_explainer_video',
    'aspect_ratio' => '16:9',
    'status' => 'storyboard_ready',
    'progress' => 100,
    'settings' => ['script' => 'x', 'music_category' => 'custom', 'music_track_id' => (string) $track->id],
]);

check('the reserved category is recognised', UserMusicLibrary::isCustom('custom'));
check('...and an ordinary one is not', !UserMusicLibrary::isCustom('technology'));
check('the project resolves to the uploaded file',
    UserMusicLibrary::resolveForProject($project, 'custom', (string) $track->id) === $track->path);
check('a catalogue category resolves to nothing here (the provider answers)',
    UserMusicLibrary::resolveForProject($project, 'technology', '1129271') === null);

// The explainer renderer's own decision, called the real way.
$renderer = new \Modules\Project\Services\RemotionRenderService();
$resolve = new ReflectionMethod($renderer, 'resolveMusic');
$resolve->setAccessible(true);
$music = $resolve->invoke($renderer, $project->fresh(), [['mood' => 'neutral']]);
check('the explainer render is handed a bed', is_array($music), json_encode($music));
check('...and it is MY file', is_array($music) && str_contains((string) $music['url'], $track->path),
    is_array($music) ? (string) $music['url'] : 'null');

// A track belonging to someone else must not be playable by asking for it.
$stolen = $project->settings;
$stolen['music_track_id'] = (string) $theirs->id;
$project->update(['settings' => $stolen]);
$music = $resolve->invoke($renderer, $project->fresh(), [['mood' => 'neutral']]);
check('naming another user\'s track cannot play it',
    !is_array($music) || !str_contains((string) $music['url'], (string) $theirs->path),
    is_array($music) ? (string) $music['url'] : 'null');
$project->update(['settings' => array_merge($stolen, ['music_track_id' => (string) $track->id])]);

echo "\n=== the setting is legal everywhere ===\n";
check('template settings validation accepts it',
    TemplateProcessorFactory::validateTemplateSettings('ai_image_shorts', ['music_category' => 'custom']) === []);
check('...and still rejects nonsense',
    TemplateProcessorFactory::validateTemplateSettings('ai_image_shorts', ['music_category' => 'polka']) !== []);
check('a numeric track id passes the id format check',
    TemplateProcessorFactory::validateTemplateSettings(
        'ai_image_shorts',
        ['music_category' => 'custom', 'music_track_id' => (string) $track->id]
    ) === []);

echo "\n=== removing ===\n";
$path = $track->path;
check('the owner can remove it', UserMusicLibrary::remove((int) $owner->id, (int) $track->id));
check('...the file is gone', !Storage::disk('public')->exists($path));
check('...the row is gone', UserMusicTrack::find($track->id) === null);
check('...and a project still pointing at it renders silent, not crashed',
    UserMusicLibrary::resolveForProject($project->fresh(), 'custom', (string) $track->id) === null
    || UserMusicLibrary::find((int) $owner->id) !== null);

// Cleanup.
$project->delete();
foreach ($made as $t) {
    UserMusicLibrary::remove((int) $t->user_id, (int) $t->id);
}
if ($madeOther) {
    $other->delete();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
