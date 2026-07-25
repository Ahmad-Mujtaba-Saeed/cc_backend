<?php

/**
 * gpt5-check — the gpt-5-nano adoption (loop iter 31): the tune() compat
 * shim, the math-valve + text-review defaults, the vision guard, and the
 * CostTracker bucket. Ends with a LIVE smoke call (one tiny tuned request)
 * when an API key is configured — skipped, never failed, without one.
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/gpt5-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Modules\Project\Services\CostTracker;
use Modules\Project\Support\LlmModels;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

// ---- 1. tune(): the GPT-5 contract differences, absorbed ------------------
$p = LlmModels::tune([
    'model' => 'gpt-5-nano',
    'messages' => [],
    'temperature' => 0.2,
    'max_tokens' => 400,
    'response_format' => ['type' => 'json_object'],
], 'low');
check('gpt-5: temperature stripped', !array_key_exists('temperature', $p));
check('gpt-5: max_tokens renamed + low-effort headroom added (400+3072)', !isset($p['max_tokens']) && $p['max_completion_tokens'] === 3472);
check('gpt-5: reasoning_effort applied', $p['reasoning_effort'] === 'low');
check('gpt-5: response_format survives', ($p['response_format']['type'] ?? '') === 'json_object');

$p = LlmModels::tune(['model' => 'gpt-5-nano', 'max_tokens' => 8000]);
check('gpt-5: minimal headroom on top of large budgets (8000+1024)', $p['max_completion_tokens'] === 9024);
check('gpt-5: default effort is minimal', $p['reasoning_effort'] === 'minimal');
$p = LlmModels::tune(['model' => 'gpt-5-nano', 'reasoning_effort' => 'high']);
check('gpt-5: explicit reasoning_effort wins', $p['reasoning_effort'] === 'high');

$orig = ['model' => 'gpt-4o-mini', 'temperature' => 0.0, 'max_tokens' => 80];
check('gpt-4o-mini payload is byte-identical (no-op)', LlmModels::tune($orig, 'low') === $orig);

// ---- 2. Model routing ------------------------------------------------------
check('math valve defaults to gpt-5-nano', LlmModels::for('math') === 'gpt-5-nano');
check('explainer role stays gpt-4o-mini', LlmModels::for('explainer') === 'gpt-4o-mini');
check('vlm role stays gpt-4o-mini (vision guard)', LlmModels::for('vlm') === 'gpt-4o-mini');
check('text review model resolves gpt-5-nano', (string) config('services.openai.text_review_model') === 'gpt-5-nano');
check('gpt-5 models are NOT in the admin global whitelist', !array_key_exists('gpt-5-nano', LlmModels::MODELS));

// The services that must ride the valve name it in their constructors.
foreach (['MathStoryboardComposerService', 'MathVisualSynthesisService', 'MathTopicService', 'ScriptSkeletonService'] as $svc) {
    $src = file_get_contents(__DIR__ . "/../modules/Project/Services/{$svc}.php");
    check("{$svc} rides the math valve", str_contains($src, "LlmModels::for('math')"));
    check("{$svc} tunes its payload(s)", str_contains($src, 'LlmModels::tune('));
}
$src = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php');
check('ScriptAnalysisService tunes its payload', str_contains($src, 'LlmModels::tune('));
$src = file_get_contents(__DIR__ . '/../modules/Project/Services/StoryboardTextReviewService.php');
check('StoryboardTextReviewService tunes its payload', str_contains($src, 'LlmModels::tune('));

// ---- 3. Cost bucket ---------------------------------------------------------
$rates = CostTracker::rates();
check('nano rates present ($0.05 / $0.40)', ($rates['gpt_5_nano_input_per_1m'] ?? 0) == 0.05 && ($rates['gpt_5_nano_output_per_1m'] ?? 0) == 0.40);
$src = file_get_contents(__DIR__ . '/../modules/Project/Services/CostTracker.php');
check('recordChat buckets gpt-5* before the mini/full split', str_contains($src, "str_starts_with(\$model, 'gpt-5')"));

// ---- 4. Malformed-output resilience (the first real nano run) --------------
// gpt-5-nano sent a geometry `shape` as an ARRAY; the bare (string) cast
// threw and sank the whole analyze job. Pinned: the field guard degrades the
// figure, and the per-scene try/catch means a clamp that throws costs ONE
// scene, never the video.
$v = new \Modules\Project\Support\ShotListValidator();
$out = $v->validate(['scenes' => [[
    'scene_id' => 's1', 'layout_template' => 'geometry_diagram', 'duration_seconds' => 8,
    'narration' => ['text' => 'Crash payload from the first nano run.'],
    'slots' => ['slot_geometry' => ['content_type' => 'geometry', 'shape' => ['coordinate_plane']]],
]]], ['math_mode' => true]);
check('array-shaped `shape` degrades instead of throwing', ($out['scenes'][0]['layout_template'] ?? '') === 'single_focus');
$src = file_get_contents(__DIR__ . '/../modules/Project/Support/ShotListValidator.php');
check('per-scene try/catch backstop in the validate loop', str_contains($src, 'validation threw'));

// ---- 5. Live smoke ----------------------------------------------------------
$key = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
if (empty($key)) {
    echo "  --  live smoke skipped (no API key in this environment)\n";
} else {
    $payload = LlmModels::tune([
        'model' => 'gpt-5-nano',
        'messages' => [
            ['role' => 'system', 'content' => 'Return ONLY JSON: {"ok": true, "sum": <the sum>}'],
            ['role' => 'user', 'content' => 'Sum 17 and 25.'],
        ],
        'temperature' => 0.0,
        'max_tokens' => 60,
        'response_format' => ['type' => 'json_object'],
    ]);
    try {
        $r = Http::withToken($key)->timeout(60)->post('https://api.openai.com/v1/chat/completions', $payload);
        check('live: gpt-5-nano accepts the tuned payload (HTTP 200)', $r->successful());
        $parsed = json_decode((string) $r->json('choices.0.message.content'), true);
        check('live: JSON answer parses and is correct', is_array($parsed) && (int) ($parsed['sum'] ?? 0) === 42);
        $usage = $r->json('usage');
        check('live: usage block present for CostTracker', is_array($usage) && ($usage['completion_tokens'] ?? 0) > 0);
        echo '      usage: ', json_encode($usage), "\n";
    } catch (\Throwable $e) {
        check('live smoke call', false);
        echo '      error: ', $e->getMessage(), "\n";
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
