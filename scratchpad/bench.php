<?php

/**
 * bench — run the whole corpus through the real explainer pipeline and score
 * each storyboard against the rubric written for that script.
 *
 *   docker compose exec -T app php scratchpad/bench.php --tag=baseline
 *   docker compose exec -T app php scratchpad/bench.php --only=front-doors --repeat=6
 *   docker compose exec -T app php scratchpad/bench.php --model=gpt-4.1 --tag=strong
 *
 * Item 5 of the 2026-08-31 user program. What it exercises is the SHIPPING
 * path, not a fixture: MathTopicService routes it, ScriptSkeletonService plans
 * the acts, the tree composer casts the cards, ShotListValidator repairs,
 * CanvasDirectorService directs and SceneBudgetLinter lints — exactly what
 * AnalyzeExplainerScriptJob does, minus persistence, TTS and rendering.
 *
 * Every case is written to the run file the moment it finishes, so a run that
 * dies at case 22 keeps 21 results and --resume picks it back up. Scoring is a
 * separate pass (bench/Scorer.php, bench-report.php): a run is captured once
 * and can be re-scored for free after the rubric is argued with.
 *
 * Flags:
 *   --tag=NAME        run file name (default: a timestamp)
 *   --only=a,b        run just these case ids
 *   --skip=a,b        run everything except these
 *   --limit=N         first N cases
 *   --repeat=N        run each case N times (stochasticity; casting rates)
 *   --model=NAME      override the LLM for this run (restored on exit)
 *   --no-director     skip the canvas director call (cheaper; flights unscored)
 *   --aspect=16:9     aspect ratio to validate/direct for
 *   --resume          skip case ids already present in the run file
 *   --quiet           one line per case instead of the finding list
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/bench/Scorer.php';

use Bench\Scorer;
use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Services\CanvasDirectorService;
use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\MathStoryboardComposerService;
use Modules\Project\Services\MathTopicService;
use Modules\Project\Services\ScriptAnalysisService;
use Modules\Project\Services\ScriptSkeletonService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\LlmModels;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

// ------------------------------------------------------------------ flags --

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $mm)) {
        $opt[$mm[1]] = $mm[2] ?? true;
    }
}
$tag = (string) ($opt['tag'] ?? date('Ymd-His'));
$repeat = max(1, (int) ($opt['repeat'] ?? 1));
$aspect = (string) ($opt['aspect'] ?? '16:9');
$withDirector = !isset($opt['no-director']);
$quiet = isset($opt['quiet']);

$cases = require __DIR__ . '/bench/corpus.php';
if (!empty($opt['only'])) {
    $want = array_map('trim', explode(',', (string) $opt['only']));
    $cases = array_values(array_filter($cases, fn ($c) => in_array($c['id'], $want, true)));
}
if (!empty($opt['skip'])) {
    $no = array_map('trim', explode(',', (string) $opt['skip']));
    $cases = array_values(array_filter($cases, fn ($c) => !in_array($c['id'], $no, true)));
}
if (!empty($opt['limit'])) {
    $cases = array_slice($cases, 0, (int) $opt['limit']);
}
if ($cases === []) {
    exit("no cases selected\n");
}

// ------------------------------------------------------- model override ----

$restoreModel = null;
if (!empty($opt['model'])) {
    $model = (string) $opt['model'];
    if (!array_key_exists($model, LlmModels::MODELS)) {
        exit("unknown model '{$model}'; pick one of: " . implode(', ', array_keys(LlmModels::MODELS)) . "\n");
    }
    $restoreModel = (string) AppSetting::get(LlmModels::SETTING_KEY, LlmModels::AUTO);
    AppSetting::set(LlmModels::SETTING_KEY, $model);
    // The override is global and persisted — put it back whatever happens,
    // or the next person's dashboard is silently running on the bench's model.
    register_shutdown_function(function () use ($restoreModel) {
        AppSetting::set(LlmModels::SETTING_KEY, $restoreModel);
    });
}

$runPath = __DIR__ . '/bench/runs/' . $tag . '.json';
$run = ['tag' => $tag, 'started_at' => date('c'), 'aspect' => $aspect,
    'model' => LlmModels::selected(), 'explainer_model' => LlmModels::for('explainer'),
    'director_model' => LlmModels::for('director'), 'with_director' => $withDirector,
    'repeat' => $repeat, 'cases' => []];
if (isset($opt['resume']) && is_file($runPath)) {
    $prior = json_decode((string) file_get_contents($runPath), true);
    if (is_array($prior['cases'] ?? null)) {
        $run = $prior;
        $done = array_column($run['cases'], 'id');
        $counted = array_count_values($done);
        $cases = array_values(array_filter($cases, fn ($c) => ($counted[$c['id']] ?? 0) < $repeat));
        echo 'resuming ' . $tag . ': ' . count($run['cases']) . " results already recorded\n";
    }
}

printf("bench %s — %d case(s) x%d, model %s, director %s\n\n",
    $tag, count($cases), $repeat, LlmModels::for('explainer'), $withDirector ? 'on' : 'off');

$t0 = microtime(true);
foreach ($cases as $case) {
    for ($pass = 1; $pass <= $repeat; $pass++) {
        $label = $case['id'] . ($repeat > 1 ? " #{$pass}" : '');
        $started = microtime(true);
        try {
            $result = runCase($case, $aspect, $withDirector);
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];
        }
        $result['id'] = $case['id'];
        $result['pass'] = $pass;
        $result['seconds'] = round(microtime(true) - $started, 1);

        if (isset($result['error'])) {
            printf("  %-22s FAILED  %s\n", $label, $result['error']);
            $run['cases'][] = $result;
            save($runPath, $run);
            continue;
        }

        $scored = Scorer::score($case, $result);
        $result['score'] = $scored;
        $run['cases'][] = $result;
        save($runPath, $run);

        $m = $scored['metrics']['validated'];
        printf("  %-22s %5.1f/100  %2d scenes  %2d cards  media %2.0f%%  %3.0fs  %s\n",
            $label, $scored['total'], $m['count'], count($m['templates']),
            $m['media_share'] * 100, $m['total_seconds'],
            $result['shape'] . ($result['math_mode'] ? ' [math]' : ''));
        if (!$quiet) {
            foreach ($scored['findings'] as $f) {
                if ($f['severity'] === 'info') {
                    continue;
                }
                printf("      %-5s %-18s %s\n", $f['severity'], $f['code'], $f['message']);
            }
        }
    }
}

$run['finished_at'] = date('c');
$run['duration_seconds'] = round(microtime(true) - $t0, 1);
save($runPath, $run);

$scores = array_values(array_filter(array_map(
    fn ($c) => $c['score']['total'] ?? null,
    $run['cases']
), fn ($v) => $v !== null));
printf("\n%d scored, mean %.1f/100, %.0f min. Run file: scratchpad/bench/runs/%s.json\n",
    count($scores), $scores ? array_sum($scores) / count($scores) : 0,
    $run['duration_seconds'] / 60, $tag);
echo "Report:  docker compose exec -T app php scratchpad/bench-report.php {$tag}\n";

// ------------------------------------------------------------- the pipeline --

/**
 * One case through the shipping pipeline. Mirrors AnalyzeExplainerScriptJob
 * up to the lint report; persistence, theming, TTS and rendering are not part
 * of what a storyboard bench measures.
 */
