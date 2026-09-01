<?php

/**
 * Bench cases 1 — argument, journey, compare, countdown.
 * Schema documented in corpus.php.
 */

return [

// ---------------------------------------------------------------- argument --

[
    'id' => 'city-flooding',
    'title' => 'Why cities flood more than they used to',
    'target_seconds' => 75,
    'script' => <<<'TXT'
Cities are flooding more often, and it is not only because there is more rain.
A field soaks up almost everything that falls on it. A city does not: roofs, roads and car parks send water straight into a drain system that was sized decades ago for a smaller town and a gentler sky.
In a typical square kilometre of dense city, about seventy percent of the surface is sealed. Only a tenth of the rain that lands on it soaks into the ground; the rest becomes runoff in minutes.
People blame the drains, and the drains are part of it. But a pipe that was adequate in 1970 is not undersized because it aged. It is undersized because the surface above it changed.
The fix that works is not a bigger pipe. It is giving the water somewhere to go: permeable paving, planted verges, retention ponds, roofs that hold a shower for an hour and let it out slowly.
Rotterdam built squares that are public plazas in dry weather and reservoirs in a storm. Copenhagen turned roads into shallow channels that steer water toward the harbour.
The city that floods least is not the one with the biggest drains. It is the one that behaves a little more like a field.
TXT,
    'rubric' => [
        'shape' => ['argument'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['animated_chart', 'proportion_flow', 'pictogram_percent', 'stat_spotlight', 'big_counter'], 'why' => 'the 70% sealed / 10% soaks beat is a real figure and must be drawn, not bulleted'],
            ['cards' => ['myth_fact', 'common_mistake', 'quote_card'], 'why' => '"people blame the drains" is the objection the argument turns on'],
        ],
        'should' => [
            ['cards' => ['image_grid', 'photo_stack', 'split_side_by_side', 'before_after'], 'why' => 'Rotterdam and Copenhagen are two named places that want pictures'],
        ],
        'never' => ['timeline_card' => 'nothing in this script is chronological'],
        'media_min' => 0.34,
    ],
],

[
    'id' => 'airline-seats',
    'title' => 'Why airline seats got smaller',
    'target_seconds' => 70,
    'script' => <<<'TXT'
The seat you sat in last summer is about four centimetres closer to the one in front than the same seat in 1990.
Nobody woke up and decided to make flying worse. The pitch shrank because of how a ticket is priced.
Take a hundred dollar economy fare on a short flight. Roughly thirty dollars is fuel, twenty-five is the aircraft itself, twenty is crew and ground handling, fifteen is airport and air traffic fees, and what is left over is the margin the airline actually keeps.
That margin is small enough that adding six more seats to a cabin changes the answer more than anything the airline can negotiate.
So the row spacing came down from about thirty-four inches to thirty, and the seat back got thinner to give some of it back.
Airlines will tell you the thinner seat means you did not really lose anything. Measure it and you did, just less than the pitch number suggests.
The honest version is simple. You are not buying a seat. You are buying the cheapest ticket, and the seat is what is left after the price is set.
TXT,
    'rubric' => [
        'shape' => ['argument'],
        'scenes' => [7, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['receipt_card', 'proportion_flow', 'animated_chart'], 'why' => 'the hundred-dollar fare breaks into parts that SUM — a receipt or a proportion, never bullets'],
            ['cards' => ['scale_comparison', 'before_after', 'animated_chart', 'stat_spotlight', 'big_counter'], 'why' => '34 inches to 30 is a measured shrink and should be shown'],
        ],
        'should' => [
            ['cards' => ['myth_fact'], 'why' => '"the thinner seat means you lost nothing" is a stated claim being corrected'],
        ],
        'never' => ['map_card' => 'no geography in this script'],
        'media_min' => 0.3,
    ],
],

