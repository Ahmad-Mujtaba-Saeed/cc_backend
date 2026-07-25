<?php

/**
 * text-pass-check — guard rules of StoryboardTextReviewService::apply()
 * (loop iter 6). Pure logic, no key needed:
 *   php scratchpad/text-pass-check.php
 *   docker exec viralforgebackend-app-1 php scratchpad/text-pass-check.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Modules\Project\Services\StoryboardTextReviewService as Pass;

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

function scene(string $id, string $narr, string $tpl = 'single_focus'): array
{
    return [
        'scene_id' => $id,
        'layout_template' => $tpl,
        'narration' => ['text' => $narr],
        'slots' => [],
    ];
}

function issue(string $sceneId, string $type, string $quote, string $fix = ''): array
{
    return ['scene_id' => $sceneId, 'type' => $type, 'quote' => $quote, 'fix' => $fix];
}

$codes = fn (array $r, string $code) => array_values(array_filter($r['findings'], fn ($f) => $f['code'] === $code));

// 1. Meta-narration deletion: the sentence goes, the content stays.
$r = Pass::apply(
    [scene('scene_1', "In this video we will explore the fall of Blockbuster. Blockbuster owned 9000 stores at its peak.")],
    [issue('scene_1', 'meta_narration', 'In this video we will explore the fall of Blockbuster.')]
);
check('meta sentence removed', $r['scenes'][0]['narration']['text'] === 'Blockbuster owned 9000 stores at its peak.');
check('removal counted + logged as fixed', $r['applied'] === 1 && count($codes($r, 'text_pass_fixed')) === 1);

// 2. Figure callout rewritten in place.
$r = Pass::apply(
    [scene('scene_2', 'As you can see in the chart below, sales tripled. That growth changed everything.')],
    [issue('scene_2', 'figure_callout', 'As you can see in the chart below, sales tripled.', 'Sales tripled in just two years.')]
);
check('callout rewritten to the fact', str_starts_with($r['scenes'][0]['narration']['text'], 'Sales tripled in just two years. That growth'));

// 3. A quote that is not verbatim is refused, narration untouched.
$before = 'The PS2 sold over 155 million units worldwide.';
$r = Pass::apply(
    [scene('scene_3', $before)],
    [issue('scene_3', 'repeated_sentence', 'The PS2 sold 155 million units worldwide.')]
);
check('non-verbatim quote refused', $r['applied'] === 0 && $r['scenes'][0]['narration']['text'] === $before);
check('refusal surfaces as a manual finding', count($codes($r, 'text_pass_manual')) === 1);

// 4. Too-short quotes cannot match safely.
$r = Pass::apply([scene('scene_4', 'Let us go. The rest of this narration is long enough to survive.')],
    [issue('scene_4', 'meta_narration', 'Let us go.')]);
check('short quote refused', $r['applied'] === 0);

// 5. A ballooning "fix" is not a conservative rewrite.
$r = Pass::apply([scene('scene_5', 'Welcome back to the channel, everyone. Rome fell in 476.')],
    [issue('scene_5', 'meta_narration', 'Welcome back to the channel, everyone.',
        str_repeat('This replacement sentence grows far beyond the original quote length. ', 3))]);
check('oversized fix refused', $r['applied'] === 0);

// 6. Step-locked working narration is protected.
$r = Pass::apply(
    [scene('scene_6', 'First we subtract five from both sides. Then we divide by two.', 'math_steps')],
    [issue('scene_6', 'meta_narration', 'First we subtract five from both sides.')]
);
check('math_steps narration never edited', $r['applied'] === 0 && count($codes($r, 'text_pass_manual')) === 1);

// 7. An edit may not silence a scene.
$r = Pass::apply([scene('scene_7', "Let's dive right into it, shall we?")],
    [issue('scene_7', 'meta_narration', "Let's dive right into it, shall we?")]);
check('edit that empties a scene refused', $r['applied'] === 0 && $r['scenes'][0]['narration']['text'] !== '');

// 8. Unknown types and unknown scenes.
$r = Pass::apply([scene('scene_8', 'A perfectly ordinary sentence about the topic at hand.')], [
    issue('scene_8', 'tone_of_voice', 'A perfectly ordinary sentence about the topic at hand.'),
    issue('scene_99', 'meta_narration', 'A perfectly ordinary sentence about the topic at hand.'),
]);
check('invented type silently ignored', count($codes($r, 'text_pass_manual')) === 1);
check('unknown scene refused as manual', $r['applied'] === 0);

// 9. The edit budget caps a runaway review.
$scenes = [];
$issues = [];
for ($i = 1; $i <= 8; $i++) {
    $scenes[] = scene("scene_{$i}", "Now let's dive in properly. The number {$i} carries the real content of this scene.");
    $issues[] = issue("scene_{$i}", 'meta_narration', "Now let's dive in properly.");
}
$r = Pass::apply($scenes, $issues);
check('edit budget stops at ' . Pass::MAX_EDITS, $r['applied'] === Pass::MAX_EDITS && count($codes($r, 'text_pass_manual')) === 2);

// 10. A quote occurring twice is replaced once (first occurrence).
$r = Pass::apply(
    [scene('scene_9', 'History repeats itself, they say. History repeats itself, they say. And it did.')],
    [issue('scene_9', 'repeated_sentence', 'History repeats itself, they say.')]
);
check('double occurrence edited once', substr_count($r['scenes'][0]['narration']['text'], 'History repeats itself') === 1);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
