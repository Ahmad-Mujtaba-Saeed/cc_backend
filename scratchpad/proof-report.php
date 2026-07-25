<?php
/** proof-report — did the proof path hold: tree, figures, and no unfillable slots? */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;

$bad = 0;
foreach (array_slice($argv, 1) as $id) {
    $p = Project::find($id);
    $s = $p->settings;
    $tpl = [];
    $media = 0;
    foreach (ExplainerScene::where('project_id', $id)->orderBy('order')->get() as $sc) {
        $tpl[] = str_replace('_card', '', (string) $sc->layout_template);
        foreach ((array) $sc->slots as $sl) {
            if (in_array($sl['content_type'] ?? '', ['image', 'video'], true)) {
                $media++;
            }
        }
    }
    $figures = count(array_filter($tpl, fn ($t) => in_array($t, ['geometry_diagram', 'function_plot', 'math_steps'], true)));
    $problems = [];
    if ($media > 0) {
        $problems[] = "{$media} unfillable media slot(s)";
    }
    if ($figures < 2) {
        $problems[] = "only {$figures} figure(s)";
    }
    $bad += count($problems);
    printf("#%-4s tree=%-3s mode=%-10s figs=%d  %s\n    %s\n", $id,
        !empty($s['composed_by_tree']) ? 'YES' : 'no',
        $s['composition_mode'] ?? 'slides', $figures,
        $problems ? 'PROBLEM: ' . implode(', ', $problems) : 'ok',
        implode(' ', $tpl));
}
echo $bad === 0 ? "\nproof path clean\n" : "\n{$bad} problem(s)\n";
