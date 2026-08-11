<?php

/**
 * slot-image-check — drawing a slot's picture from the storyboard.
 *
 * The feature is "let me see the AI's picture before I render, and keep asking
 * for a different one". What makes that safe is a single prompt builder shared
 * by the storyboard and the render: the generated file is cached under
 * `slot-fill:md5(prompt)`, and the render's fill pass decides whether to
 * redraw by comparing that hash. If the two ever disagree, every render
 * re-bills an image the user already accepted — or worse, puts the picture
 * they rejected back. This checks that they agree, and that the user's own art
 * direction survives to the render.
 *
 *   docker compose exec app php scratchpad/slot-image-check.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Support\ExplainerImagePrompt;
use Modules\Project\Support\ExplainerRegistry;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

$theme = ExplainerRegistry::colorScheme(null);
$slot = [
    'content_type' => 'image',
    'label' => 'The counter',
    'asset_request' => ['description' => 'a busy video rental counter in 1998'],
];

echo "\n=== what the picture is OF ===\n";
check('the slot description is the subject',
    ExplainerImagePrompt::subject($slot) === 'a busy video rental counter in 1998');

$withInstruction = $slot;
$withInstruction['asset_request']['instruction'] = 'seen from above, at night, no people';
check('the user\'s direction is APPENDED, not substituted',
    ExplainerImagePrompt::subject($withInstruction)
    === 'a busy video rental counter in 1998. seen from above, at night, no people');

check('a slot with no description falls back to its label',
    ExplainerImagePrompt::subject(['content_type' => 'image', 'label' => 'The counter']) === 'The counter');
check('...and a slot with neither still says something',
    ExplainerImagePrompt::subject(['content_type' => 'image']) === 'the idea this scene narrates');

check('the VLM retry nudge still changes the subject',
    str_ends_with(ExplainerImagePrompt::subject($slot, true), ExplainerImagePrompt::RETRY_NUDGE));

echo "\n=== the house style is not negotiable ===\n";
$prompt = ExplainerImagePrompt::flatVector('a rental counter', $theme);
foreach ([
    'flat 2D vector' => 'flat vector, never a photograph',
    'No text, no words' => 'the hard no-text rule',
    'fills the frame' => 'the subject owns the frame',
    'Strictly three colours' => 'the palette is locked to the theme',
] as $needle => $why) {
    check("the prompt still carries {$why}", str_contains($prompt, $needle));
}
check('the theme is named in WORDS, not hex', !str_contains($prompt, '#'));

echo "\n=== the storyboard and the render agree ===\n";
// This is the whole point. The controller builds the prompt from the slot; the
// processor's fill pass builds it again at render and compares the hash.
$fromStoryboard = ExplainerImagePrompt::forSlot($withInstruction, $theme);
$atRender = ExplainerImagePrompt::forSlot($withInstruction, $theme);
check('the same slot yields the same prompt', $fromStoryboard['prompt'] === $atRender['prompt']);
check('...and therefore the same cache name', $fromStoryboard['name'] === $atRender['name']);
check('the cache name is the convention the pipeline already reads',
    str_starts_with($fromStoryboard['name'], 'slot-fill:'));

$withoutInstruction = ExplainerImagePrompt::forSlot($slot, $theme);
check('adding direction changes the hash, so the render redraws',
    $withoutInstruction['name'] !== $fromStoryboard['name']);

$otherTheme = ExplainerRegistry::colorScheme(ExplainerRegistry::colorSchemeNames()[3] ?? null);
check('changing the colour scheme changes it too — the art is palette-locked',
    ExplainerImagePrompt::forSlot($slot, $otherTheme)['name'] !== $withoutInstruction['name']);

// The processor delegates to the same builder; prove the private wrapper it
// keeps has not drifted from it.
$processor = new ReflectionClass(\Modules\Project\Processors\ExplainerVideoProcessor::class);
$source = file_get_contents($processor->getFileName());
check('the processor no longer keeps its own copy of the art direction',
    substr_count($source, 'Bold flat 2D vector illustration of') === 0);
check('...nor its own copy of the colour namer',
    substr_count($source, "=> 'golden yellow'") === 0);

echo "\n=== \"draw it again\" must actually draw again ===\n";
// The pipeline's seeds are deterministic so a re-render does not reshuffle a
// project's pictures. That is exactly wrong for the redraw button, whose whole
// request is a DIFFERENT picture of the same subject.
$python = new App\Services\PythonAIService();
check('the ordinary seed is stable across calls',
    $python->imageSeed('42', 0, ['vary_seed' => true]) === $python->imageSeed('42', 0, ['vary_seed' => true]));
check('an explicit seed overrides it', $python->imageSeed('42', 0, ['seed' => 12345]) === 12345);
check('...so two redraws of the same prompt differ',
    $python->imageSeed('42', 0, ['seed' => 111]) !== $python->imageSeed('42', 0, ['seed' => 222]));
check('a junk seed falls back to the deterministic one',
    $python->imageSeed('42', 0, ['seed' => 'nonsense', 'vary_seed' => true])
    === $python->imageSeed('42', 0, ['vary_seed' => true]));

echo "\n=== colours become words a model can follow ===\n";
check('a near-black field', ExplainerImagePrompt::colorName('#0A0F1E') === 'very dark blue, almost black',
    ExplainerImagePrompt::colorName('#0A0F1E'));
check('an amber accent', str_contains(ExplainerImagePrompt::colorName('#FFB020'), 'orange'),
    ExplainerImagePrompt::colorName('#FFB020'));
check('junk degrades quietly', ExplainerImagePrompt::colorName('nonsense') === 'neutral dark');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
