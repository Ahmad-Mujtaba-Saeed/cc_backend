<?php

/**
 * Explainer credit lifecycle check — runs inside ONE rolled-back transaction
 * with the queue faked, so it leaves no rows and dispatches no jobs.
 *
 *   docker compose exec app php scratchpad/explainer-billing-check.php
 *
 * Covers: tier pricing + limits, storyboard charge on create (HTTP controller),
 * refund when analysis never delivered, free first render, paid re-render,
 * AI-visuals consent gate, per-render picture billing + partial refund, the
 * double-render claim, and refund idempotency.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Billing\Services\CreditService;
use Modules\Project\Http\Controllers\ExplainerController;
use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;
use Modules\Project\Support\ExplainerBilling;
use Modules\User\Models\User;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS ' : '  FAIL ') . $label . ($detail !== '' ? "  [{$detail}]" : '') . PHP_EOL;
}

Queue::fake();
DB::beginTransaction();

try {
    $user = User::where('email', 'admin@admin.com')->first();
    if (!$user || !$user->hasActiveSubscription()) {
        throw new RuntimeException('admin@admin.com needs an active subscription (run AdminSubscriberSeeder).');
    }
    auth()->setUser($user);
    $credits = app(CreditService::class);
    $credits->syncDailyGrant($user);
    $user->forceFill(['credits' => 1000])->save();
    $balance = fn () => (int) $user->fresh()->credits;
    $controller = app(ExplainerController::class);
    $json = fn ($response) => [$response->getStatusCode(), $response->getData(true)];

    echo "Tiers\n";
    check('5 min = 100', ExplainerBilling::storyboardCost('short') === 100);
    check('10 min = 250', ExplainerBilling::storyboardCost('medium') === 250);
    check('15 min = 350', ExplainerBilling::storyboardCost('long') === 350);
    check('max length 900s', ExplainerBilling::maxSeconds() === 900);
    check('420s infers medium', ExplainerBilling::tierForSeconds(420) === 'medium');

    echo "Storyboard charge (HTTP controller)\n";
    [$code, $body] = $json($controller->store(Request::create('/x', 'POST', [
        'title' => 'Billing check', 'script' => str_repeat('A sentence of script. ', 5),
        'duration_tier' => 'short', 'target_seconds' => 600,
    ])));
    check('600s refused on the 5-min tier', $code === 422, "code {$code}");
    check('refusal charged nothing', $balance() === 1000);

    [$code, $body] = $json($controller->store(Request::create('/x', 'POST', [
        'title' => 'Billing check', 'script' => str_repeat('A sentence of script. ', 5),
        'duration_tier' => 'medium', 'target_seconds' => 540,
    ])));
    check('created on the 10-min tier', $code === 201, "code {$code}");
    check('charged 250 for the storyboard', $balance() === 750, 'balance ' . $balance());
    $project = Project::find($body['data']['id']);
    check('tier stored on the project', ($project->settings['duration_tier'] ?? null) === 'medium');
    check('analysis job queued', Queue::pushed(\Modules\Project\Jobs\AnalyzeExplainerScriptJob::class)->count() === 1);

    ExplainerBilling::refundStoryboardIfUndelivered($project);
    check('failed analysis (no scenes) refunds 250', $balance() === 1000, 'balance ' . $balance());
    ExplainerBilling::refundStoryboardIfUndelivered($project);
    check('storyboard refund is idempotent', $balance() === 1000);

    [$code] = $json($controller->reanalyze(Request::create('/x', 'POST', ['target_seconds' => 800]), $project->fresh()));
    // status is still 'analyzing' from store(): must be refused first
    check('re-analyse refused while analysing', $code === 409, "code {$code}");
    $project->update(['status' => 'storyboard_ready']);
    [$code] = $json($controller->reanalyze(Request::create('/x', 'POST', ['target_seconds' => 800]), $project->fresh()));
    check('re-analyse past the tier refused', $code === 422, "code {$code}");

    echo "Renders\n";
    ExplainerScene::create([
        'project_id' => $project->id, 'scene_id' => 's1', 'order' => 1, 'duration_seconds' => 6,
        'narration' => 'Hello there.', 'layout_template' => 'single_focus', 'transition' => 'cut',
        'slots' => [
            'main' => ['content_type' => 'image', 'asset_request' => ['description' => 'a red bicycle leaning on a wall']],
            'side' => ['content_type' => 'image', 'asset_request' => ['description' => 'a blue kettle on a stove']],
        ],
    ]);
    $project->update(['status' => 'storyboard_ready', 'settings' => array_merge($project->fresh()->settings, ['auto_visuals' => false])]);

    $q = ExplainerBilling::renderQuote($project->fresh());
    check('first render is free', $q['free_render'] && $q['total'] === 0, json_encode($q));

    [$code, $body] = $json($controller->toggleAutoVisuals(Request::create('/x', 'POST', ['enabled' => true]), $project->fresh()));
    check('AI visuals ON without consent -> 409 confirm_cost', $code === 409 && ($body['code'] ?? '') === 'confirm_cost', "code {$code}");
    check('quote shows 2 pictures x 25 = 50', ($body['data']['images'] ?? 0) === 2 && ($body['data']['total'] ?? 0) === 50, json_encode($body['data'] ?? []));
    [$code] = $json($controller->toggleAutoVisuals(Request::create('/x', 'POST', ['enabled' => true, 'accept_cost' => true]), $project->fresh()));
    check('AI visuals ON with consent', $code === 200);

    $q = ExplainerBilling::renderQuote($project->fresh());
    check('free render + 2 pictures = 50', $q['total'] === 50 && $q['images'] === 2, json_encode($q));

    [$code, $body] = $json($controller->render($project->fresh()));
    check('render queued', $code === 200, "code {$code} " . json_encode($body));
    check('charged 50 (pictures only)', $balance() === 950, 'balance ' . $balance());
    check('paid_image_fills = 2 recorded', (int) ($project->fresh()->settings['billing']['paid_image_fills'] ?? -1) === 2);

    [$code] = $json($controller->render($project->fresh()));
    check('second click while processing -> 409', $code === 409, "code {$code}");
    check('second click charged nothing', $balance() === 950);

    // Only one of the two pictures was drawn -> 25 back.
    $credits->refundRenderPart($project->fresh(), 25, 'test: one picture not drawn');
    check('undrawn picture refunded', $balance() === 975, 'balance ' . $balance());
    // The render then fails -> the REST of the charge (25), not 50 again.
    $credits->refund($project->fresh());
    check('failure refunds only what is left', $balance() === 1000, 'balance ' . $balance());
    $credits->refund($project->fresh());
    check('render refund is idempotent', $balance() === 1000);

    // A render succeeded: next one is paid.
    $settings = $project->fresh()->settings;
    $settings['billing']['renders_completed'] = 1;
    $settings['auto_visuals'] = false;
    $project->update(['settings' => $settings, 'status' => 'completed']);
    // Stock b-roll slots never block readiness (AI visuals is off now).
    $scene = ExplainerScene::where('project_id', $project->id)->first();
    $scene->update(['slots' => array_map(fn ($slot) => $slot + ['stock_query' => 'bicycle'], $scene->slots)]);
    $q = ExplainerBilling::renderQuote($project->fresh());
    check('re-render costs 100', !$q['free_render'] && $q['total'] === 100, json_encode($q));
    [$code] = $json($controller->render($project->fresh()));
    check('re-render queued', $code === 200, "code {$code}");
    check('charged 100', $balance() === 900, 'balance ' . $balance());

    // Not enough credits.
    Project::whereKey($project->id)->update(['status' => 'completed']);
    $user->forceFill(['credits' => 40])->save();
    [$code, $body] = $json($controller->render($project->fresh()));
    check('re-render with 40 credits -> 402', $code === 402 && ($body['code'] ?? '') === 'insufficient_credits', "code {$code}");
    check('refused render released the project', $project->fresh()->status === 'completed', $project->fresh()->status);

    echo "Voice\n";
    [$code] = $json($controller->setVoice(Request::create('/x', 'POST', ['tts_voice' => 'af_sarah']), $project->fresh()));
    check('voice change saved', $code === 200 && ($project->fresh()->settings['tts_voice'] ?? '') === 'af_sarah');
    try {
        $controller->setVoice(Request::create('/x', 'POST', ['tts_voice' => 'clone_999999']), $project->fresh());
        check('someone else\'s clone refused', false);
    } catch (\Illuminate\Validation\ValidationException $e) {
        check('someone else\'s clone refused', true);
    }
} catch (\Throwable $e) {
    $fail++;
    echo 'ERROR: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
} finally {
    DB::rollBack();
}

echo PHP_EOL . "{$pass} passed, {$fail} failed (all changes rolled back)" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