[
    'id' => 'sleep-eight-hours',
    'title' => 'The eight hours of sleep myth',
    'target_seconds' => 70,
    'script' => <<<'TXT'
You have heard that everyone needs eight hours of sleep. That number is an average, and treating an average as a target is how a lot of people end up lying awake worrying about sleep.
Sleep need is a distribution. Most adults land somewhere between seven and nine hours, a minority are genuinely fine on six and a half, and a small group needs closer to ten.
The 2015 consensus statement from the American Academy of Sleep Medicine put the healthy adult range at seven or more hours, not at eight exactly.
The stronger predictor of how you feel is not the total. It is regularity: going to bed and getting up within about the same half hour every day, including weekends.
People often say they will catch up at the weekend. Two long lie-ins do restore some alertness, but they shift your body clock later, which makes Monday worse than the debt you were repaying.
So the useful question is not whether you hit eight. It is whether your nights look like each other.
TXT,
    'rubric' => [
        'shape' => ['argument'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['myth_fact'], 'why' => 'this script exists to debunk one widespread belief — the card was built for exactly this'],
            ['cards' => ['evidence_card'], 'why' => 'the AASM 2015 statement is a NAMED source with a year, the card\'s whole precondition'],
        ],
        'should' => [
            ['cards' => ['spectrum_card', 'animated_chart', 'pictogram_percent'], 'why' => 'sleep need as a range rather than a point'],
        ],
        'never' => ['timeline_card' => 'one cited year is not a chronology'],
        'media_min' => 0.25,
    ],
],

[
    'id' => 'minimum-wage',
    'title' => 'Does raising the minimum wage cost jobs?',
    'target_seconds' => 80,
    'script' => <<<'TXT'
Ask two economists whether raising the minimum wage costs jobs and you can get two confident, opposite answers. Both of them have evidence.
The classic prediction is simple. Put a price floor above the market wage and employers buy less labour. It is the first thing you learn about a price floor.
Then in 1994 Card and Krueger compared fast food restaurants across the New Jersey and Pennsylvania border after New Jersey raised its wage, and found employment did not fall. That paper is why the question is still open.
Later work using much larger datasets, including the 2019 Cengiz study of a hundred and thirty-eight state-level increases, found the same thing up to a point: small and moderate increases mostly moved money without moving headcount.
The honest reading is that the effect depends on how far above the local median wage the floor sits. At forty percent of the median the evidence for job loss is weak. Push toward eighty percent and it stops being weak.
So it is not a yes or no question. It is a question about where on that dial your city actually is.
TXT,
    'rubric' => [
        'shape' => ['argument'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['evidence_card'], 'why' => 'Card and Krueger 1994 / Cengiz 2019 are named sources — the card\'s home ground'],
            ['cards' => ['spectrum_card', 'quadrant_map', 'animated_chart', 'function_plot'], 'why' => 'the payoff is a DIAL (40% to 80% of median), a one-dimensional position card'],
        ],
        'should' => [
            ['cards' => ['versus_card', 'split_side_by_side', 'myth_fact'], 'why' => 'the two confident opposite answers'],
        ],
        'never' => ['list_ranking' => 'nothing is ranked here'],
        'media_min' => 0.25,
    ],
],

