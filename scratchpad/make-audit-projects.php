<?php

/**
 * make-audit-projects — four explainers whose CONTENT asks for four different
 * sets of cards, so the casting can be audited against something.
 *
 *   docker compose exec app php scratchpad/make-audit-projects.php <key> [user_id]
 *
 * Keys and what each one is shaped to demand:
 *   rent      a decision between two options  -> versus, split, quadrant/spectrum, decision_tree, receipt
 *   container a history across decades        -> timeline, map, big_counter, quote, scale_comparison
 *   label     a practical how-to              -> step_flow, labeled_diagram, common_mistake, practice, checklist
 *   bill      where money goes                -> receipt, proportion_flow, pictogram_percent, animated_chart
 *
 * ~$0.05 of gpt-4o-mini each (analysis only, no render).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Jobs\AnalyzeExplainerScriptJob;
use Modules\Project\Models\Project;

$rent = <<<'TXT'
Everyone tells you renting is throwing money away. It is a good line and it is mostly wrong, because it compares the wrong two numbers.

Start with what each side actually costs. A renter pays rent, and that is the whole bill. A buyer pays a mortgage, but the mortgage is only part of it: there is interest, which in the early years is most of the payment, plus property tax, insurance, and maintenance that runs about one percent of the value every year. Add those up and a chunk of an owner's monthly cost buys them nothing either. That is the honest comparison: rent on one side, the unrecoverable costs of owning on the other.

Now the part people forget. Buying costs a fortune to start and a fortune to leave. Stamp duty or transfer tax, legal fees and a survey on the way in. Agent fees on the way out. Together that is often eight to ten percent of the price, which is why the break-even is measured in years, not months. Under about five years, renting usually wins. Past ten, owning usually does. Between the two it depends on your city.

There is a second axis nobody puts on the chart: flexibility. A renter can leave in a month. An owner who has to move sells into whatever market exists that year. If your job is stable and you like the street you are on, that flexibility is worth little. If you might move for work, it is worth a great deal.

And the forced-saving argument is real. Most people do not invest the difference. A mortgage takes the money before they can spend it, which is a genuine advantage of owning even though it has nothing to do with property being a good investment.

So the question is not which is better. It is: how long will you stay, how stable is your income, and would you actually invest the difference? Answer those three and the maths answers itself.
TXT;

$container = <<<'TXT'
In nineteen fifty-six, a trucking man named Malcolm McLean watched dockers unload his lorry one crate at a time and asked why the whole trailer could not go on the ship.

Before that, loading a ship was a craft. Sacks, barrels and crates were carried aboard by hand and stacked by men who knew how to make an uneven load stay put. It took weeks. Cargo cost about six dollars a ton to load, and a serious share of it was stolen along the way. A ship spent more time in port than at sea.

McLean bought a tanker, welded a deck across it, and sailed fifty-eight steel boxes from Newark to Houston. Loading cost about sixteen cents a ton. That is not a saving. That is a different industry.

The world did not change overnight. A box is only useful if the crane, the lorry, the train wagon and the ship agree on its size, and for a decade they did not. The corner fittings were standardised in the late sixties, and once every port could handle every box, the network effect took over.

Then the map redrew itself. Ports that could not take container ships died, and some of them had been trading for centuries. New ports appeared where there was deep water and space for stacks, often miles from the old city docks. Dock labour, which had been one of the great organised trades, collapsed to a fraction of its size.

And the economics of distance broke. When moving a thing costs almost nothing, it stops mattering where it is made. A factory can be ten thousand miles from its customer. Everything about the last fifty years of manufacturing follows from that one number falling.

Today about a quarter of a billion container moves happen every year. The box that did it is a plain steel rectangle with no moving parts, and it is probably the most consequential object of the twentieth century.
TXT;

$label = <<<'TXT'
The nutrition label is designed to be read, and almost nobody reads it in the right order. Here is the order that actually tells you something.

Start at the top, with the serving size. Everything below it is per serving, not per packet, and the serving is often smaller than the thing you are holding. A bottle of juice that says two and a half servings is telling you to multiply every number underneath by two and a half.

Second, the calories, but only as a sanity check against the serving size. A number on its own means very little.

Third, jump straight to the ingredients list, which is not part of the table at all. It is ordered by weight, so whatever is first is most of what you are eating. If a form of sugar is in the first three, you are eating a sweet. There are more than fifty names for sugar, and splitting one sweetener into three lets each one sit further down the list.

Fourth, back to the table for the fibre and the added sugars. Added sugars are the line the food industry fought hardest against, because it separates the sugar that came with the fruit from the sugar that was poured in.

The commonest mistake is reading the percentages as a score. They are a percentage of a daily amount for an average adult eating two thousand calories, which may be nothing like you. Treat them as a rough scale: five percent is a little, twenty percent is a lot.

The second mistake is trusting the front of the packet. The front is marketing and the back is regulated. Low fat usually means more sugar. Made with real fruit can mean almost none.

So: serving size, calories, ingredients, fibre and added sugars, and only then the percentages. Four lines and a list, in that order, and you can read any label in about ten seconds.
TXT;

$bill = <<<'TXT'
Your electricity bill looks like one number for one thing. It is really four businesses sharing a page, and only one of them sells you electricity.

Take a typical hundred pound bill. About a third of it is the energy itself: the gas, wind and nuclear that actually generated the power. That is the part that moves when wholesale prices move, which is why bills spike when gas does.

Another quarter goes to the network: the pylons, the substations and the cables under your street, and the people who fix them at three in the morning. You are renting the road the electricity travels on, and that cost barely changes whatever you do.

Roughly a fifth is policy: the levies that pay for renewable subsidies, for insulating low-income homes, and for the schemes governments attach to bills instead of taxes. It is a tax that does not look like one.

Then there is the supplier's own cost and margin, which is smaller than almost everyone assumes: a few pounds in every hundred. Switching suppliers moves that slice and very little else.

And finally value added tax on top of the whole thing.

Two consequences follow. First, using less power only shrinks the first slice, so halving your usage does not halve your bill. Second, the standing charge is there whether you use anything or not, which is why an empty flat still gets a bill.

The one number that matters is not the total. It is the unit rate, in pence per kilowatt hour, next to the standing charge in pence per day. Those two numbers are the price. Everything else on the page is arithmetic.
TXT;

$topics = [
    'rent' => ['Renting versus buying, honestly', $rent],
    'container' => ['How a steel box rewrote world trade', $container],
    'label' => ['How to read a nutrition label', $label],
    'bill' => ['What your electricity bill actually pays for', $bill],
];

$key = (string) ($argv[1] ?? '');
if (!isset($topics[$key])) {
    exit('usage: make-audit-projects.php <' . implode('|', array_keys($topics)) . "> [user_id]\n");
}
[$title, $script] = $topics[$key];

$project = Project::create([
    'user_id' => (int) ($argv[2] ?? 1),
    'title' => $title,
    'template_type' => 'ai_explainer_video',
    'aspect_ratio' => '16:9',
    'status' => 'analyzing',
    'progress' => 0,
    'settings' => ['script' => $script, 'target_seconds' => 180],
]);
printf("created project %d: %s (%d words)\n", $project->id, $title, str_word_count($script));

$t0 = microtime(true);
(new AnalyzeExplainerScriptJob($project))->handle();
$project->refresh();
printf("analysed in %.0fs -> %s\n", microtime(true) - $t0, $project->status);
if ($project->error_message) {
    echo "error: {$project->error_message}\n";
}
