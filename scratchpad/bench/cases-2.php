<?php

/**
 * Bench cases 2 — demo walkthroughs, concept explainers, the two cards that
 * exist for objects (custom_card), and four ROUTING probes.
 *
 * A routing probe carries 'route_only' => true: what is being tested is which
 * pipeline the script lands in (math board vs the generic composer), not the
 * card-by-card storyboard, so casting is scored on presence of the mode's own
 * cards rather than against a full rubric.
 *
 * Schema documented in corpus.php.
 */

return [

// --------------------------------------------------------------- demo shape --

[
    'id' => 'vreato-demo',
    'title' => 'Turn any YouTube video into shorts with Vreato',
    'target_seconds' => 90,
    'guide' => 'I have screen recordings of every step: the dashboard, the URL paste, the template picker, the settings panel, the render progress bar, and the finished vertical clip playing. Use my screenshots, keep this order, and do not turn any step into a text-only card.',
    'script' => <<<'TXT'
You have a forty minute podcast and no time to cut it into clips. Here is the whole job in about two minutes.
Vreato takes a long video and finds the moments worth posting, then cuts them vertical with captions burned in.
Start on the dashboard and press New Project. You pick a template first, because the template decides what the output looks like.
Paste the YouTube link into the field at the top. It reads the transcript straight away, so you do not have to upload anything.
Choose how many clips you want. Four is the default and it is usually right for a forty minute source.
Open the settings panel. This is where you set the aspect ratio, the caption style, and whether you want the gameplay strip underneath.
Press Generate. The progress bar walks through downloading, transcribing, ranking the moments, cutting and rendering.
When it finishes you get the clips in a switcher, and you can play each one before you download it. Captions are word by word, already synced.
There is a second thing worth knowing. Every clip carries its own YouTube kit, with the title, the description and the chapters already written.
That is the whole tool. One link in, four posts out.
TXT,
    'rubric' => [
        'shape' => ['demo'],
        'scenes' => [9, 16],
        'math_mode' => false,
        'must' => [
            ['cards' => ['phone_mockup'], 'why' => 'every step of this script is a SCREEN, and the guide says the user has the screenshots'],
            ['cards' => ['split_side_by_side', 'full_bleed_with_side_panel', 'before_after', 'photo_stack', 'image_grid'], 'why' => 'a walkthrough must change framing between screens or it is six identical browser frames'],
        ],
        'should' => [
            ['cards' => ['step_flow', 'checklist_card', 'icon_grid'], 'why' => 'one preview or recap of the whole process'],
        ],
        'never' => [
            'quote_card' => 'the demo shape exists so a walkthrough stops inventing objections nobody made (project 135)',
            'timeline_card' => 'the steps of a process are not dates',
        ],
        'media_min' => 0.5,
    ],
],

[
    'id' => 'password-manager',
    'title' => 'Set up a password manager in five minutes',
    'target_seconds' => 80,
    'script' => <<<'TXT'
The average person reuses the same four passwords across about two hundred accounts. One breach anywhere and the rest are open. A password manager fixes that in an afternoon.
Install the app on your phone and the extension in your browser. Both, not one — the browser is where you sign in and the phone is where you need it when you are away from the desk.
The first thing it asks for is a master password. This is the only one you will ever type again, so make it three or four random words, not a clever variation of the old one.
Next, import. Every browser has an export button buried in its password settings, and the manager reads that file directly, so you are not typing two hundred entries by hand.
Now the part people skip. Open the security dashboard, which lists every reused and breached password you have, sorted worst first. Change the top ten today and the rest as you meet them.
Turn on autofill and let it generate new passwords for you. If you never see the password, you cannot reuse it.
Last, print the recovery kit and put it somewhere physical. A password manager is the one account where losing access is genuinely unrecoverable.
TXT,
    'rubric' => [
        'shape' => ['demo'],
        'scenes' => [8, 14],
        'math_mode' => false,
        'must' => [
            ['cards' => ['phone_mockup'], 'why' => 'the install, the import and the security dashboard are literal screens'],
            ['cards' => ['step_flow', 'checklist_card', 'icon_grid', 'split_side_by_side', 'before_after'], 'why' => 'the framing has to change across the steps'],
        ],
        'should' => [
            ['cards' => ['stat_spotlight', 'big_counter', 'pictogram_percent'], 'why' => 'four passwords across two hundred accounts is the opening figure'],
            ['cards' => ['common_mistake'], 'why' => '"a clever variation of the old one" is the named error'],
        ],
        'never' => ['map_card' => 'no geography'],
        'media_min' => 0.45,
    ],
],

// ------------------------------------------------- concept explainers -------

[
    'id' => 'lithium-battery',
    'title' => 'What is actually inside a lithium battery',
    'target_seconds' => 75,
    'script' => <<<'TXT'
A lithium battery is not a tank of electricity. It is a sandwich, and everything it does comes from what the layers are made of.
On one side is the cathode, usually a lithium metal oxide, and it is the expensive layer — the cobalt and nickel in it are most of the cost of the cell.
On the other side is the anode, almost always graphite, which is cheap and does one job: hold lithium ions between its sheets without falling apart.
Between them sits the separator, a plastic film about fifteen microns thick, perforated so ions pass and electrons cannot. If it tears, the cell shorts, and that is what thermal runaway actually is.
The whole sandwich is soaked in electrolyte, the liquid the ions swim through.
Charging pushes lithium ions from the cathode, through the separator, into the graphite. Discharging lets them come back, and the electrons that could not cross the separator have to go the long way round, through your phone.
That is the entire trick. A battery is not storing electricity. It is storing lithium on the wrong side of a wall.
TXT,
    'rubric' => [
        'shape' => ['generic', 'argument'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['layer_stack', 'labeled_diagram'], 'why' => 'cathode / separator / anode genuinely sit on top of each other — this is what layer_stack is for, and labeled_diagram is the drawn alternative'],
        ],
        'should' => [
            ['cards' => ['cycle_diagram', 'step_flow'], 'why' => 'charge and discharge is a two-way process worth drawing'],
            ['cards' => ['term_card'], 'why' => '"thermal runaway" is jargon the script stops to define'],
            ['cards' => ['scale_comparison', 'stat_spotlight'], 'why' => 'a fifteen micron film is a size nobody can picture'],
        ],
        'never' => ['list_ranking' => 'nothing is ranked'],
        'media_min' => 0.3,
    ],
],

