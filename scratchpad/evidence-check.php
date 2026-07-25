<?php

/**
 * evidence-check — the "according to..." card and the refusal it is named for:
 * clamp, the nameable-source guarantee (constructive counterpart of the
 * no_source lint), degrade/cap, linter and casting of evidence_card
 * (loop iter 38, card queue #2 item 11).
 *   docker exec viralforgebackend-app-1 php scratchpad/evidence-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptAnalysisService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($got !== '' ? "  -> {$got}" : '') . "\n";
    }
}

function evidenceScene(string $id, array $slot): array
{
    return [
        'scene_id' => $id,
        'layout_template' => 'evidence_card',
        'duration_seconds' => 12,
        'narration' => ['text' => 'According to the study, the change added years of healthy life.'],
        'slots' => ['slot_evidence' => array_merge(['content_type' => 'evidence'], $slot)],
    ];
}

$run = fn (array $scenes) => (new ShotListValidator())->validate(
    ['scenes' => $scenes],
    ['hook_enabled' => false, 'outro_enabled' => false]
)['scenes'];
$one = fn (array $slot) => $run([evidenceScene('scene_1', $slot)])[0] ?? [];
$isText = fn (array $out) => ($out['layout_template'] ?? '') === 'single_focus';
$slotOf = fn (array $out) => $out['slots']['slot_evidence'] ?? [];

$GOOD = ['source' => 'Harvard University', 'finding' => 'A short daily walk added two years of life.'];

echo "--- registry ---\n";
$templates = ExplainerRegistry::templates();
check('evidence_card template registered', isset($templates['evidence_card']));
check('its slot is slot_evidence', isset($templates['evidence_card']['slots']['slot_evidence']));
check('the slot allows the evidence type',
    ($templates['evidence_card']['slots']['slot_evidence']['allowed'] ?? []) === ['evidence']);
check('capped at 1 per video', ($templates['evidence_card']['max_per_video'] ?? null) === 1);
$reg = json_decode(file_get_contents(__DIR__ . '/../modules/Project/Resources/explainer_registry.json'), true);
check('evidence content type registered', isset($reg['content_types']['evidence']));
check('registry version bumped past the scale card', (int) ($reg['version'] ?? 0) >= 37, (string) ($reg['version'] ?? ''));

echo "--- a real citation renders ---\n";
$out = $one($GOOD + ['year' => '2021', 'sample' => '12,000 adults', 'caption' => 'Observational', 'heading' => 'The evidence']);
check('the card survives with a named source', ($out['layout_template'] ?? '') === 'evidence_card', $out['layout_template'] ?? '');
$slot = $slotOf($out);
check('finding kept', ($slot['finding'] ?? '') === $GOOD['finding'], $slot['finding'] ?? '');
check('source kept', ($slot['source'] ?? '') === 'Harvard University', $slot['source'] ?? '');
check('year, sample, caption, heading all kept',
    ($slot['year'] ?? '') === '2021' && ($slot['sample'] ?? '') === '12,000 adults'
    && ($slot['caption'] ?? '') === 'Observational' && ($slot['heading'] ?? '') === 'The evidence');

echo "--- THE REFUSAL: no nameable source degrades to text, never a fake citation ---\n";
foreach ([
    'studies show', 'Studies show:', 'research', 'Research finds', 'experts',
    'Experts say', 'scientists', 'a study', 'The study', 'one study',
    'the data', 'sources', 'people', 'many', 'AB',
] as $vague) {
    $out = $one(['source' => $vague, 'finding' => $GOOD['finding']]);
    check("\"{$vague}\" is refused and becomes plain text", $isText($out), $out['layout_template'] ?? '');
}
$out = $one(['source' => 'studies show', 'finding' => $GOOD['finding']]);
check('the refused finding survives as the text beat',
    (bool) array_filter($out['slots']['slot_main']['bullets'] ?? [], fn ($b) => str_contains($b, 'daily walk')),
    json_encode($out['slots']['slot_main']['bullets'] ?? []));

echo "--- and a REAL source is not caught by the blocklist ---\n";
foreach ([
    'Harvard University', 'The Lancet', 'Pew Research', 'Pew Research reports',
    'Stanford researchers', 'The Framingham Study', 'NASA', 'WHO',
] as $named) {
    $out = $one(['source' => $named, 'finding' => $GOOD['finding']]);
    check("\"{$named}\" is accepted as provenance", ($out['layout_template'] ?? '') === 'evidence_card', $out['layout_template'] ?? '');
}

echo "--- a finding is required too ---\n";
check('no finding degrades to text', $isText($one(['source' => 'Harvard University', 'finding' => ''])));
check('nothing at all degrades to text', $isText($one([])));

echo "--- caps and aliases ---\n";
$long = str_repeat('word ', 60);
$out = $one(['source' => str_repeat('S', 80), 'finding' => $long, 'year' => str_repeat('9', 40), 'sample' => str_repeat('n', 90)]);
$slot = $slotOf($out);
check('finding capped at 160', mb_strlen($slot['finding'] ?? '') <= 160, (string) mb_strlen($slot['finding'] ?? ''));
check('source capped at 48', mb_strlen($slot['source'] ?? '') <= 48, (string) mb_strlen($slot['source'] ?? ''));
check('year capped at 12', mb_strlen($slot['year'] ?? '') <= 12, (string) mb_strlen($slot['year'] ?? ''));
check('sample capped at 40', mb_strlen($slot['sample'] ?? '') <= 40, (string) mb_strlen($slot['sample'] ?? ''));
$out = $one(['institution' => 'Oxford University', 'claim' => 'Sleep improved recall.', 'n' => '500 students']);
$slot = $slotOf($out);
check('institution/claim/n are accepted as the model writes them',
    ($slot['source'] ?? '') === 'Oxford University' && ($slot['finding'] ?? '') === 'Sleep improved recall.'
    && ($slot['sample'] ?? '') === '500 students',
    json_encode($slot));

echo "--- the over-cap degrade keeps the finding AND cites its source ---\n";
$scenes = $run([
    evidenceScene('scene_1', $GOOD + ['year' => '2021', 'sample' => '12,000 adults']),
    evidenceScene('scene_2', $GOOD + ['year' => '2020']),
]);
$cards = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'evidence_card'));
check('only one card survives the cap', count($cards) === 1, (string) count($cards));
$degraded = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'single_focus'));
$bullets = $degraded[0]['slots']['slot_main']['bullets'] ?? [];
check('the degrade keeps the finding verbatim',
    (bool) array_filter($bullets, fn ($b) => str_contains($b, 'daily walk')), json_encode($bullets));
check('and cites the source beneath it',
    (bool) array_filter($bullets, fn ($b) => str_contains($b, '— Harvard University')), json_encode($bullets));

echo "--- the linter treats it as a drawn card ---\n";
$lint = (new SceneBudgetLinter())->lint($run([
    evidenceScene('scene_1', $GOOD + ['year' => '2021', 'sample' => '12,000 adults']),
]), []);
$codes = array_column($lint['items'] ?? [], 'code');
check('not flagged as a sparse text scene', !in_array('sparse_scene', $codes, true), implode(',', $codes));
check('not flagged as dead air', !in_array('dead_air', $codes, true), implode(',', $codes));
check('not flagged as a static hold', !in_array('static_hold', $codes, true), implode(',', $codes));

$scene = $run([evidenceScene('scene_1', $GOOD + ['sample' => '12,000 adults'])])[0];
$m = (new ReflectionClass(SceneBudgetLinter::class))->getMethod('sceneScreenText');
$m->setAccessible(true);
$screen = strtolower((string) $m->invoke(null, $scene));
check('the sync audit reads the finding', str_contains($screen, 'daily walk'), mb_substr($screen, 0, 140));
check('and the cited source', str_contains($screen, 'harvard'), mb_substr($screen, 0, 140));

echo "--- casting knows about it ---\n";
$docs = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('CARD_DOCS');
check('the composer documents the card', isset($docs['evidence_card']));
check('the doc names the real slot key', str_contains($docs['evidence_card'] ?? '', 'slot_evidence'));
check('the doc insists the source names someone',
    str_contains($docs['evidence_card'] ?? '', 'MUST NAME'));
$menus = (new ReflectionClass(GenericStoryboardComposerService::class))->getConstant('MENUS');
$offered = array_keys(array_filter($menus, fn ($m) => in_array('evidence_card', $m, true)));
check('offered on at least one intent', $offered !== [], implode(',', $offered));
check('every menu entry is a real template',
    !array_diff(array_unique(array_merge(...array_values($menus))), array_keys($templates)));

// The giant-call card list lives in a prompt-building method; scan the class
// source directly for the card mention rather than reflecting a private string.
$src = file_get_contents((new ReflectionClass(ScriptAnalysisService::class))->getFileName());
check('the giant analyzer call lists the card', str_contains($src, '"evidence_card"'));
check('and warns the source must name someone', str_contains($src, 'MUST NAME'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
