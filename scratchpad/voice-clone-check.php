<?php

/**
 * voice-clone-check — "My Voices" (Chatterbox-Nano voice cloning).
 *
 * What has to hold for the feature to be what it claims:
 *
 *   1. PRIVACY. A voice is its owner's alone: another user cannot list, play,
 *      use, validate or delete it by guessing `clone_<id>` or a row id, and its
 *      audio leaves the server only through a signed, expiring URL.
 *   2. A cloned narrator REACHES THE RENDER through the ordinary tts_voice
 *      setting — and when it can't be used (someone else's, not ready, engine
 *      down) the render falls back to the stock narrator instead of dying.
 *   3. (VOICE_LIVE=1) The real engine clones a voice and reads text back
 *      intelligibly: a Kokoro recording stands in for the user's upload, and
 *      Whisper transcribes the clone's output to score it against the text.
 *
 *   docker compose exec -T -u www-data app php scratchpad/voice-clone-check.php
 *   docker compose exec -T -u www-data -e VOICE_LIVE=1 app php scratchpad/voice-clone-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PythonAIService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Project\Models\UserVoice;
use Modules\Project\Models\UserVoiceClip;
use Modules\Project\Processors\TemplateProcessorFactory;
use Modules\Project\Services\TTSGenerationService;
use Modules\Project\Services\VoiceCloneService;
use Modules\Project\Support\TtsVoices;
use Modules\User\Models\User;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok  ' : 'FAIL  ') . $label . (!$ok && $detail !== '' ? "  ({$detail})" : '') . "\n";
}

/** Dispatch a request through the real HTTP kernel as $user. */
function asUser(User $user, string $method, string $uri, array $params = []): Symfony\Component\HttpFoundation\Response
{
    global $kernel, $tokens;
    // A real bearer token through the real middleware — no test doubles
    // (Mockery is a dev dependency and is not installed in the image).
    $tokens[$user->id] ??= $user->createToken('voice-clone-check')->plainTextToken;
    // Sanctum's guard caches the first user it resolves; forget it so every
    // request authenticates from its own token.
    app('auth')->forgetGuards();
    $request = Request::create($uri, $method, $params, [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens[$user->id],
    ]);

    return $kernel->handle($request);
}

$tokens = [];

$disk = Storage::disk('local');
$owner = User::query()->orderBy('id')->first();
if (!$owner) {
    echo "no users to test with\n";
    exit(1);
}
$other = User::query()->where('id', '!=', $owner->id)->orderBy('id')->first();
$madeOther = false;
if (!$other) {
    $other = User::create([
        'name' => 'voice-isolation probe',
        'email' => 'voice-probe-' . uniqid() . '@example.invalid',
        'password' => bcrypt(bin2hex(random_bytes(16))),
        'phone' => '',
    ]);
    $madeOther = true;
}
echo "\nowner = user {$owner->id}, other = user {$other->id}" . ($madeOther ? ' (created for this run)' : '') . "\n";

$madeVoices = [];

