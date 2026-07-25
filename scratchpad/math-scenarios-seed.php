<?php

/**
 * math-scenarios-seed — creates a spread of worked-math explainer projects so
 * the whole math pipeline can be exercised on real model output rather than
 * fixtures. Each scenario deliberately hits a different set of code paths
 * (radicals, scenario_diagram shapes, proofs/evolving figures, rule injection,
 * units, plots), which is how a regression in one card shows up as a diff
 * against the others instead of a one-off.
 *
 *   docker exec viralforgebackend-app-1 php scratchpad/math-scenarios-seed.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Jobs\AnalyzeExplainerScriptJob;
use Modules\Project\Models\Project;

$scenarios = [
    [
        'title' => 'Solve x^2 - 6x + 4 = 0 with the quadratic formula',
        'why' => 'radicals everywhere: +- and sqrt in the rule, the steps and the answer',
        'script' => <<<'TXT'
We are solving the quadratic equation x^2 - 6x + 4 = 0.
It does not factor nicely, so we reach for the quadratic formula.
The quadratic formula says x equals negative b, plus or minus the square root of b squared minus four a c, all over two a.
Here a = 1, b = -6 and c = 4.
Substituting gives x = (6 +- sqrt(36 - 16)) / 2.
Thirty six minus sixteen is twenty, so x = (6 +- sqrt(20)) / 2.
The square root of twenty simplifies to two root five, so x = 3 +- sqrt(5).
That gives two solutions: x is about 5.236 and x is about 0.764.
Substitute 5.236 back into the original equation and you land on zero, so the answer holds.
TXT,
    ],
    [
        'title' => 'Why does a^2 + b^2 = c^2 work for right triangles?',
        'why' => 'proof_concept: evolving geometry figure, chalk board skin, no numeric answer',
        'script' => <<<'TXT'
Every right triangle hides a promise: the square on the longest side equals the two smaller squares added together.
Start with a right triangle with legs a and b and hypotenuse c.
Erect a square on each of the three sides.
Now take four copies of the triangle and arrange them inside a big square of side a plus b.
The leftover space in the middle is exactly the square on c.
Rearrange the same four triangles and the leftover space becomes the square on a plus the square on b.
Same big square, same four triangles, so the leftover areas must be equal.
That is why a squared plus b squared equals c squared, and it is why the distance formula works at all.
TXT,
    ],
    [
        'title' => 'A ball is thrown at 20 m/s from a 25 m roof - when does it land?',
        'why' => 'word problem: scenario_diagram arc, function_plot, units lint, check-the-answer',
        'script' => <<<'TXT'
A ball is thrown straight up at twenty metres per second from the top of a twenty five metre building.
Its height in metres after t seconds is h(t) = -4.9t^2 + 20t + 25.
We want the time when the ball hits the ground, which is when the height is zero.
Set -4.9t^2 + 20t + 25 = 0.
Using the quadratic formula with a = -4.9, b = 20 and c = 25, we get t = (-20 +- sqrt(400 + 490)) / (-9.8).
The square root of eight hundred and ninety is about 29.83.
Only the positive time makes physical sense, so t is about 5.08 seconds.
Substitute 5.08 back into the height formula and you get almost exactly zero, so the ball lands after about five seconds.
TXT,
    ],
    [
        'title' => 'Find the missing side of a right triangle with legs 9 and 12',
        'why' => 'geometry_diagram with side labels + a clean radical that simplifies to an integer',
        'script' => <<<'TXT'
A right triangle has legs of nine centimetres and twelve centimetres. How long is the hypotenuse?
Pythagoras tells us a squared plus b squared equals c squared.
Substitute the legs: 9^2 + 12^2 = c^2.
Nine squared is eighty one and twelve squared is one hundred and forty four.
So c^2 = 225.
Take the square root of both sides: c = sqrt(225) = 15.
The hypotenuse is fifteen centimetres.
Check it: fifteen squared is two hundred and twenty five, which is exactly eighty one plus one hundred and forty four.
TXT,
    ],
    [
        'title' => 'Solve log(x) + log(x - 3) = 1',
        'why' => 'MathRuleLibrary log product rule, a rejected extraneous root, no figure at all',
        'script' => <<<'TXT'
Solve the equation log x plus log of x minus three equals one.
The product rule for logarithms says log a plus log b equals log of a times b.
So the left side becomes log of x times x minus three.
That gives log(x^2 - 3x) = 1.
A base ten log equal to one means the inside equals ten.
So x^2 - 3x = 10, which rearranges to x^2 - 3x - 10 = 0.
That factors as (x - 5)(x + 2) = 0, so x = 5 or x = -2.
But logarithms reject negative inputs, so x = -2 is extraneous.
The only solution is x = 5.
TXT,
    ],
    [
        'title' => 'A jacket costs $80 after a 20% discount - what was the original price?',
        'why' => 'percentage word problem: units carried end to end, no radical, reverse reasoning',
        'script' => <<<'TXT'
A jacket is on sale for eighty dollars after a twenty percent discount. What was the original price?
The trap is subtracting twenty percent of eighty. That is the wrong direction.
Eighty dollars is what is left after the discount, so eighty dollars is eighty percent of the original price.
Write it as an equation: 0.8 * p = 80.
Divide both sides by nought point eight: p = 80 / 0.8.
So p = 100.
The original price was one hundred dollars.
Check it: twenty percent of one hundred dollars is twenty dollars, and one hundred minus twenty is eighty. It holds.
TXT,
    ],
    [
        'title' => 'How to compute standard deviation of 2, 4, 4, 4, 5, 5, 7, 9',
        'why' => 'a radical wrapping a whole fraction, plus a data set that invites a chart',
        'script' => <<<'TXT'
Let us compute the standard deviation of the numbers two, four, four, four, five, five, seven and nine.
First find the mean. The eight numbers add to forty, so the mean is five.
Now subtract the mean from each value and square the result.
The squared differences are nine, one, one, one, zero, zero, four and sixteen.
Those add up to thirty two.
Divide by the count: thirty two over eight is four. That is the variance.
The standard deviation is the square root of the variance, so sigma = sqrt(4) = 2.
So the values sit on average two units away from the mean of five.
TXT,
    ],
];

$created = [];
foreach ($scenarios as $s) {
    $project = Project::create([
        'user_id' => 1,
        'title' => $s['title'],
        'template_type' => 'ai_explainer_video',
        'aspect_ratio' => '16:9',
        'status' => 'analyzing',
        'progress' => 0,
        'settings' => [
            'script' => $s['script'],
            'target_seconds' => 120,
            'test_scenario' => $s['why'],
        ],
    ]);
    AnalyzeExplainerScriptJob::dispatch($project);
    $created[] = $project->id;
    echo "queued #{$project->id}  {$s['title']}\n";
}

echo "\nids: " . implode(',', $created) . "\n";
