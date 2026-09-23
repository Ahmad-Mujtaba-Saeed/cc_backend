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

echo "\n== 5. a drawn part carries a subject, not markup ==\n";
$r = CinematicScene::sanitize(['elements' => [
    ['id' => 'tap', 'kind' => 'visual', 'prompt' => str_repeat('a kitchen tap with a curved spout ', 12),
        'title' => 'The tap that is far too long to print', 'status' => 'CLOSED-FOR-EVER', 'note' => str_repeat('drips ', 20), 'tone' => 'glittery'],
    ['id' => 'nothing', 'kind' => 'visual', 'title' => 'No subject'],
    ['id' => 'pipe', 'kind' => 'visual', 'prompt' => 'a length of copper pipe', 'text' => 'Pipe', 'sub' => 'ignored',
        'image_path' => 'explainer/flow/pipe-abc123.png'],
    ['id' => 'evil', 'kind' => 'visual', 'prompt' => 'a valve', 'image_path' => '../../etc/passwd.png'],
], 'css' => '.x{color:red}'], $exists, '');
$by = array_column($r['slot']['elements'], null, 'id');
check('a subject is kept and capped', mb_strlen($by['tap']['prompt'] ?? '') <= 180 && str_starts_with($by['tap']['prompt'] ?? '', 'a kitchen tap'));
check('title, status and note are capped', mb_strlen($by['tap']['title'] ?? '') <= 20 && mb_strlen($by['tap']['status'] ?? '') <= 12 && mb_strlen($by['tap']['note'] ?? '') <= 40);
check('an unknown tone is dropped', !isset($by['tap']['tone']));
check('a visual with nothing to draw is dropped', !isset($by['nothing']));
check('a title falls back to the part text', ($by['pipe']['title'] ?? '') === 'Pipe');
check('drawn parts carry no sub line', !isset($by['pipe']['sub']));
check('an already drawn part keeps its picture', ($by['pipe']['image_path'] ?? '') === 'explainer/flow/pipe-abc123.png');
check('a path that climbs out of the disk is refused', !isset($by['evil']['image_path']));
check('css is not kept (no part writes markup any more)', !isset($r['slot']['css']));
$r = CinematicScene::sanitize(['elements' => [
    ['id' => 'a', 'kind' => 'visual', 'prompt' => 'a human heart with two upper chambers labeled atria', 'title' => 'Atria'],
    ['id' => 'b', 'kind' => 'visual', 'prompt' => 'a server rack with the word server on the front', 'title' => 'Server'],
    ['id' => 'c', 'kind' => 'visual', 'prompt' => 'a pair of human lungs seen from the front', 'title' => 'Lungs'],
]], $exists, '');
$by = array_column($r['slot']['elements'], null, 'id');
check('a subject may not ask for writing the image model cannot do',
    ($by['a']['prompt'] ?? '') === 'a human heart with two upper chambers' && ($by['b']['prompt'] ?? '') === 'a server rack',
    json_encode(array_column($r['slot']['elements'], 'prompt')));
check('a clean subject is left exactly as it was', ($by['c']['prompt'] ?? '') === 'a pair of human lungs seen from the front');
$again = CinematicScene::sanitize($r['slot'], $exists, '');
check('drawn parts are idempotent through a second sanitize', $again['slot'] == $r['slot']);

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
check('registry version bumped to 51', (int) $reg['version'] === 51);
check('the depth rig has its levels + a default', ExplainerRegistry::motionDepthNames() === ['off', 'subtle', 'full']
    && ExplainerRegistry::defaultMotionDepth() === 'subtle', implode('|', ExplainerRegistry::motionDepthNames()));
