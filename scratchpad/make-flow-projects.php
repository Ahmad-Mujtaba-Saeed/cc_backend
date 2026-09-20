<?php

/**
 * make-flow-projects — create real explainer projects on topics the FLOW
 * EXPLAINING CARD should reach for, and analyse them synchronously so the
 * storyboard (and every drawing) can be inspected without the queue worker.
 *
 *   docker compose exec app php scratchpad/make-flow-projects.php <key> [user_id]
 *
 * Keys: `payment` (a card payment's journey), `bottle` (a bottle recycled).
 * Both are chains of DIFFERENT, concrete, drawable things — the shape this
 * card is for, and deliberately far from the reference (servers) and from the
 * first test project (anatomy, whose pieces are all one organ).
 *
 * Costs what creating it in the dashboard costs: roughly $0.05-0.10 of
 * gpt-4o-mini for the analysis, plus ~$0.001 per part drawn (a subject already
 * drawn for any project is free).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Jobs\AnalyzeExplainerScriptJob;
use Modules\Project\Models\Project;

$payment = <<<'TXT'
You tap your card, the machine beeps, and the shop says thank you. It feels like the money jumped straight out of your pocket and into theirs. It did not. In those two seconds, a message left the shop, crossed four companies, came back with an answer, and the money itself did not move at all.

Start at the counter. The card terminal reads your card and builds a small request. It does not send your card number in the clear. The chip in your card creates a one-time code for this purchase, so even if someone copies the message, it buys them nothing.

That request goes first to the acquirer, the shop's payment bank. The acquirer's job is to know which network a card belongs to and hand the request on. It looks at the first digits of the card and routes it to the right card network.

The network is the switchboard in the middle, and it is very good at one thing: finding the bank that issued your card, anywhere in the world, and passing the request to it. It does not hold anyone's money. It moves messages and keeps the rules.

Now the request lands where the real decision is made: your bank, the issuer. It checks three things in a few milliseconds. Is this card real and active? Is there enough money or credit behind it? And does this purchase look like you? If the answer is yes, it does something small but important: it does not pay anyone. It puts a hold on that amount in your account, and sends back one word. Approved.

That approval runs all the way back down the chain, to the network, the acquirer, the terminal, and finally the beep. The whole round trip usually takes under two seconds. At this point the shop has a promise, not money.

The money moves later, in a batch. At the end of the day the shop sends every approved sale to its acquirer at once. The acquirer totals them up, and the next working day the funds are settled: your bank pays the network, the network pays the acquirer, and the acquirer pays the shop. Your statement finally turns the hold into a real charge.

Everyone in that chain takes a slice, which is why the shop never receives the full price. The biggest piece is the interchange fee, and it goes to your bank, not the shop's. A smaller piece goes to the network. The acquirer takes what is left. On a ten pound sale, the shop usually keeps a little over nine pounds and eighty pence.

This is also why a refund is slow when a payment was instant. A payment is an approval first and a transfer later. A refund has no approval step at all. It is a new instruction that has to travel the same chain in reverse and wait for the next settlement, which is why your money can take days to come back.

And it explains the strange holds you sometimes see. A hotel or a fuel pump does not know your final total, so it asks your bank to hold an estimate. The hold reserves the money without taking it. When the real amount arrives, the hold is released. If the shop never sends that final amount, the hold quietly expires on its own.

So the next time a terminal beeps, picture what just happened: a one-time code, four companies, one bank saying yes, and a promise that turns into money tomorrow.
TXT;

$bottle = <<<'TXT'
You drop a plastic bottle into the recycling bin, and that is the end of it, as far as you can tell. It is actually the beginning of a two week journey through a series of machines, each one solving a single problem: how do you get one kind of plastic out of a mountain of mixed rubbish?

The first problem is that your bottle is not alone. The truck tips everything from the street into one pile at a sorting plant, and that pile is paper, cans, glass, food waste and every kind of plastic mixed together. Nobody sorts it by hand any more. The pile goes onto a conveyor belt, and the machines take turns.

A spinning drum of steel discs comes first. Flat things like cardboard ride over the top of the discs, and small heavy things like your bottle fall through the gaps. One shape problem solved.

Then a magnet hangs over the belt and lifts out the steel cans. Aluminium is not magnetic, so a second machine handles it: a drum spinning a magnetic field so fast that it makes aluminium briefly magnetic in reverse, and the cans literally jump off the belt into their own bin.

Now the belt is mostly plastic, and the hardest question arrives. A bottle, a yoghurt pot and a milk carton all look the same to a machine, but they are three different plastics that cannot be melted together. So the belt runs under an infrared scanner. Every plastic reflects light in its own signature, and the scanner reads it. Milliseconds later a row of air jets fires and blows the bottles off the line into their own stream.

What is left is a pure enough pile of one plastic, and it gets squashed into a bale the size of a washing machine and sold by the tonne. Your bottle is now a commodity with a price.

At the reprocessing plant the bale is broken open and the bottles are shredded into flakes the size of a fingernail. This is where the label and the cap finally come off, because they are made of different plastics. The flakes go into a tank of water. Bottle plastic sinks. Cap plastic floats. The two streams separate themselves, for free, using nothing but density.

The flakes are then washed hot to strip the glue and whatever was left inside, dried, and melted. The melt is pushed through a fine screen to catch anything solid, then out through a die as long strands, which are chopped into pellets. The pellets look like grey rice and they are the actual product of all of this.

Here is the part most people miss. Every melt shortens the plastic's molecules a little, so the pellets are weaker than the plastic that went in. Used for a new drinks bottle, they need to be cleaned to food grade and usually blended with fresh plastic. Used for a park bench or a fleece, they need no such care. That is why plastic is not recycled in a circle so much as down a staircase, one step at a time.

A new bottle is blown from those pellets in about two seconds. A short tube of plastic is heated, air is forced into it, and it expands into the mould like a balloon with a shape. It is filled, labelled, sold, and drunk.

So the bin is not the end of the story. It is the first machine in a line of them, and the thing that decides whether your bottle becomes a bottle again is not the bin at all. It is whether it reaches the scanner clean, with the cap on and the liquid out.
TXT;

$topics = [
    'payment' => ['How a card payment actually reaches the shop', $payment],
    'bottle' => ['How a plastic bottle becomes a new bottle', $bottle],
];

$key = (string) ($argv[1] ?? '');
if (!isset($topics[$key])) {
    exit("usage: make-flow-projects.php <" . implode('|', array_keys($topics)) . "> [user_id]\n");
}
[$title, $script] = $topics[$key];
$userId = (int) ($argv[2] ?? 1);

$project = Project::create([
    'user_id' => $userId,
    'title' => $title,
    'template_type' => 'ai_explainer_video',
    'aspect_ratio' => '16:9',
    'status' => 'analyzing',
    'progress' => 0,
    'settings' => ['script' => $script, 'target_seconds' => 240],
]);
echo "created project {$project->id} for user {$userId} (" . str_word_count($script) . " words)\n";

$t0 = microtime(true);
(new AnalyzeExplainerScriptJob($project))->handle();
$project->refresh();
printf("analysis finished in %.0fs -> status %s\n", microtime(true) - $t0, $project->status);
if ($project->error_message) {
    echo "error: {$project->error_message}\n";
}

$scenes = $project->explainerScenes()->orderBy('order')->get();
$total = 0.0;
$flow = 0.0;
foreach ($scenes as $s) {
    $total += (float) $s->duration_seconds;
    if ($s->layout_template === 'cinematic_card') {
        $flow += (float) $s->duration_seconds;
    }
}
printf("%d scenes, %.0fs, flow cards %.0fs = %d%% of the runtime\n\n", $scenes->count(), $total, $flow, $total > 0 ? round(100 * $flow / $total) : 0);

foreach ($scenes as $s) {
    printf("%2d. %-26s %5.1fs  %s\n", $s->order, $s->layout_template, $s->duration_seconds, mb_substr((string) $s->narration, 0, 84));
    if ($s->layout_template !== 'cinematic_card') {
        continue;
    }
    $slot = ($s->slots ?? [])['slot_cinematic'] ?? [];
    echo '      HEADING: ' . ($slot['heading'] ?? '') . "\n";
    foreach ((array) ($slot['elements'] ?? []) as $el) {
        $then = array_map(
            fn ($c) => ($c['word'] ?? '?') . ':' . ($c['status'] ?? $c['note'] ?? 'changes'),
            (array) ($el['then'] ?? [])
        );
        printf(
            "      %-7s %-12s %-4s %-6s w=%-11s %-20s %s%s\n",
            $el['kind'],
            $el['place'],
            $el['depth'],
            $el['camera'],
            $el['word'] ?? '-',
            mb_substr((string) ($el['title'] ?? $el['text'] ?? $el['formula'] ?? ''), 0, 20)
                . (isset($el['status']) ? ' [' . $el['status'] . ']' : ''),
            ($el['kind'] ?? '') === 'visual'
                ? (empty($el['image_path']) ? '[NOT DRAWN] ' : '[drawn] ') . mb_substr((string) ($el['prompt'] ?? ''), 0, 62)
                : mb_substr((string) ($el['sub'] ?? ''), 0, 62),
            $then ? '  then: ' . implode(' ', $then) : ''
        );
    }
    foreach ((array) ($slot['links'] ?? []) as $l) {
        printf("      link %s -> %s%s%s\n", $l['from'], $l['to'], isset($l['label']) ? ' "' . $l['label'] . '"' : '', !empty($l['flow']) ? ' (flow)' : '');
    }
}