try {
    echo "\n=== voice ids ===\n";
    check('clone_12 is a clone id', TtsVoices::isClone('clone_12'));
    check('clone_0 is not', !TtsVoices::isClone('clone_0'));
    check('clone_x is not', !TtsVoices::isClone('clone_x'));
    check('clone_1;drop is not', !TtsVoices::isClone('clone_1;drop'));
    check('cloneRowId round-trips', TtsVoices::cloneRowId(TtsVoices::cloneKey(42)) === 42);
    check('stock voice still allowed without a user', TtsVoices::isAllowed('alloy', null) && TtsVoices::isAllowed('am_michael', 0));

    echo "\n=== creating a voice ===\n";
    $tmp = tempnam(sys_get_temp_dir(), 'vc') . '.wav';
    file_put_contents($tmp, str_repeat("\x00\x01", 16 * 1024));
    $voice = VoiceCloneService::createVoice((int) $owner->id, new UploadedFile($tmp, '../../etc/passwd.wav', 'audio/wav', null, true), '  <b>My</b>   voice ');
    $madeVoices[] = $voice->id;
    $key = $voice->voiceKey();

    check('row starts queued', $voice->status === UserVoice::STATUS_QUEUED);
    check('name is cleaned', $voice->name === 'My voice', $voice->name);
    check("files live under voices/{$owner->id}/", str_starts_with($voice->directory, "voices/{$owner->id}/"), $voice->directory);
    check('upload stored as original.wav (client name ignored)', $disk->exists($voice->directory . '/original.wav'));
    check('recording is NOT on the public disk', !Storage::disk('public')->exists($voice->directory . '/original.wav'));
    check('consent is recorded', $voice->consented_at !== null);

    echo "\n=== ownership ===\n";
    check('a queued voice does not validate yet', !TtsVoices::isAllowed($key, (int) $owner->id));
    $voice->update(['status' => UserVoice::STATUS_READY]);
    check('ready voice validates for its owner', TtsVoices::isAllowed($key, (int) $owner->id));
    check('...and not for anyone else', !TtsVoices::isAllowed($key, (int) $other->id));
    check('...and not with no user', !TtsVoices::isAllowed($key, null));
    check('not usable before its reference exists', VoiceCloneService::usable((int) $owner->id, $key) === null);
    $disk->put($voice->referencePath(), str_repeat("\x00", 4096));
    $disk->put($voice->samplePath(), str_repeat("\x00", 4096));
    check('usable for its owner once the reference exists', VoiceCloneService::usable((int) $owner->id, $key)?->id === $voice->id);
    check('never usable for another user', VoiceCloneService::usable((int) $other->id, $key) === null);
    check('owner picker lists it', array_key_exists($key, VoiceCloneService::optionsFor((int) $owner->id)));
    check('other picker does not', !array_key_exists($key, VoiceCloneService::optionsFor((int) $other->id)));

    auth()->setUser($owner);
    $errors = TemplateProcessorFactory::validateTemplateSettings('ai_horror_shorts', ['tts_voice' => $key]);
    check('template settings accept it for the owner', !in_array('Invalid TTS voice selected', $errors, true), implode('; ', $errors));
    auth()->setUser($other);
    $errors = TemplateProcessorFactory::validateTemplateSettings('ai_horror_shorts', ['tts_voice' => $key]);
    check('template settings reject it for another user', in_array('Invalid TTS voice selected', $errors, true));

    echo "\n=== http isolation ===\n";
    $res = asUser($owner, 'GET', '/api/voices');
    $ids = array_column(json_decode($res->getContent(), true)['voices'] ?? [], 'id');
    check('GET /api/voices lists it for the owner', $res->getStatusCode() === 200 && in_array($voice->id, $ids, true), $res->getStatusCode() . ' ' . substr($res->getContent(), 0, 200));
    $res = asUser($other, 'GET', '/api/voices');
    $ids = array_column(json_decode($res->getContent(), true)['voices'] ?? [], 'id');
    check('...and not for another user', !in_array($voice->id, $ids, true));
    check("GET /api/voices/{id} is 404 for another user", asUser($other, 'GET', "/api/voices/{$voice->id}")->getStatusCode() === 404);
    check("PATCH by another user is 404", asUser($other, 'PATCH', "/api/voices/{$voice->id}", ['name' => 'stolen'])->getStatusCode() === 404);
    check("clip for someone else's voice is 404", asUser($other, 'POST', '/api/voices/clips', ['voice_id' => $voice->id, 'text' => 'hello'])->getStatusCode() === 404);
    check("DELETE by another user is 404", asUser($other, 'DELETE', "/api/voices/{$voice->id}")->getStatusCode() === 404);
    check('...and the voice survives it', UserVoice::find($voice->id) !== null);

    $res = asUser($owner, 'GET', '/api/tts/voices');
    check('/api/tts/voices offers it to the owner as a clone', array_key_exists($key, json_decode($res->getContent(), true)['clones'] ?? []));
    $res = asUser($other, 'POST', '/api/tts/preview', ['voice' => $key]);
    check("another user's preview of it is 404", $res->getStatusCode() === 404, (string) $res->getStatusCode());

    echo "\n=== signed media ===\n";
    $res = asUser($owner, 'POST', '/api/tts/preview', ['voice' => $key]);
    $url = json_decode($res->getContent(), true)['url'] ?? '';
    check('owner preview returns a relative signed URL', str_starts_with($url, "/api/voices/media/sample/{$voice->id}?") && str_contains($url, 'signature='), $url);
    $media = $kernel->handle(Request::create($url, 'GET'));
    check('the signed URL streams audio', $media->getStatusCode() === 200 && str_contains((string) $media->headers->get('Content-Type'), 'audio/wav'), (string) $media->getStatusCode());
    $forged = str_replace("/sample/{$voice->id}?", '/sample/' . ($voice->id + 1) . '?', $url);
    check('changing the id breaks the signature', $kernel->handle(Request::create($forged, 'GET'))->getStatusCode() === 403);
    check('no signature is refused', $kernel->handle(Request::create("/api/voices/media/sample/{$voice->id}", 'GET'))->getStatusCode() === 403);

    echo "\n=== render routing / fallback ===\n";
    $tts = new TTSGenerationService();
    $out = $disk->path('voices/tmp/check-fallback-' . uniqid() . '.wav');
    @mkdir(dirname($out), 0775, true);
    $result = $tts->generateTTS('This is a fallback test.', $key, $out, false, ['template_type' => 'ai_horror_shorts', 'user_id' => (int) $other->id]);
    check("another user's clone falls back to a stock narrator", ($result['success'] ?? false) && ($result['engine'] ?? null) !== 'clone' && is_file($out), $result['error'] ?? '');
    @unlink($out);

    if (getenv('VOICE_LIVE') !== '1') {
        $health = (new VoiceCloneService())->health();
        echo "\n(voice engine: " . json_encode($health) . ")\n";
        echo "skipping live clone — set VOICE_LIVE=1 once the voice container is up\n";
    } else {
        echo "\n=== LIVE: clone + speak ===\n";
        $health = (new VoiceCloneService())->health();
        check('voice engine is up with the model loaded', ($health['model_ready'] ?? false) === true, json_encode($health));

        // Stand-in for the user's upload: ~20 s of a Kokoro narrator.
        $referenceText = 'Every morning I walk down to the little bakery on the corner. The smell of fresh bread reaches the street '
            . 'long before the door does. The baker always asks how my week is going, and I always tell her the truth: '
            . 'busy, a little tired, but happy. Then I take my coffee to the window and watch the city wake up.';
        $live = VoiceCloneService::createVoice((int) $owner->id, new UploadedFile($tmp, 'placeholder.wav', 'audio/wav', null, true), 'Live check');
        $madeVoices[] = $live->id;
        $disk->delete($live->directory . '/original.wav');
        $kokoro = (new PythonAIService())->generateTTS($referenceText, 'bf_emma', $disk->path($live->directory . '/original.wav'), false);
        check('kokoro produced a stand-in recording', ($kokoro['success'] ?? false) && $disk->exists($live->directory . '/original.wav'), $kokoro['error'] ?? '');

        $t = microtime(true);
        (new VoiceCloneService())->prepare($live);
        $live->refresh();
        check('prepare made the voice ready', $live->isReady(), (string) $live->error);
        echo '      prepare took ' . round(microtime(true) - $t, 1) . "s, speech {$live->speech_seconds}s, warnings: " . json_encode($live->warnings) . "\n";
        check('reference.wav and conds.pt exist', $disk->exists($live->referencePath()) && $disk->exists($live->directory . '/conds.pt'));
        check('a sample was spoken', $disk->exists($live->samplePath()));

        $script = "Welcome back to the channel. Today we are looking at why the sky is blue, and it is simpler than you think.\n\n"
            . 'Sunlight contains every colour. Blue light is scattered far more by the air, so it reaches your eyes from every direction.';
        $clipPath = $disk->path('voices/tmp/check-live-' . uniqid() . '.wav');
        $t = microtime(true);
        $result = $tts->generateTTS($script, $live->voiceKey(), $clipPath, true, ['template_type' => 'ai_explainer_video', 'user_id' => (int) $owner->id]);
        $took = microtime(true) - $t;
        check('the router used the clone for its owner', ($result['success'] ?? false) && ($result['engine'] ?? null) === 'clone', $result['error'] ?? json_encode($result['engine'] ?? null));
        echo '      synth+timings took ' . round($took, 1) . 's for ' . round((float) ($result['duration'] ?? 0), 1) . "s of audio\n";
        check('word timings were recovered', count($result['word_timings'] ?? []) >= 20, (string) count($result['word_timings'] ?? []));

        // Intelligibility: how many of the script's words Whisper hears back.
        $said = array_map(fn ($w) => strtolower(preg_replace('/[^a-z]/i', '', $w['word'])), $result['word_timings'] ?? []);
        $wanted = array_values(array_filter(array_map(fn ($w) => strtolower(preg_replace('/[^a-z]/i', '', $w)), preg_split('/\s+/', $script))));
        $heard = count(array_intersect($wanted, $said)) / max(1, count($wanted));
        echo '      whisper heard ' . round($heard * 100) . "% of the script's words\n";
        check('the clone is intelligible (>= 85% of words heard)', $heard >= 0.85);
        echo "      listen: {$clipPath}\n";
    }
} finally {
    echo "\n=== cleanup ===\n";
    foreach ($madeVoices as $id) {
        $row = UserVoice::find($id);
        if ($row) {
            $removed = (new VoiceCloneService())->removeVoice((int) $row->user_id, (int) $id);
            if ($id === ($madeVoices[0] ?? null)) {
                check('owner delete removes the row', $removed && UserVoice::find($id) === null);
                check('...and its files', !$disk->exists($row->directory));
            }
        }
    }
    UserVoiceClip::whereIn('user_voice_id', $madeVoices)->delete();
    $owner->tokens()->where('name', 'voice-clone-check')->delete();
    $other->tokens()->where('name', 'voice-clone-check')->delete();
    if ($madeOther) {
        $other->delete();
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