check('cinematic_card template exists with slot_cinematic', isset($reg['templates']['cinematic_card']['slots']['slot_cinematic']));
check('slot accepts the cinematic content type', ExplainerRegistry::allowedContentTypes('cinematic_card', 'slot_cinematic') === ['cinematic']);
check('the registry lists the drawn kind first', ($reg['cinematic']['kinds'][0] ?? '') === 'visual', json_encode($reg['cinematic']['kinds'] ?? []));
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
// The demote writes bullets; a later pass may move those same words onto a
// checklist when the generic cards are over their share (iter 74). What must
// never change is the WORDS, so the check follows the content, not the card.
$partsOf = function (array $scene): array {
    foreach ((array) ($scene['slots'] ?? []) as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        foreach (['bullets', 'pros'] as $field) {
            if (!empty($slot[$field])) {
                return (array) $slot[$field];
            }
        }
        if (!empty($slot['items'])) {
            return array_map(fn ($i) => (string) ($i['label'] ?? ''), (array) $slot['items']);
        }
    }

    return [];
};
check('a demoted card keeps its parts', count($partsOf($c2)) >= 3, json_encode($c2['slots']));
check('...every part verbatim', in_array('Years — memory cells keep the recipe', $partsOf($c2), true), implode(' | ', $partsOf($c2)));

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
$teaching = $mk('cinematic_card', 12);
$teaching['narration']['text'] = 'Your request reaches the server, the server checks the cache first, and when the cache is empty the database does the slow work and hands the answer back to be kept.';
$fine = [$mk('single_focus', 8), $teaching, $mk('single_focus', 8), $mk('single_focus', 8), $mk('single_focus', 8), $mk('single_focus', 8)];
check('one well-placed card under budget -> no fault', $m->invoke($composer, $fine, $sk) === [], implode(' | ', $m->invoke($composer, $fine, $sk)));
$faults = $m->invoke($composer, [$mk('single_focus', 8), $mk('cinematic_card', 12), $mk('single_focus', 8), $mk('single_focus', 8), $mk('single_focus', 8), $mk('single_focus', 8)], $sk);
check('a one-line cinematic narration is sent back to teach', (bool) array_filter($faults, fn ($f) => str_contains($f, '25-35 words')));

echo "\n== 10b. the core phase is cast on purpose ==\n";
$skel = [
    ['intent' => 'hook', 'brief' => 'Users keep getting logged out'],
    ['intent' => 'context', 'brief' => 'The app added two servers behind a load balancer'],
    ['intent' => 'point', 'brief' => 'A striking number about outages'],
    ['intent' => 'point', 'brief' => 'Why it happens: how each server keeps sessions in its own memory and the balancer sends clicks anywhere'],
    ['intent' => 'point', 'brief' => 'What users say about it'],
    ['intent' => 'resolution', 'brief' => 'A shared session store fixes it'],
    ['intent' => 'payoff', 'brief' => 'Stay logged in'],
];
check('the mechanism phase is the core', GenericStoryboardComposerService::corePhase($skel) === 3, (string) GenericStoryboardComposerService::corePhase($skel));
check('no explaining phase -> no core', GenericStoryboardComposerService::corePhase([['intent' => 'hook', 'brief' => 'x'], ['intent' => 'payoff', 'brief' => 'y']]) === null);
$phaseMenu = new ReflectionMethod($composer, 'menuForPhase');
$phaseMenu->setAccessible(true);
$coreProp = new ReflectionProperty($composer, 'corePhase');
$coreProp->setAccessible(true);
$coreProp->setValue($composer, 3);
check('the core phase offers only cinematic_card', $phaseMenu->invoke($composer, 3, 'point') === ['cinematic_card']);
// A menu is now ORDERED by fit (iter 74): the same cards, with the ones the
// phase's own words ask for first and the generic fallback last.
$plain = $phaseMenu->invoke($composer, 2, 'point', '');
check('other phases keep every card their intent offers',
    array_diff(GenericStoryboardComposerService::menuFor('point'), $plain) === []);
check('...with single_focus moved to the end, not the front',
    end($plain) === 'single_focus' && $plain[0] !== 'single_focus', $plain[0] . ' ... ' . end($plain));
$shaped = $phaseMenu->invoke($composer, 2, 'point', 'Renting versus buying: what each one costs');
check('...and a beat that names a shape is offered that card first',
    $shaped[0] === 'versus_card', implode(', ', array_slice($shaped, 0, 3)));
