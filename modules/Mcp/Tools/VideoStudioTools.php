<?php

namespace Modules\Mcp\Tools;

use Modules\Mcp\Models\McpUpload;
use Modules\Mcp\Server\Args;
use Modules\Mcp\Server\McpContext;
use Modules\Mcp\Server\ToolException;
use Modules\Mcp\Server\ToolResult;
use Modules\Mcp\Services\McpCatalog;
use Modules\Mcp\Services\McpPreviewService;
use Modules\Mcp\Services\McpRenderService;
use Modules\Mcp\Services\McpVideoService;
use Modules\Mcp\Services\PresenterService;
use Modules\Mcp\Support\Guides;
use Modules\Mcp\Support\McpScenes;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\McpOrigin;

/**
 * The tools the user's LLM drives the studio with.
 *
 * Descriptions are written FOR the model: what the tool does, when to call
 * it, and what to do next. The handlers are thin — the services hold the
 * logic — so a description can never drift far from what actually happens.
 */
final class VideoStudioTools
{
    private const RO = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    private const WRITE = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        $videoId = ['type' => 'integer', 'description' => 'The video id from create_video / list_videos.'];
        $transitions = ExplainerRegistry::transitions();
        $moods = ExplainerRegistry::moods();

        return [
            $this->def('get_guide', 'Read a guide',
                'Read the studio\'s guides BEFORE building a video. Topics: "workflow" (start here: the process and the rules), '
                . '"scene_code" (how to write custom animated scenes: the sandbox, the hero-kit API, timing to the voice, craft rules), '
                . '"examples" (complete scene modules showing the expected quality), "design" (art direction for a whole video), '
                . '"cards" (the ready-made card library and exact slot shapes), "presenter" (motion graphics over the user\'s own talking-head recording).',
                ['topic' => ['type' => 'string', 'enum' => Guides::TOPICS, 'description' => 'Which guide. Default: workflow.']],
                [], self::RO),

            $this->def('list_voices', 'List narrator voices',
                'The free narrator voices (with a sample audio link for each) and the user\'s own cloned voices. ASK THE USER which voice they want — '
                . 'share a few options with their sample links — before creating a narrated video.',
                [], [], self::RO),

            $this->def('list_music', 'List background music',
                'Without a category: the music categories. With a category: auditionable tracks (id, title, duration, preview url). '
                . 'Offer the user a choice, or use "auto".',
                ['category' => ['type' => 'string', 'description' => 'A category from the first call, e.g. "cinematic", "corporate", "custom" (the user\'s uploads).']],
                [], self::RO),

            $this->def('list_styles', 'List looks',
                'Colour schemes (with their colours), font packs, aspect ratios, fps, transitions (with what each cut means), moods and presenter layouts. '
                . 'The default look "unique" generates a palette + type trio for this video alone.',
                [], [], self::RO),

            $this->def('create_video', 'Create a video',
                'Start a new video (an empty timeline you fill with upsert_scene). mode "narrated" = a voice reads your scene narrations (default); '
                . 'mode "presenter" = the user uploads a talking-head recording of themselves speaking and you lay motion graphics over it. '
                . 'Confirm aspect ratio, voice and music with the user first. Renders at 60 fps by default. Max length 15 minutes.',
                [
                    'title' => ['type' => 'string', 'description' => 'Video title.'],
                    'mode' => ['type' => 'string', 'enum' => ['narrated', 'presenter'], 'description' => 'Default narrated.'],
                    'aspect_ratio' => ['type' => 'string', 'enum' => ['16:9', '9:16', '1:1'], 'description' => 'Default 16:9.'],
                    'voice' => ['type' => 'string', 'description' => 'Narrator voice id from list_voices (narrated mode). Default af_heart.'],
                    'music_category' => ['type' => 'string', 'description' => 'auto (default), none, or a category from list_music.'],
                    'music_track_id' => ['type' => 'string', 'description' => 'Pin one track from list_music.'],
                    'music_volume' => ['type' => 'number', 'description' => '0–0.6, default ~0.09 (it ducks under the voice automatically).'],
                    'color_scheme' => ['type' => 'string', 'description' => '"unique" (default, generated for this video) or a scheme name from list_styles.'],
                    'font_pack' => ['type' => 'string', 'description' => 'auto (default) or a pack from list_styles.'],
                    'mood' => ['type' => 'string', 'enum' => $moods, 'description' => 'Steers the generated unique look.'],
                    'captions' => ['type' => 'boolean', 'description' => 'Burn karaoke captions in. Default: on for 9:16, off otherwise.'],
                    'fps' => ['type' => 'integer', 'enum' => [30, 60], 'description' => 'Default 60.'],
                    'script' => ['type' => 'string', 'description' => 'The full script, kept with the video for reference.'],
                    'language' => ['type' => 'string', 'description' => 'Presenter mode: spoken language code (transcription is English-tuned today). Default en.'],
                ],
                ['title'], self::WRITE),

            $this->def('update_video', 'Change video settings',
                'Change title, voice, music, look, captions, fps or sound effects of an existing video. Changing the voice re-records every narration at the next preview/render.',
                [
                    'video_id' => $videoId,
                    'title' => ['type' => 'string'],
                    'voice' => ['type' => 'string'],
                    'music_category' => ['type' => 'string'],
                    'music_track_id' => ['type' => 'string', 'description' => 'Empty string clears a pinned track.'],
                    'music_volume' => ['type' => 'number'],
                    'color_scheme' => ['type' => 'string'],
                    'font_pack' => ['type' => 'string'],
                    'motion_style' => ['type' => 'string', 'description' => 'Card motion preset (auto, crisp, classic, bounce, elegant, swiss) — affects library cards only.'],
                    'captions' => ['type' => 'boolean'],
                    'fps' => ['type' => 'integer', 'enum' => [30, 60]],
                    'sound_effects' => ['type' => 'boolean', 'description' => 'Whooshes on cuts and pops on card reveals. Default on.'],
                ],
                ['video_id'], self::WRITE),

            $this->def('get_video', 'Inspect a video',
                'The video\'s settings, look (theme colours), media shelf, every scene (kind, length, voice/preview state; presenter windows) and render state. '
                . 'Call it to resume work, after an upload, or before rendering.',
                ['video_id' => $videoId], ['video_id'], self::RO),

            $this->def('list_videos', 'List my studio videos',
                'The videos made through this connection, newest first.',
                ['limit' => ['type' => 'integer', 'description' => 'Default 20, max 50.']], [], self::RO),

            $this->def('upsert_scene', 'Write a scene',
                'Create a scene (omit scene_id) or replace one (give scene_id; fields you omit are kept). Give EITHER `code` — a complete custom Remotion scene '
                . 'module in the studio sandbox (read get_guide scene_code; this is how great videos are made) — OR `card` — a library card {template, slots} (get_guide cards). '
                . 'Narrated mode: `narration` is the exact words the voice says over this scene (one idea, ~8–25 s). '
                . 'Presenter mode: give start_seconds/end_seconds (a window of the recording, from get_transcript) and presenter_layout. '
                . 'Code is compiled on the spot; errors come back for you to fix. Then call preview_scene.',
                [
                    'video_id' => $videoId,
                    'scene_id' => ['type' => 'string', 'description' => 'Existing scene to replace. Omit to create.'],
                    'narration' => ['type' => 'string', 'description' => 'Narrated mode: the spoken words for this scene.'],
                    'code' => ['type' => 'string', 'description' => 'A complete TSX module: `export default function Scene()`, imports only from "remotion" and "hero-kit".'],
                    'card' => ['type' => 'object', 'description' => '{"template": "<card name>", "slots": {"slot_x": {"content_type": "...", ...}}} — see get_guide cards.'],
                    'title' => ['type' => 'string', 'description' => 'Short label for this scene (also the fallback title card if code ever fails).'],
                    'position' => ['type' => 'integer', 'description' => 'Narrated mode: 1-based place in the video (default: append).'],
                    'after_scene_id' => ['type' => 'string', 'description' => 'Narrated mode: place right after this scene.'],
                    'transition' => ['type' => 'string', 'enum' => $transitions, 'description' => 'Narrated mode: the cut INTO this scene. "none" = hard cut.'],
                    'hold_seconds' => ['type' => 'number', 'description' => 'Narrated mode: extra seconds held after the narration ends (0–8). A silent title beat: no narration + hold_seconds ≥ 1.5.'],
                    'mood' => ['type' => 'string', 'enum' => $moods],
                    'start_seconds' => ['type' => 'number', 'description' => 'Presenter mode: where this scene starts in the recording.'],
                    'end_seconds' => ['type' => 'number', 'description' => 'Presenter mode: where it ends.'],
                    'presenter_layout' => ['type' => 'string', 'enum' => McpScenes::PRESENTER_LAYOUTS, 'description' => 'Presenter mode: full | pip | split | hidden.'],
                    'pip_corner' => ['type' => 'string', 'enum' => McpScenes::PIP_CORNERS],
                    'pip_shape' => ['type' => 'string', 'enum' => McpScenes::PIP_SHAPES],
                    'split_side' => ['type' => 'string', 'enum' => ['left', 'right'], 'description' => 'Presenter side in a split (top/bottom in 9:16: left = top).'],
                ],
                ['video_id'], self::WRITE),

            $this->def('delete_scene', 'Delete a scene', 'Remove one scene.',
                ['video_id' => $videoId, 'scene_id' => ['type' => 'string']],
                ['video_id', 'scene_id'], ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]),