[
    'id' => 'glass-recycling',
    'title' => 'Why glass is endlessly recyclable and mostly is not recycled',
    'target_seconds' => 75,
    'script' => <<<'TXT'
Glass is the one packaging material that can go round forever. Melt it, form it, use it, melt it again — the material does not degrade. So why does most of it not come back?
The loop itself is short. A bottle is collected, sorted by colour, crushed into cullet, melted with sand and soda ash at about fifteen hundred degrees, formed into a new bottle, filled, and sold again.
Every step in that loop works. The one that breaks is sorting. Mixed-colour cullet can only become green glass or aggregate, so a single broken brown bottle in a clear stream downgrades the batch.
In the United States about a third of glass containers get recycled. In Sweden it is over ninety percent, and the difference is not technology. It is that the bottles are collected separately by colour instead of thrown into one commingled bin.
There is a real prize for fixing it. Every ten percent of cullet in the mix cuts the furnace energy by around three percent, and the furnace is most of the carbon in a bottle.
The material was never the problem. The bin was.
TXT,
    'rubric' => [
        'shape' => ['argument', 'generic'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['cycle_diagram'], 'why' => 'the collect-sort-melt-form-fill loop returns to its start — nothing else in the registry draws that'],
            ['cards' => ['animated_chart', 'pictogram_percent', 'proportion_flow', 'stat_spotlight'], 'why' => 'a third vs ninety percent is a comparison of real shares'],
        ],
        'should' => [
            ['cards' => ['common_mistake', 'myth_fact'], 'why' => 'the commingled bin is the specific error being named'],
        ],
        'never' => ['timeline_card' => 'a process is not a chronology'],
        'media_min' => 0.3,
    ],
],

// ----------------------------------------------------------------- journey --

[
    'id' => 'shipping-container',
    'title' => 'The box that rebuilt world trade',
    'target_seconds' => 85,
    'script' => <<<'TXT'
In 1956 loading a ship cost about five dollars and eighty cents a ton. Within a decade the same ton cost sixteen cents. One idea did that.
Before it, cargo moved as break bulk: sacks, crates and barrels, each handled by gangs of dockworkers, each stacked by eye. A ship could spend longer in port than at sea.
Malcolm McLean was a trucking man, not a shipping man, and that is why he saw it. In April 1956 he loaded fifty-eight aluminium truck bodies onto a converted tanker, the Ideal X, and sailed them from Newark to Houston.
The 1960s were spent arguing about size. Ports, railways and truckers all wanted a different box, and the standard only settled when ISO fixed the corner castings and the twenty and forty foot lengths between 1968 and 1970.
Vietnam made it real. The US military needed reliable resupply across the Pacific, and containers were the only way to do it, which paid for the ships and the cranes that the civilian trade later inherited.
Then the ports moved. New York's Manhattan piers, too small for the cranes and the yard space, gave way to Newark across the harbour, and the same story repeated in London, Liverpool and San Francisco.
Today around eight hundred million container movements happen a year. The box did not just cut a cost. It made it worth manufacturing something on the other side of the planet.
TXT,
    'rubric' => [
        'shape' => ['journey'],
        'scenes' => [8, 13],
        'math_mode' => false,
        'must' => [
            ['cards' => ['timeline_card'], 'why' => '1956, 1968-70, the Vietnam years — real dated chronology, the timeline card\'s only legitimate use'],
            ['cards' => ['big_counter', 'stat_spotlight', 'animated_chart', 'scale_comparison'], 'why' => '$5.86 to $0.16 a ton, and 800 million movements, are headline figures'],
        ],
        'should' => [
            ['cards' => ['map_card'], 'why' => 'Newark to Houston, and the ports that moved, are geography'],
            ['cards' => ['before_after', 'split_side_by_side', 'image_grid'], 'why' => 'break bulk vs container is a picture pair'],
        ],
        'never' => ['decision_tree' => 'the viewer is not choosing anything'],
        'media_min' => 0.34,
    ],
],

