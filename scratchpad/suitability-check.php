<?php

/**
 * Iter 15 — card suitability gate.
 *
 * Run in-container:  docker compose exec -T app php scratchpad/suitability-check.php
 *
 * The gate must catch the project-42 failures (a timeline of non-dates, a
 * counter with no figure) WITHOUT degrading honest cards — the false-positive
 * cases below are the ones that would cost a good card, so they carry as much
 * weight as the catches.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\CardSuitability;
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

echo "\n== looksChronological: real time markers (must all be TRUE)\n";
foreach (['1969', '1990s', 'Mar 2024', 'January', '44 BC', '1200 CE', 'Q3', 'Day 3',
          '3/2024', '12-05', '19th century', 'c. 1500', 'Today', 'Ancient Rome',
          'Week 2', 'the future'] as $s) {
    check("chrono: {$s}", CardSuitability::looksChronological($s));
}

echo "\n== looksChronological: NOT time markers (must all be FALSE)\n";
foreach (['relevance', 'page speed', 'content quality', 'user intent', 'backlinks',
          'the algorithm', 'mobile friendliness', ''] as $s) {
    check("not chrono: '{$s}'", !CardSuitability::looksChronological($s));
}

echo "\n== timeline_card suitability\n";

// The actual project-42 payload: ranking criteria cast as a timeline.
$proj42 = ['slot_timeline' => ['content_type' => 'timeline_nodes', 'nodes' => [
    ['date' => 'relevance', 'label' => 'how well it matches'],
    ['date' => 'page speed', 'label' => 'how fast it loads'],
    ['date' => 'content quality', 'label' => 'how useful it is'],
]]];
check('proj42 criteria list is unfit', CardSuitability::unfitReason('timeline_card', $proj42) !== null);

$real = ['slot_timeline' => ['content_type' => 'timeline_nodes', 'nodes' => [
    ['date' => '1969', 'label' => 'first landing'],
    ['date' => '1972', 'label' => 'last landing'],
    ['date' => '2024', 'label' => 'return'],
]]];
check('real dated timeline is fit', CardSuitability::unfitReason('timeline_card', $real) === null);

// Models routinely leave `date` empty and put the year in the label.
$inLabel = ['slot_timeline' => ['content_type' => 'timeline_nodes', 'nodes' => [
    ['date' => '', 'label' => '1969 — the landing'],
    ['date' => '', 'label' => '1972 — the last one'],
]]];
check('dates in the label are still fit', CardSuitability::unfitReason('timeline_card', $inLabel) === null);

$oneDated = ['slot_timeline' => ['content_type' => 'timeline_nodes', 'nodes' => [
    ['date' => '2024', 'label' => 'launched'],
    ['date' => 'relevance', 'label' => 'matters'],
    ['date' => 'speed', 'label' => 'matters too'],
]]];
check('a single dated row among nouns is unfit', CardSuitability::unfitReason('timeline_card', $oneDated) !== null);

$eras = ['slot_timeline' => ['content_type' => 'timeline_nodes', 'nodes' => [
    ['date' => 'Ancient', 'label' => 'the beginning'],
    ['date' => 'Today', 'label' => 'where we are'],
]]];
check('era words are chronology', CardSuitability::unfitReason('timeline_card', $eras) === null);

// Below 2 nodes is a SHAPE problem the clamps own — the gate must stay out.
$thin = ['slot_timeline' => ['nodes' => [['date' => 'x', 'label' => 'y']]]];
check('sub-2-node timeline left to the clamps', CardSuitability::unfitReason('timeline_card', $thin) === null);

echo "\n== big_counter is owned UPSTREAM, not by this gate\n";

// The other half of the project-42 report turned out to be already fixed:
// normalizeCardScene degrades a big_counter whose HEADING carries no figure.
// This gate must not duplicate it — a second rule with a different degrade
// target (stat_spotlight vs single_focus) would be a regression. These cases
// pin the existing behaviour so a future edit cannot quietly drop it.
check(
    'gate does not claim big_counter',
    CardSuitability::unfitReason('big_counter', ['slot_counter' => [
        'content_type' => 'text_block', 'heading' => 'A huge amount',
    ]]) === null
);

$counter = fn (array $slot) => (new ShotListValidator())->validate(
    ['scenes' => [['narration' => ['text' => 'It is an enormous number of queries every single day of the year.'],
                   'layout_template' => 'big_counter', 'slots' => ['slot_counter' => $slot]]]],
    ['hook_enabled' => false, 'outro_enabled' => false]
)['scenes'][0]['layout_template'];

check('figureless counter still degraded upstream',
    $counter(['content_type' => 'text_block', 'heading' => 'A huge amount', 'bullets' => ['it keeps growing']]) !== 'big_counter');
check('counter with a figure in the heading kept',
    $counter(['content_type' => 'text_block', 'heading' => '500 Million', 'bullets' => ['searches a day']]) === 'big_counter');
check('figure only in a bullet does NOT save it (the heading IS the number)',
    $counter(['content_type' => 'text_block', 'heading' => 'Searches', 'bullets' => ['8.5 billion a day']]) !== 'big_counter');
check('chart-backed counter kept',
    $counter(['content_type' => 'chart', 'chart_type' => 'counter', 'values' => [500]]) === 'big_counter');

echo "\n== ungated cards are untouched\n";
foreach (['single_focus', 'icon_grid', 'animated_chart', 'spectrum_card', 'math_steps', 'cycle_diagram', 'big_counter'] as $t) {
    check("{$t} not gated", CardSuitability::unfitReason($t, ['slot_main' => ['heading' => 'whatever']]) === null);
}

echo "\n== end-to-end through validate()\n";

$validator = new ShotListValidator();
$out = $validator->validate(['scenes' => [
    ['narration' => ['text' => 'Google ranks pages by relevance, page speed and content quality.'],
     'layout_template' => 'timeline_card',
     'slots' => ['slot_timeline' => ['content_type' => 'timeline_nodes', 'nodes' => [
         ['date' => 'relevance', 'label' => 'how well it matches'],
         ['date' => 'page speed', 'label' => 'how fast it loads'],
         ['date' => 'content quality', 'label' => 'how useful it is'],
     ]]]],
    ['narration' => ['text' => 'It is a truly enormous number of queries every single day.'],
     'layout_template' => 'big_counter',
     'slots' => ['slot_counter' => ['content_type' => 'text_block', 'heading' => 'A huge amount', 'bullets' => ['it keeps growing']]]],
]], ['hook_enabled' => false, 'outro_enabled' => false]);

$templates = array_map(fn ($s) => $s['layout_template'], $out['scenes']);
check('unfit timeline recast away', !in_array('timeline_card', $templates, true), implode(',', $templates));
check('unfit counter recast away', !in_array('big_counter', $templates, true), implode(',', $templates));
check('recast is reported', $out['changed'] === true);

$warned = array_filter($out['warnings'], fn ($w) => str_contains((string) $w, 'unsuitable'));
check('the timeline recast names its reason', count($warned) >= 1, implode(' | ', $out['warnings']));

// The content must SURVIVE the recast — a degrade that drops the rows would be
// worse than the wrong card.
$flat = json_encode($out['scenes']);
check('timeline rows survive the recast', str_contains($flat, 'page speed'));
check('counter text survives the recast', str_contains($flat, 'huge amount') || str_contains($flat, 'A huge amount'));

echo "\n== honest cards pass validate() untouched\n";
$good = (new ShotListValidator())->validate(['scenes' => [
    ['narration' => ['text' => 'The programme ran from 1969 to 1972 before returning in 2024.'],
     'layout_template' => 'timeline_card',
     'slots' => ['slot_timeline' => ['content_type' => 'timeline_nodes', 'nodes' => [
         ['date' => '1969', 'label' => 'first landing'],
         ['date' => '1972', 'label' => 'last landing'],
         ['date' => '2024', 'label' => 'the return'],
     ]]]],
    ['narration' => ['text' => 'That is five hundred million searches every single day of the year.'],
     'layout_template' => 'big_counter',
     'slots' => ['slot_counter' => ['content_type' => 'text_block', 'heading' => '500 Million', 'bullets' => ['searches a day']]]],
]], ['hook_enabled' => false, 'outro_enabled' => false]);

$goodTemplates = array_map(fn ($s) => $s['layout_template'], $good['scenes']);
check('real timeline kept', in_array('timeline_card', $goodTemplates, true), implode(',', $goodTemplates));
check('real counter kept', in_array('big_counter', $goodTemplates, true), implode(',', $goodTemplates));
check('no suitability warning on honest cards',
    count(array_filter($good['warnings'], fn ($w) => str_contains((string) $w, 'unsuitable'))) === 0,
    implode(' | ', $good['warnings']));

echo "\n" . str_repeat('-', 52) . "\n";
echo ($fail === 0 ? "PASS" : "FAIL") . "  {$pass}/" . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);
