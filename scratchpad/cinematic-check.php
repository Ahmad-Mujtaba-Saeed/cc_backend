<?php

/**
 * cinematic-check — cinematic_card: the sanitizer, the validator's budget and
 * degrade paths, the board strip, and the composer's casting + critique.
 *
 *   php scratchpad/cinematic-check.php            (no LLM, no database)
 *
 * The user's rules the checks encode: these cards are the video's KEY
 * explaining beats, several are fine, together at most 30% of the runtime,
 * and (so they never wreck the flow) never two in a row. Everything the model
 * can get wrong about a staging is repaired or refused here, never at render.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Support\CinematicScene;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ShotListValidator;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

$icons = array_flip(ExplainerRegistry::iconNames());
$exists = static fn (string $n) => isset($icons[$n]);
$narration = 'When a virus gets in, a vaccine hands your immune system a harmless copy first. '
    . 'It learns to build antibodies, and memory cells keep that recipe for years.';

$good = [
    'heading' => 'How a vaccine trains your immune system',
    'brief' => 'the virus, the harmless copy, the antibodies, the memory cells',
    'elements' => [
        ['id' => 'virus', 'kind' => 'icon', 'icon' => 'bug', 'text' => 'The virus', 'place' => 'left', 'depth' => 'far', 'word' => 'virus', 'camera' => 'push'],
        ['id' => 'copy', 'kind' => 'text', 'text' => 'A harmless copy', 'place' => 'center', 'depth' => 'mid', 'word' => 'copy', 'camera' => 'angle'],
        ['id' => 'antibodies', 'kind' => 'icon', 'icon' => 'shield', 'text' => 'Antibodies', 'place' => 'right', 'depth' => 'near', 'word' => 'antibodies', 'camera' => 'push'],
        ['id' => 'memory', 'kind' => 'stat', 'text' => 'Years', 'sub' => 'memory cells keep the recipe', 'place' => 'bottom', 'depth' => 'mid', 'word' => 'memory', 'camera' => 'rack'],
    ],
];

echo "\n== 1. the sanitizer keeps a good staging intact ==\n";
$r = CinematicScene::sanitize($good, $exists, $narration);
check('a good staging is ok', $r['ok'], implode('; ', $r['warnings']));
check('all four parts survive', count($r['slot']['elements']) === 4);
check('content type is cinematic', $r['slot']['content_type'] === 'cinematic');
check('cue words survive when spoken', array_column($r['slot']['elements'], 'word') === ['virus', 'copy', 'antibodies', 'memory']);
check('no warnings on clean input', $r['warnings'] === [], implode('; ', $r['warnings']));
$again = CinematicScene::sanitize($r['slot'], $exists, $narration);
check('sanitizing is idempotent', $again['slot'] == $r['slot']);

echo "\n== 2. enums fall back, strings cap, ids are unique ==\n";
$r = CinematicScene::sanitize(['elements' => [
    ['id' => 'x', 'kind' => 'text', 'text' => 'One', 'place' => 'middle-ish', 'depth' => 'deep', 'camera' => 'dolly'],
    ['id' => 'x', 'kind' => 'text', 'text' => str_repeat('long ', 40), 'place' => 'top', 'depth' => 'near', 'camera' => 'rack'],
    ['id' => '__bad id', 'kind' => 'banana', 'text' => 'Becomes text'],
]], $exists, '');
$els = $r['slot']['elements'];
check('unknown place -> center', $els[0]['place'] === 'center');
check('unknown depth -> mid', $els[0]['depth'] === 'mid');
check('unknown camera -> push', $els[0]['camera'] === 'push');
check('duplicate ids are made unique', $els[0]['id'] !== $els[1]['id'], $els[0]['id'] . ' / ' . $els[1]['id']);
check('text is capped at 90', mb_strlen($els[1]['text']) <= 90);
check('an unknown kind WITH words becomes text', $els[2]['kind'] === 'text');
check('ids are renderer-safe (start alphanumeric)', (bool) preg_match('/^[a-z0-9][a-z0-9-]*$/', $els[2]['id']), $els[2]['id']);

echo "\n== 3. cue words must actually be spoken ==\n";
$r = CinematicScene::sanitize(['elements' => [
    ['kind' => 'text', 'text' => 'A', 'word' => 'photosynthesis'],
    ['kind' => 'text', 'text' => 'B', 'word' => 'antibody'],
    ['kind' => 'text', 'text' => 'C', 'word' => 'immunes'],
]], $exists, $narration);
$els = $r['slot']['elements'];
check('a word never spoken is dropped', !isset($els[0]['word']));
check('an inflection still matches (antibody ~ antibodies)', ($els[1]['word'] ?? '') === 'antibody');
check('a cue one letter past a spoken word matches (immunes ~ immune)', ($els[2]['word'] ?? '') === 'immunes');
$r = CinematicScene::sanitize(['elements' => [
    ['kind' => 'text', 'text' => 'A', 'word' => 'antibodies'],
    ['kind' => 'text', 'text' => 'B', 'word' => 'island'],
]], $exists, 'A tale is told about an island in a sea.');
check('"a" alone does not count as saying "antibodies" (the stub-word bug)', !isset($r['slot']['elements'][0]['word']));
check('"island" is matched by the real word, not by "is"', ($r['slot']['elements'][1]['word'] ?? '') === 'island');

echo "\n== 4. kinds degrade instead of breaking ==\n";
$r = CinematicScene::sanitize(['elements' => [
    ['kind' => 'icon', 'icon' => 'definitely-not-an-icon', 'text' => 'Label'],
    ['kind' => 'icon', 'icon' => 'nope'],
    ['kind' => 'stat', 'text' => ''],
    ['kind' => 'formula', 'formula' => '', 'text' => 'no maths here'],
    ['kind' => 'formula', 'formula' => 'E = m*c^2', 'sub' => 'energy'],
]], $exists, '');
$kinds = array_column($r['slot']['elements'], 'kind');
check('an icon not in the library keeps its label as text', $kinds[0] === 'text');
check('an icon with no library match and no label is dropped', count($r['slot']['elements']) === 3, json_encode($kinds));
check('a formula with no formula but words becomes text', $kinds[1] === 'text');
check('a real formula survives', $kinds[2] === 'formula' && $r['slot']['elements'][2]['formula'] === 'E = m*c^2');
$r = CinematicScene::sanitize(['elements' => [
    ['kind' => 'formula', 'formula' => 'y = A sin(wt + p)', 'sub' => 'the mirror wave'],
    ['kind' => 'text', 'text' => 'Other'],
]], $exists, 'The chip builds a mirror wave that rises where the noise falls.');
check('an invented formula becomes its sub line as text', $r['slot']['elements'][0]['kind'] === 'text' && $r['slot']['elements'][0]['text'] === 'the mirror wave');
$r = CinematicScene::sanitize(['elements' => [
    ['kind' => 'formula', 'formula' => 'A = P*(1 + r)^t', 'sub' => 'the balance'],
    ['kind' => 'text', 'text' => 'Other'],
]], $exists, 'The balance grows by a formula over time.');
check('a formula the narration talks about is kept', $r['slot']['elements'][0]['kind'] === 'formula');
$r = CinematicScene::sanitize(['elements' => [
    ['kind' => 'formula', 'formula' => 'v = 340 m/s'], ['kind' => 'text', 'text' => 'A'], ['kind' => 'text', 'text' => 'B'],
]], $exists, 'Sound moves at 340 metres every second.');
check('a formula sharing a spoken number is kept', $r['slot']['elements'][0]['kind'] === 'formula');

echo "\n== 5. html parts are sanitised like custom_card ==\n";
$r = CinematicScene::sanitize(['elements' => [
    ['kind' => 'html', 'html' => '<div class="cell" onclick="x()">Nucleus<script>alert(1)</script><img src="https://evil/x.png"></div>'],
    ['kind' => 'text', 'text' => 'Other'],
], 'css' => '.cell{box-shadow:0 0 9px red;color:var(--accent)} @import url(x.css);'], $exists, '');
$html = $r['slot']['elements'][0]['html'] ?? '';
check('script is gone', !str_contains($html, 'script') && !str_contains($html, 'alert'), $html);
check('handlers are gone', !str_contains($html, 'onclick'));
check('img is gone', !str_contains($html, '<img'));
check('the text survives', str_contains($html, 'Nucleus'));
$css = $r['slot']['css'] ?? '';
check('css is scoped to the card', str_contains($css, '.cc-scope'), $css);
check('the flat-law strip still applies to shadows', !str_contains($css, 'box-shadow'), $css);
check('@import is gone', !str_contains($css, '@import'));
$r = CinematicScene::sanitize(['elements' => [
    ['kind' => 'text', 'text' => 'One'],
    ['kind' => 'text', 'text' => 'Two'],
], 'css' => '.x{color:red}'], $exists, '');
check('css is not kept when no part is html', !isset($r['slot']['css']));

echo "\n== 6. depth, counts, rejection ==\n";
$flat = ['elements' => array_map(fn ($i) => ['kind' => 'text', 'text' => "Part {$i}", 'depth' => 'mid'], range(1, 4))];
$r = CinematicScene::sanitize($flat, $exists, '');
check('an all-one-plane staging gets its depths spread', count(array_unique(array_column($r['slot']['elements'], 'depth'))) === 3);
$r = CinematicScene::sanitize(['elements' => array_map(fn ($i) => ['kind' => 'text', 'text' => "P{$i}"], range(1, 9))], $exists, '');
check('parts are capped at six', count($r['slot']['elements']) === 6);
$r = CinematicScene::sanitize(['elements' => [['kind' => 'text', 'text' => 'Lonely']]], $exists, '');
check('one part is rejected (not a staging)', !$r['ok']);
check('isPending: brief without parts', CinematicScene::isPending(['brief' => 'x']));
check('isPending: staged is not pending', !CinematicScene::isPending($good));

echo "\n== 7. registry ==\n";
$reg = ExplainerRegistry::all();
check('registry version bumped to 48', (int) $reg['version'] === 48);
check('cinematic_card template exists with slot_cinematic', isset($reg['templates']['cinematic_card']['slots']['slot_cinematic']));
check('slot accepts the cinematic content type', ExplainerRegistry::allowedContentTypes('cinematic_card', 'slot_cinematic') === ['cinematic']);
check('runtime share is 30%', abs(ExplainerRegistry::cinematicMaxShare() - 0.3) < 1e-9);

echo "\n== 8. validator: keeps, degrades, budgets ==\n";
$scene = fn (string $id, string $tpl, array $slots, string $narr, float $secs = 8.0) => [
    'scene_id' => $id, 'duration_seconds' => $secs, 'narration' => ['text' => $narr],
    'layout_template' => $tpl, 'slots' => $slots,
];
$text = fn (string $id, string $h, float $secs = 6.0) => $scene($id, 'single_focus', ['slot_main' => [
    'content_type' => 'text_block', 'heading' => $h, 'bullets' => ["A plain line about {$h}"],
]], "This is the plain beat about {$h}, spoken at an ordinary pace for a while.", $secs);
$cine = fn (string $id, array $slot, float $secs = 12.0) => $scene($id, 'cinematic_card', ['slot_cinematic' => $slot], $narration, $secs);

/** The text block a demoted card became — wherever the media floor then put it. */
$textOf = function (array $scene): array {
    foreach ((array) ($scene['slots'] ?? []) as $slot) {
        if (is_array($slot) && ($slot['content_type'] ?? '') === 'text_block') {
            return $slot;
        }
    }
    return [];
};