$coreProp->setValue($composer, null);
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
$svc = Modules\Project\Services\CinematicSceneService::class;
$weak = $svc::weaknesses(['elements' => [
    ['kind' => 'text', 'place' => 'top_left'], ['kind' => 'text', 'place' => 'top'], ['kind' => 'text', 'place' => 'top_right'], ['kind' => 'text', 'place' => 'top'],
]]);
check('all text is named (too little drawn)', (bool) array_filter($weak, fn ($w) => str_contains($w, 'DRAWN')));
check('no links is named', (bool) array_filter($weak, fn ($w) => str_contains($w, 'nothing is connected')));
check('one row is named', (bool) array_filter($weak, fn ($w) => str_contains($w, 'one row')));
$diagram = ['elements' => [
    ['id' => 'a', 'kind' => 'visual', 'place' => 'left'], ['id' => 'b', 'kind' => 'visual', 'place' => 'center', 'then' => [['status' => 'SET']]], ['id' => 'c', 'kind' => 'visual', 'place' => 'bottom_right'],
], 'links' => [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'c']]];
check('a drawn, linked, two-row staging is not weak', $svc::weaknesses($diagram) === [], implode(' | ', $svc::weaknesses($diagram)));

echo "\n== 14. the diagram language: every piece is drawn ==\n";
$narr = 'Everyone hits the same server, and it is drowning at ninety percent. So you put a load balancer in front, '
    . 'and it picks one of three servers for every request, so the load spreads out evenly.';
$flow = ['heading' => 'What a load balancer does', 'elements' => [
    ['id' => 'crowd', 'kind' => 'visual', 'prompt' => 'a group of three simple standing person figures side by side',
        'title' => 'Everyone', 'note' => 'every visitor', 'place' => 'left', 'depth' => 'far', 'word' => 'everyone', 'camera' => 'push'],
    ['id' => 's1', 'kind' => 'visual', 'prompt' => 'a tall server rack cabinet with stacked slots',
        'title' => 'Server 1', 'status' => '90%', 'tone' => 'bad', 'place' => 'top_right', 'depth' => 'mid', 'word' => 'drowning', 'camera' => 'push',
        'then' => [['word' => 'evenly', 'status' => '34%', 'tone' => 'accent']]],
    ['id' => 'lb', 'kind' => 'visual', 'prompt' => 'a network router box with three short antennas on top',
        'title' => 'Balancer', 'note' => 'picks one', 'place' => 'center', 'depth' => 'near', 'word' => 'balancer', 'camera' => 'angle'],
    ['id' => 's2', 'kind' => 'visual', 'prompt' => 'a tall server rack cabinet with stacked slots',
        'title' => 'Server 2', 'status' => '5%', 'tone' => 'muted', 'place' => 'right', 'depth' => 'mid', 'word' => 'three', 'camera' => 'rack',
        'then' => [['word' => 'evenly', 'status' => '33%', 'tone' => 'accent']]],
], 'links' => [
    ['from' => 'crowd', 'to' => 'lb', 'flow' => true],
    ['from' => 'lb', 'to' => 's1', 'flow' => true],
    ['from' => 'lb', 'to' => 's2', 'flow' => true],
]];
$r = CinematicScene::sanitize($flow, $exists, $narr);
check('the reference flow survives whole', $r['ok'] && count($r['slot']['elements']) === 4, implode('; ', $r['warnings']));
check('every part is drawn', array_column($r['slot']['elements'], 'kind') === ['visual', 'visual', 'visual', 'visual']);
check('the repeated component keeps the SAME subject word for word',
    $r['slot']['elements'][1]['prompt'] === $r['slot']['elements'][3]['prompt']);
check('tones survive', ($r['slot']['elements'][1]['tone'] ?? '') === 'bad' && ($r['slot']['elements'][3]['tone'] ?? '') === 'muted');
check('the fan-out links survive', count($r['slot']['links']) === 3);
$again = CinematicScene::sanitize($r['slot'], $exists, $narr);
check('the whole flow is idempotent', $again['slot'] == $r['slot']);

echo "\n== 15. state changes ==\n";
$r = CinematicScene::sanitize(['elements' => [
    ['id' => 'cache', 'kind' => 'visual', 'prompt' => 'a metal storage box with a hinged lid', 'title' => 'Cache', 'status' => 'MISS', 'tone' => 'bad', 'then' => [
        ['word' => 'put', 'status' => 'SET', 'tone' => 'accent', 'note' => 'answer kept for next time'],
        ['word' => 'xylophone', 'status' => 'STALE'],
        ['word' => 'server', 'status' => 'THIRD'],
    ]],
    ['id' => 'b', 'kind' => 'visual', 'prompt' => 'a paper envelope', 'title' => 'B', 'then' => [['word' => 'server']]],
    ['id' => 'c', 'kind' => 'visual', 'prompt' => 'a filing cabinet', 'title' => 'C'],
]], $exists, 'Your request goes to the server, the cache misses, and the answer is put in the cache on the way back.');
$by = array_column($r['slot']['elements'], null, 'id');
check('a change keeps its cue and its fields', ($by['cache']['then'][0]['word'] ?? '') === 'put' && ($by['cache']['then'][0]['status'] ?? '') === 'SET');
check('a change carries a new note', ($by['cache']['then'][0]['note'] ?? '') === 'answer kept for next time');
check('at most two changes per part', count($by['cache']['then']) === 2);
check('a change whose cue is never spoken keeps its fields, loses its word', !isset($by['cache']['then'][1]['word']) && ($by['cache']['then'][1]['status'] ?? '') === 'STALE');
check('a change that changes nothing is dropped', !isset($by['b']['then']));

