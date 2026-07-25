<?php

/**
 * imagebrief-check — the image-prompt chain that produced project 42's
 * "trash images". Every case below is a REAL brief or prompt from that video.
 *
 *   docker compose exec app php scratchpad/imagebrief-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\SceneStyleService;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $fail++;
        echo "FAIL  {$name}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}

$svc = new SceneStyleService();
$ref = new ReflectionClass($svc);
$sanitize = $ref->getMethod('sanitizeSubject');
$sanitize->setAccessible(true);
$clean = fn (string $s) => $sanitize->invoke($svc, $s);

// 1. Glyph requests the art direction forbids — all real project-42 briefs.
$out = $clean("a large number '500 Million' with search icons surrounding it");
check('quoted number dropped, drawable remainder kept', !str_contains($out, '500') && str_contains($out, 'search icons'), $out);

$out = $clean('a stopwatch with a Google logo, indicating speed and time');
check('logo clause dropped, stopwatch survives', stripos($out, 'logo') === false && stripos($out, 'stopwatch') !== false, $out);

$out = $clean("a computer screen displaying a search bar with the word 'Enter' highlighted");
check('"the word X" clause dropped', stripos($out, 'the word') === false && $out !== '', $out);

// 2. Good briefs must pass through untouched.
foreach ([
    'data packets traveling through a network cable',
    'a data center filled with servers processing information',
    'two piggy banks on a seesaw, one overflowing',
] as $good) {
    check('good brief survives verbatim', $clean($good) === $good, $good);
}

// 3. Style vocabulary is still stripped (the original job of the sanitizer).
$out = $clean('a rocket lifting off from a laptop, cinematic lighting, 8k');
check('style clauses still stripped', stripos($out, 'cinematic') === false && stripos($out, '8k') === false, $out);

// 4. A brief that is ONLY a glyph request yields nothing (caller falls back).
check('pure text request yields empty', $clean('the words "Hello World"') === '', '(empty)');

// 5. Art direction (behavioural — build a real prompt, read what it says).
$project = Modules\Project\Models\Project::find(42) ?? new Modules\Project\Models\Project();
$proc = new ReflectionClass(\Modules\Project\Processors\ExplainerVideoProcessor::class);
$inst = $proc->newInstanceWithoutConstructor();
$pp = $proc->getProperty('project');
$pp->setAccessible(true);
$pp->setValue($inst, $project);
$fvp = $proc->getMethod('flatVectorPrompt');
$fvp->setAccessible(true);
$art = $fvp->invoke($inst, 'a data center filled with servers');

check('art direction no longer asks for negative space', stripos($art, 'negative space') === false);
check('art direction demands a filled frame', stripos($art, 'fills the frame edge to edge') !== false);
check('art direction still bans text', stripos($art, 'No text') !== false);
check('subject is carried into the prompt', stripos($art, 'a data center filled with servers') !== false);

// Illustrations must follow the video's frame; only the SPRITE pass stays 1:1
// (cut-out objects really are square).
$src = file_get_contents($proc->getFileName());
preg_match_all("/'template' => '([a-z_]+)',\s*(?:\/\/[^\n]*\n\s*)*'aspect_ratio' => '1:1'/m", $src, $m);
check('only the sprite pass hardcodes 1:1', ($m[1] ?? []) === ['explainer_scenario_sprite'], implode(',', $m[1] ?? []) ?: 'none');

// 6. Generated filenames must be unique per image (no cross-pass overwrite).
$py = file_get_contents(__DIR__ . '/../app/Services/PythonAIService.php');
check('image filename carries a per-image digest', str_contains($py, "substr(md5(\$url), 0, 8)"));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
