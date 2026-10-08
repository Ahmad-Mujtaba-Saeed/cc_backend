<?php

namespace Modules\Mcp\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Mcp\Models\McpUpload;
use Modules\Mcp\Server\Args;
use Modules\Mcp\Server\ToolException;
use Modules\Mcp\Support\McpScenes;
use Modules\Project\Contracts\MusicProviderInterface;
use Modules\Project\Models\ExplainerAsset;
use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Models\UserColorScheme;
use Modules\Project\Services\MediaLibraryService;
use Modules\Project\Services\UserMusicLibrary;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\HeroScenes;
use Modules\Project\Support\McpOrigin;
use Modules\Project\Support\ShotListValidator;
use Modules\Project\Support\StyleRecipe;
use Modules\User\Models\User;

/**
 * Everything the model does to a video before it renders: create it, set its
 * look and sound, write/move/delete scenes, and stock its media shelf.
 *
 * The video is an ordinary `ai_explainer_video` project marked
 * `settings.origin = mcp`, so the whole render pipeline (TTS, word timings,
 * the Remotion renderer, captions, mastering) is reused as-is. What differs:
 *
 *  - there is no analysis job and no storyboard UI: the model writes every
 *    scene, either as a CARD from our library (validated by the same
 *    ShotListValidator the AI planner goes through) or as CUSTOM CODE — a
 *    Remotion component compiled by the hero sandbox's guard;
 *  - nothing here spends money: no LLM calls, no image models.
 *
 * Settings mutations go through {@see locked()}: MCP clients call tools in
 * parallel, and two upserts read-modify-writing the same JSON column would
 * silently drop one scene's bookkeeping.
 */
final class McpVideoService
{
    /** Cards the studio does not offer: each depends on a paid model, or is auto-inserted. */
    public const BLOCKED_TEMPLATES = ['cinematic_card', 'custom_card', 'chapter_cover', 'outro_card'];

    /** Slot content the studio does not accept (paid drawing passes / raw html). */
    public const BLOCKED_CONTENT = ['cinematic', 'vector_motif', 'custom_html'];

    public function __construct(private User $user)
    {
    }

    // ------------------------------------------------------------------
    // lookup
    // ------------------------------------------------------------------

    public function find(int $videoId): Project
    {
        $project = Project::where('id', $videoId)->where('user_id', $this->user->id)->first();
        if (!$project || !McpOrigin::is($project)) {
            throw new ToolException("Video {$videoId} not found. list_videos shows the videos made through this connection.");
        }

        return $project;
    }

    /** Run a read-modify-write of the project under its lock. */
    public static function locked(Project $project, callable $fn): mixed
    {
        return Cache::lock("mcp:project:{$project->id}", 30)->block(25, function () use ($project, $fn) {
            $project->refresh();

            return $fn($project);
        });
    }

    public function assertEditable(Project $project): void
    {
        if (in_array($project->status, McpRenderService::BUSY, true) && !McpRenderService::isStaleQueued($project)) {
            throw new ToolException('This video is ' . ($project->status === 'queued' ? 'waiting in the render queue' : 'rendering right now')
                . '. Wait for get_render_status to report completed or failed before changing it.');
        }
    }

