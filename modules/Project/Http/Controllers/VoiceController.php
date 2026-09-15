<?php

namespace Modules\Project\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Jobs\PrepareUserVoiceJob;
use Modules\Project\Jobs\SynthesizeVoiceClipJob;
use Modules\Project\Models\UserVoice;
use Modules\Project\Models\UserVoiceClip;
use Modules\Project\Services\VoiceCloneService;

/**
 * "My Voices": clone your own voice from a recording, then have any text read
 * aloud in it. Everything here is scoped to the signed-in user — another
 * user's voice or clip answers 404, exactly as if it did not exist.
 */
class VoiceController extends Controller
{
    public function index(): JsonResponse
    {
        $userId = (int) auth()->id();

        return response()->json([
            'success' => true,
            'enabled' => VoiceCloneService::enabled(),
            'engine' => $this->engineState(),
            'limits' => [
                'max_voices' => VoiceCloneService::MAX_VOICES_PER_USER,
                'max_kilobytes' => VoiceCloneService::MAX_KILOBYTES,
                'max_clip_chars' => VoiceCloneService::MAX_CLIP_CHARS,
                'accept' => '.' . implode(',.', VoiceCloneService::EXTENSIONS),
            ],
            'voices' => VoiceCloneService::voicesFor($userId)
                ->map(fn (UserVoice $voice) => $this->presentVoice($voice))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!VoiceCloneService::enabled()) {
            return response()->json(['success' => false, 'message' => 'Voice cloning is turned off.'], 503);
        }

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'file' => [
                'required',
                'file',
                'max:' . VoiceCloneService::MAX_KILOBYTES,
                'mimetypes:' . implode(',', VoiceCloneService::MIME_TYPES),
            ],
            'consent' => 'accepted',
        ], [
            'consent.accepted' => 'Please confirm this is your own voice, or that you have the speaker\'s permission.',
            'file.mimetypes' => 'Upload an audio recording (WAV, MP3, M4A, OGG, FLAC or WEBM).',
        ]);

        try {
            $voice = VoiceCloneService::createVoice((int) auth()->id(), $request->file('file'), $data['name']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        PrepareUserVoiceJob::dispatch((int) $voice->id);

        return response()->json([
            'success' => true,
            'data' => ['voice' => $this->presentVoice($voice)],
            'message' => 'Cloning your voice. This usually takes a minute or two.',
        ], 201);
    }

    public function show(int $voiceId): JsonResponse
    {
        $voice = VoiceCloneService::findVoice((int) auth()->id(), $voiceId);

        return $voice
            ? response()->json(['success' => true, 'data' => ['voice' => $this->presentVoice($voice)]])
            : $this->notFound('Voice');
    }

    public function update(Request $request, int $voiceId): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:80']);

        $voice = VoiceCloneService::findVoice((int) auth()->id(), $voiceId);
        if (!$voice) {
            return $this->notFound('Voice');
        }

        VoiceCloneService::rename($voice, $data['name']);

        return response()->json(['success' => true, 'data' => ['voice' => $this->presentVoice($voice->fresh())]]);
    }

    public function destroy(int $voiceId): JsonResponse
    {
        return (new VoiceCloneService())->removeVoice((int) auth()->id(), $voiceId)
            ? response()->json(['success' => true, 'message' => 'Voice deleted.'])
            : $this->notFound('Voice');
    }

    // ------------------------------------------------------------------ text to speech

    public function clips(): JsonResponse
    {
        $clips = UserVoiceClip::with('voice:id,name')
            ->where('user_id', (int) auth()->id())
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json([
            'success' => true,
            'clips' => $clips->map(fn (UserVoiceClip $clip) => $this->presentClip($clip))->values(),
        ]);
    }

    public function storeClip(Request $request): JsonResponse
    {
        if (!VoiceCloneService::enabled()) {
            return response()->json(['success' => false, 'message' => 'Voice cloning is turned off.'], 503);
        }

        $data = $request->validate([
            'voice_id' => 'required|integer',
            'text' => 'required|string|max:' . VoiceCloneService::MAX_CLIP_CHARS,
        ]);

        $userId = (int) auth()->id();
        $voice = VoiceCloneService::findVoice($userId, (int) $data['voice_id']);
        if (!$voice) {
            return $this->notFound('Voice');
        }
        if (!$voice->isReady()) {
            return response()->json(['success' => false, 'message' => 'That voice is not ready yet.'], 422);
        }

        $text = trim($data['text']);
        if (!preg_match('/[\p{L}\p{N}]/u', $text)) {
            return response()->json(['success' => false, 'message' => 'Type some words for the voice to read.'], 422);
        }

        $clip = UserVoiceClip::create([
            'user_id' => $userId,
            'user_voice_id' => $voice->id,
            'text' => $text,
            'status' => UserVoiceClip::STATUS_QUEUED,
        ]);
        $clip->setRelation('voice', $voice);

        SynthesizeVoiceClipJob::dispatch((int) $clip->id);

        return response()->json(['success' => true, 'data' => ['clip' => $this->presentClip($clip)]], 201);
    }