[
    'id' => 'water-cycle',
    'title' => 'The water cycle, properly',
    'target_seconds' => 70,
    'script' => <<<'TXT'
Every school draws the water cycle as a circle, and the circle is right. What the drawing usually leaves out is how uneven each step is.
Start with evaporation. About eighty-six percent of the water that enters the atmosphere comes off the ocean, not off lakes and rivers.
That vapour rises, cools and condenses into cloud droplets around specks of dust and salt. Without those specks the air would just stay humid.
Then precipitation, and here is the imbalance: about seventy-eight percent of rain falls back onto the ocean it came from. Only the remainder reaches land at all.
On land the water splits three ways. Some runs off into rivers, some soaks into the soil for plants, and some sinks into groundwater that may not see the surface for a thousand years.
And then collection, back to the ocean, and the loop starts again.
The cycle takes about nine days for a single molecule in the atmosphere, and up to ten thousand years for one that ends up in deep groundwater. Same loop, wildly different speeds.
TXT,
    'rubric' => [
        'shape' => ['generic'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['cycle_diagram'], 'why' => 'a loop that returns to its start, stated as a loop in sentence one'],
            ['cards' => ['animated_chart', 'pictogram_percent', 'proportion_flow', 'stat_spotlight'], 'why' => '86% off the ocean, 78% back into it — real shares'],
        ],
        'should' => [
            ['cards' => ['proportion_flow'], 'why' => 'the land water splitting three ways is one whole dividing into parts'],
            ['cards' => ['scale_comparison', 'timeline_card', 'spectrum_card'], 'why' => 'nine days against ten thousand years'],
        ],
        'never' => ['versus_card' => 'nothing is competing'],
        'media_min' => 0.25,
    ],
],

