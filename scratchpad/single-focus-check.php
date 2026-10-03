<?php

/**
 * single-focus-check — the two rules for the plainest card.
 *
 *   docker compose exec app php scratchpad/single-focus-check.php
 *
 * 1. A picture may never hide inside a single_focus: the card has one slot, so
 *    the media replaces the words, the storyboard shows an empty text card,
 *    and the user first learns there was a photograph when the render lands.
 * 2. Never two single_focus in a row, and the recast is read from the card's
 *    own content.
 *
 * No LLM, no network. The database is only touched by Laravel's bootstrap.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

$text = function (string $heading, array $bullets): array {
    return ['content_type' => 'text_block', 'heading' => $heading, 'bullets' => $bullets, 'reveal' => 'sequential'];
};

$scene = function (string $id, string $template, array $slots, string $narration, float $seconds = 8.0): array {
    return [
        'scene_id' => $id,
        'order' => 0,
        'duration_seconds' => $seconds,
        'narration' => ['text' => $narration],
        'layout_template' => $template,
        'transition' => 'fade',
        'mood' => 'neutral',
        'slots' => $slots,
    ];
};

$run = function (array $scenes): array {
    $result = (new ShotListValidator())->validate(
        ['aspect_ratio' => '16:9', 'scenes' => $scenes],
        ['hook_enabled' => false, 'outro_enabled' => false]
    );

    return $result;
};

echo "\n== 1. the registry no longer offers a picture slot on single_focus ==\n";
$allowed = ExplainerRegistry::allowedContentTypes('single_focus', 'slot_main');
check('single_focus takes text or a drawn motif only', $allowed === ['text_block', 'vector_motif'], implode('|', $allowed));
check('registry version is at least 51', (int) ExplainerRegistry::all()['version'] >= 51);

echo "\n== 2. a picture inside single_focus becomes a card that SHOWS it ==\n";
$hidden = $scene('s1', 'single_focus', ['slot_main' => [
    'content_type' => 'image',
    'label' => 'The sorting plant',
    'camera_move' => 'push_in',
    'asset_request' => ['description' => 'a conveyor belt of mixed plastic bottles', 'media_kind' => 'image'],
    'asset_ref' => ['path' => 'projects/1/uploads/belt.jpg', 'type' => 'image'],
]], 'Everything the truck tips out lands on one long conveyor belt.');
$out = $run([$hidden, $scene('s2', 'stat_spotlight', ['slot_stat' => $text('62%', ['of it is sorted by machine'])], 'Most of it is sorted by machine.')]);
$first = $out['scenes'][0];
check('the card is recast', $first['layout_template'] === 'full_bleed_with_banner', $first['layout_template']);
check('the picture moves to the visible background slot', ($first['slots']['slot_background']['content_type'] ?? '') === 'image');
check('an upload already attached survives the recast',
    ($first['slots']['slot_background']['asset_ref']['path'] ?? '') === 'projects/1/uploads/belt.jpg');
check('its brief and camera move survive',
    ($first['slots']['slot_background']['camera_move'] ?? '') === 'push_in'
    && str_contains((string) ($first['slots']['slot_background']['asset_request']['description'] ?? ''), 'conveyor belt'));
check('the beat keeps words on screen', ($first['slots']['slot_banner']['content_type'] ?? '') === 'text_block');
check('the change is reported', (bool) array_filter($out['warnings'], fn ($w) => str_contains($w, 'invisible in the storyboard')));

echo "\n== 3. a drawn motif is left alone (it IS the beat's picture) ==\n";
$motif = $scene('m1', 'single_focus', ['slot_main' => ['content_type' => 'vector_motif', 'subject' => 'a bottle melted down and re-formed as a jar']], 'The bottle is melted down and formed again.');
$out = $run([$motif, $scene('m2', 'quote_card', ['slot_quote' => $text('', ['Nothing is wasted.'])], 'Nothing is wasted.')]);
check('a motif card stays single_focus', $out['scenes'][0]['layout_template'] === 'single_focus', $out['scenes'][0]['layout_template']);

echo "\n== 4. never two single_focus in a row ==\n";
$a = $scene('a', 'single_focus', ['slot_main' => $text('The first idea', ['A line about the first idea that runs on a while'])], 'Here is the first idea and what it means for the rest of this.');
$b = $scene('b', 'single_focus', ['slot_main' => $text('The second idea', ['A line about the second idea that also runs on'])], 'Here is the second idea, which follows on from the first one.');
$c = $scene('c', 'single_focus', ['slot_main' => $text('The third idea', ['A line about the third idea as well'])], 'And the third idea, which closes the run of three plain cards.');
// Two media scenes so the media floor is already satisfied: what changes in
// this fixture is then the adjacency rule and nothing else.
$pic = fn (string $id) => $scene($id, 'full_bleed_with_side_panel', [
    'slot_background' => ['content_type' => 'image', 'label' => '', 'camera_move' => 'ken_burns',
        'asset_request' => ['description' => 'a sorting plant conveyor', 'media_kind' => 'image'], 'asset_ref' => null],
    'slot_panel' => $text('The plant', ['Everything lands on one belt']),
], 'Everything the truck tips out lands on one long belt at the plant.');
$out = $run([$a, $b, $c, $pic('d'), $pic('e')]);
$templates = array_column($out['scenes'], 'layout_template');
check('no two single_focus are adjacent', !(bool) array_filter(
    array_keys($templates),
    fn ($i) => $i > 0 && $templates[$i] === 'single_focus' && $templates[$i - 1] === 'single_focus'
), implode(' -> ', $templates));
check('only the second of a pair moves', $templates[0] === 'single_focus');
check('the change is reported', (bool) array_filter($out['warnings'], fn ($w) => str_contains($w, 'two single_focus cards in a row')));

echo "\n== 5. the recast is read from the card's own content ==\n";
$statish = $scene('n2', 'single_focus', ['slot_main' => $text('9.8 m/s', ['is how fast it falls'])], 'It falls at nine point eight metres per second every second.');
$out = $run([$a, $statish]);
check('a number under a short heading becomes a stat card',
    $out['scenes'][1]['layout_template'] === 'stat_spotlight', $out['scenes'][1]['layout_template']);

$termish = $scene('t2', 'single_focus', ['slot_main' => $text('Entropy', ['is the amount of disorder in a system'])], 'Entropy is the amount of disorder in a system, and it only goes up.');
$out = $run([$a, $termish]);
$second = $out['scenes'][1];
check('a definition becomes a term card', $second['layout_template'] === 'term_card', $second['layout_template']);
check('...with the word and the sentence apart',
    ($second['slots']['slot_term']['term'] ?? '') === 'Entropy'
    && str_contains((string) ($second['slots']['slot_term']['definition'] ?? ''), 'disorder'));

$plain = $scene('p2', 'single_focus', ['slot_main' => $text('What happens next', ['The belt carries it to the scanner', 'Air jets blow the bottles off the line'])], 'The belt carries everything to the scanner, and air jets blow the bottles off the line.');
$out = $run([$a, $plain]);
$second = $out['scenes'][1];
// Either way of putting a picture beside words is right; which one is chosen
// depends on the neighbours, so the checks are on the SHAPE, not the name.
check('anything else earns a picture, on a card that shows one',
    in_array($second['layout_template'], ['full_bleed_with_side_panel', 'split_side_by_side'], true),
    $second['layout_template']);
$picture = $second['slots']['slot_background'] ?? $second['slots']['slot_left'] ?? [];
$words = $second['slots']['slot_panel'] ?? $second['slots']['slot_right'] ?? [];
check('...the picture slot is a real request the user can act on',
    ($picture['content_type'] ?? '') === 'image'
    && trim((string) ($picture['asset_request']['description'] ?? '')) !== '');
check('...and the words are kept beside it',
    ($words['content_type'] ?? '') === 'text_block' && ($words['heading'] ?? '') === 'What happens next');

echo "\n== 6. the pass is idempotent ==\n";
$once = $run([$a, $b, $c]);
$twice = (new ShotListValidator())->validate(
    ['aspect_ratio' => '16:9', 'scenes' => $once['scenes']],
    ['hook_enabled' => false, 'outro_enabled' => false]
);
check('a second pass changes no template',
    array_column($twice['scenes'], 'layout_template') === array_column($once['scenes'], 'layout_template'),
    implode(' -> ', array_column($twice['scenes'], 'layout_template')));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