    public function showClip(int $clipId): JsonResponse
    {
        $clip = VoiceCloneService::findClip((int) auth()->id(), $clipId);

        return $clip
            ? response()->json(['success' => true, 'data' => ['clip' => $this->presentClip($clip->load('voice:id,name'))]])
            : $this->notFound('Clip');
    }

    public function destroyClip(int $clipId): JsonResponse
    {
        return VoiceCloneService::removeClip((int) auth()->id(), $clipId)
            ? response()->json(['success' => true, 'message' => 'Clip deleted.'])
            : $this->notFound('Clip');
    }

    /**
     * Stream one private audio file. Reached only through a signed, expiring
     * URL ({@see VoiceCloneService::mediaUrl()}), which only the owner-scoped
     * responses above ever mint.
     */
    public function media(Request $request, string $kind, int $id)
    {
        $path = match ($kind) {
            'sample' => UserVoice::find($id)?->samplePath(),
            'reference' => UserVoice::find($id)?->referencePath(),
            'clip' => UserVoiceClip::find($id)?->path,
            default => null,
        };

        $disk = Storage::disk('local');
        if (!$path || !$disk->exists($path)) {
            abort(404);
        }

        return $disk->response(
            $path,
            $kind === 'clip' ? "voice-clip-{$id}.wav" : "voice-{$kind}-{$id}.wav",
            ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, max-age=600'],
            $request->boolean('download') ? 'attachment' : 'inline'
        );
    }

    // ------------------------------------------------------------------ presentation

    private function presentVoice(UserVoice $voice): array
    {
        $disk = Storage::disk('local');

        return [
            'id' => (int) $voice->id,
            'voice_id' => $voice->voiceKey(),
            'name' => $voice->name,
            'status' => $voice->status,
            'error' => $voice->error,
            'warnings' => array_values((array) ($voice->warnings ?? [])),
            'speech_seconds' => round((float) $voice->speech_seconds, 1),
            'created_at' => $voice->created_at?->toIso8601String(),
            'sample_url' => $voice->isReady() && $disk->exists($voice->samplePath())
                ? VoiceCloneService::mediaUrl('sample', (int) $voice->id)
                : null,
            'reference_url' => $disk->exists($voice->referencePath())
                ? VoiceCloneService::mediaUrl('reference', (int) $voice->id)
                : null,
        ];
    }

    private function presentClip(UserVoiceClip $clip): array
    {
        $ready = $clip->status === UserVoiceClip::STATUS_READY && $clip->path;

        return [
            'id' => (int) $clip->id,
            'voice_id' => (int) $clip->user_voice_id,
            'voice_name' => $clip->voice?->name,
            'text' => $clip->text,
            'status' => $clip->status,
            'error' => $clip->error,
            'duration_seconds' => round((float) $clip->duration_seconds, 1),
            'created_at' => $clip->created_at?->toIso8601String(),
            'audio_url' => $ready ? VoiceCloneService::mediaUrl('clip', (int) $clip->id) : null,
            'download_url' => $ready ? VoiceCloneService::mediaUrl('clip', (int) $clip->id, true) : null,
        ];
    }

    /** ready | loading | offline | error | disabled — cached briefly; the page polls. */
    private function engineState(): array
    {
        if (!VoiceCloneService::enabled()) {
            return ['ready' => false, 'state' => 'disabled'];
        }

        return Cache::remember('voice_clone:engine_state', 20, function () {
            $health = (new VoiceCloneService())->health();
            $state = match (true) {
                ($health['model_ready'] ?? false) === true => 'ready',
                ($health['loading'] ?? false) === true => 'loading',
                ($health['status'] ?? '') === 'healthy' => 'error',
                default => 'offline',
            };

            return ['ready' => $state === 'ready', 'state' => $state];
        });
    }

    private function notFound(string $what): JsonResponse
    {
        return response()->json(['success' => false, 'message' => "{$what} not found"], 404);
    }
}