$v = new ShotListValidator();
$out = $v->validate(['scenes' => [
    $text('a', 'Opening'), $cine('c1', $good + ['content_type' => 'cinematic']), $text('b', 'Middle'),
    $text('d', 'More'), $text('e', 'Even more'), $text('f', 'Last'),
]], ['hook_enabled' => false, 'outro_enabled' => false]);
$c1 = array_values(array_filter($out['scenes'], fn ($s) => $s['scene_id'] === 'c1'))[0] ?? null;
check('a staged cinematic card survives validation', ($c1['layout_template'] ?? '') === 'cinematic_card', json_encode($out['warnings']));
check('its parts survive', count($c1['slots']['slot_cinematic']['elements'] ?? []) === 4);
$twice = (new ShotListValidator())->validate(['scenes' => $out['scenes']], ['hook_enabled' => false, 'outro_enabled' => false]);
$c1b = array_values(array_filter($twice['scenes'], fn ($s) => $s['scene_id'] === 'c1'))[0] ?? null;
check('validation is idempotent for the card', ($c1b['slots']['slot_cinematic'] ?? null) == ($c1['slots']['slot_cinematic'] ?? null));

$out = (new ShotListValidator())->validate(['scenes' => [
    $text('a', 'Opening'),
    $cine('pending', ['content_type' => 'cinematic', 'heading' => 'Why it works', 'brief' => 'the three parts']),
    $text('b', 'After'),
]], ['hook_enabled' => false, 'outro_enabled' => false]);
$p = array_values(array_filter($out['scenes'], fn ($s) => $s['scene_id'] === 'pending'))[0] ?? null;
check('an unstaged card degrades to an ordinary card', ($p['layout_template'] ?? '') !== 'cinematic_card', $p['layout_template'] ?? 'missing');
check('...keeping its heading', ($textOf($p)['heading'] ?? '') === 'Why it works', json_encode($p['slots'] ?? []));

