<?php
// Compare clip selection across models. Usage: php shorts-model-compare.php <model> [count]
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\ClipSelectionService;
use Modules\Project\Services\ClipEditPlanService;
use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Support\LlmModels;

$model = $argv[1] ?? 'gpt-4o-mini';
$count = (int) ($argv[2] ?? 4);

$previous = AppSetting::get(LlmModels::SETTING_KEY, 'auto');
AppSetting::set(LlmModels::SETTING_KEY, $model);

$ids  = ['XRJOAv_7qnY', 'GJ1pZWtFvt0', '-Ty0HRzrY4k', 'HGD5Cx2SVvw', 'DcAHosNxz9E'];
$dir  = __DIR__ . '/shorts-cache';
$plan = new ClipEditPlanService();

echo "MODEL: " . LlmModels::for('general') . "  (requesting {$count} clips each)\n";
$grand = ['clips' => 0, 'len' => 0.0, 'spread' => [], 'time' => 0.0];

try {
    foreach ($ids as $id) {
        $t = json_decode(file_get_contents("$dir/$id.json"), true);
        $segs = $t['segments']; $dur = $t['totalDuration'];

        $t0 = microtime(true);
        try { $clips = (new ClipSelectionService())->findBestClips($segs, $dur, $count); }
        catch (\Throwable $e) { echo "  $id !! " . $e->getMessage() . "\n"; continue; }
        $el = microtime(true) - $t0;
        $grand['time'] += $el;

        $positions = [];
        printf("\n%-13s %5.0f min | %d clips | %.0fs\n", $id, $dur/60, count($clips), $el);
        foreach ($clips as $i => $c) {
            $s = $c['final_start_seconds']; $e = $c['final_end_seconds'];
            $p = $plan->plan($segs, $s, $e);
            $pos = 100*$s/$dur;
            $positions[] = $pos;
            $grand['clips']++; $grand['len'] += ($e-$s);
            printf("   %6.1f-%6.1fs  %5.1fs  sc=%3d  pos=%3.0f%%  %s\n",
                $s, $e, $e-$s, $c['final_score'], $pos, $p['is_edited'] ? 'edited' : '');
            printf("      \"%s\"\n", mb_substr(preg_replace('/\s+/',' ',trim($c['subtitle'])), 0, 95));
        }
        // spread = how much of the video the picks span
        if (count($positions) > 1) {
            $grand['spread'][] = max($positions) - min($positions);
        }
    }
} finally {
    AppSetting::set(LlmModels::SETTING_KEY, $previous);
}

printf("\n--- SUMMARY %s ---\n", $model);
printf("clips=%d  avg_len=%.1fs  avg_positional_spread=%.0f%%  total_time=%.0fs\n",
    $grand['clips'], $grand['len']/max(1,$grand['clips']),
    array_sum($grand['spread'])/max(1,count($grand['spread'])), $grand['time']);
