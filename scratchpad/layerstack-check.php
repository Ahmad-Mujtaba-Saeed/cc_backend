<?php

/**
 * layerstack-check — clamp matrix, order preservation, degrades, linter
 * visibility and casting for layer_stack (loop iter 29, card queue #2 item 1).
 * THE invariant under test: ORDER IS THE CONTENT. A stack whose layers were
 * rearranged or deduplicated teaches a wrong structure, so the clamp must
 * keep top-first order byte-for-byte and must NOT collapse repeated labels
 * (real stacks alternate materials).
 * Run inside the app container:
 *   docker exec viralforgebackend-app-1 php scratchpad/layerstack-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

$validator = new ShotListValidator();
$clamp = function (array $slot) use ($validator): ?array {
    $m = new ReflectionMethod($validator, 'clampLayerStackContent');
    $m->setAccessible(true);

    return $m->invoke($validator, $slot);
};

// ---- 1. Registry ----------------------------------------------------------
$tpl = ExplainerRegistry::templates()['layer_stack'] ?? null;
check('template registered with slot_layers/layers', is_array($tpl) && in_array('layers', $tpl['slots']['slot_layers']['allowed'] ?? [], true));
check('capped at 1 per video', (int) ($tpl['max_per_video'] ?? 0) === 1);
check('registry version >= 33', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 33);

// ---- 2. Clamp matrix ------------------------------------------------------
$five = ['layers' => [
    ['label' => 'Exosphere', 'caption' => 'fades into space'],
    ['label' => 'Thermosphere'],
    ['label' => 'Mesosphere', 'caption' => 'meteors burn up'],
    ['label' => 'Stratosphere'],
    ['label' => 'Troposphere', 'caption' => 'all weather here'],
], 'highlight_index' => 4, 'heading' => 'Five shells', 'caption' => 'Not to scale'];
$c = $clamp($five);
check('5-layer payload survives whole', $c !== null && count($c['layers']) === 5);
check('ORDER PRESERVED top-first', array_column($c['layers'], 'label') === ['Exosphere', 'Thermosphere', 'Mesosphere', 'Stratosphere', 'Troposphere']);
check('captions ride their layers', ($c['layers'][0]['caption'] ?? '') === 'fades into space' && !isset($c['layers'][1]['caption']));
check('highlight kept when valid', ($c['highlight_index'] ?? null) === 4);
check('heading + caption kept', ($c['heading'] ?? '') === 'Five shells' && ($c['caption'] ?? '') === 'Not to scale');

$repeat = $clamp(['layers' => [
    ['label' => 'Electrode'], ['label' => 'Separator'], ['label' => 'Electrode'],
]]);
check('repeated labels are NOT deduplicated', $repeat !== null && array_column($repeat['layers'], 'label') === ['Electrode', 'Separator', 'Electrode']);

check('2 layers → null (a stack needs depth)', $clamp(['layers' => [['label' => 'A'], ['label' => 'B']]]) === null);
$seven = $clamp(['layers' => array_map(fn ($i) => ['label' => "L{$i}"], range(1, 7))]);
check('7th layer dropped, first six kept in order', $seven !== null && array_column($seven['layers'], 'label') === ['L1', 'L2', 'L3', 'L4', 'L5', 'L6']);

$alias = $clamp(['items' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']]]);
check("`items` alias accepted", $alias !== null && count($alias['layers']) === 3);
$strings = $clamp(['layers' => ['Crust', 'Mantle', 'Core']]);
check('bare-string layers accepted', $strings !== null && array_column($strings['layers'], 'label') === ['Crust', 'Mantle', 'Core']);

$long = $clamp(['layers' => [
    ['label' => str_repeat('x', 40), 'caption' => str_repeat('y', 60)],
    ['label' => 'B'], ['label' => 'C'],
]]);
check('label clamps to 24, caption to 44', mb_strlen($long['layers'][0]['label']) === 24 && mb_strlen($long['layers'][0]['caption']) === 44);
$stale = $clamp(['layers' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']], 'highlight_index' => 9]);
check('stale highlight dropped not guessed', $stale !== null && !isset($stale['highlight_index']));
$junk = $clamp(['layers' => [['label' => 'A'], ['label' => ''], 42, null, ['label' => 'B'], ['label' => 'C']]]);
check('junk rows skipped, survivors in order', $junk !== null && array_column($junk['layers'], 'label') === ['A', 'B', 'C']);

// ---- 3. normalizeCardScene: thin payload degrades to text, order kept -----
$normalize = function (array $scene) use ($validator): array {
    $m = new ReflectionMethod($validator, 'normalizeCardScene');
    $m->setAccessible(true);

    return $m->invoke(
        $validator,
        (string) $scene['layout_template'],
        (string) $scene['scene_id'],
        $scene,
        (string) ($scene['narration']['text'] ?? '')
    );
};
$thin = $normalize([
    'scene_id' => 's1', 'layout_template' => 'layer_stack',
    'narration' => ['text' => 'Two layers only.'],
    'slots' => ['slot_layers' => ['layers' => [['label' => 'Top'], ['label' => 'Bottom']], 'heading' => 'Thin']],
]);
check('below 3 layers degrades to text', ($thin['layout_template'] ?? '') === 'single_focus');
check('degrade keeps order + heading', ($thin['slots']['slot_main']['bullets'] ?? []) === ['Top', 'Bottom'] && ($thin['slots']['slot_main']['heading'] ?? '') === 'Thin');

$good = $normalize([
    'scene_id' => 's2', 'layout_template' => 'layer_stack',
    'narration' => ['text' => 'Three layers.'],
    'slots' => ['slot_layers' => $five],
]);
check('healthy payload passes normalize whole', ($good['layout_template'] ?? '') === 'layer_stack' && count($good['slots']['slot_layers']['layers']) === 5);

// ---- 4. Cap degrade: label — caption bullets, top-first -------------------
$degrade = function (array $scene) use ($validator): array {
    $m = new ReflectionMethod($validator, 'degradeCappedCard');
    $m->setAccessible(true);

    return $m->invoke($validator, $scene);
};
$capped = $degrade([
    'scene_id' => 's3', 'layout_template' => 'layer_stack',
    'slots' => ['slot_layers' => $clamp($five) + []],
]);
check('cap degrade → single_focus', ($capped['layout_template'] ?? '') === 'single_focus');
$bullets = $capped['slots']['slot_main']['bullets'] ?? [];
check('cap bullets keep order with captions', ($bullets[0] ?? '') === 'Exosphere — fades into space' && ($bullets[4] ?? '') === 'Troposphere — all weather here');

// ---- 5. Linter: animated + sync-audit visibility --------------------------
$animated = new ReflectionClassConstant(SceneBudgetLinter::class, 'ANIMATED_CARDS');
check('linter treats layer_stack as animated', in_array('layer_stack', $animated->getValue(), true));

$screen = new ReflectionMethod(SceneBudgetLinter::class, 'sceneScreenText');
$screen->setAccessible(true);
$text = $screen->invoke(null, [
    'layout_template' => 'layer_stack',
    'slots' => ['slot_layers' => ['layers' => [['label' => 'Troposphere', 'caption' => 'weather lives here']]]],
]);
check('sync audit SEES layer labels + captions', str_contains($text, 'Troposphere') && str_contains($text, 'weather lives here'));

// ---- 6. Casting -----------------------------------------------------------
foreach (['context', 'point', 'aspect'] as $intent) {
    check("composer menu `{$intent}` offers the card", in_array('layer_stack', GenericStoryboardComposerService::menuFor($intent), true));
}
$giant = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php');
check('giant call documents layer_stack', str_contains($giant, '"layer_stack" when things genuinely sit ON TOP of each other'));

// ---- 7. Routing: an anatomy card is not evidence of a derivation ----------
check('layer_stack is not a math-board template', !in_array('layer_stack', ShotListValidator::MATH_TEMPLATES, true));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
