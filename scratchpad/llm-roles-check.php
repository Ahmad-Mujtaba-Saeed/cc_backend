<?php

/**
 * llm-roles-check — the model a call site actually gets. No API calls.
 *
 *   docker compose exec -T app php scratchpad/llm-roles-check.php
 *
 * Guards the `planner` role split out of `explainer` (iter 57): the L1 act
 * planner and the scene composer are different jobs with different appetites,
 * and the bench measured a model that is better at one and worse at the other.
 * The split must be INVISIBLE until someone configures it — an unset
 * planner_model has to resolve exactly as the explainer model did before, or a
 * production install silently changes which model plans every video.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\AccessControl\Models\AppSetting;
use Modules\Project\Support\LlmModels;

$pass = 0;
$fail = 0;
function ok(string $what, bool $cond): void
{
    global $pass, $fail;
    $cond ? $pass++ : $fail++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $what . "\n";
}

// Leave the admin's real setting exactly as found, whatever happens here.
$prior = (string) AppSetting::get(LlmModels::SETTING_KEY, LlmModels::AUTO);
register_shutdown_function(fn () => AppSetting::set(LlmModels::SETTING_KEY, $prior));

AppSetting::set(LlmModels::SETTING_KEY, LlmModels::AUTO);

echo "\nunset planner == explainer (the whole point of the split)\n";
config(['services.openai.planner_model' => null, 'services.openai.explainer_model' => 'gpt-4o-mini']);
ok('planner falls back to the explainer model',
    LlmModels::for('planner') === LlmModels::for('explainer'));
ok('and it is the explainer default itself', LlmModels::for('planner') === 'gpt-4o-mini');

config(['services.openai.planner_model' => '']);
ok('an EMPTY planner_model is treated as unset, not as a model name',
    LlmModels::for('planner') === 'gpt-4o-mini');

echo "\nconfigured separately\n";
config(['services.openai.planner_model' => 'gpt-4.1-mini', 'services.openai.explainer_model' => 'gpt-4.1']);
ok('planner takes its own model', LlmModels::for('planner') === 'gpt-4.1-mini');
ok('the composer keeps the explainer model', LlmModels::for('explainer') === 'gpt-4.1');
ok('the two roles are genuinely independent',
    LlmModels::for('planner') !== LlmModels::for('explainer'));

echo "\nthe admin override still outranks every role\n";
AppSetting::set(LlmModels::SETTING_KEY, 'gpt-4o');
foreach (['explainer', 'planner', 'math', 'director', 'general'] as $role) {
    ok("{$role} obeys the admin pick", LlmModels::for($role) === 'gpt-4o');
}
ok('vlm still refuses a text-only override', LlmModels::for('vlm') === 'gpt-4o');

AppSetting::set(LlmModels::SETTING_KEY, LlmModels::AUTO);
config(['services.openai.explainer_model_math' => 'gpt-5-nano']);
ok('math keeps its own escalation model', LlmModels::for('math') === 'gpt-5-nano');
config(['services.openai.explainer_model_math' => null]);
ok('an unset math model falls back to explainer, as before',
    LlmModels::for('math') === LlmModels::for('explainer'));

echo "\nthe call site\n";
$src = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptSkeletonService.php');
ok('planGeneric asks for the planner role', str_contains($src, "LlmModels::for('planner')"));
ok('its cost event is recorded against the same model',
    substr_count($src, "LlmModels::for('planner')") >= 2);
ok('nothing in the skeleton service still asks for the explainer role',
    !str_contains($src, "LlmModels::for('explainer')"));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