$out = (new ShotListValidator())->validate(['scenes' => [
    $text('a', 'Opening', 6), $cine('c1', $good), $cine('c2', $good), $text('b', 'x', 6), $text('c', 'y', 6),
    $text('d', 'z', 6), $text('e', 'w', 6), $text('f', 'v', 6), $text('g', 'u', 6), $text('h', 't', 6),
]], ['hook_enabled' => false, 'outro_enabled' => false]);
$tpls = array_column($out['scenes'], 'layout_template', 'scene_id');
check('never two in a row: the second of a pair is demoted', ($tpls['c1'] ?? '') === 'cinematic_card' && ($tpls['c2'] ?? '') !== 'cinematic_card', json_encode($tpls));
$c2 = array_values(array_filter($out['scenes'], fn ($s) => $s['scene_id'] === 'c2'))[0];
check('a demoted card keeps its parts as bullets', count($textOf($c2)['bullets'] ?? []) >= 3, json_encode($c2['slots']));
check('...a stat keeps its label', in_array('Years — memory cells keep the recipe', $textOf($c2)['bullets'] ?? [], true));

// Budget: three spaced cinematic cards in a short video — way over 30%.
$rich = $good;
$rich['elements'][] = ['kind' => 'text', 'text' => 'Fifth part', 'place' => 'top', 'depth' => 'near'];
$three = ['elements' => array_slice($good['elements'], 0, 3)];
$out = (new ShotListValidator())->validate(['scenes' => [
    $text('a', 'Open', 5), $cine('c1', $three), $text('b', 'x', 5), $cine('c2', $rich), $text('c', 'y', 5),
    $cine('c3', $good), $text('d', 'z', 5),
]], ['hook_enabled' => false, 'outro_enabled' => false]);
$total = array_sum(array_column($out['scenes'], 'duration_seconds'));
$cineSecs = array_sum(array_map(
    fn ($s) => $s['layout_template'] === 'cinematic_card' ? (float) $s['duration_seconds'] : 0.0,
    $out['scenes']
));
$kept = array_column(array_filter($out['scenes'], fn ($s) => $s['layout_template'] === 'cinematic_card'), 'scene_id');
check('cinematic cards end at or under 30% of the runtime', $cineSecs <= $total * 0.3 + 0.01, sprintf('%.1fs of %.1fs', $cineSecs, $total));
check('at least one survives (the cap trims, it does not wipe)', count($kept) >= 1, json_encode($kept));
check('the richest staging is the one kept', in_array('c2', $kept, true), json_encode($kept));
check('the budget is reported in the warnings', (bool) array_filter($out['warnings'], fn ($w) => str_contains($w, 'of the runtime')));