[
    'id' => 'inflation',
    'title' => 'What inflation actually measures',
    'target_seconds' => 75,
    'script' => <<<'TXT'
Inflation is not the price of things going up. It is the value of money going down, measured against a fixed shopping basket.
The basket is the whole trick. A statistics agency picks a few hundred goods and services, weights them by how much people actually spend, and prices the same list every month.
In a typical basket, housing is about a third of the weight, transport around fifteen percent, food thirteen, and everything else splits the rest.
So if rent rises ten percent and televisions fall ten percent, the index does not sit still. Housing carries far more weight, and the number goes up.
That is why the headline figure so often disagrees with your own experience. You are not buying the average basket. If you rent and commute, your personal inflation is higher than the print.
Core inflation strips out food and energy, not because those do not matter, but because they swing on weather and war and drown out the signal in everything else.
The number is real. It just answers a narrower question than most people think it does.
TXT,
    'rubric' => [
        'shape' => ['generic', 'argument'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['proportion_flow', 'animated_chart', 'receipt_card', 'pictogram_percent'], 'why' => 'the basket weights (a third, 15%, 13%, the rest) are one whole splitting into parts'],
        ],
        'should' => [
            ['cards' => ['term_card'], 'why' => '"core inflation" is defined mid-script — the term card is exactly that stop'],
            ['cards' => ['myth_fact'], 'why' => '"inflation is the price of things going up" is corrected in sentence one'],
        ],
        'never' => ['map_card' => 'no geography'],
        'media_min' => 0.25,
    ],
],

[
    'id' => 'noise-cancelling',
    'title' => 'How noise cancelling headphones work',
    'target_seconds' => 70,
    'script' => <<<'TXT'
Noise cancelling is not insulation. The headphone is not blocking the sound — it is playing a second sound designed to erase the first.
Sound is a pressure wave. Draw it and you get a sine curve: air pushed above normal pressure, then below, a few hundred times a second.
If you play a second wave that is exactly upside down — the same shape shifted by half a cycle — the peaks of one land in the troughs of the other and they sum to nothing. That is destructive interference.
So a microphone on the outside of the cup listens, a chip inverts what it hears, and the driver plays the inverse along with your music.
The catch is timing. The chip has about a third of a millisecond to do it, and the shorter the wavelength the tighter that gets, which is why cancelling works beautifully on a low engine rumble around a hundred hertz and barely at all on a voice at two thousand.
That is why a plane goes quiet and the person next to you does not.
TXT,
    'rubric' => [
        'shape' => ['generic'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['function_plot', 'labeled_diagram'], 'why' => 'a sine wave and its inverse is the ONE picture this script needs, and function_plot draws it natively rather than asking for a photograph of a waveform'],
        ],
        'should' => [
            ['cards' => ['term_card'], 'why' => '"destructive interference" is named and defined'],
            ['cards' => ['animated_chart', 'spectrum_card', 'scale_comparison'], 'why' => '100 Hz cancels, 2000 Hz does not — a position on a range'],
        ],
        'never' => ['cycle_diagram' => 'a wave is not a process loop'],
        'media_min' => 0.25,
    ],
],

[
    'id' => 'boarding-pass',
    'title' => 'Everything on your boarding pass',
    'target_seconds' => 70,
    'script' => <<<'TXT'
A boarding pass looks like a receipt, but almost every field on it is doing something you were never told about.
Top left is the passenger name, printed exactly as it appears in the reservation system, which is why a middle initial can matter at the gate.
The flight number carries the airline's two letter code and up to four digits. Even numbers usually mean eastbound or northbound, odd numbers the return leg.
The seat is obvious. The letter beside it is not: the boarding group, which is set by fare class and status, and is the only field on the card that decides what happens to your bag.
Then there is a short string in the corner that most people never notice, something like S or SSSS. Four S characters means secondary screening was selected before you ever reached the airport.
And the barcode at the bottom is not encrypted. It carries your name, your record locator and your frequent flyer number in plain text, which is why photographing your pass for social media is a genuinely bad idea.
Every one of those fields was added because something went wrong once.
TXT,
    'rubric' => [
        'shape' => ['generic', 'argument'],
        'scenes' => [6, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['custom_card', 'labeled_diagram'], 'why' => 'the beat is about what the OBJECT SAYS — a boarding pass with named fields. custom_card draws it; labeled_diagram is the honest near miss. A generated photo of a boarding pass comes back with garbled text'],
        ],
        'should' => [
            ['cards' => ['custom_card'], 'why' => 'the card doc names a boarding pass as its example — if it is not cast here it will not be cast anywhere'],
            ['cards' => ['common_mistake', 'myth_fact'], 'why' => 'photographing the barcode is the named error'],
        ],
        'never' => ['phone_mockup' => 'a printed pass is not a screen, and a generated screenshot of one is unreadable text'],
        'media_min' => 0.2,
    ],
],