echo "\n== 16. links ==\n";
$r = CinematicScene::sanitize(['elements' => [
    ['id' => 'a', 'kind' => 'visual', 'prompt' => 'a paper envelope', 'title' => 'A'],
    ['id' => 'a', 'kind' => 'visual', 'prompt' => 'a second envelope', 'title' => 'Second A'],
    ['id' => 'Cache Box', 'kind' => 'visual', 'prompt' => 'a metal storage box', 'title' => 'Cache'],
], 'links' => [
    ['from' => 'a', 'to' => 'Cache Box', 'label' => 'goes to the cache every single time', 'style' => 'elbow', 'tone' => 'accent', 'flow' => 1],
    ['from' => 'a', 'to' => 'cache-box'],
    ['from' => 'a', 'to' => 'a'],
    ['from' => 'a', 'to' => 'ghost'],
    ['from' => 'a-2', 'to' => 'cache-box', 'style' => 'zigzag', 'tone' => 'rainbow'],
]], $exists, '');
$links = $r['slot']['links'] ?? [];
check('links resolve the model ids (and slugged ones)', ($links[0]['from'] ?? '') === 'a' && ($links[0]['to'] ?? '') === 'cache-box');
check('a duplicate pair is dropped', count(array_filter($links, fn ($l) => $l['from'] === 'a' && $l['to'] === 'cache-box')) === 1);
check('a self-link and a link to a missing part are dropped', count($links) === 2, json_encode($links));
check('the renamed duplicate part can still be linked by its final id', ($links[1]['from'] ?? '') === 'a-2');
check('labels are capped, styles/tones enumerated, flow is boolean', mb_strlen($links[0]['label']) <= 24 && $links[0]['style'] === 'elbow' && $links[0]['flow'] === true && !isset($links[1]['style']) && !isset($links[1]['tone']));
$again = CinematicScene::sanitize($r['slot'], $exists, '');
check('links survive a second sanitize (idempotent)', ($again['slot']['links'] ?? null) == $links);
$many = ['elements' => array_map(fn ($i) => ['id' => "p{$i}", 'kind' => 'visual', 'prompt' => "object {$i}", 'title' => "P{$i}"], range(0, 5)), 'links' => []];
foreach (range(0, 5) as $a) {
    foreach (range(0, 5) as $b) {
        $many['links'][] = ['from' => "p{$a}", 'to' => "p{$b}"];
    }
}
check('links are capped at eight', count(CinematicScene::sanitize($many, $exists, '')['slot']['links']) === 8);

echo "\n== 17. demotion reads drawn parts ==\n";
$demote = new ReflectionMethod(ShotListValidator::class, 'cinematicAsText');
$demote->setAccessible(true);
[$h, $bullets] = $demote->invoke(new ShotListValidator(), ['heading' => 'Why caching helps', 'elements' => [
    ['kind' => 'visual', 'prompt' => 'a metal storage box', 'title' => 'Cache', 'status' => 'SET'],
    ['kind' => 'visual', 'prompt' => 'three person figures', 'title' => 'Everyone'],
    ['kind' => 'stat', 'text' => '240 ms', 'sub' => 'saved on every hit'],
]]);
check('a drawn part becomes "Cache — SET"', in_array('Cache — SET', $bullets, true), json_encode($bullets));
check('a drawn part with no state becomes its title', in_array('Everyone', $bullets, true));
check('a stat keeps its sub line', in_array('240 ms — saved on every hit', $bullets, true));