echo "\n== 9. the maths board never shows one ==\n";
$board = (new ShotListValidator())->stripBoardMediaSlots([$cine('c1', CinematicScene::sanitize($good, $exists, $narration)['slot'])]);
check('on the board it becomes a plain card', $board[0]['layout_template'] === 'single_focus');
check('...with its parts as bullets', count($board[0]['slots']['slot_main']['bullets'] ?? []) >= 3);

echo "\n== 10. composer casting ==\n";
foreach (['point', 'aspect', 'resolution', 'turning_point'] as $intent) {
    check("offered on '{$intent}'", in_array('cinematic_card', GenericStoryboardComposerService::menuFor($intent), true));
}
foreach (['hook', 'context', 'ranking_reveal', 'contenders', 'setup'] as $intent) {
    check("NOT offered on '{$intent}'", !in_array('cinematic_card', GenericStoryboardComposerService::menuFor($intent), true));
}
check('offered early in the explanation menus (not buried last)', array_search('cinematic_card', GenericStoryboardComposerService::menuFor('point'), true) <= 2);
$doc = GenericStoryboardComposerService::cardDocs()['cinematic_card'] ?? '';
check('the doc names the slot the registry declares', str_contains($doc, 'slot_cinematic'));
check('the doc states the 30% rule and the adjacency rule', str_contains($doc, '30%') && str_contains($doc, 'never sit next to each other'));
check('the doc asks for a brief, not a staging', str_contains($doc, 'brief') && !str_contains($doc, 'elements'));