            $this->def('reorder_scenes', 'Reorder scenes', 'Narrated mode: set the scene order. List every scene id exactly once.',
                ['video_id' => $videoId, 'scene_ids' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ['video_id', 'scene_ids'], self::WRITE),

            $this->def('preview_scene', 'Preview a scene (see its frames)',
                'Records the scene\'s voice (narrated mode), renders frames of it through the real renderer and returns them as an IMAGE (one labelled contact sheet), with measured problems: '
                . 'text off-frame (must fix), text too small/overlapping, code that crashed (the scene fell back to a plain card), slow or empty frames. '
                . 'Also returns the real word timings. Look at every frame critically and fix what you see. Preview every custom scene before rendering.',
                [
                    'video_id' => $videoId,
                    'scene_id' => ['type' => 'string'],
                    'at' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'Points in the scene to render, 0–1 (max 6). Default [0.08, 0.35, 0.65, 0.95]. Several points come back as ONE labelled contact sheet; a single point comes back as one larger frame for close inspection.'],
                ],
                ['video_id', 'scene_id'], ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]),

            $this->def('search_media', 'Search free stock media',
                'Search free stock photos/videos (Pexels, Pixabay, Unsplash, Openverse, Wikimedia). with_thumbnails=true returns ONE numbered contact sheet of the first 9 results so you can see them. '
                . 'Then add_media the one you want.',
                [
                    'video_id' => $videoId,
                    'query' => ['type' => 'string', 'description' => '2–4 plain words, e.g. "city traffic night".'],
                    'kind' => ['type' => 'string', 'enum' => ['image', 'video'], 'description' => 'Default image.'],
                    'with_thumbnails' => ['type' => 'boolean', 'description' => 'Attach one numbered contact sheet of up to 9 thumbnails. Default false.'],
                ],
                ['video_id', 'query'], ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true]),

            $this->def('add_media', 'Add stock media to the video',
                'Download a search_media result onto the video\'s media shelf under a name. Pictures on the shelf can be drawn in ANY custom scene with '
                . '<Asset name="..." /> and used in card picture slots with "media": "<name>". Videos on the shelf work in card video slots.',
                [
                    'video_id' => $videoId,
                    'provider' => ['type' => 'string'],
                    'id' => ['type' => 'string'],
                    'query' => ['type' => 'string', 'description' => 'The exact query the result came from.'],
                    'kind' => ['type' => 'string', 'enum' => ['image', 'video']],
                    'name' => ['type' => 'string', 'description' => 'Shelf name, e.g. "ocean_wave".'],
                ],
                ['video_id', 'provider', 'id', 'query', 'name'], ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true]),

            $this->def('create_upload_link', 'Create an upload link for the user',
                'A private upload page link (valid 48 h) to GIVE THE USER: purpose "presenter" for their talking-head recording (presenter mode, up to 15 min), '
                . 'or "image" for a picture of theirs (logo, product shot, screenshot) that lands on the media shelf under `name`. '
                . 'After they upload, poll get_video (presenter.status becomes "ready" once it is transcribed).',
                [
                    'video_id' => $videoId,
                    'purpose' => ['type' => 'string', 'enum' => McpUpload::PURPOSES],
                    'name' => ['type' => 'string', 'description' => 'For images: the shelf name it will have.'],
                ],
                ['video_id', 'purpose'], self::WRITE),

            $this->def('get_transcript', 'Read the presenter transcript',
                'Presenter mode: what the user said, with timestamps. format "segments" (sentences, default) or "words" (every word with start/end). '
                . 'Page with from_seconds/to_seconds on long recordings.',
                [
                    'video_id' => $videoId,
                    'format' => ['type' => 'string', 'enum' => ['segments', 'words']],
                    'from_seconds' => ['type' => 'number'],
                    'to_seconds' => ['type' => 'number'],
                ],
                ['video_id'], self::RO),

            $this->def('render_video', 'Render the final video',
                'Render the whole video to MP4 (60 fps by default). Checks first: every scene compiles, custom scenes were previewed with their current code '
                . '(or pass force=true to have the render test them), the video is ≤ 15 minutes. Free; a few renders per day. Rendering takes minutes — then poll get_render_status.',
                ['video_id' => $videoId, 'force' => ['type' => 'boolean', 'description' => 'Render even if some custom scenes were never previewed.']],
                ['video_id'], self::WRITE),

            $this->def('get_render_status', 'Check the render',
                'Render progress; when completed, a download link for the MP4 (valid 7 days), captions (.srt), thumbnail, and the dashboard link. Poll every 1–2 minutes.',
                ['video_id' => $videoId], ['video_id'], self::RO),
        ];
    }

    public function has(string $name): bool
    {
        return method_exists($this, 'tool_' . $name) && in_array($name, array_column($this->definitions(), 'name'), true);
    }

    public function call(string $name, array $arguments, McpContext $ctx): ToolResult
    {
        return $this->{'tool_' . $name}(new Args($arguments), $ctx);
    }

    // ------------------------------------------------------------------
    // handlers
    // ------------------------------------------------------------------

    private function tool_get_guide(Args $a, McpContext $ctx): ToolResult
    {
        $topic = $a->enum('topic', Guides::TOPICS, 'workflow');

        return ToolResult::text(Guides::read((string) $topic));
    }

    private function tool_list_voices(Args $a, McpContext $ctx): ToolResult
    {
        return ToolResult::json([
            'voices' => McpCatalog::voices((int) $ctx->user->id),
            'default' => McpCatalog::DEFAULT_VOICE,
        ], 'Free voices (open a preview_url to hear one). Ask the user to pick.');
    }

    private function tool_list_music(Args $a, McpContext $ctx): ToolResult
    {
        return ToolResult::json(McpCatalog::music((int) $ctx->user->id, $a->string('category', false, 40)));
    }

    private function tool_list_styles(Args $a, McpContext $ctx): ToolResult
    {
        return ToolResult::json(McpCatalog::styles((int) $ctx->user->id));
    }

    private function tool_create_video(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->create($a);
        $out = $service->describe($project, false);

        if (McpOrigin::isPresenter($project)) {
            [, $token] = McpUpload::mint($project, 'presenter');
            $out['upload_link'] = $this->uploadUrl($token);
            $out['next'] = 'Give the user upload_link and ask them to upload their recording (up to 15 minutes). '
                . 'Poll get_video every minute until presenter.status is "ready", then read get_transcript and design scenes over it (get_guide presenter).';
        } else {
            $out['next'] = 'Plan the whole video first (scene list with one visual idea each — get_guide design), then write scenes with upsert_scene and preview each one.';
        }

        return ToolResult::json($out, "Created video {$project->id}.");
    }

    private function tool_update_video(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $service->assertEditable($project);
        $changed = $service->applySettings($project, $a);

        return ToolResult::json(['changed' => $changed, 'video' => $service->describe($project->fresh(), false)]);
    }

    private function tool_get_video(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);

        return ToolResult::json($service->describe($service->find($a->videoId())));
    }

    private function tool_list_videos(Args $a, McpContext $ctx): ToolResult
    {
        return ToolResult::json(['videos' => (new McpVideoService($ctx->user))->listVideos((int) ($a->int('limit', 1, 50) ?? 20))]);
    }

    private function tool_upsert_scene(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $result = $service->upsertScene($project, $a);
        $failed = isset($result['compile']) && !$result['compile']['ok'];

        $out = ToolResult::json($result, $failed ? 'Scene saved, but its code was REFUSED by the sandbox:' : 'Scene saved.');

        return $failed ? ToolResult::error('Scene saved, but its code was REFUSED by the sandbox — fix and upsert again.', $result) : $out;
    }

    private function tool_delete_scene(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $service->deleteScene($project, (string) $a->string('scene_id', true, 40));

        return ToolResult::json(['deleted' => true, 'scenes' => $service->describe($project->fresh())['scenes']]);
    }

    private function tool_reorder_scenes(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $service->reorder($project, (array) $a->array('scene_ids', true, 400));

        return ToolResult::json(['scenes' => $service->describe($project->fresh())['scenes']]);
    }

    private function tool_preview_scene(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $service->assertEditable($project);
        $at = $a->array('at', false, 6) ?? [];
        $at = array_values(array_filter(array_map(fn ($v) => is_numeric($v) ? (float) $v : null, $at), fn ($v) => $v !== null));

        return (new McpPreviewService())->preview($project, (string) $a->string('scene_id', true, 40), $at, (int) $ctx->user->id);
    }

    private function tool_search_media(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $query = (string) $a->string('query', true, 80);
        $kind = (string) $a->enum('kind', ['image', 'video'], 'image');
        $found = $service->searchMedia($project, $query, $kind, (bool) $a->bool('with_thumbnails', false));

        $result = ToolResult::json([
            'query' => $query,
            'kind' => $kind,
            'results' => $found['results'],
            'next' => $found['results'] === []
                ? 'Nothing found — try simpler words, or draw it in code.'
                : 'add_media with the provider, id, the same query and a shelf name.',
        ]);
        if ($found['sheet'] !== null) {
            $result->addText('Attached: ONE contact sheet of thumbnails, each tile labelled with its result number (#'
                . implode(', #', $found['sheet_numbers']) . '), left to right then top to bottom.');
            $result->addImage($found['sheet']);
        } elseif ((bool) $a->bool('with_thumbnails', false) && $found['results'] !== []) {
            $result->addText('No thumbnails could be attached this time; pick by title/size, or open thumb_url.');
        }

        return $result;
    }

    private function tool_add_media(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $item = $service->addMedia(
            $project,
            (string) $a->string('provider', true, 24),
            (string) $a->string('id', true, 120),
            (string) $a->string('query', true, 80),
            (string) $a->enum('kind', ['image', 'video'], 'image'),
            (string) $a->string('name', true, 60)
        );

        return ToolResult::json([
            'name' => $item['name'],
            'kind' => $item['kind'],
            'size' => "{$item['width']}x{$item['height']}",
            'use' => $item['kind'] === 'image'
                ? "In custom code: <Asset name=\"{$item['name']}\" style={{...}} />. In a card picture slot: \"media\": \"{$item['name']}\"."
                : "In a card video slot: \"media\": \"{$item['name']}\".",
            'credit' => $item['credit'],
        ], 'Added to the media shelf.');
    }

    private function tool_create_upload_link(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $purpose = (string) $a->enum('purpose', McpUpload::PURPOSES, null, true);
        if ($purpose === 'presenter' && !McpOrigin::isPresenter($project)) {
            throw new ToolException('This is a narrated video. Create a presenter video (create_video mode=presenter) to use a recording.');
        }
        $name = null;
        if ($purpose === 'image') {
            $name = McpScenes::mediaName((string) $a->string('name', true, 60));
        }
        [$upload, $token] = McpUpload::mint($project, $purpose, $name);

        return ToolResult::json([
            'upload_link' => $this->uploadUrl($token),
            'expires_at' => $upload->expires_at->toIso8601String(),
            'purpose' => $purpose,
            'name' => $name,
            'next' => $purpose === 'presenter'
                ? 'Give the user this link. When they have uploaded, poll get_video until presenter.status is "ready".'
                : "Give the user this link. Once uploaded, the picture is on the shelf as \"{$name}\" (check get_video).",
        ]);
    }

    private function tool_get_transcript(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());
        $presenter = (array) ($project->settings['mcp']['presenter'] ?? []);
        if (!McpOrigin::isPresenter($project) || ($presenter['status'] ?? null) !== 'ready') {
            throw new ToolException('No transcript yet (presenter status: ' . ($presenter['status'] ?? 'not a presenter video') . ').');
        }
        $from = (float) ($a->number('from_seconds', 0) ?? 0);
        $to = (float) ($a->number('to_seconds', 0) ?? (float) ($presenter['duration'] ?? 0));
        $format = (string) $a->enum('format', ['segments', 'words'], 'segments');
        $data = PresenterService::transcript($project);

        $items = [];
        if ($format === 'words') {
            foreach (PresenterService::wordsBetween($project, $from, $to, false) as $w) {
                $items[] = $w['word'] . '@' . number_format($w['start'], 2, '.', '') . '-' . number_format($w['end'], 2, '.', '');
            }
            $items = array_slice($items, 0, 1500);
            $body = ['format' => 'words (word@start-end seconds)', 'from_seconds' => $from, 'to_seconds' => $to, 'words' => implode(' ', $items)];
        } else {
            foreach ($data['segments'] as $seg) {
                if ((float) $seg['end'] > $from && (float) $seg['start'] < $to) {
                    $items[] = sprintf('[%.2f–%.2f] %s', $seg['start'], $seg['end'], $seg['text']);
                }
            }
            $body = ['format' => 'segments', 'from_seconds' => $from, 'to_seconds' => $to, 'duration' => $presenter['duration'] ?? null, 'segments' => array_slice($items, 0, 600)];
        }

        return ToolResult::json($body);
    }

    private function tool_render_video(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);
        $project = $service->find($a->videoId());

        return ToolResult::json((new McpRenderService($ctx->user))->start($project, (bool) $a->bool('force', false)), 'Render queued.');
    }

    private function tool_get_render_status(Args $a, McpContext $ctx): ToolResult
    {
        $service = new McpVideoService($ctx->user);

        return ToolResult::json((new McpRenderService($ctx->user))->status($service->find($a->videoId())));
    }

    // ------------------------------------------------------------------

    private function uploadUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/upload?t=' . $token;
    }

    /** One tool definition in MCP's shape. */
    private function def(string $name, string $title, string $description, array $properties, array $required, array $annotations): array
    {
        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return [
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'inputSchema' => $schema,
            'annotations' => array_merge(['title' => $title], $annotations),
        ];
    }
}