echo "\n== 18. the staging gate asks for drawings that change and connect ==\n";
check('the reference flow is not weak', $svc::weaknesses($r2 = CinematicScene::sanitize($flow, $exists, $narr)['slot']) === [], implode(' | ', $svc::weaknesses($r2)));
$static = $r2;
unset($static['elements'][1]['then'], $static['elements'][3]['then']);
check('a diagram that never changes is sent back', (bool) array_filter($svc::weaknesses($static), fn ($w) => str_contains($w, 'nothing changes')));
$loose = $r2;
$loose['links'] = [];
check('a diagram with nothing connected is sent back', (bool) array_filter($svc::weaknesses($loose), fn ($w) => str_contains($w, 'connected')));
$words = ['elements' => [['kind' => 'stat', 'place' => 'left'], ['kind' => 'text', 'place' => 'center'], ['kind' => 'icon', 'place' => 'bottom']], 'links' => [['from' => 'a', 'to' => 'b']]];
check('a staging of labels and numbers is sent back to draw', (bool) array_filter($svc::weaknesses($words), fn ($w) => str_contains($w, 'DRAWN')));
$sameThing = ['elements' => [
    ['kind' => 'visual', 'prompt' => 'a human heart showing the right atrium', 'place' => 'left', 'then' => [['status' => 'FULL']]],
    ['kind' => 'visual', 'prompt' => 'a human heart showing the left atrium', 'place' => 'center'],
    ['kind' => 'visual', 'prompt' => 'a human heart with the aorta', 'place' => 'bottom'],
], 'links' => [['from' => 'a', 'to' => 'b']]];
check('the same object drawn three ways is sent back', (bool) array_filter($svc::weaknesses($sameThing), fn ($w) => str_contains($w, 'same object')), implode(' | ', $svc::weaknesses($sameThing)));
$repeats = ['elements' => [
    ['kind' => 'visual', 'prompt' => 'a tall server rack cabinet', 'place' => 'left', 'then' => [['status' => '34%']]],
    ['kind' => 'visual', 'prompt' => 'a tall server rack cabinet', 'place' => 'center'],
    ['kind' => 'visual', 'prompt' => 'a tall server rack cabinet', 'place' => 'bottom'],
    ['kind' => 'visual', 'prompt' => 'a network router box', 'place' => 'top'],
], 'links' => [['from' => 'a', 'to' => 'b']]];
check('three of ONE component (identical subjects) is fine', $svc::weaknesses($repeats) === [], implode(' | ', $svc::weaknesses($repeats)));

echo "\n== 18b. the drawings: prompt, cache key, keying ==\n";
$flowSvc = new Modules\Project\Services\FlowVisualService();
$prompt = Modules\Project\Services\FlowVisualService::prompt('a tall server rack cabinet');
check('the drawing prompt names the subject and forbids text',
    str_contains($prompt, 'a tall server rack cabinet') && str_contains($prompt, 'no text') && str_contains($prompt, 'white background'));
check('the same subject gives the same prompt (so it is drawn once)',
    $prompt === Modules\Project\Services\FlowVisualService::prompt('  a tall server rack cabinet  '));
$stencil = new ReflectionMethod(Modules\Project\Services\FlowVisualService::class, 'stencil');
$stencil->setAccessible(true);
// A drawing the model might hand back: a grey page, a lighter panel behind
// the object, and the object itself in black.
$page = imagecreatetruecolor(200, 200);
imagefill($page, 0, 0, imagecolorallocate($page, 226, 226, 226));
imagefilledrectangle($page, 30, 30, 170, 170, imagecolorallocate($page, 214, 214, 214));
imagefilledrectangle($page, 80, 60, 120, 140, imagecolorallocate($page, 8, 8, 8));
ob_start();
imagepng($page);
$pageBytes = (string) ob_get_clean();
$keyed = $stencil->invoke($flowSvc, $pageBytes);
check('a drawing keys to a png', is_string($keyed) && str_starts_with($keyed, "\x89PNG"));
$out = imagecreatefromstring((string) $keyed);
check('the stencil is cropped to the drawing, not the page',
    imagesx($out) < 60 && imagesy($out) < 100, imagesx($out) . 'x' . imagesy($out));