[
    'id' => 'penicillin',
    'title' => 'How penicillin actually got made',
    'target_seconds' => 80,
    'script' => <<<'TXT'
Alexander Fleming noticed a mould killing bacteria on a forgotten petri dish in 1928, published it, and then largely put it down. The discovery is famous. The reason it took another sixteen years to reach a wounded soldier is not.
Fleming's problem was that penicillin was almost impossible to extract and unstable once you had it. He could see the halo of dead staph around the mould, but he could not make a medicine out of it.
In 1939 a team at Oxford — Howard Florey, Ernst Chain and Norman Heatley — picked the paper back up. Heatley built the extraction rig out of bedpans, milk churns and a doorbell.
The first patient, a policeman named Albert Alexander, improved dramatically in 1941 and then died when the supply ran out. They had been recovering the drug from his urine to keep dosing him.
Britain was being bombed and could not build fermentation plants, so Florey flew to the United States. A lab in Peoria found that corn steep liquor multiplied the yield, and a mouldy cantaloupe from a local market carried the strain that industry actually used.
By D-Day in June 1944 there was enough penicillin for every Allied casualty who needed it. Fleming, Florey and Chain shared the Nobel Prize the next year.
The discovery took a moment. The manufacturing took the war.
TXT,
    'rubric' => [
        'shape' => ['journey'],
        'scenes' => [8, 13],
        'math_mode' => false,
        'must' => [
            ['cards' => ['timeline_card'], 'why' => '1928, 1939, 1941, 1944, 1945 is a spine of real dates'],
        ],
        'should' => [
            ['cards' => ['evidence_card', 'quote_card', 'quote_portrait'], 'why' => 'named people carry this story'],
            ['cards' => ['image_grid', 'photo_stack', 'split_side_by_side', 'full_bleed_with_banner'], 'why' => 'the petri dish, the bedpan rig and the cantaloupe are all pictures'],
        ],
        'never' => ['versus_card' => 'nobody is being compared'],
        'media_min' => 0.34,
    ],
],

[
    'id' => 'nokia',
    'title' => 'How Nokia lost a 50 percent market share',
    'target_seconds' => 75,
    'script' => <<<'TXT'
In 2007 Nokia sold more than half the smartphones on earth. Six years later it sold its phone business to Microsoft for about seven billion dollars, less than a tenth of what the company had been worth.
Nokia started as a paper mill in 1865, made rubber boots and cables, and only became a phone company in the 1980s. Reinvention was in its bones, which is what makes the ending strange.
Through the 1990s and 2000s it did everything right. The 1100 became the best selling phone of all time. By 2007 its share of smartphones was around fifty percent.
The iPhone arrived that June. Nokia's own engineers had built touchscreen prototypes years earlier and the company had shelved them, because the phones that were selling had keypads and the phones that were selling paid everyone's salary.
Symbian was the deeper problem. It had been written for low power hardware and small screens, and every app took months of work per handset. Developers went to iOS and Android instead, and an app store with nothing in it is not a platform.
In 2011 the company bet the business on Windows Phone. It was a coherent decision and it was also the last one; share fell to three percent by 2013.
Nokia had the engineers, the money and the warning. What it did not have was a reason to break the thing that was still working.
TXT,
    'rubric' => [
        'shape' => ['journey', 'argument'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['animated_chart', 'timeline_card', 'big_counter', 'stat_spotlight'], 'why' => '50% to 3% across dated years is the whole story and must be drawn'],
        ],
        'should' => [
            ['cards' => ['before_after', 'split_side_by_side', 'versus_card'], 'why' => 'keypad vs touchscreen'],
            ['cards' => ['common_mistake', 'myth_fact'], 'why' => 'the shelved prototype is the nameable error'],
        ],
        'never' => ['practice_card' => 'nothing is being taught to solve'],
        'media_min' => 0.3,
    ],
],

// ----------------------------------------------------------------- compare --

