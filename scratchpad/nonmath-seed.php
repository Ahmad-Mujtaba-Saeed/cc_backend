<?php
/**
 * nonmath-seed — 4 realistic NON-MATHS explainer projects for testing the card
 * system end to end (and that the geometry-synth change never touches ordinary
 * videos). Each topic is chosen to pull a different card family; two of them
 * deliberately describe DIAGRAMS so the diagram cards get exercised outside
 * maths mode.
 *
 *   docker exec viralforgebackend-app-1 php scratchpad/nonmath-seed.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Jobs\AnalyzeExplainerScriptJob;
use Modules\Project\Models\Project;

$scenarios = [
    [
        'title' => 'How does the water cycle actually work?',
        'why' => 'science process: cycle_diagram + labeled_diagram + step_flow',
        'script' => <<<'TXT'
Water is never created or destroyed. It just moves in a loop, over and over, and that loop is called the water cycle.
It starts with the sun. Heat warms the oceans, lakes and rivers, and the water turns into invisible vapour and rises. This first stage is called evaporation.
Plants add to it too. Through their leaves they release water vapour into the air, a stage called transpiration.
High up, the air is colder. The vapour cools and clumps together into tiny droplets that form clouds. This is condensation.
When those droplets grow heavy enough, they fall back to earth as rain, snow or hail. This is precipitation.
Some of that water soaks into the ground and feeds underground stores; the rest runs downhill across the land and gathers back into rivers and the sea. This is collection.
And then the sun heats it again, and the whole loop begins once more. The same water that fell as rain today may have been in a cloud last week and an ocean last year.
TXT,
    ],
    [
        'title' => 'Why is the sky blue?',
        'why' => 'science explainer: labeled_diagram of scattering + myth_fact',
        'script' => <<<'TXT'
Look up on a clear day and the sky is blue. But sunlight is white. So where does the blue come from?
A common myth is that the sky is blue because it reflects the ocean. That is not true at all.
Here is what really happens. Sunlight is made of every colour mixed together, from red at one end to blue and violet at the other.
As that light enters our atmosphere, it slams into countless tiny molecules of air, mostly nitrogen and oxygen.
The colours are not scattered equally. Blue light travels in shorter, tighter waves, and short waves get bounced around far more than long red ones. This bouncing is called scattering.
So the blue light gets thrown across the whole sky in every direction, while the red and yellow light mostly carries straight on through.
When you look up, you are seeing that scattered blue light arriving from all over the sky at once.
And at sunset, when the light travels through much more air to reach you, even the blue gets scattered away, leaving the reds and oranges we love.
TXT,
    ],
    [
        'title' => 'How did the Roman Empire fall?',
        'why' => 'history narrative: timeline + step_flow + quote',
        'script' => <<<'TXT'
The Roman Empire did not collapse in a single day. It came apart slowly, over centuries, from a mix of pressures that fed on each other.
At its height around the year 117, Rome ruled from Britain to the Middle East, tens of millions of people under one flag.
But an empire that size was expensive. The army that held the borders drained the treasury, and to pay for it emperors taxed harder and debased the coins, which fuelled inflation.
Politics rotted from the inside. In one fifty year stretch, Rome burned through more than twenty emperors, most murdered by their own soldiers.
To govern such a sprawl, the empire was split in two, east and west. The wealthier east endured; the west was left weaker and exposed.
Along the northern frontier, pressure grew as tribes pushed into Roman land, some fleeing the Huns sweeping in from the east.
In the year 410, the unthinkable happened: the city of Rome itself was sacked. As one writer put it, the city that had taken the whole world was itself taken.
Finally, in 476, the last western emperor was deposed, and the western empire was gone. The east lived on for another thousand years as Byzantium.
TXT,
    ],
    [
        'title' => 'SSD vs HDD: which should you actually buy?',
        'why' => 'tech comparison: versus_card + checklist + big_counter',
        'script' => <<<'TXT'
If you are buying a computer or an upgrade, you will face one choice again and again: SSD or HDD. Let us settle it.
Both store your files, but they work in completely different ways.
A hard disk drive, or HDD, is mechanical. A metal platter spins thousands of times a minute while a tiny arm skates across it reading the data. It is old, proven technology.
A solid state drive, or SSD, has no moving parts at all. It stores everything in flash memory chips and finds any file almost instantly.
That difference shows up as speed. A typical SSD reads data around ten times faster than a hard drive, so your computer boots in seconds and programs open the moment you click.
Because nothing moves, an SSD is also tougher if you drop your laptop, and it runs silently and cooler, using less battery.
So why does anyone still buy a hard drive? Price and size. Hard drives give you far more storage for the money, which matters if you are hoarding terabytes of video.
Here is the simple rule. Want speed and you will keep it simple: choose an SSD. Need huge cheap storage for backups and media: an HDD still earns its place. Many people use both, an SSD for the system and an HDD for the pile.
TXT,
    ],
];

foreach ($scenarios as $s) {
    $p = Project::create([
        'user_id' => 1,
        'title' => $s['title'],
        'template_type' => 'ai_explainer_video',
        'aspect_ratio' => '16:9',
        'status' => 'analyzing',
        'progress' => 0,
        'settings' => ['script' => $s['script'], 'target_seconds' => 120, 'test_scenario' => $s['why']],
    ]);
    AnalyzeExplainerScriptJob::dispatch($p);
    echo "queued #{$p->id}  {$s['title']}\n";
}

echo "\nAll queued. Watch the worker; when each flips to storyboard_ready, open /dashboard/explainer/<id>.\n";