$corner = imagecolorsforindex($out, imagecolorat($out, 1, 1));
$middle = imagecolorsforindex($out, imagecolorat($out, (int) (imagesx($out) / 2), (int) (imagesy($out) / 2)));
check('the panel behind the object is keyed away', $corner['alpha'] > 100, json_encode($corner));
check('the object itself is solid ink', $middle['alpha'] < 12, json_encode($middle));
// A texture is refused rather than rendered as a smudge.
$noise = imagecreatetruecolor(120, 120);
imagefill($noise, 0, 0, imagecolorallocate($noise, 255, 255, 255));
for ($y = 0; $y < 120; $y++) {
    for ($x = 0; $x < 120; $x++) {
        if (($x + $y) % 2 === 0) {
            imagesetpixel($noise, $x, $y, imagecolorallocate($noise, 0, 0, 0));
        }
    }
}
ob_start();
imagepng($noise);
$noiseBytes = (string) ob_get_clean();
check('a texture is refused', $stencil->invoke($flowSvc, $noiseBytes) === null);
// The model's favourite mistake: the object inside a drawn box.
$framed = imagecreatetruecolor(200, 200);
imagefill($framed, 0, 0, imagecolorallocate($framed, 252, 252, 252));
$ink = imagecolorallocate($framed, 10, 10, 10);
imagesetthickness($framed, 4);
imagerectangle($framed, 10, 10, 189, 189, $ink);
imageellipse($framed, 100, 100, 70, 70, $ink);
ob_start();
imagepng($framed);
$boxed = $stencil->invoke($flowSvc, (string) ob_get_clean());
check('a frame drawn around the object is stripped', is_string($boxed));
if (is_string($boxed)) {
    $im = imagecreatefromstring($boxed);
    // The circle is 70px of a 200px page; at half scale with padding the
    // stencil must be far smaller than the framed page it came in.
    check('...and what is left is the object, not the box', imagesx($im) < 60 && imagesy($im) < 60, imagesx($im) . 'x' . imagesy($im));
}
$dark = imagecreatetruecolor(120, 120);
imagefill($dark, 0, 0, imagecolorallocate($dark, 12, 12, 12));
imagefilledrectangle($dark, 40, 40, 80, 80, imagecolorallocate($dark, 250, 250, 250));
ob_start();
imagepng($dark);
$inverted = $stencil->invoke($flowSvc, (string) ob_get_clean());
check('a drawing made white-on-black is INVERTED, not thrown away', is_string($inverted));
if (is_string($inverted)) {
    $flipped = imagecreatefromstring($inverted);
    $mid = imagecolorsforindex($flipped, imagecolorat($flipped, (int) (imagesx($flipped) / 2), (int) (imagesy($flipped) / 2)));
    check('...and the white shape becomes the ink', $mid['alpha'] < 12, json_encode($mid));
}
$bleed = imagecreatetruecolor(120, 120);
imagefill($bleed, 0, 0, imagecolorallocate($bleed, 250, 250, 250));
$black = imagecolorallocate($bleed, 0, 0, 0);
imagesetthickness($bleed, 3);
imagerectangle($bleed, 1, 1, 118, 118, $black);
imageline($bleed, 1, 1, 118, 118, $black);
ob_start();
imagepng($bleed);
check('a drawing with no margin is refused', $stencil->invoke($flowSvc, (string) ob_get_clean()) === null);
$blank = imagecreatetruecolor(80, 80);
imagefill($blank, 0, 0, imagecolorallocate($blank, 255, 255, 255));
ob_start();
imagepng($blank);
check('a blank page is refused', $stencil->invoke($flowSvc, (string) ob_get_clean()) === null);

echo "\n== 18c. the drawing pass fills a storyboard ==\n";
$board = ['scenes' => ['s3' => ['scene_id' => 's3', 'layout_template' => 'cinematic_card', 'slots' => ['slot_cinematic' => $r2]]]];
$filled = $flowSvc->drawAll($board, 'load balancing');
check('scene keys are preserved (revisions hand over keyed drafts)', array_keys($filled['scenes']) === ['s3']);
check('parts already drawn are never redrawn',
    ($flowSvc->available() ? true : $filled['scenes']['s3']['slots']['slot_cinematic'] == $r2));

echo "\n== 19. staging runs on the ordinary model ==\n";
$staging = (new ReflectionProperty(Modules\Project\Services\CinematicSceneService::class, 'model'));
$staging->setAccessible(true);
check('the staging pass uses the explainer model (gpt-4o-mini everywhere)', $staging->getValue(new Modules\Project\Services\CinematicSceneService()) === Modules\Project\Support\LlmModels::for('explainer'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