[
    'id' => 'coffee-tea',
    'title' => 'Coffee vs tea: what actually differs',
    'target_seconds' => 70,
    'script' => <<<'TXT'
Coffee and tea are the same trick played two ways: a plant that makes caffeine to poison insects, and a species that decided to drink it.
Start with the dose. A 240 millilitre filter coffee carries about 95 milligrams of caffeine. The same cup of black tea carries about 47, and green tea about 28.
But the curve matters more than the number. Tea contains L-theanine, an amino acid that slows the absorption and takes the edge off the peak, which is why the same total dose feels smoother.
On acidity they separate again. Coffee sits around pH 5, black tea around pH 6.3, which is most of the reason coffee is harder on an empty stomach.
Cost per cup is closer than people think. Roughly forty cents of beans against fifteen cents of loose leaf, and both are dwarfed by whatever you are putting in them.
So the verdict is not that one wins. If you want a fast peak, drink coffee. If you want three hours of level attention, drink tea. If you want to sleep, note that both have a half life of around five hours.
TXT,
    'rubric' => [
        'shape' => ['compare'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['versus_card'], 'why' => 'two named contenders weighed on several dimensions is the versus card by definition'],
            ['cards' => ['animated_chart', 'scale_comparison', 'stat_spotlight'], 'why' => '95 / 47 / 28 mg is a measured series'],
        ],
        'should' => [
            ['cards' => ['function_plot'], 'why' => 'the absorption CURVE is named explicitly — the plot card is global now, not maths-only'],
        ],
        'never' => ['list_ranking' => 'two things are not a countdown'],
        'media_min' => 0.3,
    ],
],

[
    'id' => 'train-vs-plane',
    'title' => 'Train or plane for a 500km trip',
    'target_seconds' => 70,
    'script' => <<<'TXT'
For a five hundred kilometre trip the plane looks obviously faster. Door to door, it usually is not.
Count the whole journey. The flight is an hour and ten in the air, but add forty minutes to the airport, ninety minutes before departure, twenty to get off and collect a bag, and forty-five into the city at the other end. Call it four hours and forty-five.
The train is two hours and fifty minutes at three hundred kilometres an hour, plus fifteen minutes at each end because the station is already in the city. Three hours twenty.
Carbon is not close. That flight emits roughly ninety kilograms of CO2 per passenger. The same trip by electric high speed rail is around eight.
Price usually favours the plane if you book late, and the train if you book early, which is the opposite of what most people assume.
The rule of thumb the operators use is simple: under about four hours of rail time, the train takes the majority of the market. Beyond that, the plane wins it back.
TXT,
    'rubric' => [
        'shape' => ['compare'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['versus_card'], 'why' => 'two contenders, several rounds — the canonical versus shape'],
            ['cards' => ['receipt_card', 'proportion_flow', 'animated_chart', 'scale_comparison', 'timeline_card'], 'why' => 'the door-to-door time is a sum of named parts (40 + 90 + 70 + 20 + 45), not a bullet list'],
        ],
        'should' => [
            ['cards' => ['scale_comparison', 'pictogram_percent', 'big_counter'], 'why' => '90kg vs 8kg of CO2 is a size comparison'],
        ],
        'never' => ['cycle_diagram' => 'nothing repeats here'],
        'media_min' => 0.3,
    ],
],

[
    'id' => 'rent-vs-buy',
    'title' => 'Rent or buy: the honest version',
    'target_seconds' => 80,
    'script' => <<<'TXT'
Renting is throwing money away. That is the line, and it is wrong in a specific way worth understanding.
Buying has its own throw-away costs. On a four hundred thousand mortgage at five percent, the first year is about twenty thousand in interest, four thousand in property tax, two thousand in insurance and maybe four thousand in maintenance. Thirty thousand a year that builds no equity whatsoever.
Against that, rent on the same house might be twenty-four thousand. The equity you build in year one is only the principal part of the payment, which early on is small.
The crossover is transaction cost. Buying and selling costs roughly eight to ten percent of the price in fees, so a house you sell inside three years usually loses to renting even in a rising market.
So the real question is not rent versus buy. It is how long you will stay, and how confident you are about that.
If you know you are staying seven years, buy. If you might move in two, rent, and put the difference somewhere it can grow.
And if you genuinely do not know, that uncertainty has a price, and renting is how you pay it.
TXT,
    'rubric' => [
        'shape' => ['compare', 'argument'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['receipt_card', 'proportion_flow', 'animated_chart'], 'why' => '20k + 4k + 2k + 4k = 30k is a breakdown that sums — the receipt card is the right answer'],
            ['cards' => ['decision_tree', 'versus_card', 'checklist_card', 'quadrant_map'], 'why' => 'the payoff is a genuine two-branch decision on how long you stay'],
        ],
        'should' => [
            ['cards' => ['myth_fact'], 'why' => '"renting is throwing money away" is the belief corrected in sentence one'],
            ['cards' => ['function_plot'], 'why' => 'the crossover over years of tenure is a curve'],
        ],
        'never' => ['map_card' => 'no geography'],
        'media_min' => 0.25,
    ],
],

