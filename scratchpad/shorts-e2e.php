<?php
// Create a Long-Video-to-Shorts project and run it SYNCHRONOUSLY, so the whole
// pipeline (download -> transcribe -> select -> cut -> compose) is exercised.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\Project;
use Modules\Project\Processors\YTGameplayShortProcessor;
use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Support\LlmModels;

$url      = $argv[1] ?? 'https://www.youtube.com/watch?v=-Ty0HRzrY4k';
$clips    = (int) ($argv[2] ?? 3);
$gameplay = ($argv[3] ?? '1') === '1';
$model    = $argv[4] ?? null;

if ($model) { AppSetting::set(LlmModels::SETTING_KEY, $model); }

$user = \Modules\User\Models\User::first();

$project = Project::create([
    'user_id'       => $user->id,
    'title'         => 'E2E shorts test ' . date('H:i:s'),
    'template_type' => 'yt_gameplay_short',
    'status'        => 'pending',
    'progress'      => 0,
    'aspect_ratio'  => '9:16',
    'settings'      => [
        'input_mode'       => 'youtube_url',
        'main_url'         => $url,
        'clip_count'       => $clips,
        'gameplay_enabled' => $gameplay,
        'gameplay_source'  => 'backend_library',
        'caption_template' => 'modern_karaoke',
        'caption_position' => 'top_section',
    ],
]);

echo "project_id={$project->id}  url={$url}  clips={$clips}  gameplay=" . ($gameplay ? 'on' : 'off')
   . "  model=" . LlmModels::for('general') . "\n";

$t0 = microtime(true);
$ok = (new YTGameplayShortProcessor($project))->process();
printf("result=%s  in %.0fs\n", $ok ? 'OK' : 'FAILED', microtime(true) - $t0);

$project->refresh();
echo "status={$project->status}  failed_step={$project->failed_step}\n";
echo "error=" . substr((string) $project->error_message, 0, 300) . "\n";
echo json_encode($project->processing_state['output_videos'] ?? [], JSON_PRETTY_PRINT) . "\n";
