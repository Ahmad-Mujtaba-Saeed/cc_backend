<?php

/**
 * Iter 18 — term_card (plus the sceneScreenText gap it exposed).
 *
 * Run in-container:  docker compose exec -T app php scratchpad/term-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function one(array $slot): array
{
    $out = (new ShotListValidator())->validate(['scenes' => [[
        'narration' => ['text' => 'Before going further you need one word, because the rest of this only makes sense with it.'],
        'layout_template' => 'term_card',
        'slots' => ['slot_term' => $slot],
    ]]], ['hook_enabled' => false, 'outro_enabled' => false]);

    return $out['scenes'][0];
}

echo "\n== registry\n";
check('version bumped to 27', (int) (ExplainerRegistry::all()['version'] ?? 0) >= 27,
    (string) (ExplainerRegistry::all()['version'] ?? '?'));
check('term_card template exists', in_array('term_card', ExplainerRegistry::templateNames(), true));
check('term_card caps at 2 per video', ExplainerRegistry::maxPerVideo('term_card') === 2,
    var_export(ExplainerRegistry::maxPerVideo('term_card'), true));

echo "\n== clamp: both halves required\n";

$full = one([
    'content_type' => 'term',
    'term' => 'Arbitrage',
    'phonetic' => 'AR-bi-trahzh',
    'part_of_speech' => 'noun',
    'definition' => 'Buying in one market and selling in another for more.',
    'caption' => 'from French arbitrer',
]);
check('complete entry survives', $full['layout_template'] === 'term_card', $full['layout_template']);
$t = $full['slots']['slot_term'];
check('term kept', ($t['term'] ?? '') === 'Arbitrage');
check('definition kept', str_starts_with($t['definition'] ?? '', 'Buying in one market'));
check('phonetic kept', ($t['phonetic'] ?? '') === 'AR-bi-trahzh');
check('part_of_speech kept', ($t['part_of_speech'] ?? '') === 'noun');
check('caption kept', ($t['caption'] ?? '') === 'from French arbitrer');

$noDef = one(['content_type' => 'term', 'term' => 'Arbitrage']);
check('term without definition degrades', $noDef['layout_template'] !== 'term_card', $noDef['layout_template']);
check('the word survives the degrade', str_contains(json_encode($noDef), 'Arbitrage'));

$noTerm = one(['content_type' => 'term', 'definition' => 'A meaning with nothing to attach it to.']);
check('definition without a term degrades', $noTerm['layout_template'] !== 'term_card', $noTerm['layout_template']);
check('the meaning survives the degrade', str_contains(json_encode($noTerm), 'nothing to attach'));

echo "\n== clamp: aliases and hygiene\n";

$alias = one(['content_type' => 'term', 'heading' => 'Fungible', 'body' => 'Interchangeable with another identical unit.']);
check('heading/body alias into term/definition', ($alias['slots']['slot_term']['term'] ?? '') === 'Fungible',
    json_encode($alias['slots']['slot_term'] ?? []));
check('heading equal to the term is not repeated', !isset($alias['slots']['slot_term']['heading']),
    json_encode($alias['slots']['slot_term'] ?? []));

$distinct = one(['content_type' => 'term', 'term' => 'Fungible', 'definition' => 'Interchangeable.', 'heading' => 'Key idea']);
check('a genuinely different heading is kept', ($distinct['slots']['slot_term']['heading'] ?? '') === 'Key idea');

$long = one([
    'content_type' => 'term',
    'term' => str_repeat('a', 50),
    'definition' => str_repeat('b', 200),
    'phonetic' => str_repeat('c', 60),
    'part_of_speech' => str_repeat('d', 40),
    'caption' => str_repeat('e', 150),
]);
$L = $long['slots']['slot_term'];
check('term clamped to 28', mb_strlen($L['term']) === 28, (string) mb_strlen($L['term']));
check('definition clamped to 120', mb_strlen($L['definition']) === 120);
check('phonetic clamped to 32', mb_strlen($L['phonetic']) === 32);
check('part_of_speech clamped to 16', mb_strlen($L['part_of_speech']) === 16);
check('caption clamped to 80', mb_strlen($L['caption']) === 80);

// IPA must survive untouched — a sanitiser would eat the diacritics.
$ipa = one(['content_type' => 'term', 'term' => 'Entrepreneur',
    'phonetic' => '/ˌɒn.trə.prəˈnɜː/', 'definition' => 'Someone who starts a business and carries its risk.']);
check('IPA passes through byte-for-byte', ($ipa['slots']['slot_term']['phonetic'] ?? '') === '/ˌɒn.trə.prəˈnɜː/',
    $ipa['slots']['slot_term']['phonetic'] ?? '');

echo "\n== cap degrade (max 2 per video)\n";

$mk = fn (string $term, string $def) => [
    'narration' => ['text' => "The word {$term} matters here and this sentence is long enough to hold the beat."],
    'layout_template' => 'term_card',
    'slots' => ['slot_term' => ['content_type' => 'term', 'term' => $term, 'definition' => $def,
        'phonetic' => 'foo-BAR', 'part_of_speech' => 'noun', 'caption' => 'origin note']],
];
$capped = (new ShotListValidator())->validate(['scenes' => [
    $mk('Alpha', 'The first definition of the set.'),
    $mk('Beta', 'The second definition of the set.'),
    $mk('Gamma', 'The third definition, over the cap.'),
]], ['hook_enabled' => false, 'outro_enabled' => false]);
$tpls = array_map(fn ($s) => $s['layout_template'], $capped['scenes']);
check('first two term cards kept', $tpls[0] === 'term_card' && $tpls[1] === 'term_card', implode(',', $tpls));
check('third degraded', $tpls[2] !== 'term_card', implode(',', $tpls));
$third = json_encode($capped['scenes'][2]);
check('degrade keeps the word as the heading', str_contains($third, 'Gamma'));
check('degrade keeps the definition', str_contains($third, 'third definition'));
check('degrade keeps pronunciation + part of speech', str_contains($third, 'foo-BAR') && str_contains($third, 'noun'));

echo "\n== linter exemptions\n";

$lint = SceneBudgetLinter::lint([array_merge($full, ['duration_seconds' => 11.0])], []);
$noise = array_filter($lint['items'], fn ($i) => in_array($i['code'] ?? '', ['min_text', 'static_hold'], true));
check('no min_text/static_hold on a long term scene', count($noise) === 0, json_encode(array_values($noise)));

echo "\n== sceneScreenText sees the new card fields (iter 17 gap included)\n";

$m = new ReflectionMethod(SceneBudgetLinter::class, 'sceneScreenText');
$m->setAccessible(true);

$termText = $m->invoke(null, ['slots' => ['slot_term' => [
    'content_type' => 'term', 'term' => 'Arbitrage', 'definition' => 'Buying low elsewhere.',
    'phonetic' => 'AR-bi-trahzh', 'part_of_speech' => 'noun',
]]]);
check('term text is visible to the sync audit', str_contains($termText, 'Arbitrage') && str_contains($termText, 'Buying low'), $termText);

// iter 17 shipped venn without these keys reaching the audit; iter 12 the same
// for spectrum. Both are fixed here.
$vennText = $m->invoke(null, ['slots' => ['slot_venn' => [
    'content_type' => 'venn',
    'sets' => [['label' => 'Mammals', 'caption' => 'feed young milk'], ['label' => 'Sea life']],
    'overlap_label' => 'Whales',
]]]);
check('venn set labels visible', str_contains($vennText, 'Mammals') && str_contains($vennText, 'Sea life'), $vennText);
check('venn set captions visible', str_contains($vennText, 'feed young milk'));
check('venn overlap label visible', str_contains($vennText, 'Whales'));

$specText = $m->invoke(null, ['slots' => ['slot_spectrum' => [
    'content_type' => 'spectrum',
    'axis' => ['left_label' => 'Cheap', 'right_label' => 'Expensive'],
    'spectrum_items' => [['label' => 'Bikes', 'position' => 0.1], ['label' => 'Yachts', 'position' => 0.95]],
]]]);
check('spectrum item labels visible', str_contains($specText, 'Bikes') && str_contains($specText, 'Yachts'), $specText);
check('spectrum axis poles visible', str_contains($specText, 'Cheap') && str_contains($specText, 'Expensive'));

echo "\n== casting\n";
$composer = file_get_contents(__DIR__ . '/../modules/Project/Services/GenericStoryboardComposerService.php');
check('term_card in a composer menu', str_contains($composer, "'term_card'"));
check('term_card has CARD_DOCS', str_contains($composer, "'term_card' => 'slot_term:"));
$analysis = file_get_contents(__DIR__ . '/../modules/Project/Services/ScriptAnalysisService.php');
check('term_card in the giant-call card list', str_contains($analysis, '"term_card"'));

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? 'PASS' : 'FAIL') . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