// The constructor reads the model setting (a database row); the critique
// needs neither, so build it without.
$composer = (new ReflectionClass(GenericStoryboardComposerService::class))->newInstanceWithoutConstructor();
$m = new ReflectionMethod($composer, 'critiqueCinematic');
$m->setAccessible(true);
$sk = array_map(fn ($i) => ['intent' => $i, 'brief' => 'x'], ['hook', 'context', 'point', 'point', 'aspect', 'payoff']);
$mk = fn (string $tpl, float $secs = 8.0) => ['layout_template' => $tpl, 'duration_seconds' => $secs, 'narration' => ['text' => 'words words words'], 'slots' => []];
$plain = array_map(fn () => $mk('single_focus'), range(1, 6));
$faults = $m->invoke($composer, $plain, $sk);
check('no cinematic beat in an explaining video -> nudged once', count($faults) === 1 && str_contains($faults[0], 'cinematic_card'), $faults[0] ?? '');
$pair = [$mk('single_focus'), $mk('cinematic_card', 10), $mk('cinematic_card', 10), $mk('single_focus', 20), $mk('single_focus', 20), $mk('single_focus', 20)];
$faults = $m->invoke($composer, $pair, $sk);
check('two in a row -> named', (bool) array_filter($faults, fn ($f) => str_contains($f, 'next to each other')));
$heavy = [$mk('single_focus', 4), $mk('cinematic_card', 14), $mk('single_focus', 4), $mk('cinematic_card', 14), $mk('single_focus', 4), $mk('single_focus', 4)];
$faults = $m->invoke($composer, $heavy, $sk);
check('over 30% -> named with the share', (bool) array_filter($faults, fn ($f) => str_contains($f, 'the limit is 30%')), implode(' | ', $faults));
$fine = [$mk('single_focus', 8), $mk('cinematic_card', 12), $mk('single_focus', 8), $mk('single_focus', 8), $mk('single_focus', 8), $mk('single_focus', 8)];
check('one well-placed card under budget -> no fault', $m->invoke($composer, $fine, $sk) === []);
$short = array_map(fn () => $mk('single_focus'), range(1, 4));
check('a very short video is not nudged', $m->invoke($composer, $short, array_slice($sk, 0, 4)) === []);

