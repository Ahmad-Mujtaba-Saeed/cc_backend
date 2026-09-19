<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Modules\Project\Services\RapidApiTranscriptionService;
use Modules\Project\Services\Shorts\{VideoBriefService, ShortPlaybook};
foreach (array_slice($argv, 1) as $id) {
    $t = (new RapidApiTranscriptionService())->transcribeByVideoId($id);
    $b = (new VideoBriefService())->build("(title unknown) $id", $t['segments'], $t['totalDuration']);
    echo "== $id ==\n", VideoBriefService::describe($b, $id), "\nplaybook: ", ShortPlaybook::key($b['format'] ?? '', 'other', false), "\n\n";
}
