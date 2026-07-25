<?php
/** beats-check — do the beats a maths video PROMISES actually appear, run after run? */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Services\MathTopicService;

$miss = 0;
foreach (array_slice($argv, 1) as $id) {
    $p = Project::find($id);
    $tpl = [];
    foreach (ExplainerScene::where('project_id', $id)->orderBy('order')->get() as $s) {
        $tpl[] = (string) $s->layout_template;
    }
    $has = fn (string $t) => in_array($t, $tpl, true);
    $physical = MathTopicService::looksPhysical($p->title . ' ' . ($p->settings['script'] ?? ''));
    $proof = str_contains(strtolower($p->title), 'why does');

    $want = ['working' => $has('math_steps')];
    if (!$proof) {
        $want['idea/formula beat'] = $has('formula_anatomy') || $has('single_focus');
        $want['visual answer'] = $has('geometry_diagram') || $has('function_plot');
    }
    if ($physical && !$proof) {
        $want['situation drawn'] = $has('scenario_diagram');
    }
    $bad = array_keys(array_filter($want, fn ($v) => !$v));
    $miss += count($bad);
    printf("#%-4s %-9s %s\n", $id, $bad ? 'MISSING' : 'ok', $bad ? implode(', ', $bad) : implode(' ', array_map(fn ($t) => str_replace('_card', '', $t), $tpl)));
}
echo $miss === 0 ? "\nevery promised beat present\n" : "\n{$miss} missing beat(s)\n";
