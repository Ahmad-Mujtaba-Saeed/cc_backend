<?php

/*
|--------------------------------------------------------------------------
| Claude / MCP video studio
|--------------------------------------------------------------------------
|
| The MCP server (modules/Mcp) lets a user's own LLM — Claude Desktop, Claude
| Code, claude.ai connectors, any MCP client — build an explainer video with
| our tools: it writes every scene (as Remotion code in the hero sandbox, or
| as one of our cards), looks at rendered preview frames, and renders the MP4.
|
| The user's model does all the thinking, so an MCP video costs us no LLM or
| image-model money. That is the deal that keeps it free: paid features
| (AI pictures, the flow card's drawings, paid voices) are simply not offered
| over MCP. What we still spend is CPU — rendering — hence the quotas below.
*/

return [
    'enabled' => env('MCP_ENABLED', true),

    // What the server calls itself in the MCP `initialize` handshake.
    'server_name' => env('MCP_SERVER_NAME', 'vreato-video-studio'),
    'server_title' => env('MCP_SERVER_TITLE', 'Vreato Video Studio'),
    'server_version' => '1.0.0',

    // The public base URL clients connect to (shown on the Connect page).
    // Defaults to APP_URL; set it when the API sits behind another host.
    'public_url' => env('MCP_PUBLIC_URL', env('APP_URL', 'http://localhost:8086')),

    // Hard ceiling on a video's length: 15 minutes.
    'max_video_seconds' => (int) env('MCP_MAX_VIDEO_SECONDS', 900),

    // Ceilings that keep one request from becoming a denial of service.
    'max_scenes' => (int) env('MCP_MAX_SCENES', 160),
    'max_code_chars' => 60000,
    'max_narration_chars' => 1500,
    'max_videos_in_progress' => (int) env('MCP_MAX_DRAFTS', 30),
    'max_tokens_per_user' => 10,

    // Renders are the expensive part (CPU minutes on the render host).
    'renders_per_day' => (int) env('MCP_RENDERS_PER_DAY', 5),
    // Frame previews are cheap but not free (one headless still each).
    'previews_per_hour' => (int) env('MCP_PREVIEWS_PER_HOUR', 150),

    // 60 fps by default — the smoothest the renderer can go.
    'default_fps' => (int) env('MCP_DEFAULT_FPS', 60),

    // Paid narration (gpt-4o-mini-tts) is off: MCP videos speak with the free
    // self-hosted Kokoro voices, or the user's own cloned voice.
    'allow_paid_voices' => (bool) env('MCP_ALLOW_PAID_VOICES', false),

    // Where MCP work runs. Renders and recording prep go on their own queue,
    // drained by the `mcp-worker` container (docker-compose.yml), never by
    // the worker that renders the paid templates.
    'queue' => [
        'connection' => env('MCP_QUEUE_CONNECTION', 'mcp'),
        'name' => env('MCP_QUEUE', 'mcp'),
    ],

    // Optional: a SEPARATE render server for MCP videos (another Remotion
    // process, or another machine with the storage mounted), so studio
    // renders don't compete with paid renders for CPU. Unset = the main one
    // (services.remotion.*). The asset base is where that server fetches
    // storage files from.
    'render_url' => env('MCP_REMOTION_URL'),
    'asset_base_url' => env('MCP_REMOTION_ASSET_BASE_URL'),

    // A render still queued after this long is treated as lost (the queue was
    // flushed) and may be requested again.
    'queued_ttl_hours' => 24,

    // Cleanup (php artisan mcp:prune, scheduled daily): presenter frame
    // strips are GBs each and are re-extracted on demand, so they go once a
    // video has been left alone this long. Preview stills likewise.
    'prune' => [
        'frames_after_days' => (int) env('MCP_PRUNE_FRAMES_DAYS', 7),
        'previews_after_days' => 3,
        'uploads_after_hours' => 72,
    ],

    // The presenter recording (a talking-head video the user speaks the
    // script in): uploaded in chunks through a signed link.
    'upload' => [
        'max_bytes' => (int) env('MCP_UPLOAD_MAX_BYTES', 4 * 1024 * 1024 * 1024),
        'chunk_bytes' => 8 * 1024 * 1024,
        'ttl_hours' => 48,
        'image_max_bytes' => 25 * 1024 * 1024,
    ],
];
