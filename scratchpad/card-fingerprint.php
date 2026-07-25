<?php
/** card-fingerprint — one line per project: the cast, for comparing runs. */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (array_slice($argv, 1) as $id) {
    $cards = [];
    foreach (Modules\Project\Models\ExplainerScene::where('project_id', $id)->orderBy('order')->get() as $s) {
        $cards[] = str_replace('_card', '', (string) $s->layout_template);
    }
    echo str_pad("#{$id}", 6) . implode(' ', $cards) . "\n";
}