echo "\n== 11. narration must name the pieces ==\n";
$thin = ['layout_template' => 'cinematic_card', 'duration_seconds' => 12,
    'narration' => ['text' => 'Over days, your immune system learns to identify invaders. It takes time, but it is critical for defense.'],
    'slots' => ['slot_cinematic' => ['brief' => 'how your immune system learns: the virus, the spike protein, the antibodies, the T cells']]];
$missing = GenericStoryboardComposerService::unspokenPieces($thin);
check('a narration that names none of the pieces is caught', count($missing) >= 3, implode(' ', $missing));
$rich = $thin;
$rich['narration']['text'] = 'The virus carries a spike protein. Your body builds antibodies to grab it, and T cells hunt the infected cells.';
check('a narration that names them passes', GenericStoryboardComposerService::unspokenPieces($rich) === []);
$faults = $m->invoke($composer, [$mk('single_focus'), $thin, $mk('single_focus'), $mk('single_focus'), $mk('single_focus'), $mk('single_focus')], $sk);
check('the critique names the unspoken pieces', (bool) array_filter($faults, fn ($f) => str_contains($f, 'never says them')));

echo "\n== 12. the guarantee: promote when none was cast ==\n";
$ensure = new ReflectionMethod($composer, 'ensureCinematic');
$ensure->setAccessible(true);
$long = 'Noise cancelling works by listening to the room, building the mirror image of that sound, and playing it back so the two waves meet and cancel each other out.';
$tb = fn (string $h, array $b, string $narr) => ['scene_id' => 's', 'layout_template' => 'single_focus', 'duration_seconds' => 8,
    'narration' => ['text' => $narr], 'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => $h, 'bullets' => $b]]];
$chart = ['scene_id' => 'c', 'layout_template' => 'animated_chart', 'duration_seconds' => 8, 'narration' => ['text' => $long . ' ' . $long],
    'slots' => ['slot_chart' => ['content_type' => 'chart', 'values' => [1, 2], 'labels' => ['a', 'b']]]];
$board = [
    $tb('Hook', [], 'Short opening line here.'),
    $tb('Context', [], 'A short context line.'),
    $chart,
    $tb('How it cancels', ['A microphone listens', 'A chip builds the mirror wave', 'The waves cancel'], $long),
    $tb('Point two', [], 'A shorter point that is not long enough.'),
    $tb('Payoff', [], 'The end.'),
];
$promoted = $ensure->invoke($composer, $board, $sk);
check('the longest text-led explaining beat is promoted', $promoted[3]['layout_template'] === 'cinematic_card', $promoted[3]['layout_template']);
check('...its items become the brief', str_contains($promoted[3]['slots']['slot_cinematic']['brief'] ?? '', 'A chip builds the mirror wave'));
check('...its narration is kept', $promoted[3]['narration']['text'] === $long);
check('a chart is never converted (it keeps its visual)', $promoted[2]['layout_template'] === 'animated_chart');
check('exactly one is promoted', count(array_filter($promoted, fn ($s) => $s['layout_template'] === 'cinematic_card')) === 1);
$already = $board;
$already[1]['layout_template'] = 'cinematic_card';
check('nothing is promoted when one was already cast', $ensure->invoke($composer, $already, $sk)[3]['layout_template'] === 'single_focus');
check('a short video is left alone', $ensure->invoke($composer, array_slice($board, 0, 4), array_slice($sk, 0, 4))[3]['layout_template'] === 'single_focus');

echo "\n== 13. weak stagings earn one retry ==\n";
$weak = Modules\Project\Services\CinematicSceneService::weaknesses(['elements' => [
    ['kind' => 'text', 'place' => 'top_left'], ['kind' => 'text', 'place' => 'top'], ['kind' => 'text', 'place' => 'top_right'],
]]);
check('all text is named', (bool) array_filter($weak, fn ($w) => str_contains($w, 'plain text')));
check('one row is named', (bool) array_filter($weak, fn ($w) => str_contains($w, 'one row')));
check('a varied two-row staging is not weak', Modules\Project\Services\CinematicSceneService::weaknesses($good) === []);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
