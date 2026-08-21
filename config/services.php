<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'public_key' => env('FIREBASE_PUBLIC_KEY'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        // NOTE: every *_model value below is the per-role DEFAULT. An admin can
        // override all of them at once from Settings → AI model, which is stored
        // as the `llm_model` AppSetting and resolved by Support\LlmModels. These
        // apply whenever that switch is left on "auto".
        // Model used by the explainer script-analysis "brain".
        'explainer_model' => env('OPENAI_EXPLAINER_MODEL', 'gpt-4o-mini'),
        // Escalation valve for maths videos (roadmap 3a): classified maths
        // analysis, skeleton planning, the tree composers and the visual
        // synthesis calls run on this while ordinary explainers stay on the
        // cheap model. Defaults to gpt-5-nano (iter 31): cheaper than
        // 4o-mini AND a reasoning model — the correctness-critical calls
        // are exactly where a beat of reasoning pays (project 91's "≤40"
        // schema echo came from the 4o-mini tree_proof call). GPT-5 payload
        // differences are absorbed by LlmModels::tune() at these call
        // sites. Set to "" to fall back to the explainer model.
        'explainer_model_math' => env('OPENAI_EXPLAINER_MODEL_MATH', 'gpt-5-nano'),
        // The storyboard text-review pass (iter 6) is pure verbatim-quote
        // nomination behind hard guards — reasoning-model precision at a
        // third of the input price. Same tune() shim applies.
        'text_review_model' => env('OPENAI_TEXT_REVIEW_MODEL', 'gpt-5-nano'),
        // The planning tree (roadmap §2): worked problems composed by
        // skeleton-cast focused calls instead of the giant analyze call.
        // Set EXPLAINER_TREE=false to force the legacy path.
        'explainer_tree' => env('EXPLAINER_TREE', true),
        // Text-side quality pass (roadmap 3b): one cheap read of the final
        // narration for meta-talk, screen descriptions, repeats and
        // placeholder junk. EXPLAINER_TEXT_PASS=false disables it.
        'explainer_text_pass' => env('EXPLAINER_TEXT_PASS', true),
        // Geometry figure synthesis (iter 40): a focused per-scene call that
        // REBUILDS a geometry_diagram whose slot came back thin — a bare shape
        // name with no points/labels/marks while the narration clearly
        // describes a labelled figure (the Thales bug: "circle, diameter AB,
        // point C, triangle ABC" rendering as a lone circle + radius).
        // EXPLAINER_GEOMETRY_SYNTH=false disables it.
        'explainer_geometry_synth' => env('EXPLAINER_GEOMETRY_SYNTH', true),
        // The canvas director benefits from a stronger model: one call per
        // analyze, and mini reliably ignores the relation/variety directives.
        'director_model' => env('OPENAI_DIRECTOR_MODEL', 'gpt-4o'),
        // Speech model for the optional OpenAI narration engine (admin-switchable
        // against self-hosted Kokoro; styled via per-template instructions).
        'tts_model' => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
        // Post-render VLM frame review (explainer §12.4): samples 6 frames and
        // asks a vision model to flag gibberish AI text / empty layouts. One
        // cheap call per render (~$0.01); admin kill-switch via env, per-project
        // override via settings['vlm_review_enabled'].
        'vlm_review' => env('OPENAI_VLM_REVIEW', true),
        'vlm_model' => env('OPENAI_VLM_MODEL', 'gpt-4o-mini'),
        // Non-explainer templates: gameplay clip selection and script
        // generation. Previously hardcoded to gpt-4o-mini.
        'general_model' => env('OPENAI_GENERAL_MODEL', 'gpt-4o-mini'),
    ],

    // Node/Remotion render service for the AI explainer template.
    'remotion' => [
        'url' => env('REMOTION_SERVICE_URL', 'http://localhost:3020'),
        'timeout' => env('REMOTION_SERVICE_TIMEOUT', 1800), // 30 min
        // Public base URL the render service uses to fetch uploaded assets.
        // Defaults to APP_URL when null.
        'asset_base_url' => env('REMOTION_ASSET_BASE_URL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Python AI Service Configuration
    'python_ai' => [
        'url' => env('PYTHON_AI_SERVICE_URL', 'http://ai:8000'),
        'timeout' => env('PYTHON_AI_SERVICE_TIMEOUT', 3600), // 1 hour for video processing
        'retry_attempts' => 3,
        'retry_delay' => 5, // seconds
        'image_generation_endpoint' => env('PYTHON_AI_IMAGE_GENERATION_ENDPOINT', '/generate-images'),
    ],

    // Kokoro TTS (self-hosted in the Python ai service) — free, used for
    // explainer narration instead of the paid fal Chatterbox endpoint.
    // af_bella is widely considered the most natural Kokoro voice.
    'kokoro' => [
        'voice' => env('KOKORO_VOICE', 'af_bella'),
    ],

    'fal_ai' => [
        'url' => env('FAL_AI_SERVICE_URL', 'http://fal:8000'),
        'image_generation_endpoint' => env('FAL_AI_IMAGE_GENERATION_ENDPOINT', '/xai/grok-imagine-image'),
        'auth_token' => env('FAL_AI_AUTH_TOKEN'),
        'timeout' => env('FAL_AI_SERVICE_TIMEOUT', 3600), // 1 hour for video processing

        // Chatterbox TTS (narration) — synchronous fal endpoint.
        'tts_endpoint' => env('FAL_TTS_ENDPOINT', 'https://fal.run/fal-ai/chatterbox/text-to-speech'),
        // Optional reference voice (mp3/wav URL) to clone; blank = Chatterbox default voice.
        'tts_reference_audio' => env('FAL_TTS_REFERENCE_AUDIO'),
        'tts_exaggeration' => (float) env('FAL_TTS_EXAGGERATION', 0.25),
        'tts_temperature' => (float) env('FAL_TTS_TEMPERATURE', 0.7),
        'tts_cfg' => (float) env('FAL_TTS_CFG', 0.5),
    ],

    // Cloudflare R2 Configuration
    'r2' => [
        'endpoint' => env('R2_ENDPOINT'),
        'url' => env('R2_URL'),
        'bucket' => env('R2_BUCKET_NAME'),
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'auto'),
    ],

    // RapidAPI Configuration for YouTube Video Download + Transcription
    'rapidapi' => [
        'key' => env('RAPIDAPI_KEY'),
        'download_host' => env('RAPIDAPI_DOWNLOAD_HOST', 'youtube-info-download-api.p.rapidapi.com'),
        'transcribe_host' => env('RAPIDAPI_TRANSCRIBE_HOST', 'youtube-transcriber11.p.rapidapi.com'),
    ],

    // Apify Configuration — alternative YouTube downloader (truefetch/youtube-video-downloader actor)
    'apify' => [
        'token' => env('APIFY_TOKEN'),
        'youtube_actor' => env('APIFY_YOUTUBE_ACTOR', 'truefetch/youtube-video-downloader'),
        'video_quality' => env('APIFY_VIDEO_QUALITY', 'medium'),
    ],

    // Which provider downloads YouTube videos by default when no admin override is stored.
    // Admins can switch this at runtime via the app settings UI (manage-settings permission).
    'youtube_downloader' => [
        'default' => env('YOUTUBE_DOWNLOADER', 'rapidapi'), // rapidapi | apify
    ],

    // Background-music source. Only the fallback for a fresh install — the
    // live value is the admin's `music_provider` app_setting.
    'music' => [
        'default' => env('MUSIC_PROVIDER', 'pixabay'), // pixabay | jamendo
    ],

];