[
    'id' => 'text-scam',
    'title' => 'How a delivery text scam works',
    'target_seconds' => 65,
    'script' => <<<'TXT'
The message says your parcel could not be delivered and asks for one small fee. Here is why that particular sentence works.
It arrives on a Tuesday afternoon, when a lot of people genuinely are expecting something. The scam does not need to be believable to everyone. It needs to be believable to the fraction who have a parcel out.
The sender is a mobile number, not a company name, because a real courier sends from a registered short code. That is the first tell.
The link is the second. It reads like the courier's domain with one extra word in front of it, and the part your phone shows you is the part before the first slash, which is the part the scammer controls.
Then the page asks for one pound ninety-nine. The money is not the point. The card number is the point, and so is the fact that you typed it voluntarily.
The last step happens days later: a phone call from your bank's fraud team, who already have half your details and need the code they just texted you.
The whole thing is one shape. A small, plausible, urgent ask, and then a second contact that leans on the first.
TXT,
    'rubric' => [
        'shape' => ['argument', 'generic'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['custom_card', 'labeled_diagram', 'myth_fact', 'common_mistake'], 'why' => 'the text message itself has to be on screen with its tells pointed at'],
        ],
        'should' => [
            ['cards' => ['custom_card'], 'why' => 'a chat exchange is the card doc\'s own example of what NOT to ask a phone_mockup for'],
            ['cards' => ['step_flow', 'checklist_card', 'timeline_card'], 'why' => 'the two-contact shape is a sequence'],
        ],
        'never' => ['practice_card' => 'the viewer is not solving anything'],
        'media_min' => 0.2,
    ],
],

[
    'id' => 'un-structure',
    'title' => 'How the United Nations is actually organised',
    'target_seconds' => 75,
    'script' => <<<'TXT'
People say the UN as if it were one thing. It is six, and only one of them can make a decision that binds anybody.
At the top sits the Charter, and under it six principal organs.
The General Assembly is all hundred and ninety-three member states, one vote each. It can pass anything and bind nobody — its resolutions are recommendations.
The Security Council is fifteen members, five of them permanent with a veto. This is the only organ whose decisions are legally binding on all members, which is why every argument about the UN is really an argument about this room.
The Secretariat is the staff, about thirty-seven thousand people, headed by the Secretary-General. It administers; it does not legislate.
The International Court of Justice settles disputes between states, and only between states, at The Hague.
Then the Economic and Social Council, which coordinates the agencies you have actually heard of — the World Health Organization, UNICEF, the refugee agency — and the Trusteeship Council, which finished its job in 1994 and has been suspended ever since.
So when someone says the UN failed, the useful question is which of the six they mean.
TXT,
    'rubric' => [
        'shape' => ['generic', 'argument'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['hierarchy_card'], 'why' => 'a root with six branches and named sub-agencies is an org chart — exactly what hierarchy_card draws'],
        ],
        'should' => [
            ['cards' => ['stat_spotlight', 'big_counter', 'pictogram_percent'], 'why' => '193 states, 15 members, 5 vetoes are the figures the argument rests on'],
            ['cards' => ['icon_grid', 'checklist_card'], 'why' => 'the six organs listed'],
        ],
        'never' => ['cycle_diagram' => 'an org chart is not a loop'],
        'media_min' => 0.2,
    ],
],

