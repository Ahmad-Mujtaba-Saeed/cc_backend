<?php

use Illuminate\Support\Facades\Route;
use Modules\Project\Http\Controllers\ProjectController;
use Modules\Project\Http\Controllers\ExplainerController;
use Modules\Project\Http\Controllers\MusicController;
use Modules\Project\Http\Controllers\TtsController;
use Modules\Project\Http\Controllers\VoiceController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->group(function () {
    // Project CRUD routes
    Route::get('/projects', [ProjectController::class, 'index']);
    // Finished renders for the "My Videos" page — must be registered BEFORE
    // /projects/{project} or "videos" is captured as a project id.
    Route::get('/projects/videos', [ProjectController::class, 'videos']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
    
    // Project specific actions
    Route::post('/projects/{project}/upload-video', [ProjectController::class, 'uploadVideo']);
    Route::post('/projects/{project}/upload-setting-file', [ProjectController::class, 'uploadSettingFile']);
    Route::get('/projects/{project}/download-video', [ProjectController::class, 'downloadVideo']);
    Route::post('/projects/{project}/process', [ProjectController::class, 'process']);
    Route::post('/projects/{project}/retry', [ProjectController::class, 'retry']);
    Route::put('/projects/{project}/progress', [ProjectController::class, 'updateProgress']);
    Route::post('/projects/{project}/duplicate', [ProjectController::class, 'duplicate']);
    
    // Narration voices (active provider) + on-demand cached voice previews
    Route::get('/tts/voices', [TtsController::class, 'voices']);
    Route::post('/tts/preview', [TtsController::class, 'preview'])->middleware('throttle:20,1');

    // "My Voices": the user's own cloned voices and the clips read in them.
    // Private to their owner — anyone else's id answers 404.
    Route::get('/voices', [VoiceController::class, 'index']);
    Route::post('/voices', [VoiceController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/voices/clips', [VoiceController::class, 'clips']);
    Route::post('/voices/clips', [VoiceController::class, 'storeClip'])->middleware('throttle:20,1');
    Route::get('/voices/clips/{clipId}', [VoiceController::class, 'showClip'])->whereNumber('clipId');
    Route::delete('/voices/clips/{clipId}', [VoiceController::class, 'destroyClip'])->whereNumber('clipId');
    Route::get('/voices/{voiceId}', [VoiceController::class, 'show'])->whereNumber('voiceId');
    Route::patch('/voices/{voiceId}', [VoiceController::class, 'update'])->whereNumber('voiceId');
    Route::delete('/voices/{voiceId}', [VoiceController::class, 'destroy'])->whereNumber('voiceId');

    // Background-music browsing for the create flows (audition + select)
    Route::get('/music/options', [MusicController::class, 'options']);
    Route::get('/music/tracks', [MusicController::class, 'tracks'])->middleware('throttle:30,1');
    // The user's OWN uploaded beds — private to them, reusable on every
    // project and every template from then on.
    Route::post('/music/library', [MusicController::class, 'upload'])->middleware('throttle:20,1');
    Route::delete('/music/library/{trackId}', [MusicController::class, 'destroyTrack'])
        ->whereNumber('trackId');

    // Template configuration routes
    Route::get('/templates', [ProjectController::class, 'getTemplates']);
    Route::get('/templates/{templateType}/config', [ProjectController::class, 'getTemplateConfig']);
    Route::post('/templates/validate', [ProjectController::class, 'validateTemplateSettings']);
    Route::post('/templates/estimate-time', [ProjectController::class, 'getEstimatedProcessingTime']);

    // Explainer video (ai_explainer_video) — slot-based AI composition
    Route::get('/explainer/registry', [ExplainerController::class, 'registry']);
    Route::post('/explainer/generate-script', [ExplainerController::class, 'generateScript'])->middleware('throttle:10,1');
    Route::post('/explainer/projects', [ExplainerController::class, 'store']);
    Route::get('/explainer/projects/{project}/storyboard', [ExplainerController::class, 'storyboard']);
    Route::get('/explainer/projects/{project}/status', [ExplainerController::class, 'status']);
    Route::post('/explainer/projects/{project}/reanalyze', [ExplainerController::class, 'reanalyze']);
    // Rename the project — the storyboard heading, the thumbnail copy and the
    // YouTube packaging all read from this title.
    Route::post('/explainer/projects/{project}/title', [ExplainerController::class, 'setTitle']);
    // Add one AI-written scene at a chosen place on the board. Runs through
    // the revision pipeline's insert op, so every other card keeps its
    // uploads and its cached voiceover.
    Route::post('/explainer/projects/{project}/scenes', [ExplainerController::class, 'addScene'])
        ->middleware('throttle:20,1');
    // Targeted AI edit of the storyboard: the user's note, applied to the
    // cards it is about and nothing else.
    Route::post('/explainer/projects/{project}/revise', [ExplainerController::class, 'revise'])->middleware('throttle:20,1');
    Route::post('/explainer/projects/{project}/render', [ExplainerController::class, 'render']);
    Route::post('/explainer/projects/{project}/shuffle-theme', [ExplainerController::class, 'shuffleTheme']);
    // Pick the palette outright, instead of shuffling until one turns up.
    Route::post('/explainer/projects/{project}/color-scheme', [ExplainerController::class, 'setColorScheme']);
    // A user's OWN palettes: listed with the built-ins, created and deleted
    // here, and private to whoever made them.
    Route::get('/explainer/color-schemes', [ExplainerController::class, 'colorSchemes']);
    Route::post('/explainer/color-schemes', [ExplainerController::class, 'storeColorScheme']);
    Route::delete('/explainer/color-schemes/{name}', [ExplainerController::class, 'destroyColorScheme']);
    Route::post('/explainer/projects/{project}/narration', [ExplainerController::class, 'toggleNarration']);
    Route::post('/explainer/projects/{project}/music', [ExplainerController::class, 'toggleMusic']);
    Route::post('/explainer/projects/{project}/captions', [ExplainerController::class, 'toggleCaptions']);
    Route::post('/explainer/projects/{project}/backdrop', [ExplainerController::class, 'toggleBackdrop']);
    Route::post('/explainer/projects/{project}/auto-visuals', [ExplainerController::class, 'toggleAutoVisuals']);
    Route::post('/explainer/projects/{project}/font-pack', [ExplainerController::class, 'setFontPack']);
    Route::post('/explainer/projects/{project}/motion-style', [ExplainerController::class, 'setMotionStyle']);
    Route::post('/explainer/projects/{project}/skin', [ExplainerController::class, 'setSkin']);
    Route::post('/explainer/projects/{project}/board-style', [ExplainerController::class, 'setBoardStyle']);
    Route::post('/explainer/projects/{project}/smooth-motion', [ExplainerController::class, 'setSmoothMotion']);
    Route::post('/explainer/projects/{project}/preview', [ExplainerController::class, 'preview']);
    // The same shot list the MP4 is rendered from, for the browser player.
    Route::get('/explainer/projects/{project}/player-payload', [ExplainerController::class, 'playerPayload']);
    // A signed link to a finished file (cross-origin <a download> is ignored);
    // the file itself is served by `explainer.download` below.
    Route::get('/explainer/projects/{project}/download-link/{kind}', [ExplainerController::class, 'downloadLink'])
        ->whereIn('kind', ['video', 'srt', 'youtube_kit', 'thumbnail']);
    Route::post('/explainer/projects/{project}/composition-mode', [ExplainerController::class, 'setCompositionMode']);
    Route::post('/explainer/projects/{project}/chapter-chip', [ExplainerController::class, 'toggleChapterChip']);
    Route::post('/explainer/projects/{project}/accent-shift', [ExplainerController::class, 'toggleAccentShift']);
    Route::post('/explainer/projects/{project}/aspect-variants', [ExplainerController::class, 'toggleAspectVariants']);
    Route::post('/explainer/projects/{project}/brand', [ExplainerController::class, 'setBrand']);
    Route::post('/explainer/projects/{project}/scenes/{sceneId}/slots/{slotKey}/asset', [ExplainerController::class, 'uploadAsset']);
    // Free media library (§8b): search the stock providers for a slot, then
    // adopt one hit into it. The search is cached per query and read-only;
    // adopting downloads server-side from the service's own result.
    Route::get('/explainer/projects/{project}/media-search', [ExplainerController::class, 'searchMedia'])
        ->middleware('throttle:60,1');
    Route::post('/explainer/projects/{project}/scenes/{sceneId}/slots/{slotKey}/media', [ExplainerController::class, 'adoptMedia'])
        ->middleware('throttle:40,1');
    // Draw (or re-draw) an image slot with AI, on demand, with the user's own
    // art direction. Throttled: each call is a real image-model charge.
    Route::post('/explainer/projects/{project}/scenes/{sceneId}/slots/{slotKey}/generate', [ExplainerController::class, 'generateSlotImage'])
        ->middleware('throttle:30,1');
    Route::delete('/explainer/projects/{project}/scenes/{sceneId}/slots/{slotKey}/asset', [ExplainerController::class, 'deleteAsset']);
    Route::patch('/explainer/projects/{project}/scenes/{sceneId}/slots/{slotKey}', [ExplainerController::class, 'updateSlot']);
    Route::patch('/explainer/projects/{project}/scenes/{sceneId}', [ExplainerController::class, 'updateScene']);
});

// Cloned-voice audio for <audio> tags, which cannot send a bearer token. The
// signature is minted only inside the owner-scoped /voices responses above,
// expires after an hour, and is checked relative so a TLS proxy can't break it.
Route::get('/voices/media/{kind}/{id}', [VoiceController::class, 'media'])
    ->whereIn('kind', ['sample', 'reference', 'clip'])
    ->whereNumber('id')
    ->middleware(['signed:relative', 'throttle:120,1'])
    ->name('voices.media');

// The finished explainer files as attachments. No session — a plain browser
// navigation cannot send the bearer token — so the relative signature minted
// by ExplainerController::downloadLink (owner only, 10 minutes) is the grant.
Route::get('/explainer/downloads/{project}/{kind}', [ExplainerController::class, 'downloadFile'])
    ->whereNumber('project')
    ->whereIn('kind', ['video', 'srt', 'youtube_kit', 'thumbnail'])
    ->middleware(['signed:relative', 'throttle:60,1'])
    ->name('explainer.download');
