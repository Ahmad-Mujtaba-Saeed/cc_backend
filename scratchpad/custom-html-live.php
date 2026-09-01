<?php

/**
 * custom-html-live — will the planner author a fragment, and is what it writes
 * both SAFE and USABLE?
 *
 *   docker compose exec app php scratchpad/custom-html-live.php
 *
 * Two failure modes matter here and neither shows up in a unit test:
 *
 *  1. The model never reaches for the card — a last resort so restrained that
 *     it is dead code.
 *  2. The model reaches for it CONSTANTLY, and a video that should have used
 *     animated_chart and step_flow becomes hand-drawn HTML — worse, because
 *     the real cards are typeset, animated and tested.
 *
 * So this runs a script whose middle beat genuinely has no card (a printed
 * ticket) and reports what was cast, what the sanitizer had to remove, and
 * whether the fragment survived at all.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Services\ScriptSkeletonService;
use Modules\Project\Support\ShotListValidator;

$scripts = [
    'parking ticket' => <<<'TXT'
The parking ticket on your windscreen is a small piece of legal engineering, and almost nobody reads it properly.
Look at the top and you will find a code — usually two letters and a number, like BS 24. That is the contravention code, and it is the entire legal basis for the charge.
Under it there is a time, and often two: the time the officer started observing, and the time the notice was issued. In most places those have to be at least five minutes apart, because an officer is required to watch before they write.
Then the amount. Almost every ticket carries two numbers: the full charge, and a discounted rate if you pay within fourteen days. The discount is not generosity. It is a device to stop you appealing.
Because the last block on the ticket is the appeal window, and it usually runs longer than the discount does. If you appeal, you lose the discount. If you pay, you lose the right to appeal.
None of that is hidden. It is printed on the paper in your hand. It is just laid out so the number you notice first is the one they want you to pay.
TXT,
    'a scam text' => <<<'TXT'
Almost every delivery scam text is the same four messages, in the same order, and once you have seen the shape you cannot unsee it.
It opens friendly and vague. "Hi mum, this is my new number, my old phone broke." No name, because they do not have one. The vagueness is deliberate — you supply the name yourself when you reply.
You answer with a name. Now they have it, and the second message uses it. "Thanks Sarah, I was worried you wouldn't see this." That is the moment the conversation stops being cold and starts being warm, and it cost them nothing.
The third message introduces a small, boring problem. Not a crisis — a nuisance. "I'm locked out of my banking app until Monday and I've got a bill due." Small problems get help; crises get questions.
And then the fourth message asks. Never for much. Always for now. "Could you cover it and I'll send it back Tuesday?"
Every step is designed so the next one feels like the natural thing to do. The ask is never the first message, because the first message is not for money. It is for a name.
TXT,
];

$which = $argv[1] ?? 'a scam text';
$script = $scripts[$which] ?? reset($scripts);
echo "SCRIPT: {$which}
";

echo "Planning the skeleton…\n";
$skeleton = (new ScriptSkeletonService())->planGeneric($script, '', 65);
if ($skeleton === []) {
    echo "no skeleton; stopping.\n";
    exit(1);
}
$phases = $skeleton['phases'] ?? $skeleton;
echo '  ' . count($phases) . ' phases: ' . implode(', ', array_map(
    fn ($p) => is_array($p) ? (string) ($p['intent'] ?? '?') : (string) $p,
    $phases
)) . "\n";

echo "Composing…\n";
$raw = (new GenericStoryboardComposerService())->compose($script, $skeleton, 65);
if ($raw === null) {
    echo "composer returned null; stopping.\n";
    exit(1);
}

$scenes = $raw['scenes'] ?? [];
echo count($scenes) . " scenes cast:\n";
foreach ($scenes as $scene) {
    printf("  %-12s %s\n", $scene['scene_id'] ?? '?', $scene['layout_template'] ?? '?');
}

$authored = array_values(array_filter($scenes, fn ($s) => ($s['layout_template'] ?? '') === 'custom_card'));
printf("\n>> custom_card cast %d time(s)%s\n", count($authored), count($authored) === 0 ? ' — the last resort stayed unused on this script' : '');

foreach ($authored as $scene) {
    $slot = $scene['slots']['slot_custom'] ?? [];
    printf("\nRAW fragment (%d chars html, %d chars css):\n%s\n",
        mb_strlen((string) ($slot['html'] ?? '')),
        mb_strlen((string) ($slot['css'] ?? '')),
        mb_substr((string) ($slot['html'] ?? ''), 0, 700)
    );
}

$validated = (new ShotListValidator())->validate(['aspect_ratio' => '16:9', 'scenes' => $scenes]);

echo "\nAfter validation:\n";
$survived = 0;
foreach ($validated['scenes'] as $scene) {
    printf("  %-12s %s\n", $scene['scene_id'] ?? '?', $scene['layout_template'] ?? '?');
    if (($scene['layout_template'] ?? '') === 'custom_card') {
        $survived++;
        $slot = $scene['slots']['slot_custom'] ?? [];
        printf("      html %d chars, css %d chars, cues: %d\n",
            mb_strlen((string) ($slot['html'] ?? '')),
            mb_strlen((string) ($slot['css'] ?? '')),
            preg_match_all('/data-(at|word)=/', (string) ($slot['html'] ?? ''))
        );
        // The two things that must be true of what SHIPS.
        $html = (string) ($slot['html'] ?? '');
        $bad = preg_match('/<script|on\w+=|https?:\/\/|<img|<iframe/i', $html) === 1;
        echo '      ', $bad ? 'DANGEROUS MARKUP SURVIVED' : 'clean: no script, no handlers, no network', "\n";
        $css = (string) ($slot['css'] ?? '');
        if ($css !== '') {
            $scoped = str_starts_with(trim($css), '.cc-scope');
            echo '      ', $scoped ? 'css scoped to the card' : 'CSS NOT SCOPED', "\n";
        }
    }
}
printf("\n%d of %d fragments survived sanitizing.\n", $survived, count($authored));

$notes = array_values(array_filter(
    $validated['warnings'] ?? [],
    fn ($w) => str_contains($w, 'custom card')
));
if ($notes !== []) {
    echo "\nWhat the sanitizer had to change:\n";
    foreach (array_slice($notes, 0, 12) as $w) {
        echo "  - {$w}\n";
    }
}