[
    'id' => 'front-doors',
    'title' => 'Four cities, four front doors',
    'target_seconds' => 70,
    'script' => <<<'TXT'
Every city has a personality, and you can read it off the front doors.
Walk through Amsterdam and the houses lean forward over the canal, narrow and tall, with a hoisting beam at the top because the staircases were too tight to carry furniture up.
In Marrakesh the street front is almost blank. A heavy studded door, no windows, and everything the family cares about faces inward onto a courtyard nobody outside ever sees.
Kyoto does the opposite again: a wooden lattice screen at the front, a paper door behind it, so the boundary between the street and the room is something you can slide open by a hand's width.
And in Reykjavik the houses are wrapped in corrugated iron and painted in colours you can find in a whiteout — red, ochre, teal — because for four months of the year the light is the thing in shortest supply.
Four cities, four front doors, and none of them are decoration. Each one is a solution to a problem the place could not avoid: a canal, a climate, a religion, a winter.
That is the thing about vernacular architecture. Nobody designed it in one go. It is thousands of small corrections, each made by someone who had to live with the last one.
TXT,
    'rubric' => [
        'shape' => ['generic', 'journey', 'argument'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['image_grid', 'photo_stack', 'split_side_by_side', 'before_after'], 'why' => 'four named places compared side by side is the beat image_grid was built for'],
        ],
        'should' => [
            ['cards' => ['image_grid'], 'why' => 'the four-doors beat is the card\'s canonical case — the live check for iter 52 used this exact script'],
            ['cards' => ['map_card'], 'why' => 'four cities on four continents'],
        ],
        'never' => ['animated_chart' => 'there is not a single number in this script'],
        'media_min' => 0.45,
    ],
],

[
    'id' => 'atmosphere-layers',
    'title' => 'The five layers of the atmosphere',
    'target_seconds' => 70,
    'script' => <<<'TXT'
The atmosphere is not a fog that thins out. It is five distinct layers, and what separates them is whether the temperature is rising or falling as you climb.
The troposphere is the bottom twelve kilometres, where all the weather is and where it gets colder the higher you go. Everything you have ever seen happen in the sky happened here.
Above it the stratosphere runs to about fifty kilometres, and here the temperature goes back up, because the ozone layer is absorbing ultraviolet. That warm-on-top arrangement is why it does not mix, and why airliners fly in the bottom of it.
The mesosphere, to about eighty-five kilometres, is where meteors burn up. It is the coldest place in the atmosphere, around minus ninety.
The thermosphere goes to six hundred kilometres and is technically thousands of degrees, but the air is so thin that a thermometer would read cold. This is where the aurora happens and where the space station orbits.
And the exosphere is the fade-out, hydrogen and helium atoms drifting off into space.
Five layers, and the boundary between each one is a place where the temperature changes its mind.
TXT,
    'rubric' => [
        'shape' => ['generic'],
        'scenes' => [6, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['layer_stack'], 'why' => 'five things stacked in real vertical order — the card\'s own documented example'],
        ],
        'should' => [
            ['cards' => ['function_plot', 'animated_chart', 'labeled_diagram'], 'why' => 'the temperature profile reversing at each boundary is a curve'],
            ['cards' => ['scale_comparison', 'stat_spotlight'], 'why' => '12km against 600km is a scale nobody pictures'],
        ],
        'never' => ['cycle_diagram' => 'a stack is not a loop'],
        'media_min' => 0.25,
    ],
],

