<?php
// Queue a Long-Video-to-Shorts project through the REAL worker path (ProcessVideoJob).
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\Project;
use Modules\Project\Jobs\ProcessVideoJob;

$url   = $argv[1];
$label = $argv[2] ?? 'real run';
$clips = (int) ($argv[3] ?? 4);

$project = Project::create([
    'user_id'       => 1,
    'title'         => "Shorts test: {$label}",
    'template_type' => 'yt_gameplay_short',
    'status'        => 'pending',
    'progress'      => 0,
    'aspect_ratio'  => '9:16',
    'settings'      => [
        'input_mode'       => 'youtube_url',
        'main_url'         => $url,
        'clip_count'       => $clips,
        'edit_style'       => 'auto_mix',
        'layout_mode'      => 'auto',
        'music_category'   => 'auto',
        'music_volume'     => 0.12,
        'captions_enabled' => true,
        'gameplay_enabled' => false,
        'gameplay_source'  => 'backend_library',
        'caption_template' => 'modern_karaoke',
        'caption_position' => 'top_section',
    ],
]);
ProcessVideoJob::dispatch($project);
echo "queued project {$project->id} ({$label})\n";