    public function listVideos(int $limit = 20): array
    {
        return Project::where('user_id', $this->user->id)
            ->where('template_type', 'ai_explainer_video')
            ->where('settings->origin', McpOrigin::ORIGIN)
            ->orderByDesc('updated_at')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (Project $p) => [
                'video_id' => $p->id,
                'title' => $p->title,
                'mode' => $p->settings['mcp']['mode'] ?? 'narrated',
                'aspect_ratio' => $p->aspect_ratio,
                'status' => $this->publicStatus($p),
                'scenes' => $p->explainerScenes()->count(),
                'updated_at' => optional($p->updated_at)->toIso8601String(),
            ])->all();
    }

    public function publicStatus(Project $p): string
    {
        return match ($p->status) {
            'queued' => 'queued for rendering',
            'processing' => 'rendering',
            'completed' => 'rendered',
            'failed' => 'render_failed',
            default => 'draft',
        };
    }

    // ------------------------------------------------------------------
    // create / update
    // ------------------------------------------------------------------

    public function create(Args $a): Project
    {
        $inProgress = Project::where('user_id', $this->user->id)
            ->where('settings->origin', McpOrigin::ORIGIN)
            ->where('status', 'draft')
            ->count();
        if ($inProgress >= (int) config('mcp.max_videos_in_progress', 30)) {
            throw new ToolException('You already have ' . $inProgress . ' unrendered MCP videos. Render or finish one of them first (list_videos).');
        }

        $title = $a->string('title', true, 200);
        $mode = $a->enum('mode', ['narrated', 'presenter'], 'narrated');
        $aspect = $a->enum('aspect_ratio', ['16:9', '9:16', '1:1'], '16:9');

        $settings = [
            'origin' => McpOrigin::ORIGIN,
            'mcp' => [
                'mode' => $mode,
                'next_scene' => 1,
                'scenes' => [],
                'media' => [],
                'language' => $a->string('language', false, 10, 'en'),
            ],
            'composition_mode' => $mode === 'presenter' ? 'presenter' : 'slides',
            'render_fps' => (int) ($a->int('fps', 24, 60) ?? config('mcp.default_fps', 60)) >= 45 ? 60 : 30,
            'narration_enabled' => $mode !== 'presenter',
            'hook_enabled' => false,
            'outro_enabled' => false,
            'auto_visuals' => false,
            'auto_visuals_auto' => false,
            'sfx_enabled' => true,
            'motion_blur_enabled' => true,
            'backdrop_enabled' => true,
            'captions_enabled' => $a->bool('captions', $aspect === '9:16'),
            'music_category' => 'auto',
            'music_volume' => MusicProviderInterface::DEFAULT_VOLUME,
        ];
        $script = $a->string('script', false, 60000);
        if ($script !== null && $script !== '') {
            $settings['script'] = $script;
        }
        $settings['tts_voice'] = McpCatalog::DEFAULT_VOICE;

        // Every MCP video gets its own generated look unless a scheme is
        // picked: palette, font trio, motion tuning — so two users' videos
        // never come out looking like the same template.
        $mood = $a->enum('mood', ExplainerRegistry::moods(), 'neutral');
        $settings['style_recipe'] = StyleRecipe::generate(
            StyleRecipe::newSeed(),
            StyleRecipe::contextFrom(['color_scheme' => null], (string) $mood, false)
        );
        $settings['color_scheme'] = StyleRecipe::SCHEME;

        $project = Project::create([
            'user_id' => $this->user->id,
            'title' => $title,
            'template_type' => 'ai_explainer_video',
            'aspect_ratio' => $aspect,
            'status' => 'draft',
            'progress' => 0,
            'settings' => $settings,
        ]);

        // Look/sound arguments share update()'s validation.
        $this->applySettings($project, $a);

        if ($mode === 'presenter') {
            self::locked($project, function (Project $p) {
                $s = $p->settings;
                $s['mcp']['presenter'] = ['status' => 'awaiting_upload'];
                $p->update(['settings' => $s]);
            });
        }

        return $project->fresh();
    }

    /** @return string[] what changed */
    public function applySettings(Project $project, Args $a): array
    {
        $changed = [];

        $voice = $a->string('voice', false, 40);
        if ($voice !== null && $voice !== '') {
            if (!McpCatalog::isAllowedVoice($voice, $this->user->id)) {
                throw new ToolException("Unknown voice \"{$voice}\". Call list_voices for the ids you can use.");
            }
        }

        $music = $a->string('music_category', false, 40);
        $musicCategories = array_merge(['auto', 'none', UserMusicLibrary::CATEGORY], MusicProviderInterface::CATEGORIES);
        if ($music !== null && !in_array($music, $musicCategories, true)) {
            throw new ToolException('`music_category` must be one of: ' . implode(', ', $musicCategories) . '.');
        }
        $track = $a->string('music_track_id', false, 32);
        if ($track !== null && $track !== '' && !preg_match('/^[a-z0-9]{1,32}$/i', $track)) {
            throw new ToolException('`music_track_id` must be a track id from list_music.');
        }
        $volume = $a->number('music_volume', 0, 0.6);

        $scheme = $a->string('color_scheme', false, 60);
        $schemeTheme = null;
        if ($scheme !== null && $scheme !== '' && $scheme !== StyleRecipe::SCHEME
            && !in_array($scheme, ExplainerRegistry::colorSchemeNames(), true)) {
            $custom = UserColorScheme::where('user_id', $this->user->id)->where('name', $scheme)->first();
            if (!$custom) {
                throw new ToolException("Unknown color_scheme \"{$scheme}\". list_styles shows them.");
            }
            $schemeTheme = $custom->toTheme();
        }

        $fontPack = $a->string('font_pack', false, 40);
        if ($fontPack !== null && $fontPack !== 'auto' && !in_array($fontPack, ExplainerRegistry::fontPackNames(), true)) {
            throw new ToolException('`font_pack` must be auto or one of: ' . implode(', ', ExplainerRegistry::fontPackNames()) . '.');
        }

        $motionStyle = $a->string('motion_style', false, 40);
        if ($motionStyle !== null && $motionStyle !== 'auto' && !in_array($motionStyle, ExplainerRegistry::motionStyleNames(), true)) {
            throw new ToolException('`motion_style` must be auto or one of: ' . implode(', ', ExplainerRegistry::motionStyleNames()) . '.');
        }

        $captions = $a->bool('captions');
        $fps = $a->int('fps', 24, 60);
        $title = $a->string('title', false, 200);
        $sfx = $a->bool('sound_effects');

        self::locked($project, function (Project $p) use (&$changed, $voice, $music, $track, $volume, $scheme, $schemeTheme, $fontPack, $motionStyle, $captions, $fps, $title, $sfx) {
            $s = $p->settings ?? [];
            if ($voice !== null && $voice !== '' && ($s['tts_voice'] ?? null) !== $voice) {
                $s['tts_voice'] = $voice;
                $changed[] = 'voice';
                // Every scene's narration is re-voiced: drop the timing marks.
                foreach ((array) ($s['mcp']['scenes'] ?? []) as $id => $meta) {
                    unset($s['mcp']['scenes'][$id]['voiced']);
                }
            }
            if ($music !== null) {
                $s['music_category'] = $music;
                $s['music_enabled'] = $music !== 'none';
                if ($track === null) {
                    unset($s['music_track_id']);
                }
                $changed[] = 'music';
            }
            if ($track !== null) {
                if ($track === '') {
                    unset($s['music_track_id']);
                } else {
                    $s['music_track_id'] = $track;
                }
                $changed[] = 'music_track';
            }
            if ($volume !== null) {
                $s['music_volume'] = round($volume, 3);
                $changed[] = 'music_volume';
            }
            if ($scheme !== null && $scheme !== '') {
                $s['color_scheme'] = $scheme;
                if ($schemeTheme !== null) {
                    $s['theme_override'] = $schemeTheme;
                } else {
                    unset($s['theme_override']);
                }
                $changed[] = 'color_scheme';
            }
            if ($fontPack !== null) {
                $s['font_pack'] = $fontPack;
                $changed[] = 'font_pack';
            }
            if ($motionStyle !== null) {
                $s['motion_style'] = $motionStyle;
                $changed[] = 'motion_style';
            }
            if ($captions !== null) {
                $s['captions_enabled'] = $captions;
                $changed[] = 'captions';
            }
            if ($fps !== null) {
                $s['render_fps'] = $fps >= 45 ? 60 : 30;
                $changed[] = 'fps';
            }
            if ($sfx !== null) {
                $s['sfx_enabled'] = $sfx;
                $changed[] = 'sound_effects';
            }
            $update = ['settings' => $s];
            if ($title !== null && $title !== '') {
                $update['title'] = $title;
                $changed[] = 'title';
            }
            $p->update($update);
        });

        return array_values(array_unique($changed));
    }

    // ------------------------------------------------------------------
    // scenes
    // ------------------------------------------------------------------

    /**
     * Create or replace one scene.
     *
     * @return array<string, mixed> what happened, phrased for the model
     */
    public function upsertScene(Project $project, Args $a): array
    {
        $this->assertEditable($project);
        $settings = $project->settings ?? [];
        $presenter = McpOrigin::isPresenter($settings);
        $sceneId = $a->string('scene_id', false, 40);

        $existing = null;
        if ($sceneId !== null && $sceneId !== '') {
            $existing = $project->explainerScenes()->where('scene_id', $sceneId)->first();
            if (!$existing) {
                throw new ToolException("Scene {$sceneId} does not exist in video {$project->id}. Omit scene_id to create a new scene.");
            }
        } elseif ($project->explainerScenes()->count() >= (int) config('mcp.max_scenes', 160)) {
            throw new ToolException('This video already has the maximum of ' . config('mcp.max_scenes') . ' scenes.');
        }

        $meta = $existing ? (array) ($settings['mcp']['scenes'][$existing->scene_id] ?? []) : [];
        $code = $a->string('code', false, (int) config('mcp.max_code_chars', 60000));
        $card = $a->array('card', false, 50);
        if ($code !== null && $card !== null) {
            throw new ToolException('Give either `code` (a custom animated scene) or `card` (a library card), not both.');
        }

        $kind = $code !== null ? 'custom' : ($card !== null ? 'card' : ($meta['kind'] ?? null));

        // ---- timing: narration (narrated) or a window of the recording (presenter)
        $warnings = [];
        if ($presenter) {
            $window = $this->presenterWindow($project, $a, $existing, $meta);
            $layout = $a->enum('presenter_layout', McpScenes::PRESENTER_LAYOUTS, $meta['presenter_layout'] ?? null)
                ?? ($kind === null ? 'full' : 'pip');
            if ($kind === null) {
                if ($layout !== 'full') {
                    throw new ToolException('A presenter scene without graphics must use presenter_layout "full". Give `code` or `card` for pip/split/hidden.');
                }
                $kind = 'none';
            }
            if ($kind === 'card' && in_array($layout, ['full', 'split'], true)) {
                throw new ToolException("Library cards fill the whole frame, so they work with presenter_layout pip or hidden — not {$layout}. Use `code` for {$layout} (your code can draw a transparent overlay or fill the free side).");
            }
            $narration = $window['text'];
            $hold = 0.0;
        } else {
            if ($kind === null) {
                throw new ToolException('A new scene needs `code` (custom animated scene — recommended) or `card` (a library card).');
            }
            $narration = $a->has('narration')
                ? McpScenes::cleanNarration((string) $a->raw('narration'))
                : (string) ($existing->narration ?? '');
            $hold = $a->number('hold_seconds', 0, 8, (float) ($meta['hold'] ?? 0));
            if (trim($narration) === '' && $hold < 1.5) {
                throw new ToolException('Narrated scenes need `narration` (the words the voice says over this scene). For a silent beat, give hold_seconds of 1.5 or more instead.');
            }
            $layout = null;
            $window = null;
        }

        $title = $a->string('title', false, 80, $meta['title'] ?? null);
        $transition = $a->enum('transition', ExplainerRegistry::transitions(), $existing->transition ?? null);
        $mood = $a->enum('mood', ExplainerRegistry::moods(), $existing->mood ?? null);

        // ---- content
        $compile = null;
        $cardResult = null;
        $mediaForSlots = [];
        if ($kind === 'custom' && $code !== null) {
            $compile = McpScenes::compile($code);
        }
        if ($kind === 'card' && $card !== null) {
            [$cardResult, $mediaForSlots, $cardWarnings] = $this->validateCard($project, $card, $narration, $sceneId ?: 'scene_new', $transition, $mood);
            $warnings = array_merge($warnings, $cardWarnings);
        }

        // ---- write (under the project lock)
        $result = self::locked($project, function (Project $p) use (
            $existing, $kind, $code, $compile, $cardResult, $mediaForSlots, $narration, $hold, $title, $transition,
            $mood, $presenter, $window, $layout, $a, $meta
        ) {
            $s = $p->settings ?? [];
            if (!$existing) {
                $n = (int) ($s['mcp']['next_scene'] ?? 1);
                $sceneId = 's' . $n;
                while ($p->explainerScenes()->where('scene_id', $sceneId)->exists()) {
                    $sceneId = 's' . (++$n);
                }
                $s['mcp']['next_scene'] = $n + 1;
            } else {
                $sceneId = (string) $existing->scene_id;
            }

            $old = (array) ($s['mcp']['scenes'][$sceneId] ?? []);
            $narrationChanged = !$existing || (string) $existing->narration !== $narration;

            // The DB row: the card the renderer draws (custom scenes keep a
            // plain title card as the fallback the sandbox drops back to).
            if ($kind === 'card' && $cardResult !== null) {
                $template = $cardResult['layout_template'];
                $slots = $cardResult['slots'];
            } elseif ($kind === 'card' && $existing) {
                $template = (string) $existing->layout_template;
                $slots = $existing->slots ?? [];
            } else {
                [$template, $slots] = McpScenes::fallbackCard($title, $narration);
            }

            $duration = $presenter
                ? round($window['end'] - $window['start'], 3)
                : McpScenes::estimateSeconds($narration, $slots, $hold);
            if (!$presenter && !$narrationChanged && !empty($old['voiced']) && $existing) {
                // The voice is already recorded for this exact line: keep its length.
                $duration = round((float) $old['voiced']['seconds'] + $hold, 2);
            }

            $row = [
                'narration' => $narration,
                'layout_template' => $template,
                'slots' => $slots,
                'transition' => $transition ?? ($existing->transition ?? 'fade'),
                'mood' => $mood ?? ($existing->mood ?? 'neutral'),
                'duration_seconds' => $duration,
            ];
            if ($existing) {
                $existing->update($row);
                $scene = $existing;
            } else {
                $scene = ExplainerScene::create($row + [
                    'project_id' => $p->id,
                    'scene_id' => $sceneId,
                    'order' => 999999,
                ]);
            }

            // Bookkeeping the renderer and render_video read.
            $entry = array_merge($old, [
                'kind' => $kind,
                'title' => $title,
                'hold' => $hold,
                'updated_at' => now()->toIso8601String(),
            ]);
            if ($narrationChanged) {
                unset($entry['voiced']);
            }
            if ($presenter) {
                $entry['start'] = $window['start'];
                $entry['end'] = $window['end'];
                $entry['presenter_layout'] = $layout;
                $entry['pip_corner'] = $a->enum('pip_corner', McpScenes::PIP_CORNERS, $old['pip_corner'] ?? 'bottom_right');
                $entry['pip_shape'] = $a->enum('pip_shape', McpScenes::PIP_SHAPES, $old['pip_shape'] ?? 'rounded');
                $entry['split_side'] = $a->enum('split_side', ['left', 'right'], $old['split_side'] ?? 'left');
            }

            // Content changed → the last preview no longer describes it.
            $contentChanged = $narrationChanged || $code !== null || $cardResult !== null || $a->has('presenter_layout')
                || $a->has('start_seconds') || $a->has('end_seconds') || $a->has('hold_seconds');
            if ($contentChanged) {
                unset($entry['preview']);
            }

            // Custom code: store the module and register it as this scene's
            // hero (the renderer's existing sandboxed scene runtime).
            if ($kind === 'custom' && $code !== null && $compile !== null) {
                $entry['code_hash'] = substr(sha1($code), 0, 16);
                if ($compile['ok']) {
                    $file = HeroScenes::storagePath($p->id, $sceneId);
                    Storage::disk('public')->put($file, json_encode(['code' => $code, 'js' => $compile['js']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $s['hero_scenes'][$sceneId] = [
                        'status' => 'ready',
                        'file' => $file,
                        'source' => 'mcp',
                        'content_hash' => HeroScenes::contentHash(['narration' => $narration, 'slots' => $slots]),
                        'updated_at' => now()->toIso8601String(),
                    ];
                    $entry['code_status'] = 'ok';
                    $entry['code_errors'] = [];
                } else {
                    $s['hero_scenes'][$sceneId] = ['status' => 'failed', 'source' => 'mcp', 'error' => 'compile', 'updated_at' => now()->toIso8601String()];
                    $entry['code_status'] = 'failed';
                    $entry['code_errors'] = array_slice($compile['errors'], 0, 12);
                }
            } elseif ($kind !== 'custom') {
                if (isset($s['hero_scenes'][$sceneId])) {
                    Storage::disk('public')->delete(HeroScenes::storagePath($p->id, $sceneId));
                    unset($s['hero_scenes'][$sceneId]);
                }
                unset($entry['code_status'], $entry['code_errors'], $entry['code_hash']);
            }

            // Card media: the named shelf items become this scene's slot assets.
            if ($kind === 'card' && $cardResult !== null) {
                ExplainerAsset::where('project_id', $p->id)->where('scene_id', $sceneId)
                    ->where('slot_key', 'not like', '\_\_%')->delete();
                foreach ($mediaForSlots as $slotKey => $item) {
                    ExplainerAsset::create([
                        'project_id' => $p->id,
                        'scene_id' => $sceneId,
                        'slot_key' => $slotKey,
                        'type' => $item['kind'],
                        'path' => $item['path'],
                        'original_name' => 'library:mcp:' . $item['name'],
                    ]);
                }
            }

            $s['mcp']['scenes'][$sceneId] = $entry;
            $p->update(['settings' => $s]);

            // Placement.
            if ($presenter) {
                $this->sortByStart($p);
            } else {
                $this->place($p, $scene, $a, !$existing);
            }

            return ['scene_id' => $sceneId, 'scene' => $scene->fresh()];
        });

        $scene = $result['scene'];
        $s = $project->fresh()->settings;
        $entry = $s['mcp']['scenes'][$result['scene_id']] ?? [];

        $out = [
            'scene_id' => $result['scene_id'],
            'position' => $this->positionOf($project, $result['scene_id']),
            'kind' => $kind,
            'seconds' => (float) $scene->duration_seconds,
            'seconds_source' => $presenter ? 'recording' : (!empty($entry['voiced']) ? 'voice' : 'estimate (the real length is set when the voice is recorded — preview_scene does that)'),
        ];
        if ($kind === 'card') {
            $out['card'] = $scene->layout_template;
        }
        if ($presenter) {
            $out['start_seconds'] = $entry['start'] ?? null;
            $out['end_seconds'] = $entry['end'] ?? null;
            $out['presenter_layout'] = $entry['presenter_layout'] ?? null;
        }
        if ($compile !== null) {
            $out['compile'] = $compile['ok']
                ? ['ok' => true, 'warnings' => $compile['warnings']]
                : ['ok' => false, 'errors' => $compile['errors'], 'fix' => 'The sandbox refused this code. Fix every error and call upsert_scene again with the same scene_id and the whole corrected module.'];
        }
        if ($warnings !== []) {
            $out['card_warnings'] = array_values(array_unique($warnings));
        }
        $out['next'] = ($compile !== null && !$compile['ok'])
            ? 'Fix the compile errors, then preview_scene.'
            : 'Call preview_scene to see the frames (and to record the voice for this line).';

        return $out;
    }

    public function deleteScene(Project $project, string $sceneId): void
    {
        $this->assertEditable($project);
        self::locked($project, function (Project $p) use ($sceneId) {
            $scene = $p->explainerScenes()->where('scene_id', $sceneId)->first();
            if (!$scene) {
                throw new ToolException("Scene {$sceneId} does not exist.");
            }
            $s = $p->settings ?? [];
            if (isset($s['hero_scenes'][$sceneId])) {
                Storage::disk('public')->delete(HeroScenes::storagePath($p->id, $sceneId));
                unset($s['hero_scenes'][$sceneId]);
            }
            unset($s['mcp']['scenes'][$sceneId]);
            foreach (ExplainerAsset::where('project_id', $p->id)->where('scene_id', $sceneId)->get() as $asset) {
                // Shelf media is shared between scenes; only per-scene files go.
                if (!str_starts_with((string) $asset->original_name, 'library:mcp:') && $asset->path) {
                    Storage::disk('public')->delete($asset->path);
                }
                $asset->delete();
            }
            $scene->delete();
            $p->update(['settings' => $s]);
            $this->renumber($p);
        });
    }

    /** @param  string[]  $ids */
    public function reorder(Project $project, array $ids): void
    {
        $this->assertEditable($project);
        if (McpOrigin::isPresenter($project)) {
            throw new ToolException('Presenter videos are ordered by the recording: change a scene\'s start_seconds/end_seconds instead.');
        }
        self::locked($project, function (Project $p) use ($ids) {
            $current = $p->explainerScenes()->orderBy('order')->pluck('scene_id')->map(fn ($v) => (string) $v)->all();
            $ids = array_values(array_map('strval', $ids));
            $sortedA = $current;
            $sortedB = $ids;
            sort($sortedA);
            sort($sortedB);
            if ($sortedA !== $sortedB) {
                throw new ToolException('`scene_ids` must list every scene exactly once. Current scenes: ' . implode(', ', $current));
            }
            foreach ($ids as $i => $id) {
                ExplainerScene::where('project_id', $p->id)->where('scene_id', $id)->update(['order' => $i + 1]);
            }
        });
    }

    private function place(Project $p, ExplainerScene $scene, Args $a, bool $isNew): void
    {
        $ids = $p->explainerScenes()->orderBy('order')->pluck('scene_id')->map(fn ($v) => (string) $v)->all();
        $ids = array_values(array_filter($ids, fn ($id) => $id !== (string) $scene->scene_id));

        $after = $a->string('after_scene_id', false, 40);
        $position = $a->int('position', 1, 10000);
        if ($after !== null && $after !== '') {
            $idx = array_search($after, $ids, true);
            if ($idx === false) {
                throw new ToolException("after_scene_id {$after} does not exist.");
            }
            array_splice($ids, $idx + 1, 0, [(string) $scene->scene_id]);
        } elseif ($position !== null) {
            array_splice($ids, max(0, min(count($ids), $position - 1)), 0, [(string) $scene->scene_id]);
        } elseif ($isNew) {
            $ids[] = (string) $scene->scene_id;
        } else {
            // An edit without a move keeps its place.
            $all = $p->explainerScenes()->orderBy('order')->pluck('scene_id')->map(fn ($v) => (string) $v)->all();
            $ids = $all;
        }
        foreach ($ids as $i => $id) {
            ExplainerScene::where('project_id', $p->id)->where('scene_id', $id)->update(['order' => $i + 1]);
        }
    }

    private function renumber(Project $p): void
    {
        if (McpOrigin::isPresenter($p)) {
            $this->sortByStart($p);

            return;
        }
        foreach ($p->explainerScenes()->orderBy('order')->get() as $i => $scene) {
            $scene->update(['order' => $i + 1]);
        }
    }

    private function sortByStart(Project $p): void
    {
        $meta = (array) ($p->fresh()->settings['mcp']['scenes'] ?? []);
        $scenes = $p->explainerScenes()->get()->sortBy(fn ($s) => (float) ($meta[$s->scene_id]['start'] ?? 0))->values();
        foreach ($scenes as $i => $scene) {
            $scene->update(['order' => $i + 1]);
        }
    }

    private function positionOf(Project $p, string $sceneId): int
    {
        $ids = $p->explainerScenes()->orderBy('order')->pluck('scene_id')->map(fn ($v) => (string) $v)->all();
        $idx = array_search($sceneId, $ids, true);

        return $idx === false ? 0 : $idx + 1;
    }

    /**
     * Validate a library card through the same validator the AI planner
     * output passes, and resolve its media slots against the shelf.
     *
     * @return array{0: array, 1: array<string, array>, 2: string[]}
     */
    private function validateCard(Project $project, array $card, string $narration, string $sceneId, ?string $transition, ?string $mood): array
    {
        $template = trim((string) ($card['template'] ?? $card['layout_template'] ?? ''));
        if ($template === '' || !ExplainerRegistry::hasTemplate($template)) {
            throw new ToolException("Unknown card template \"{$template}\". get_guide(cards) lists them with their slots.");
        }
        if (in_array($template, self::BLOCKED_TEMPLATES, true)) {
            throw new ToolException("The {$template} card is not available in the studio. Write the scene as custom `code` instead.");
        }
        $slots = $card['slots'] ?? null;
        if (!is_array($slots) || $slots === []) {
            throw new ToolException('`card.slots` is required: an object keyed by slot name (e.g. {"slot_main": {"content_type": "text_block", ...}}).');
        }

        $media = (array) (($project->settings['mcp']['media'] ?? []));
        $mediaForSlots = [];
        $missing = [];
        foreach ($slots as $slotKey => &$slot) {
            if (!is_array($slot)) {
                throw new ToolException("Slot {$slotKey} must be an object with a content_type.");
            }
            $type = (string) ($slot['content_type'] ?? '');
            if (in_array($type, self::BLOCKED_CONTENT, true)) {
                throw new ToolException("content_type \"{$type}\" is not available in the studio (it needs a paid drawing model). Use a custom code scene to draw it.");
            }
            if (in_array($type, ['image', 'video', 'stock_video'], true)) {
                $name = trim((string) ($slot['media'] ?? ''));
                if ($name !== '') {
                    if (!isset($media[$name])) {
                        throw new ToolException("Slot {$slotKey}: no media named \"{$name}\" on this video's shelf. add_media (stock) or create_upload_link (the user's own picture) first.");
                    }
                    if ($type === 'video' && ($media[$name]['kind'] ?? 'image') !== 'video') {
                        throw new ToolException("Slot {$slotKey} is a video slot but \"{$name}\" is an image.");
                    }
                    $mediaForSlots[$slotKey] = ['name' => $name] + $media[$name];
                    if ($type === 'stock_video') {
                        $slot['content_type'] = 'video';
                    }
                } elseif (trim((string) ($slot['stock_query'] ?? $slot['query'] ?? '')) === '') {
                    $missing[] = $slotKey;
                }
                $slot['asset_request'] = is_array($slot['asset_request'] ?? null) ? $slot['asset_request'] : [];
                $slot['asset_request']['description'] = (string) ($slot['asset_request']['description']
                    ?? ($name !== '' ? ($media[$name]['title'] ?? $name) : ($slot['stock_query'] ?? 'b-roll')));
            }
            if ($type === 'scenario') {
                // Cut-out sprites are drawn by an image model: icons only here.
                foreach ((array) ($slot['entities'] ?? []) as $i => $entity) {
                    if (is_array($entity)) {
                        unset($slot['entities'][$i]['sprite'], $slot['entities'][$i]['sprite_url'], $slot['entities'][$i]['sprite_path']);
                    }
                }
            }
        }
        unset($slot);
        if ($missing !== []) {
            throw new ToolException('Picture/video slots need media: ' . implode(', ', $missing)
                . '. Give each one "media": "<name>" from the shelf (search_media → add_media, or create_upload_link for the user\'s own file), or "stock_query" for automatic free b-roll.');
        }

        $validator = new ShotListValidator();
        $raw = [
            'scene_id' => $sceneId,
            'narration' => ['text' => $narration],
            'layout_template' => $template,
            'slots' => $slots,
            'transition' => $transition,
            'mood' => $mood,
        ];
        $clean = $validator->validateOne($raw, 1, ['aspect_ratio' => $project->aspect_ratio ?? '16:9', 'math_mode' => false]);
        $warnings = array_map(fn ($w) => preg_replace('/^Scene [^:]+: /', '', (string) $w), $validator->warnings());
        // Fields the card does not know are dropped without a word by the
        // validator — say which, so the model learns the real shape.
        if ($clean['layout_template'] === $template) {
            $ignored = [];
            foreach ($slots as $slotKey => $slot) {
                if (isset($clean['slots'][$slotKey]) && is_array($clean['slots'][$slotKey])) {
                    self::droppedKeys($slot, $clean['slots'][$slotKey], (string) $slotKey, $ignored);
                }
            }
            if ($ignored !== []) {
                $warnings[] = 'Ignored (not part of this card\'s shape — check get_guide cards): ' . implode(', ', array_slice(array_values(array_unique($ignored)), 0, 12));
            }
        }
        if ($clean['layout_template'] !== $template) {
            $warnings[] = "The {$template} card could not be built from these slots and was replaced by {$clean['layout_template']} — check get_guide(cards) for its exact slot shape.";
        }

        // Media slots that survived validation keep their shelf file.
        $mediaForSlots = array_filter(
            $mediaForSlots,
            fn ($item, $slotKey) => in_array($clean['slots'][$slotKey]['content_type'] ?? null, ['image', 'video'], true),
            ARRAY_FILTER_USE_BOTH
        );

        return [$clean, $mediaForSlots, $warnings];
    }

    /** Paths of input keys the validated slot no longer has. */
    private static function droppedKeys(array $in, array $out, string $path, array &$found): void
    {
        $internal = ['media', 'stock_query', 'query', 'asset_request', 'content_type', 'label'];
        foreach ($in as $key => $value) {
            if (is_int($key)) {
                // A list: compare item shapes against the first surviving item.
                if (is_array($value) && isset($out[0]) && is_array($out[0])) {
                    self::droppedKeys($value, $out[$key] ?? $out[0], preg_replace('/\[\]$/', '', $path) . '[]', $found);
                }
                continue;
            }
            if (in_array($key, $internal, true) && $path !== '') {
                continue;
            }
            if (!array_key_exists($key, $out)) {
                $found[] = "{$path}.{$key}";
            } elseif (is_array($value) && is_array($out[$key])) {
                self::droppedKeys($value, $out[$key], "{$path}.{$key}", $found);
            }
        }
    }

    /**
     * The window of the recording a presenter scene covers, checked against
     * the recording and the other scenes.
     *
     * @return array{start: float, end: float, text: string}
     */
    private function presenterWindow(Project $project, Args $a, ?ExplainerScene $existing, array $meta): array
    {
        $presenter = (array) ($project->settings['mcp']['presenter'] ?? []);
        if (($presenter['status'] ?? null) !== 'ready') {
            throw new ToolException('The presenter recording is not ready yet (status: ' . ($presenter['status'] ?? 'awaiting_upload')
                . '). Give the user the upload link (create_upload_link), then poll get_video until presenter.status is "ready" and read get_transcript.');
        }
        $duration = (float) ($presenter['duration'] ?? 0);
        $start = $a->number('start_seconds', 0, $duration, isset($meta['start']) ? (float) $meta['start'] : null);
        $end = $a->number('end_seconds', 0, $duration + 0.05, isset($meta['end']) ? (float) $meta['end'] : null);
        if ($start === null || $end === null) {
            throw new ToolException('Presenter scenes need start_seconds and end_seconds (a window of the recording — read get_transcript for the timings).');
        }
        $end = min($end, $duration);
        if ($end - $start < 1.0) {
            throw new ToolException('A scene must cover at least 1 second of the recording.');
        }

        $sceneId = $existing ? (string) $existing->scene_id : null;
        foreach ((array) ($project->settings['mcp']['scenes'] ?? []) as $id => $other) {
            if ((string) $id === $sceneId || !isset($other['start'], $other['end'])) {
                continue;
            }
            if ($start < (float) $other['end'] - 0.04 && $end > (float) $other['start'] + 0.04) {
                throw new ToolException(sprintf(
                    'This window (%.2f–%.2f s) overlaps scene %s (%.2f–%.2f s). Scenes must not overlap; gaps are fine (they show the presenter full-frame).',
                    $start, $end, $id, $other['start'], $other['end']
                ));
            }
        }

        $text = PresenterService::textBetween($project, $start, $end);

        return ['start' => round($start, 3), 'end' => round($end, 3), 'text' => McpScenes::cleanNarration($text)];
    }

    // ------------------------------------------------------------------
    // media shelf
    // ------------------------------------------------------------------

    /**
     * @return array{results: array, sheet: ?string, sheet_numbers: int[]}
     */
    public function searchMedia(Project $project, string $query, string $kind, bool $withThumbnails): array
    {
        $library = new MediaLibraryService();
        if (!$library->isAvailable()) {
            throw new ToolException('No stock media provider is configured on this server. Draw visuals in code, or ask the user to upload pictures (create_upload_link purpose=image).');
        }
        $hits = $library->search($query, $kind, McpScenes::orientation($project), [], 12);

        $results = [];
        $tiles = [];
        $tmp = [];
        foreach ($hits as $i => $hit) {
            $results[] = [
                'n' => $i + 1,
                'provider' => $hit['provider'],
                'id' => $hit['id'],
                'kind' => $hit['kind'],
                'title' => $hit['title'],
                'size' => "{$hit['width']}x{$hit['height']}",
                'duration' => $hit['duration'],
                'license' => $hit['license'],
                'thumb_url' => $hit['thumb'],
            ];
            if ($withThumbnails && $i < 9 && preg_match('#^https://#i', (string) $hit['thumb'])) {
                $file = McpScenes::fetchThumbnail((string) $hit['thumb']);
                if ($file !== null) {
                    $tmp[] = $file;
                    $tiles[] = ['path' => $file, 'label' => '#' . ($i + 1), 'n' => $i + 1];
                }
            }
        }

        // ONE numbered contact sheet, not one image per hit: separate images
        // overflowed Claude.ai's ~150k-character tool-result cap and were dropped.
        try {
            $sheet = $tiles === [] ? null : \Modules\Mcp\Support\McpImages::sheet($tiles, null, 1200);
        } finally {
            foreach ($tmp as $f) {
                @unlink($f);
            }
        }

        return ['results' => $results, 'sheet' => $sheet, 'sheet_numbers' => array_column($tiles, 'n')];
    }

    public function addMedia(Project $project, string $provider, string $id, string $query, string $kind, string $name): array
    {
        $name = McpScenes::mediaName($name);
        $library = new MediaLibraryService();
        $candidate = null;
        foreach ($library->search($query, $kind, McpScenes::orientation($project), [$provider]) as $hit) {
            if (($hit['id'] ?? null) === $id) {
                $candidate = $hit;
                break;
            }
        }
        if ($candidate === null) {
            throw new ToolException('That result is not in the search for this query any more — search_media again with the same query and pick from the fresh list.');
        }

        $ext = $candidate['kind'] === 'video' ? 'mp4' : (preg_match('/\.png(\?|$)/i', (string) $candidate['download_url']) ? 'png' : 'jpg');
        $relative = "projects/{$project->id}/explainer/mcp_media/{$name}_" . Str::random(6) . ".{$ext}";
        if ($library->download($candidate, $relative) === null) {
            throw new ToolException('The file could not be downloaded. Pick another result.');
        }
        if ($candidate['kind'] === 'video') {
            McpScenes::normalizeVideo(Storage::disk('public')->path($relative));
        }

        $item = [
            'path' => $relative,
            'kind' => $candidate['kind'],
            'source' => 'stock',
            'title' => $candidate['title'],
            'width' => $candidate['width'],
            'height' => $candidate['height'],
            'credit' => [
                'provider' => $candidate['provider_label'],
                'author' => $candidate['credit']['author'] ?? '',
                'source_url' => $candidate['credit']['source_url'] ?? '',
                'license' => $candidate['license'],
            ],
        ];
        $this->putMedia($project, $name, $item);

        return ['name' => $name] + $item;
    }

    public function putMedia(Project $project, string $name, array $item): void
    {
        self::locked($project, function (Project $p) use ($name, $item) {
            $s = $p->settings ?? [];
            $old = $s['mcp']['media'][$name]['path'] ?? null;
            $s['mcp']['media'][$name] = $item;
            $p->update(['settings' => $s]);
            // Card slots that used the old file follow the name.
            ExplainerAsset::where('project_id', $p->id)->where('original_name', 'library:mcp:' . $name)
                ->update(['path' => $item['path'], 'type' => $item['kind']]);
            if ($old && $old !== $item['path']) {
                Storage::disk('public')->delete($old);
            }
        });
    }

    // ------------------------------------------------------------------
    // describe
    // ------------------------------------------------------------------

    public function describe(Project $project, bool $withScenes = true): array
    {
        $s = $project->settings ?? [];
        $mcp = (array) ($s['mcp'] ?? []);
        $presenterMode = McpOrigin::isPresenter($s);
        $rows = $project->explainerScenes()->orderBy('order')->get();

        $scenes = [];
        $total = 0.0;
        foreach ($rows as $i => $row) {
            $meta = (array) ($mcp['scenes'][$row->scene_id] ?? []);
            $seconds = (float) $row->duration_seconds;
            $total += $seconds;
            $scene = [
                'scene_id' => $row->scene_id,
                'position' => $i + 1,
                'kind' => $meta['kind'] ?? 'card',
                'title' => $meta['title'] ?? null,
                'seconds' => round($seconds, 2),
            ];
            if (($meta['kind'] ?? '') === 'card') {
                $scene['card'] = $row->layout_template;
            }
            if ($presenterMode) {
                $scene['start_seconds'] = $meta['start'] ?? null;
                $scene['end_seconds'] = $meta['end'] ?? null;
                $scene['presenter_layout'] = $meta['presenter_layout'] ?? 'full';
            } else {
                $scene['narration'] = Str::limit((string) $row->narration, 140);
                $scene['voice_recorded'] = !empty($meta['voiced']);
            }
            if (($meta['kind'] ?? '') === 'custom') {
                $scene['code'] = ($meta['code_status'] ?? 'missing') === 'ok' ? 'compiled' : 'FAILED — fix and upsert again';
            }
            $scene['preview'] = $meta['preview']['status'] ?? 'not previewed';
            $scenes[] = $scene;
        }

        if ($presenterMode) {
            $total = (float) ($mcp['presenter']['duration'] ?? $total);
        }

        $out = [
            'video_id' => $project->id,
            'title' => $project->title,
            'mode' => $mcp['mode'] ?? 'narrated',
            'status' => $this->publicStatus($project),
            'aspect_ratio' => $project->aspect_ratio,
            'fps' => ExplainerRegistry::resolveFps($s),
            'voice' => $presenterMode ? '(the presenter\'s own voice)' : ($s['tts_voice'] ?? McpCatalog::DEFAULT_VOICE),
            'music' => ['category' => $s['music_category'] ?? 'auto', 'track_id' => $s['music_track_id'] ?? null, 'volume' => $s['music_volume'] ?? null],
            'color_scheme' => $s['color_scheme'] ?? 'unique',
            'theme' => ExplainerRegistry::themeFor($s),
            'font_pack' => $s['font_pack'] ?? 'auto',
            'captions' => (bool) ($s['captions_enabled'] ?? false),
            'media_shelf' => array_map(fn ($name, $m) => [
                'name' => $name, 'kind' => $m['kind'] ?? 'image', 'source' => $m['source'] ?? 'stock',
                'size' => isset($m['width']) ? "{$m['width']}x{$m['height']}" : null,
            ], array_keys((array) ($mcp['media'] ?? [])), array_values((array) ($mcp['media'] ?? []))),
            'scene_count' => count($scenes),
            'total_seconds' => round($total, 1),
            'max_seconds' => (int) config('mcp.max_video_seconds', 900),
        ];
        if ($presenterMode) {
            $p = (array) ($mcp['presenter'] ?? []);
            $out['presenter'] = array_filter([
                'status' => $p['status'] ?? 'awaiting_upload',
                'step' => ($p['status'] ?? '') === 'processing' ? ($p['step'] ?? null) : null,
                'duration' => $p['duration'] ?? null,
                'size' => isset($p['width']) ? "{$p['width']}x{$p['height']}" : null,
                'transcript_words' => $p['words'] ?? null,
                'error' => $p['error'] ?? null,
            ], fn ($v) => $v !== null);
            $pending = McpUpload::where('project_id', $project->id)->where('purpose', 'presenter')->latest('id')->first();
            if ($pending && in_array($pending->status, ['pending', 'uploading'], true)) {
                $out['presenter']['upload'] = $pending->status . ($pending->expected_bytes ? sprintf(' (%d%%)', (int) floor(100 * $pending->received_bytes / max(1, $pending->expected_bytes))) : '');
            }
        }
        if ($withScenes) {
            $out['scenes'] = $scenes;
        }
        if (in_array($project->status, ['completed', 'failed', 'processing', 'queued'], true)) {
            $out['render'] = ['status' => $this->publicStatus($project), 'progress' => $project->progress, 'error' => $project->error_message];
        }

        return $out;
    }
}