function runCase(array $case, string $aspect, bool $withDirector): array
{
    $script = (string) $case['script'];
    $title = (string) $case['title'];
    $guide = (string) ($case['guide'] ?? '');
    $target = (int) ($case['target_seconds'] ?? 60);

    // 1. Routing.
    $mathTopic = [];
    try {
        $mathTopic = (new MathTopicService())->classify($title, $script);
    } catch (\Throwable $e) {
        // "not maths" is the pre-existing behaviour on any failure.
    }

    // 2. The acts.
    $planner = new ScriptSkeletonService();
    $skeleton = $mathTopic !== []
        ? $planner->plan($script, $mathTopic, $guide)
        : $planner->planGeneric($script, $guide, $target);

    // 3. Casting + content.
    $raw = null;
    $attempts = 0;
    $composedByTree = false;
    if ($skeleton !== [] && (bool) config('services.openai.explainer_tree', true)) {
        $kind = $mathTopic['kind'] ?? null;
        if ($kind === 'worked_problem' || $kind === 'proof_concept') {
            $composer = (new MathStoryboardComposerService())->setGuide($guide);
            $raw = $kind === 'worked_problem'
                ? $composer->compose($script, $skeleton, $mathTopic, $title)
                : $composer->composeProof($script, $skeleton, $mathTopic, $title);
        } else {
            $composer = (new GenericStoryboardComposerService())->setGuide($guide);
            $raw = $composer->compose($script, $skeleton, $target);
        }
        if ($raw !== null) {
            $composedByTree = true;
            $attempts = $composer->attempts();
        }
    }
    if ($raw === null) {
        $analysis = new ScriptAnalysisService();
        $raw = $analysis->analyze($script, [
            'aspect_ratio' => $aspect,
            'target_seconds' => $target,
            'math_topic' => $mathTopic,
            'skeleton' => $skeleton,
            'guide' => $guide,
            'title' => $title,
        ]);
        $attempts = $analysis->attempts();
    }
    $rawScenes = (array) ($raw['scenes'] ?? []);

    // 4. Repair. The bench scores the storyboard BODY: the cold-open lift and
    // the end card are packaging the user toggles, and counting them would
    // move every scene-count rubric by two for no editorial reason.
    // The aspect rides on the shot list itself (ShotListValidator reads
    // $shotList['aspect_ratio'], not the options), and the camera-default
    // picker keys off it.
    $raw['aspect_ratio'] = $aspect;
    $validated = (new ShotListValidator())->validate($raw, [
        'hook_enabled' => false,
        'outro_enabled' => false,
        'outro_title' => $title,
        'math_mode' => $mathTopic !== [],
    ]);
    $scenes = (array) ($validated['scenes'] ?? []);

    // 5. The journey.
    $planItems = [];
    if ($withDirector && $scenes !== []) {
        $planItems = (array) ((new CanvasDirectorService())->direct($scenes, $aspect)['items'] ?? []);
    }

    // 6. The quality gate.
    $lint = SceneBudgetLinter::lint($scenes, [
        'theme' => ExplainerRegistry::colorScheme(null),
        'hook_enabled' => false,
        'outro_enabled' => false,
        'math_mode' => $mathTopic !== [],
    ]);

    return [
        'title' => $title,
        'shape' => inferShape($skeleton, $mathTopic),
        'math_mode' => $mathTopic !== [],
        'math_topic' => $mathTopic,
        'skeleton' => $skeleton,
        'attempts' => $attempts,
        'composed_by_tree' => $composedByTree,
        'raw_scenes' => $rawScenes,
        'scenes' => $scenes,
        'validator_warnings' => (array) ($validated['warnings'] ?? []),
        'plan_items' => array_map(fn ($it) => [
            'scene_id' => $it['scene_id'] ?? null,
            'treatment' => $it['treatment'] ?? null,
            'relation' => $it['relation'] ?? null,
            'camera_move' => $it['camera_move'] ?? ($it['hold_move'] ?? null),
        ], $planItems),
        'lint' => $lint,
    ];
}

/**
 * The skeleton planner returns phases, not the shape it chose — so recover it
 * from the intents. An exact subset of exactly one shape's phase list is that
 * shape; anything else is reported as what it is.
 */
function inferShape(array $skeleton, array $mathTopic): string
{
    if ($mathTopic !== []) {
        return (string) ($mathTopic['kind'] ?? 'math');
    }
    if ($skeleton === []) {
        return 'none';
    }
    $intents = array_values(array_unique(array_map(
        fn ($p) => (string) ($p['intent'] ?? ''),
        $skeleton
    )));
    $hits = [];
    foreach (ScriptSkeletonService::GENERIC_SHAPES as $shape => $order) {
        if (array_diff($intents, $order) === []) {
            // Prefer the tightest shape that contains every intent used.
            $hits[$shape] = count($order);
        }
    }
    if ($hits === []) {
        return 'mixed';
    }
    asort($hits);

    return (string) array_key_first($hits);
}

function save(string $path, array $run): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
