<?php

namespace Modules\Project\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Modules\Project\Jobs\AnalyzeExplainerScriptJob;
use Modules\Project\Jobs\ProcessVideoJob;
use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Models\Project;
use Modules\Project\Services\CanvasDirectorService;
use Modules\Project\Services\CompositionDirectorService;
use Modules\Project\Services\ExplainerScriptWriterService;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Services\MusicProviderFactory;
use Modules\Project\Services\TemplateSettingsService;
use Modules\Project\Support\CanvasPlanValidator;
use Modules\Project\Support\ChapterPlanValidator;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Billing\Services\CreditService;
use Modules\Billing\Exceptions\InsufficientCreditsException;
use Modules\User\Models\User;

/**
 * ExplainerController
 *
 * REST surface for the ai_explainer_video template:
 *   POST   /explainer/projects                              create + analyze
 *   GET    /explainer/registry                              layout/slot registry (for UI)
 *   GET    /explainer/projects/{project}/storyboard         scenes + slots + upload state
 *   POST   /explainer/projects/{project}/reanalyze          re-run analysis
 *   POST   /explainer/projects/{project}/scenes/{sceneId}/slots/{slotKey}/asset   upload
 *   DELETE /explainer/projects/{project}/scenes/{sceneId}/slots/{slotKey}/asset   remove
 *   POST   /explainer/projects/{project}/render             render to MP4
 *   GET    /explainer/projects/{project}/status             lightweight poll
 */
