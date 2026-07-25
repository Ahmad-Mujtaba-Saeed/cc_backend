<?php
/** proof-seed — extra proof_concept projects: the proof path had ONE sample. */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Jobs\AnalyzeExplainerScriptJob;
use Modules\Project\Models\Project;

$scenarios = [
    ['title' => 'Why is the angle in a semicircle always 90 degrees?',
     'why' => 'proof: circle + inscribed triangle, evolving figure with a circumcircle',
     'script' => <<<'TXT'
Draw any circle and mark the two ends of a diameter. Pick any third point on the circle.
Join that point to both ends of the diameter and you always get a right angle. Always.
Here is why. Draw the radius from the centre to your third point.
That radius splits the big triangle into two smaller triangles.
Each smaller triangle has two sides that are radii, so each one is isosceles.
In an isosceles triangle the two base angles are equal, so call them a and a, and b and b.
The three angles of the big triangle add to one hundred and eighty degrees, so a + a + b + b = 180.
That means two lots of a plus b equal one hundred and eighty, so a + b = 90.
And a + b is exactly the angle at your third point. It is ninety degrees, wherever you put it.
TXT],
    ['title' => 'Why does the derivative of x^2 equal 2x?',
     'why' => 'proof: function_plot with a tangent, algebraic limit argument',
     'script' => <<<'TXT'
The derivative of x squared is two x. Where does that come from?
The derivative is the slope of the curve at a point, and slope means rise over run.
Take the point x and a second point a tiny distance h away.
The heights are x squared and (x + h) squared.
The rise is (x + h)^2 - x^2, and the run is h.
Expand the top: x^2 + 2xh + h^2 - x^2, which leaves 2xh + h^2.
Divide by h and you get 2x + h.
Now shrink h toward zero. The h term vanishes and the slope becomes exactly 2x.
That is the derivative: not a rule to memorise, but the slope you just measured.
TXT],
];

foreach ($scenarios as $s) {
    $p = Project::create([
        'user_id' => 1, 'title' => $s['title'], 'template_type' => 'ai_explainer_video',
        'aspect_ratio' => '16:9', 'status' => 'analyzing', 'progress' => 0,
        'settings' => ['script' => $s['script'], 'target_seconds' => 120, 'test_scenario' => $s['why']],
    ]);
    AnalyzeExplainerScriptJob::dispatch($p);
    echo "queued #{$p->id}  {$s['title']}\n";
}