// --------------------------------------------------------------- countdown --

[
    'id' => 'tallest-buildings',
    'title' => 'The five tallest buildings on earth',
    'target_seconds' => 70,
    'script' => <<<'TXT'
Height is a strange competition, because the rules decide the winner. We are counting architectural height to the top of the spire, which is how the record books do it.
Fifth is the Ping An Finance Center in Shenzhen, five hundred and ninety-nine metres, finished in 2017 and originally meant to be taller before airspace rules cut the spire.
Fourth, the Lotte World Tower in Seoul at five hundred and fifty-five metres — actually shorter, which tells you the list moves around depending on whose ranking you read.
Third is the Shanghai Tower, six hundred and thirty-two metres, with a twisting skin that cuts wind load by about a quarter.
Second, the Merdeka 118 in Kuala Lumpur, six hundred and seventy-nine metres, finished in 2023.
And first, still, the Burj Khalifa in Dubai at eight hundred and twenty-eight metres. It has held the record since 2010, which in this business is an eternity.
The Jeddah Tower is meant to pass a kilometre. It has been under construction, on and off, for more than a decade.
TXT,
    'rubric' => [
        'shape' => ['countdown'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['list_ranking'], 'why' => 'a ranked top-N is the ranking card, and the countdown shape offers nothing else for the reveal'],
            ['cards' => ['scale_comparison', 'animated_chart', 'big_counter', 'stat_spotlight'], 'why' => '828m against 679m is a size comparison the viewer cannot picture from words'],
        ],
        'should' => [
            ['cards' => ['map_card', 'image_grid', 'photo_stack', 'full_bleed_with_banner'], 'why' => 'five named buildings in five named cities'],
        ],
        'never' => ['cycle_diagram' => 'nothing loops'],
        'media_min' => 0.34,
    ],
],

[
    'id' => 'spoken-languages',
    'title' => 'The four most spoken languages',
    'target_seconds' => 65,
    'script' => <<<'TXT'
Most spoken depends entirely on whether you count people who learned it first or people who can use it at all. We are counting total speakers, native plus second language.
Fourth is Hindi, around six hundred and ten million speakers, concentrated almost entirely in one subcontinent.
Third, Spanish, about five hundred and fifty-nine million, and the only one of the four with the majority of its speakers in a hemisphere other than where it started.
Second is Mandarin Chinese at roughly one point one eight billion, with nine hundred and forty million of those native — the largest native base of any language on earth.
And first is English, about one and a half billion speakers, of whom only about three hundred and eighty million are native. Four out of five people who speak English did not grow up speaking it.
That last number is the interesting one. English is the only language on the list whose speakers are mostly people who chose it.
TXT,
    'rubric' => [
        'shape' => ['countdown'],
        'scenes' => [5, 10],
        'math_mode' => false,
        'must' => [
            ['cards' => ['list_ranking'], 'why' => 'a ranked reveal'],
            ['cards' => ['animated_chart', 'pictogram_percent', 'proportion_flow', 'big_counter', 'stat_spotlight'], 'why' => '1.5bn total vs 380m native is a share worth drawing'],
        ],
        'should' => [
            ['cards' => ['map_card'], 'why' => 'the geography is stated for every entry'],
        ],
        'never' => ['practice_card' => 'no problem is posed'],
        'media_min' => 0.25,
    ],
],

];