class ExplainerController extends Controller
{
    public function registry(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ExplainerRegistry::all(),
        ]);
    }

    /**
     * Write a voice-over script from just a title + target duration. Runs
     * before any project exists; the frontend drops the result into the
     * editable script textarea, so the user always reviews before analyzing.
     */
    public function generateScript(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Generous cap: a math/physics problem statement plus "here is how
            // to solve it" hints is a legitimate "title" for the script writer.
            'title' => 'required|string|min:3|max:1000',
            // The user's own direction for the script — "open by naming the
            // chapter, solve it by factoring, end on the sanity check".
            'guide' => 'sometimes|nullable|string|max:2000',
            'target_seconds' => 'sometimes|integer|min:10|max:600',
            'aspect_ratio' => 'sometimes|string|in:16:9,9:16,1:1',
            'tone' => 'sometimes|string|max:60',
            'audience' => 'sometimes|string|max:120',
            'language' => 'sometimes|string|max:40',
        ]);

        // No project exists yet — attribute the script-writer spend to the user.
        \Modules\Project\Services\CostTracker::setContext(null, auth()->id());

        try {
            $result = (new ExplainerScriptWriterService())->write(
                $validated['title'],
                (int) ($validated['target_seconds'] ?? 60),
                [
                    'guide' => $validated['guide'] ?? null,
                    'tone' => $validated['tone'] ?? null,
                    'audience' => $validated['audience'] ?? null,
                    'language' => $validated['language'] ?? null,
                    'aspect_ratio' => $validated['aspect_ratio'] ?? null,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('ExplainerController: script generation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Script generation is unavailable right now — you can write the script yourself.',
            ], 503);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!TemplateSettingsService::isEnabled('ai_explainer_video')) {
            return response()->json([
                'success' => false,
                'code' => 'template_disabled',
                'message' => 'This template is currently unavailable.',
            ], 400);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'script' => 'required|string|min:10',
            // Kept alongside the script: the storyboard composer rewrites the
            // narration from the script, so without the guide the user's own
            // direction ("open with the exercise and question number") is lost
            // between the script writer and the board.
            'guide' => 'sometimes|nullable|string|max:2000',
            'aspect_ratio' => 'sometimes|string|in:16:9,9:16,1:1',
            'target_seconds' => 'sometimes|integer|min:10|max:600',
            'tts_voice' => ['sometimes', 'string', 'in:' . implode(',', \Modules\Project\Support\TtsVoices::allIds())],
            'music_category' => ['sometimes', 'string', 'in:' . implode(',', array_merge(['none', 'auto'], MusicProviderInterface::CATEGORIES))],
            'music_track_id' => ['sometimes', 'nullable', 'regex:/^[a-z0-9]{1,32}$/i'],
            'music_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $settings = [
            'script' => $validated['script'],
            'target_seconds' => $validated['target_seconds'] ?? 60,
        ];
        if (trim((string) ($validated['guide'] ?? '')) !== '') {
            $settings['guide'] = trim((string) $validated['guide']);
        }
        if (!empty($validated['tts_voice'])) {
            $settings['tts_voice'] = $validated['tts_voice'];
        }
        if (isset($validated['music_category'])) {
            $settings['music_category'] = strtolower($validated['music_category']);
        }
        if (!empty($validated['music_track_id'])) {
            $settings['music_track_id'] = (string) $validated['music_track_id'];
        }
        if (isset($validated['music_volume'])) {
            $settings['music_volume'] = (float) $validated['music_volume'];
        }

        $project = Project::create([
            'user_id' => auth()->id(),
            'title' => $validated['title'],
            'template_type' => 'ai_explainer_video',
            'aspect_ratio' => $validated['aspect_ratio'] ?? '16:9',
            'status' => 'analyzing',
            'progress' => 0,
            'settings' => $settings,
        ]);

        AnalyzeExplainerScriptJob::dispatch($project);

        return response()->json([
            'success' => true,
            'data' => ['id' => $project->id, 'status' => $project->status],
            'message' => 'Project created. Analyzing script...',
        ], 201);
    }

    public function reanalyze(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $validated = $request->validate([
            'script' => 'sometimes|string|min:10',
            'target_seconds' => 'sometimes|integer|min:10|max:600',
        ]);

        $settings = $project->settings ?? [];
        if (isset($validated['script'])) {
            $settings['script'] = $validated['script'];
        }
        if (isset($validated['target_seconds'])) {
            $settings['target_seconds'] = $validated['target_seconds'];
        }

        $project->update(['settings' => $settings, 'status' => 'analyzing', 'progress' => 0, 'error_message' => null]);
        AnalyzeExplainerScriptJob::dispatch($project);

        return response()->json([
            'success' => true,
            'message' => 'Re-analyzing script...',
        ]);
    }

    public function storyboard(Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeStoryboard($project),
        ]);
    }

    public function uploadAsset(Request $request, Project $project, string $sceneId, string $slotKey): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $request->validate([
            'file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp,video/mp4|max:51200',
        ]);

        $scene = $project->explainerScenes()->where('scene_id', $sceneId)->first();
        if (!$scene || !isset($scene->slots[$slotKey])) {
            return response()->json(['success' => false, 'message' => 'Unknown scene or slot'], 404);
        }
        if (!in_array($scene->slots[$slotKey]['content_type'] ?? null, ['image', 'video'], true)) {
            return response()->json(['success' => false, 'message' => 'This slot does not accept an upload'], 422);
        }

        $file = $request->file('file');
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');
        $fileName = $sceneId . '_' . $slotKey . '_' . Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs("projects/{$project->id}/explainer", $fileName, 'public');

        if ($isVideo) {
            // Remotion's compositor indexes frames while the file is still
            // downloading; uploads with the moov atom at the END of the file
            // fail mid-render with "No frame found at position N". Normalize
            // every video to h264/yuv420p with the index up front.
            $this->normalizeVideoForRender(Storage::disk('public')->path($path));
        }

        // Remove a previous upload for this slot (one asset per slot).
        $existing = ExplainerAsset::where('project_id', $project->id)
            ->where('scene_id', $sceneId)->where('slot_key', $slotKey)->first();
        if ($existing && $existing->path && Storage::disk('public')->exists($existing->path)) {
            Storage::disk('public')->delete($existing->path);
        }

        $asset = ExplainerAsset::updateOrCreate(
            ['project_id' => $project->id, 'scene_id' => $sceneId, 'slot_key' => $slotKey],
            [
                'type' => $isVideo ? 'video' : 'image',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => ['scene_id' => $sceneId, 'slot_key' => $slotKey, 'url' => $asset->url, 'type' => $asset->type],
            'message' => 'Asset uploaded',
        ]);
    }

    /**
     * Make an uploaded video safe for the Remotion render path: moov index at
     * the front (+faststart), h264/yuv420p, and — critically — a CONSTANT
     * frame rate. Phone/screen recordings are variable-frame-rate, and the
     * html5 <Video> element Remotion screenshots renders VFR sources as
     * stuck, repeating frames. Only a clean CFR h264 gets the cheap `-c copy`
     * remux; everything else is re-encoded to 30fps (capped at 1920px wide).
     * Best-effort — on failure the original is kept.
     */
    private function normalizeVideoForRender(string $absPath): void
    {
        try {
            $probe = new Process([
                'ffprobe', '-v', 'error', '-select_streams', 'v:0',
                '-show_entries', 'stream=codec_name,pix_fmt,r_frame_rate,avg_frame_rate', '-of', 'csv=p=0', $absPath,
            ]);
            $probe->setTimeout(30);
            $probe->run();
            [$codec, $pixFmt, $rRate, $avgRate] = array_pad(explode(',', trim($probe->getOutput())), 4, '');

            $rate = function (string $frac): float {
                if (!str_contains($frac, '/')) {
                    return (float) $frac;
                }
                [$n, $d] = explode('/', $frac, 2);
                return (float) $d > 0 ? (float) $n / (float) $d : 0.0;
            };
            $r = $rate($rRate);
            $avg = $rate($avgRate);
            $vfr = !($r > 0) || !($avg > 0) || abs($r - $avg) / max($r, $avg) > 0.02;

            $tmp = $absPath . '.norm.mp4';
            $args = ($codec === 'h264' && $pixFmt === 'yuv420p' && !$vfr && $r <= 61)
                ? ['ffmpeg', '-v', 'error', '-y', '-i', $absPath, '-c', 'copy', '-movflags', '+faststart', $tmp]
                : [
                    'ffmpeg', '-v', 'error', '-y', '-i', $absPath,
                    '-vf', 'fps=30,scale=w=min(1920\\,iw):h=-2',
                    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '19', '-pix_fmt', 'yuv420p',
                    '-c:a', 'aac', '-movflags', '+faststart', $tmp,
                ];

            $ffmpeg = new Process($args);
            $ffmpeg->setTimeout(240);
            $ffmpeg->run();

            if ($ffmpeg->isSuccessful() && is_file($tmp) && filesize($tmp) > 0) {
                rename($tmp, $absPath);
            } else {
                @unlink($tmp);
                Log::warning('explainer: video normalization failed, keeping original upload', [
                    'path' => $absPath,
                    'error' => trim($ffmpeg->getErrorOutput()),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('explainer: video normalization error, keeping original upload', [
                'path' => $absPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleteAsset(Project $project, string $sceneId, string $slotKey): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $asset = ExplainerAsset::where('project_id', $project->id)
            ->where('scene_id', $sceneId)->where('slot_key', $slotKey)->first();

        if ($asset) {
            if ($asset->path && Storage::disk('public')->exists($asset->path)) {
                Storage::disk('public')->delete($asset->path);
            }
            $asset->delete();
        }

        return response()->json(['success' => true, 'message' => 'Asset removed']);
    }

    public function render(Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $storyboard = $this->serializeStoryboard($project);
        if (!$storyboard['ready_to_render']) {
            return response()->json([
                'success' => false,
                'message' => 'Some image slots still need uploads.',
                'data' => ['missing' => $storyboard['missing_slots']],
            ], 422);
        }

        // Gate + charge credits before rendering (analysis/storyboard stays free).
        if ($gate = $this->chargeForRender($project)) {
            return $gate;
        }

        try {
            $project->update([
                'status' => 'processing',
                'progress' => 0,
                'error_message' => null,
                'failed_step' => null,
                'processing_state' => null,
            ]);

            ProcessVideoJob::dispatch($project->fresh());
        } catch (\Throwable $e) {
            // The job never queued — refund the charge.
            app(CreditService::class)->refund($project);
            return response()->json([
                'success' => false,
                'message' => 'Failed to start render: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json(['success' => true, 'message' => 'Rendering started']);
    }

    /**
     * Randomise (shuffle) the video's colour scheme.
     */
    public function shuffleTheme(Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $settings = $project->settings ?? [];
        $current = $settings['color_scheme'] ?? null;

        // Pick a new scheme different from the current one.
        $names = ExplainerRegistry::colorSchemeNames();
        $candidates = array_values(array_filter($names, fn ($n) => $n !== $current));
        $next = $candidates[array_rand($candidates)] ?? ExplainerRegistry::randomColorSchemeName();

        $settings['color_scheme'] = $next;
        $project->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'data' => ['color_scheme' => $next, 'theme' => ExplainerRegistry::colorScheme($next)],
        ]);
    }

    /**
     * Edit a single slot: change an image slot's camera move, or a text
     * slot's heading / bullets. Lightweight "next-level editing" controls.
     */
    public function updateSlot(Request $request, Project $project, string $sceneId, string $slotKey): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $scene = $project->explainerScenes()->where('scene_id', $sceneId)->first();
        if (!$scene || !isset($scene->slots[$slotKey])) {
            return response()->json(['success' => false, 'message' => 'Unknown scene or slot'], 404);
        }

        $slots = $scene->slots;
        $slot = $slots[$slotKey];

        if ($request->filled('camera_move')) {
            $move = $request->input('camera_move');
            if (!in_array($move, ExplainerRegistry::cameraMoves(), true)) {
                return response()->json(['success' => false, 'message' => 'Invalid camera move'], 422);
            }
            $slot['camera_move'] = $move;
        }

        // Manual callout pins (image/video slots) — normalized [0,1] coords.
        if ($request->has('callouts') && in_array($slot['content_type'], ['image', 'video'], true)) {
            $slot['callouts'] = $this->cleanCallouts($request->input('callouts'));
        }

        if ($slot['content_type'] === 'text_block') {
            if ($request->has('heading')) {
                $slot['heading'] = trim((string) $request->input('heading'));
            }
            if ($request->has('bullets')) {
                $bullets = $request->input('bullets');
                if (is_array($bullets)) {
                    $slot['bullets'] = array_values(array_filter(array_map(
                        fn ($b) => trim((string) $b),
                        $bullets
                    ), fn ($b) => $b !== ''));
                }
            }
        }

        $slots[$slotKey] = $slot;
        $scene->update(['slots' => $slots]);

        return response()->json(['success' => true, 'data' => ['slot' => $slot]]);
    }

    /**
     * Clamp + cap manual callout pins.
     */
    private function cleanCallouts($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $clean = [];
        foreach ($raw as $c) {
            if (!is_array($c)) {
                continue;
            }
            $text = trim((string) ($c['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $anchor = (string) ($c['anchor'] ?? 'auto');
            if (!in_array($anchor, ['auto', 'left', 'right', 'top', 'bottom'], true)) {
                $anchor = 'auto';
            }
            $clean[] = [
                'x' => max(0.0, min(1.0, (float) ($c['x'] ?? 0.5))),
                'y' => max(0.0, min(1.0, (float) ($c['y'] ?? 0.5))),
                'text' => mb_substr($text, 0, 80),
                'anchor' => $anchor,
            ];
            if (count($clean) >= 6) {
                break;
            }
        }
        return $clean;
    }

    /**
     * Toggle AI voiceover narration for the whole project.
     */
    public function toggleNarration(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $settings = $project->settings ?? [];
        $settings['narration_enabled'] = $enabled;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['narration_enabled' => $enabled]]);
    }

    /**
     * Background music for the whole project: on/off, the Pixabay category,
     * the mix volume, and optionally ONE specific track.
     *
     * Every field is applied only when PRESENT, so the original one-key call
     * (`{enabled: false}`) keeps working untouched while the storyboard's
     * music panel can send any subset.
     *
     * `category` accepts the two pseudo-values the renderer already
     * understands — 'auto' (pick from the storyboard's dominant mood) and
     * 'none' (silent) — plus any real Pixabay category.
     *
     * `track_id` is never a path. PixabayMusicService only ever RESOLVES it
     * against the live search hits or the local library listing and falls back
     * to the seeded pick when it matches nothing, so an unknown id is inert.
     */
    public function toggleMusic(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $settings = $project->settings ?? [];

        if ($request->has('enabled')) {
            $settings['music_enabled'] = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->has('category')) {
            $category = strtolower(trim((string) $request->input('category')));
            $allowed = array_merge(['auto', 'none'], MusicProviderInterface::CATEGORIES);
            if (!in_array($category, $allowed, true)) {
                return response()->json(['success' => false, 'message' => 'Invalid music category'], 422);
            }

            // A track belongs to the category it was auditioned in — keeping a
            // 'horror' id after a switch to 'corporate' would silently resolve
            // to nothing and fall back to the seeded pick, which reads as "the
            // picker ignored me". Changing category clears the track unless
            // this same request names a new one.
            if ($category !== ($settings['music_category'] ?? 'auto') && !$request->has('track_id')) {
                unset($settings['music_track_id']);
            }
            $settings['music_category'] = $category;
        }

        if ($request->has('volume')) {
            $volume = $request->input('volume');
            if (!is_numeric($volume)) {
                return response()->json(['success' => false, 'message' => 'Invalid music volume'], 422);
            }
            // Clamped, not rejected: the slider's ends are meaningful and a
            // stray 1.5 should quieten to a legal mix, never 422 the panel.
            $settings['music_volume'] = round(max(0.0, min(1.0, (float) $volume)), 3);
        }

        if ($request->has('track_id')) {
            $trackId = trim((string) $request->input('track_id'));
            if ($trackId === '') {
                unset($settings['music_track_id']); // back to the seeded pick
            } else {
                $settings['music_track_id'] = mb_substr($trackId, 0, 64);
            }
        }

        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => [
            'music_enabled' => $settings['music_enabled'] ?? true,
            'music_category' => $settings['music_category'] ?? 'auto',
            'music_volume' => $settings['music_volume'] ?? MusicProviderInterface::DEFAULT_VOLUME,
            'music_track_id' => $settings['music_track_id'] ?? null,
        ]]);
    }

    /**
     * Toggle the `02 / 06` chapter progress chip (copilot.md §10.3, hybrid
     * mode only, default off).
     */
    public function toggleChapterChip(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $enabled = filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOLEAN);
        $settings = $project->settings ?? [];
        $settings['chapter_chip'] = $enabled;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['chapter_chip' => $enabled]]);
    }

    /**
     * Toggle the per-chapter accent shift (copilot.md §11.4, hybrid mode
     * only, default off): every chapter after the first gets the accent hue
     * tilted ±20°, computed at render time so the renderer only ever paints
     * a hex. The rest of the scheme stays put.
     */
    public function toggleAccentShift(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $enabled = filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOLEAN);
        $settings = $project->settings ?? [];
        $settings['accent_shift'] = $enabled;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['accent_shift' => $enabled]]);
    }

    /**
     * Toggle the aspect-variant bundle (copilot.md §10.6, default off): one
     * render request produces 16:9 + 9:16 + 1:1, at a credit multiplier.
     */
    public function toggleAspectVariants(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $enabled = filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOLEAN);
        $settings = $project->settings ?? [];
        $settings['aspect_variants'] = $enabled;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['aspect_variants' => $enabled]]);
    }

    /**
     * Brand kit (copilot.md §10.4): optional logo watermark + brand colour.
     * The colour only overrides the scheme accent when it passes the 4.5:1
     * contrast gate against both paper and ink — the response says whether
     * it applied so the UI can show the notice.
     */
    public function setBrand(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $request->validate([
            'logo' => 'sometimes|file|mimetypes:image/png,image/jpeg,image/webp,image/svg+xml|max:4096',
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'remove_logo' => 'sometimes|boolean',
        ]);

        $settings = $project->settings ?? [];
        $brand = is_array($settings['brand'] ?? null) ? $settings['brand'] : [];

        if (filter_var($request->input('remove_logo', false), FILTER_VALIDATE_BOOLEAN)) {
            if (!empty($brand['logo_path']) && Storage::disk('public')->exists($brand['logo_path'])) {
                Storage::disk('public')->delete($brand['logo_path']);
            }
            unset($brand['logo_path']);
        }

        if ($request->hasFile('logo')) {
            if (!empty($brand['logo_path']) && Storage::disk('public')->exists($brand['logo_path'])) {
                Storage::disk('public')->delete($brand['logo_path']);
            }
            $file = $request->file('logo');
            $brand['logo_path'] = $file->storeAs(
                "projects/{$project->id}/explainer",
                'brand_logo_' . Str::uuid() . '.' . $file->getClientOriginalExtension(),
                'public'
            );
        }

        if ($request->has('color')) {
            $color = trim((string) $request->input('color'));
            if ($color === '') {
                unset($brand['color']);
            } else {
                $brand['color'] = strtoupper($color);
            }
        }

        $settings['brand'] = $brand;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => [
            'brand' => [
                'logo_url' => !empty($brand['logo_path']) ? Storage::disk('public')->url($brand['logo_path']) : null,
                'color' => $brand['color'] ?? null,
                'color_applied' => \Modules\Project\Services\RemotionRenderService::brandColorApplied($settings),
            ],
        ]]);
    }

    /**
     * Toggle the karaoke caption track (copilot.md §4.4). Unset = the aspect
     * default (on for 9:16, off for 16:9).
     */
    public function toggleCaptions(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $settings = $project->settings ?? [];
        $settings['captions_enabled'] = $enabled;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['captions_enabled' => $enabled]]);
    }

    /**
     * Toggle the mood backdrop field (§11.5): the whisper-quiet geometric
     * texture on the flat colour field, keyed per scene mood. Default on.
     */
    public function toggleBackdrop(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $settings = $project->settings ?? [];
        $settings['backdrop_enabled'] = $enabled;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['backdrop_enabled' => $enabled]]);
    }

    /**
     * Toggle auto visuals: when on, every unfilled image slot is AI-illustrated
     * at render time (flat vector, theme colours) instead of demanding an
     * upload. Defaults on for worked-math storyboards via auto_visuals_auto.
     */
    public function toggleAutoVisuals(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $settings = $project->settings ?? [];
        $settings['auto_visuals'] = $enabled;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['auto_visuals' => $enabled]]);
    }

    /**
     * Pick the typography pack (copilot.md §4.7): editorial / classic / tech,
     * or "auto" to let the system decide.
     */
    public function setFontPack(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $pack = (string) $request->input('pack', ExplainerRegistry::defaultFontPack());
        if ($pack !== 'auto' && !in_array($pack, ExplainerRegistry::fontPackNames(), true)) {
            return response()->json(['success' => false, 'message' => 'Invalid font pack'], 422);
        }

        $settings = $project->settings ?? [];
        $settings['font_pack'] = $pack;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['font_pack' => $pack]]);
    }

    /**
     * Pick the motion style preset (copilot.md §2.5), or "auto" for the
     * planner's suggestion / mood map.
     */
    public function setMotionStyle(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $style = (string) $request->input('style', 'auto');
        if ($style !== 'auto' && !in_array($style, ExplainerRegistry::motionStyleNames(), true)) {
            return response()->json(['success' => false, 'message' => 'Invalid motion style'], 422);
        }

        $settings = $project->settings ?? [];
        $settings['motion_style'] = $style;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['motion_style' => $style]]);
    }

    /**
     * Pick the surface skin (copilot.md §11.2): flat / outline / print, or
     * "auto" for the planner's suggestion.
     */
    public function setSkin(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $skin = (string) $request->input('skin', 'auto');
        if ($skin !== 'auto' && !in_array($skin, ExplainerRegistry::skinNames(), true)) {
            return response()->json(['success' => false, 'message' => 'Invalid skin'], 422);
        }

        $settings = $project->settings ?? [];
        $settings['skin'] = $skin;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['skin' => $skin]]);
    }

    /**
     * Pick the math-board surface: slate / chalk / notebook, or "auto" for
     * the topic-picked suggestion (proof → chalk, worked problem → notebook).
     * Render-time rule (RemotionRenderService): an explicit pick wins,
     * "auto" falls through to settings['board_style_auto'].
     */
    public function setBoardStyle(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $style = (string) $request->input('board_style', 'auto');
        if ($style !== 'auto' && !in_array($style, ExplainerRegistry::boardStyleNames(), true)) {
            return response()->json(['success' => false, 'message' => 'Invalid board style'], 422);
        }

        $settings = $project->settings ?? [];
        $settings['board_style'] = $style;
        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['board_style' => $style]]);
    }

    /**
     * Switch how the video is composed: "hybrid" (AI-chaptered mix of canvas
     * journeys and slides), "canvas_journey" (one big world canvas the camera
     * flies across) or classic "slides" (scene-by-scene transitions).
     * Switching to a mode without its stored plan builds one on the spot.
     */
    public function setCompositionMode(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $mode = (string) $request->input('mode', ExplainerRegistry::defaultCompositionMode());
        if (!in_array($mode, ExplainerRegistry::compositionModes(), true)) {
            return response()->json(['success' => false, 'message' => 'Invalid composition mode'], 422);
        }

        $settings = $project->settings ?? [];
        $settings['composition_mode'] = $mode;
        $aspect = $project->aspect_ratio ?? '16:9';

        $loadScenes = fn () => $project->explainerScenes()->orderBy('order')->get()
            ->map(fn ($s) => [
                'scene_id' => $s->scene_id,
                'order' => (int) $s->order,
                'duration_seconds' => (float) $s->duration_seconds,
                'narration' => ['text' => (string) $s->narration],
                'layout_template' => (string) $s->layout_template,
                'transition' => (string) $s->transition,
                'relation' => $s->relation,
                'mood' => $s->mood ?? 'neutral',
                'slots' => $s->slots ?? [],
            ])->all();

        if ($mode === 'hybrid' && empty($settings['chapter_plan'])) {
            $scenes = $loadScenes();
            $sceneIds = array_map(fn ($s) => (string) $s['scene_id'], $scenes);
            try {
                $settings['chapter_plan'] = (new CompositionDirectorService())->direct($scenes, $aspect);
            } catch (\Throwable $e) {
                $plan = (new ChapterPlanValidator())->fallbackPlan($sceneIds);
                $plan['chapters'][0]['canvas'] = (new CanvasPlanValidator())->fallbackPlan($sceneIds, $aspect);
                $settings['chapter_plan'] = $plan;
            }

            // Act-break covers (§5.5) for the late switch into hybrid: the
            // new cover scenes become real storyboard rows, and every order
            // is renumbered to the spliced sequence.
            $covered = (new ChapterPlanValidator())->insertCovers($settings['chapter_plan'], $scenes);
            $settings['chapter_plan'] = $covered['plan'];
            if ($covered['inserted'] > 0) {
                $existing = $project->explainerScenes()->pluck('id', 'scene_id');
                foreach ($covered['scenes'] as $scene) {
                    if (isset($existing[$scene['scene_id']])) {
                        $project->explainerScenes()
                            ->where('scene_id', $scene['scene_id'])
                            ->update(['order' => $scene['order']]);
                    } else {
                        $project->explainerScenes()->create([
                            'project_id' => $project->id,
                            'scene_id' => $scene['scene_id'],
                            'order' => $scene['order'],
                            'duration_seconds' => $scene['duration_seconds'],
                            'narration' => $scene['narration']['text'] ?? '',
                            'layout_template' => $scene['layout_template'],
                            'slots' => $scene['slots'],
                            'transition' => $scene['transition'],
                            'relation' => $scene['relation'] ?? null,
                            'mood' => $scene['mood'] ?? 'neutral',
                        ]);
                    }
                }
            }
        }

        if ($mode === 'canvas_journey' && empty($settings['canvas_plan'])) {
            $scenes = $loadScenes();
            try {
                $settings['canvas_plan'] = (new CanvasDirectorService())->direct($scenes, $aspect);
            } catch (\Throwable $e) {
                $settings['canvas_plan'] = (new CanvasPlanValidator())->fallbackPlan(
                    array_map(fn ($s) => (string) $s['scene_id'], $scenes),
                    $aspect
                );
            }
        }

        $project->update(['settings' => $settings]);

        return response()->json(['success' => true, 'data' => ['composition_mode' => $mode]]);
    }

    /**
     * Freeze one frame of the real composition with the project's CURRENT
     * look (scheme, font pack, skin, motion style, board skin, brand kit) so
     * the storyboard can show the style change without a render. Cached by
     * look-fingerprint, so re-picking a style you already viewed is instant.
     */
    public function preview(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $sceneId = $request->filled('scene_id') ? (string) $request->input('scene_id') : null;
        $result = (new \Modules\Project\Services\ExplainerPreviewService())->preview($project, $sceneId);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Preview failed',
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Edit scene-level pacing: transition style and/or duration.
     */
    public function updateScene(Request $request, Project $project, string $sceneId): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        $scene = $project->explainerScenes()->where('scene_id', $sceneId)->first();
        if (!$scene) {
            return response()->json(['success' => false, 'message' => 'Unknown scene'], 404);
        }

        $update = [];

        if ($request->filled('transition')) {
            $transition = $request->input('transition');
            if (!in_array($transition, ExplainerRegistry::transitions(), true)) {
                return response()->json(['success' => false, 'message' => 'Invalid transition'], 422);
            }
            $update['transition'] = $transition;
        }

        if ($request->has('duration_seconds')) {
            $update['duration_seconds'] = max(2.0, min(20.0, (float) $request->input('duration_seconds')));
        }

        if ($request->filled('mood')) {
            $mood = $request->input('mood');
            if (!in_array($mood, ExplainerRegistry::moods(), true)) {
                return response()->json(['success' => false, 'message' => 'Invalid mood'], 422);
            }
            $update['mood'] = $mood;
        }

        if (!empty($update)) {
            $scene->update($update);
        }

        return response()->json(['success' => true, 'data' => $update]);
    }

    public function status(Project $project): JsonResponse
    {
        if ($denied = $this->guard($project)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $project->status,
                'progress' => $project->progress,
                'error_message' => $project->error_message,
                'output_url' => $project->output_path ? Storage::disk('public')->url($project->output_path) : null,
                'thumbnail_url' => $project->thumbnail_path ? Storage::disk('public')->url($project->thumbnail_path) : null,
            ],
        ]);
    }

    /**
     * Build the storyboard payload the frontend renders: each scene with its
     * slots, uploaded asset URLs merged in, and overall render-readiness.
     */
    private function serializeStoryboard(Project $project): array
    {
        $assets = $project->explainerAssets()->get()
            ->keyBy(fn ($a) => $a->scene_id . '::' . $a->slot_key);

        // Auto visuals (math videos default on): unfilled image slots are
        // AI-illustrated at render time, so they never block readiness —
        // exactly like stock b-roll. An upload still overrides the AI fill.
        $autoVisuals = (bool) ($project->settings['auto_visuals']
            ?? $project->settings['auto_visuals_auto']
            ?? false);

        $scenes = [];
        $missing = [];

        // Insertion order is NOT storyboard order once scenes have been
        // spliced after the fact (lazy-inserted chapter covers get tail ids
        // but middle `order` values) — always sort by the storyboard order.
        foreach ($project->explainerScenes()->orderBy('order')->get() as $scene) {
            $slots = [];
            foreach ($scene->slots ?? [] as $slotKey => $slot) {
                if (in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                    $asset = $assets->get($scene->scene_id . '::' . $slotKey);
                    $slot['asset'] = $asset ? ['url' => $asset->url, 'type' => $asset->type, 'name' => $asset->original_name] : null;
                    // Stock b-roll slots (§8) auto-fill at render time — they
                    // never block readiness.
                    if (!$asset && !$autoVisuals && trim((string) ($slot['stock_query'] ?? '')) === '') {
                        $missing[] = ['scene_id' => $scene->scene_id, 'slot_key' => $slotKey];
                    }
                }
                $slots[$slotKey] = $slot;
            }

            $scenes[] = [
                'scene_id' => $scene->scene_id,
                'order' => $scene->order,
                'duration_seconds' => $scene->duration_seconds,
                'narration' => (string) $scene->narration,
                'layout_template' => $scene->layout_template,
                'transition' => $scene->transition,
                'mood' => $scene->mood ?? 'neutral',
                'slots' => $slots,
            ];
        }

        $schemeName = $project->settings['color_scheme'] ?? null;

        return [
            'id' => $project->id,
            'title' => $project->title,
            'status' => $project->status,
            'progress' => $project->progress,
            'aspect_ratio' => $project->aspect_ratio,
            'error_message' => $project->error_message,
            'output_url' => $project->output_path ? Storage::disk('public')->url($project->output_path) : null,
            'scenes' => $scenes,
            'missing_slots' => $missing,
            'ready_to_render' => empty($missing) && !empty($scenes),
            'templates' => ExplainerRegistry::templates(),
            'color_scheme' => $schemeName,
            'theme' => ExplainerRegistry::colorScheme($schemeName),
            'camera_moves' => ExplainerRegistry::cameraMoves(),
            'transitions' => ExplainerRegistry::transitions(),
            'moods' => ExplainerRegistry::moods(),
            'color_schemes' => ExplainerRegistry::colorSchemes(),
            'narration_enabled' => $project->settings['narration_enabled'] ?? true,
            'music_enabled' => $project->settings['music_enabled'] ?? true,
            // Background music, editable from the storyboard. 'auto' lets the
            // renderer map the dominant scene mood onto a category; a null
            // track means the deterministic per-project pick.
            'music_category' => $project->settings['music_category'] ?? 'auto',
            'music_volume' => (float) ($project->settings['music_volume'] ?? MusicProviderInterface::DEFAULT_VOLUME),
            'music_track_id' => $project->settings['music_track_id'] ?? null,
            'music_categories' => MusicProviderInterface::CATEGORIES,
            // Without a Pixabay key the picker still works off the local
            // library, so the UI says so instead of offering dead controls.
            'music_configured' => MusicProviderFactory::make()->isConfigured(),
            'music_provider' => MusicProviderFactory::provider(),
            'analysis_attempts' => $project->settings['analysis_attempts'] ?? null,
            'script_skeleton' => $project->settings['script_skeleton'] ?? null,
            'auto_visuals' => $autoVisuals,
            'auto_visuals_auto' => (bool) ($project->settings['auto_visuals_auto'] ?? false),
            'captions_enabled' => $project->settings['captions_enabled'] ?? ($project->aspect_ratio === '9:16'),
            'backdrop_enabled' => ($project->settings['backdrop_enabled'] ?? true) !== false,
            'font_pack' => $project->settings['font_pack'] ?? ExplainerRegistry::defaultFontPack(),
            'font_packs' => ExplainerRegistry::fontPacks(),
            'motion_style' => $project->settings['motion_style'] ?? 'auto',
            'motion_style_auto' => $project->settings['motion_style_auto'] ?? null,
            'motion_styles' => ExplainerRegistry::motionStyles(),
            'skin' => $project->settings['skin'] ?? 'auto',
            'skin_auto' => $project->settings['skin_auto'] ?? null,
            // Same explicit-beats-auto rule the render uses (one shared
            // implementation) — the UI needs the resolved skin to know when
            // blueprint's fixed palette has taken the colour scheme out of
            // play, exactly like `board_style_resolved` below.
            'skin_resolved' => \Modules\Project\Services\RemotionRenderService::resolveSkin($project->settings ?? []),
            'skins' => ExplainerRegistry::skins(),
            'composition_mode' => $project->settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode(),
            'composition_modes' => ExplainerRegistry::compositionModes(),
            // Math-board skin (math_board projects only): explicit pick or
            // 'auto', plus the topic-picked auto so the UI can show it.
            // `board_style_resolved` applies the same explicit-beats-auto rule
            // the renderer uses, so the UI never re-implements that decision —
            // it needs the answer to know whether chalk/notebook's fixed
            // palette has taken the colour scheme out of play.
            'board_style' => $project->settings['board_style'] ?? 'auto',
            'board_style_auto' => $project->settings['board_style_auto'] ?? null,
            'board_style_resolved' => in_array($project->settings['board_style'] ?? null, ExplainerRegistry::boardStyleNames(), true)
                ? $project->settings['board_style']
                : (string) ($project->settings['board_style_auto'] ?? 'slate'),
            'board_styles' => ExplainerRegistry::boardStyles(),
            // Live style preview: `current_look` changes whenever a
            // look-affecting setting changes (so the UI refreshes the still),
            // and differing from `rendered_look` means the finished MP4 no
            // longer reflects those settings.
            'current_look' => \Modules\Project\Services\ExplainerPreviewService::lookHash($project),
            'rendered_look' => $project->settings['rendered_look'] ?? null,
            'canvas_plan' => $project->settings['canvas_plan'] ?? null,
            'chapter_plan' => $project->settings['chapter_plan'] ?? null,
            // Quality gates (§12): the storyboard lint report + last VLM pass.
            'lint_report' => $project->settings['lint_report'] ?? null,
            'vlm_review' => $project->settings['vlm_review'] ?? null,
            // Packaging (§10.3–10.7).
            'chapter_chip' => (bool) ($project->settings['chapter_chip'] ?? false),
            'accent_shift' => (bool) ($project->settings['accent_shift'] ?? false),
            'aspect_variants' => (bool) ($project->settings['aspect_variants'] ?? false),
            'aspect_variants_multiplier' => (float) config('credits.aspect_variants_multiplier', 2.5),
            'brand' => [
                'logo_url' => !empty($project->settings['brand']['logo_path'])
                    ? Storage::disk('public')->url($project->settings['brand']['logo_path'])
                    : null,
                'color' => $project->settings['brand']['color'] ?? null,
                'color_applied' => \Modules\Project\Services\RemotionRenderService::brandColorApplied($project->settings ?? []),
            ],
            'srt_url' => !empty($project->settings['srt_path'])
                ? Storage::disk('public')->url($project->settings['srt_path'])
                : null,
            'youtube_kit_url' => !empty($project->settings['youtube_packaging_path'])
                ? Storage::disk('public')->url($project->settings['youtube_packaging_path'])
                : null,
            'thumbnail_url' => $project->thumbnail_path ? Storage::disk('public')->url($project->thumbnail_path) : null,
            'output_videos' => array_values(array_map(
                fn ($v) => [
                    'aspect' => $v['aspect'] ?? '',
                    'label' => $v['label'] ?? ($v['aspect'] ?? ''),
                    'url' => !empty($v['path']) ? Storage::disk('public')->url($v['path']) : null,
                ],
                (array) ($project->settings['output_videos'] ?? [])
            )),
        ];
    }

    private function guard(Project $project): ?JsonResponse
    {
        if ($project->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        return null;
    }

    /**
     * Gate a render on subscription + credits and charge the cost. Returns a
     * 402 JsonResponse to abort with, or null when charged successfully.
     */
    private function chargeForRender(Project $project): ?JsonResponse
    {
        if (!TemplateSettingsService::isEnabled('ai_explainer_video')) {
            return response()->json([
                'success' => false,
                'code' => 'template_disabled',
                'message' => 'This template is currently unavailable.',
            ], 400);
        }

        /** @var CreditService $credits */
        $credits = app(CreditService::class);
        $user = $project->user ?: User::find($project->user_id);

        $credits->syncDailyGrant($user);

        if (!$user->hasActiveSubscription()) {
            return response()->json([
                'success' => false,
                'code' => 'no_subscription',
                'message' => 'An active subscription is required to generate videos.',
            ], 402);
        }

        $cost = $credits->costFor($project->template_type);
        // Aspect-variant bundle (§10.6): three renders' worth of compute.
        if (($project->settings['aspect_variants'] ?? false) === true) {
            $cost = (int) ceil($cost * (float) config('credits.aspect_variants_multiplier', 2.5));
        }

        try {
            $credits->charge($user, $project, $cost);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'success' => false,
                'code' => 'insufficient_credits',
                'message' => 'You do not have enough credits for this video.',
                'balance' => $e->balance,
                'cost' => $e->cost,
            ], 402);
        }

        return null;
    }
}