[
    'id' => 'compound-interest',
    'title' => 'Why compound interest feels slow and then is not',
    'target_seconds' => 70,
    'script' => <<<'TXT'
Compound interest is the most oversold idea in personal finance, and it is also entirely real. The disagreement is about when it shows up.
The formula is A equals P times one plus r over n, raised to n times t. P is what you put in, r is the annual rate, n is how often it compounds, t is years.
Put in ten thousand at seven percent. After one year you have ten thousand seven hundred, which is not interesting. After ten years you have nineteen thousand seven hundred. After thirty you have seventy-six thousand.
The shape is the point. Growth is exponential, so the curve is almost flat for years and then bends upward hard, and almost all of the money arrives in the last third of the time.
That is why starting early beats saving more. Ten years of contributions at twenty-five beats thirty years of the same contributions starting at thirty-five, and it is not close.
And it is why debt at twenty percent is the same curve pointed at you.
TXT,
    'rubric' => [
        'shape' => ['generic', 'argument'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'note' => 'ROUTING PROBE: a concept explainer that happens to name a formula. It must NOT be routed to the maths board — nothing here is being solved line by line — but the technical cards must still be available to it (iter 43 globalisation).',
        'must' => [
            ['cards' => ['formula_anatomy', 'function_plot'], 'why' => 'the driving equation is stated, and the CURVE is the payoff — both cards are global since the globalisation pass, and neither should require math mode'],
        ],
        'should' => [
            ['cards' => ['animated_chart', 'big_counter', 'stat_spotlight', 'scale_comparison'], 'why' => '10,700 / 19,700 / 76,000 is a measured series'],
        ],
        'never' => ['practice_card' => 'the viewer is not asked to solve anything'],
        'media_min' => 0.15,
    ],
],

[
    'id' => 'vaccine-training',
    'title' => 'How a vaccine trains the immune system',
    'target_seconds' => 75,
    'script' => <<<'TXT'
A vaccine does not fight a disease. It rehearses one.
Your immune system already knows how to kill most things that get in. The problem is that learning takes about a week, and a week is exactly what a fast infection does not give you.
So a vaccine shows the system a harmless fragment — a protein from the surface of the virus, or the instructions to make one — with nothing dangerous attached.
A cell picks up the fragment, chews it, and displays a piece of it on its surface. A helper T cell reads that display and starts the alarm.
B cells that happen to match the fragment are told to multiply, and they refine their antibodies over about ten days, testing variants against the target until the fit is much better than where they started.
Then almost all of those cells die off on purpose, and a small population stays behind as memory cells, sometimes for decades.
So the second time the real thing arrives, the week of learning has already happened. The response that took ten days takes two.
TXT,
    'rubric' => [
        'shape' => ['generic', 'argument'],
        'scenes' => [7, 12],
        'math_mode' => false,
        'must' => [
            ['cards' => ['step_flow', 'cycle_diagram', 'labeled_diagram', 'timeline_card'], 'why' => 'the display / alarm / multiply / refine / remember sequence is a process and must be drawn as one'],
        ],
        'should' => [
            ['cards' => ['before_after', 'versus_card', 'split_side_by_side', 'animated_chart', 'function_plot'], 'why' => 'ten days the first time against two the second is the payoff comparison'],
            ['cards' => ['myth_fact', 'term_card'], 'why' => '"a vaccine does not fight a disease" is a correction, and memory cells are jargon'],
        ],
        'never' => ['list_ranking' => 'nothing is ranked'],
        'media_min' => 0.25,
    ],
],

[
    'id' => 'plastic-recycled',
    'title' => 'How much plastic is actually recycled',
    'target_seconds' => 65,
    'script' => <<<'TXT'
Put a bottle in the recycling bin and you have done your part. Roughly nine percent of the time, that is true.
The OECD's 2022 Global Plastics Outlook put the worldwide recycling rate for plastic at about nine percent. Nineteen percent is incinerated, about fifty percent goes to landfill, and the remaining twenty-two percent escapes collection entirely.
The reason is not that people sort badly. It is that plastic is not one material. Seven resin codes, and only two of them — PET bottles and HDPE jugs — have a reliable market for the recovered material.
Everything else is a mixed stream, and a mixed stream costs more to sort than the resin is worth, which is why so much of it was shipped abroad until 2018, when China stopped accepting it.
And plastic does not loop. Each pass shortens the polymer chains, so a bottle becomes fibre, fibre becomes filler, and filler becomes waste. It is a downhill escalator, not a circle.
Recycling is not the lever people think it is. Using less of it is.
TXT,
    'rubric' => [
        'shape' => ['argument', 'generic'],
        'scenes' => [6, 11],
        'math_mode' => false,
        'must' => [
            ['cards' => ['pictogram_percent', 'proportion_flow', 'animated_chart', 'progress_meter'], 'why' => '9 / 19 / 50 / 22 is one whole splitting into four parts, and it is the spine of the script'],
            ['cards' => ['evidence_card'], 'why' => 'the OECD 2022 Global Plastics Outlook is a named source with a year'],
        ],
        'should' => [
            ['cards' => ['myth_fact', 'common_mistake'], 'why' => '"you have done your part" is the belief being corrected'],
            ['cards' => ['step_flow', 'proportion_flow'], 'why' => 'bottle to fibre to filler to waste is a one-way cascade, and calling it a cycle would be the error the script names'],
        ],
        'never' => ['cycle_diagram' => 'the script says explicitly that plastic does NOT loop — a cycle card here contradicts the narration'],
        'media_min' => 0.25,
    ],
],

// ------------------------------------------------------ routing probes ------

[
    'id' => 'math-quadratic',
    'title' => 'Solve 2x^2 - 7x + 3 = 0',
    'target_seconds' => 70,
    'route_only' => true,
    'script' => <<<'TXT'
Solve two x squared minus seven x plus three equals zero.
Start by checking whether it factors. We need two numbers that multiply to give two times three, which is six, and add to give minus seven. Those are minus one and minus six.
Split the middle term: two x squared minus x minus six x plus three equals zero.
Group the pairs. x times two x minus one, minus three times two x minus one, equals zero.
Factor out the common bracket: two x minus one, times x minus three, equals zero.
So either two x minus one is zero, giving x equals one half, or x minus three is zero, giving x equals three.
Check the first one. Two times a quarter is a half, minus seven halves, plus three. A half minus three and a half plus three is zero. It holds.
TXT,
    'rubric' => [
        'shape' => ['worked_problem'],
        'scenes' => [4, 12],
        'math_mode' => true,
        'must' => [
            ['cards' => ['math_steps'], 'why' => 'a calculation walked line by line is math_steps and nothing else'],
        ],
        'should' => [
            ['cards' => ['practice_card', 'common_mistake', 'formula_anatomy'], 'why' => 'a worked problem earns a check-yourself beat'],
        ],
        'never' => ['phone_mockup' => 'maths must never ask the viewer to upload a picture of the working'],
        'media_min' => 0.0,
    ],
],

[
    'id' => 'math-pythagoras',
    'title' => 'Why does a squared plus b squared equal c squared',
    'target_seconds' => 75,
    'route_only' => true,
    'script' => <<<'TXT'
Everyone can state Pythagoras. Far fewer people can say why it is true.
Take a right triangle with legs a and b and hypotenuse c.
Now draw a square on each of the three sides: a square of area a squared on one leg, b squared on the other, and c squared on the hypotenuse.
The claim is that the two smaller squares hold exactly as much area as the big one.
Here is one way to see it. Take four copies of the triangle and arrange them inside a square whose side is a plus b, leaving a tilted square of side c in the middle.
Now rearrange the same four triangles inside the same outer square, and what is left over is two squares, one of side a and one of side b.
Same outer square, same four triangles, so the leftover area has to match. c squared equals a squared plus b squared.
It is not a formula somebody chose. It is what is left when you move the triangles.
TXT,
    'rubric' => [
        'shape' => ['proof_concept'],
        'scenes' => [4, 12],
        'math_mode' => true,
        'must' => [
            ['cards' => ['geometry_diagram'], 'why' => 'a drawn proof — the figure IS the argument, and the renderer draws it natively'],
        ],
        'should' => [
            ['cards' => ['formula_anatomy', 'math_steps'], 'why' => 'the statement itself deserves the anatomy card'],
        ],
        'never' => ['phone_mockup' => 'never ask for an uploaded picture of a triangle'],
        'media_min' => 0.0,
    ],
],

[
    'id' => 'science-half-life',
    'title' => 'What a half life really means',
    'target_seconds' => 70,
    'route_only' => true,
    'script' => <<<'TXT'
A half life is not how long something lasts. It is how long it takes for half of what is there to go.
Carbon fourteen has a half life of five thousand seven hundred and thirty years. Start with a kilogram and after that time you have half a kilogram, after two half lives a quarter, after three an eighth.
The curve never reaches zero, which is the part that surprises people. It approaches it, halving forever.
The equation is N equals N nought times e to the minus lambda t, where lambda is the decay constant and is just another way of writing the same half life.
And the crucial thing is that it does not age. A carbon fourteen atom that has sat in a bone for five thousand years is exactly as likely to decay in the next second as one made this morning. There is no memory in it.
That is why dating works at all. The proportion left is a clock, and the clock does not care what happened to the sample.
TXT,
    'rubric' => [
        'shape' => ['generic', 'argument'],
        'scenes' => [5, 11],
        'math_mode' => false,
        'note' => 'ROUTING PROBE, the other direction: a SCIENCE script with an equation and a curve in it must stay on the ordinary explainer path and still get the technical cards. If this boards, the ratio safety net has regressed (iter 43).',
        'must' => [
            ['cards' => ['function_plot', 'formula_anatomy', 'animated_chart'], 'why' => 'an exponential decay curve and its equation — drawn natively, never as a photograph'],
        ],
        'should' => [
            ['cards' => ['myth_fact', 'term_card'], 'why' => '"a half life is not how long something lasts" is the correction the script opens on'],
        ],
        'never' => ['phone_mockup' => 'no screens in this script'],
        'media_min' => 0.15,
    ],
],

];
