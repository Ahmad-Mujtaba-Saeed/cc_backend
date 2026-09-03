<?php

/**
 * make-demo-projects — four explainer storyboards, deliberately unalike.
 *
 * Built to be COMPARED, not just to exist: each one is aimed at a different
 * branch of the pipeline, so opening them side by side shows what the template
 * actually does rather than four versions of the same slideshow.
 *
 *   1. science, 16:9   — drawn motifs, 60fps + motion blur (iters 60-63)
 *   2. everyday, 9:16  — portrait, captions on, an energetic motion style
 *   3. a proof, 16:9   — routes itself to math_board, a wholly different mode
 *   4. a process, 16:9 — a canvas journey, so the camera flies between beats
 *
 * Analyze only: this writes storyboards and stops. No narration, no images, no
 * render — those cost money and are the user's call to make from the dashboard.
 *
 *   docker exec viralforgebackend-app-1 php scratchpad/make-demo-projects.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Jobs\AnalyzeExplainerScriptJob;
use Modules\Project\Models\ExplainerScene;
use Modules\Project\Models\Project;

$userModel = config('auth.providers.users.model');
$user = $userModel::where('email', 'wasim.dev98@gmail.com')->first() ?? $userModel::orderBy('id')->first();
if (!$user) {
    echo "No user to own the projects.\n";
    exit(1);
}
echo "Owner: #{$user->id} {$user->email}\n\n";

$specs = [

// ---------------------------------------------------------------------------
[
    'title' => 'How a solar panel actually makes electricity',
    'aspect' => '16:9',
    'aim' => 'drawn motifs + the smoothness knobs',
    'settings' => [
        'motion_style' => 'crisp',
        // Iters 60-63 on: the smoothest clock and the shutter.
        'render_fps' => 60,
        'motion_blur_enabled' => true,
        'auto_visuals' => true,
    ],
    'script' => <<<'TXT'
A solar panel has no moving parts, makes no noise, and burns nothing. It just sits there in the light and produces electricity. So what is actually happening inside it?

Start with the light. Sunlight arrives as photons — tiny packets of energy. Most materials absorb a photon and turn it straight into heat. Silicon does something stranger.

A solar cell is two thin layers of silicon, each deliberately contaminated. The top layer has a few extra electrons. The bottom layer is missing a few. Where the two meet, the spare electrons drift across, leaving a permanent electric field at the junction — like a one-way gate built into the material.

Now a photon strikes. If it carries enough energy, it knocks an electron loose from its atom. That loose electron would normally wander and settle back. But the junction's field gives it a direction: it can only go one way.

Multiply that by trillions. Every second, an unimaginable number of electrons are knocked free and pushed the same direction. That one-way flow of electrons is an electric current — the panel is not storing energy, it is steering it.

Wire the two faces together and the electrons travel around your circuit to get back where they started, doing work on the way — running a fridge, charging a phone, feeding the grid.

The limit is brutal, though. A silicon cell can only use photons in a certain energy band. Too little energy and the photon passes through. Too much and the excess becomes heat. That is why a typical panel converts about twenty per cent of the light that lands on it, and why the lab record has crept up so slowly.

No moving parts, no fuel, no noise. Just light, a one-way gate, and a very large number of electrons all going the same way.
TXT,
],

// ---------------------------------------------------------------------------
[
    'title' => 'Why bread rises',
    'aspect' => '9:16',
    'aim' => 'portrait, captions, energetic motion',
    'settings' => [
        'motion_style' => 'bounce',
        'captions_enabled' => true,
        'auto_visuals' => true,
    ],
    'script' => <<<'TXT'
You mix flour, water, salt and yeast. Four hours later you have something twice the size, full of holes, and completely transformed. Nothing was added. So where did the volume come from?

Two things are happening at once, and they need each other.

The first is gluten. Flour contains two proteins that do nothing much on their own. Add water and work the dough, and they link into long elastic sheets. That is what kneading is for: you are not mixing, you are building a stretchy net.

The second is the yeast. Yeast is a living fungus, and it eats the sugars in the flour. What it breathes out is carbon dioxide.

On its own, gas escapes. In a bare bowl of water the bubbles would rise and pop. But the gluten net traps them. Every bubble inflates a tiny pocket and the whole dough swells around thousands of them.

That is the whole trick: one ingredient makes the gas, another holds onto it.

Then the oven finishes the job. In the first few minutes the trapped gas expands hard with the heat — bakers call it oven spring, and it is the biggest rise the loaf ever gets. The yeast works furiously and then dies at about sixty degrees. The gluten sets. The starch gelatinises. The bubbles are frozen in place forever.

Cut the loaf open and you are looking at a fossil: a record of every bubble that was there when the structure set.
TXT,
],

// ---------------------------------------------------------------------------
[
    'title' => 'Why does 0.999... equal exactly 1?',
    'aspect' => '16:9',
    'aim' => 'routes to math_board — a different composition mode entirely',
    'settings' => [
        'motion_style' => 'classic',
    ],
    'script' => <<<'TXT'
Nearly everyone's first reaction is that zero point nine recurring must be just a little bit less than one. It looks less than one. It has to stop somewhere below one. It doesn't. It is exactly one, and there are three ways to see why.

The first is the one you can do in your head. One third is zero point three recurring. Multiply both sides by three. Three thirds is one. Three times zero point three recurring is zero point nine recurring. So zero point nine recurring is one.

The second is algebra. Let x be zero point nine recurring. Multiply both sides by ten: ten x is nine point nine recurring. Now subtract the first equation from the second. On the left, ten x minus x is nine x. On the right, nine point nine recurring minus zero point nine recurring is exactly nine — the recurring tails are identical and cancel completely. So nine x equals nine, and x equals one.

The third is the one that actually settles it. Ask what number sits between zero point nine recurring and one. If they were different numbers, there would have to be one — between any two different real numbers there are infinitely many others. Try to name it. You cannot, because any decimal you propose is either below zero point nine recurring or above one. Two real numbers with nothing between them are the same number.

The discomfort is real, and it is worth naming. Zero point nine recurring is not a process that creeps toward one. It is not "getting there". It is a completed decimal — a single number, written a different way — and that number is one.
TXT,
],

// ---------------------------------------------------------------------------
[
    'title' => 'How the internet delivers a page in 200 milliseconds',
    'aspect' => '16:9',
    'aim' => 'a journey — expect a canvas flight between beats',
    'settings' => [
        'motion_style' => 'swiss',
        'composition_mode' => 'canvas_journey',
        'auto_visuals' => true,
    ],
    'script' => <<<'TXT'
You type an address and press enter. Before you have finished lifting your finger, the page is there. In that fifth of a second, your request crossed a continent and came back. Here is the journey it took.

Step one: your computer does not know where the site lives. It knows a name, not an address. So it asks a directory — the DNS — and a resolver near you answers, usually from memory, in a few milliseconds. Now you have an IP address.

Step two: your machine opens a connection. It sends a small packet saying hello. The server answers. Your machine confirms. Three messages, one round trip, before a single byte of the page has moved.

Step three: if the site is encrypted — and almost all are — there is another negotiation. The two sides agree on a cipher and exchange keys, so everything after this is unreadable to anyone in between. Another round trip.

Step four: your request finally goes out. And here is the part people miss — it does not travel in a straight line. It hops. Each router along the way looks at the destination, consults its table, and passes the packet to the next router it believes is closer. No single machine knows the whole route.

Step five: the server assembles the page and sends it back, broken into packets that may take different paths and arrive out of order. Your machine puts them back in order and asks again for anything that went missing.

Step six: the browser starts drawing before everything has arrived. It reads the HTML, discovers it needs a stylesheet and some images, and fires off new requests for those — often to a server much closer to you than the first one.

Two hundred milliseconds, several round trips, dozens of machines, and not one of them knowing the whole plan. The remarkable thing is not that it is fast. It is that it works at all.
TXT,
],

];

$created = [];

foreach ($specs as $i => $spec) {
    $n = $i + 1;
    echo "[{$n}/4] {$spec['title']}\n";
    echo "      aim: {$spec['aim']}  ({$spec['aspect']})\n";

    $project = Project::create([
        'user_id' => $user->id,
        'title' => $spec['title'],
        'template_type' => 'ai_explainer_video',
        'aspect_ratio' => $spec['aspect'],
        'status' => 'analyzing',
        'progress' => 0,
        'settings' => array_merge([
            'script' => trim($spec['script']),
            'target_seconds' => 90,
        ], $spec['settings']),
    ]);

    $started = microtime(true);
    try {
        // Synchronous: the queue would work too, but running it here means the
        // failure of one storyboard is visible immediately instead of in a log.
        (new AnalyzeExplainerScriptJob($project))->handle();
    } catch (\Throwable $e) {
        echo "      FAILED: " . $e->getMessage() . "\n\n";
        continue;
    }
    $took = round(microtime(true) - $started, 1);

    $project->refresh();
    $scenes = ExplainerScene::where('project_id', $project->id)->orderBy('order')->get();

    $templates = [];
    $motifs = 0;
    $motifShapes = 0;
    $matchCuts = 0;
    foreach ($scenes as $scene) {
        $templates[] = $scene->layout_template;
        if (($scene->transition ?? '') === 'match_dissolve') {
            $matchCuts++;
        }
        foreach ((array) ($scene->slots ?? []) as $slot) {
            if (($slot['content_type'] ?? '') === 'vector_motif') {
                $motifs++;
                $motifShapes += count($slot['shapes'] ?? []);
            }
        }
    }

    echo "      project #{$project->id}  status={$project->status}  ({$took}s)\n";
    echo '      ' . count($scenes) . ' scenes, mode=' . ($project->settings['composition_mode'] ?? 'auto')
        . ', ' . round((float) ($project->settings['predicted_seconds'] ?? 0)) . "s predicted\n";
    echo "      drawn motifs: {$motifs}" . ($motifs ? " ({$motifShapes} shapes)" : '')
        . ($matchCuts ? ", {$matchCuts} match cut(s)" : '') . "\n";
    echo '      cards: ' . implode(', ', array_unique($templates)) . "\n\n";

    $created[] = $project;
}

echo "Done. Open each from the dashboard's explainer editor:\n";
foreach ($created as $p) {
    echo "  #{$p->id}  {$p->title}\n";
}
