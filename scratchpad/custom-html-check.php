<?php

/**
 * custom-html-check — the escape-hatch card and, mostly, its sanitizer.
 *
 *   docker compose exec app php scratchpad/custom-html-check.php
 *
 * `custom_card` lets the planner author markup that is mounted inside the
 * render browser. That is the most powerful card in the registry and the only
 * one with a security surface, so the bulk of this file is adversarial: every
 * case is a thing a fragment must NOT be able to do.
 *
 * The rule the tests encode: sanitizing happens ONCE, in the validator, and
 * what is stored on the slot is already safe. The renderer never re-checks.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Services\GenericStoryboardComposerService;
use Modules\Project\Support\CustomHtml;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\SceneBudgetLinter;
use Modules\Project\Support\ShotListValidator;

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

/** Sanitize and return the html only. */
function clean(string $html, string $css = ''): string
{
    return CustomHtml::sanitize($html, $css)['html'];
}

/** Sanitize and return the css only. */
function cleanCss(string $css, string $html = '<div>x</div>'): string
{
    return CustomHtml::sanitize($html, $css)['css'];
}

echo "\n== 1. nothing executes ==\n";

$out = clean('<div>before<script>fetch("https://evil")</script>after</div>');
check('script tags are removed with their source', !str_contains($out, 'script') && !str_contains($out, 'fetch'), $out);

$out = clean('<div onclick="steal()" onmouseover="x()" ONERROR="y()">text</div>');
check('every on* handler goes, whatever the case', !preg_match('/on\w+=/i', $out), $out);

$out = clean('<svg viewBox="0 0 10 10"><foreignObject><script>x()</script></foreignObject><circle r="4"/></svg>');
check('svg foreignObject cannot re-enter html', !str_contains($out, 'foreign') && !str_contains($out, 'script'), $out);
check('...and the drawing survives', str_contains($out, '<circle'));

$out = clean('<svg><use href="https://evil/x.svg#a"/><animate onbegin="x()"/></svg>');
check('svg use/animate are not in the allowlist', !str_contains($out, '<use') && !str_contains($out, 'onbegin'), $out);

echo "\n== 2. nothing reaches the network ==\n";

foreach ([
    '<img src="https://evil/pixel.png">' => 'img',
    '<iframe src="https://evil"></iframe>' => 'iframe',
    '<object data="https://evil"></object>' => 'object',
    '<embed src="https://evil">' => 'embed',
    '<link rel="stylesheet" href="https://evil/x.css">' => 'link',
    '<video src="https://evil/x.mp4"></video>' => 'video',
] as $markup => $label) {
    $out = clean('<div>keep' . $markup . '</div>');
    check("{$label} cannot fetch", !str_contains($out, 'evil') && str_contains($out, 'keep'), $out);
}

$out = clean('<a href="javascript:alert(1)">click</a><div>keep</div>');
check('a javascript: link is unwrapped to text', !str_contains($out, 'javascript') && str_contains($out, 'click'), $out);

$css = cleanCss('.a { background: url(https://evil/x.png); color: red; }');
check('css url() is rejected, the rest of the rule survives', !str_contains($css, 'url(') && str_contains($css, 'color: red'), $css);

$css = cleanCss('@import url("https://evil/x.css"); .a { color: red; }');
check('@import goes and does NOT take the next rule with it', !str_contains($css, 'evil') && str_contains($css, 'color: red'), $css);

$css = cleanCss('@media (min-width: 1px) { .hidden { display: none } } .a { color: red; }');
check('a block at-rule is removed WITH its body', !str_contains($css, 'display: none') && str_contains($css, 'color: red'), $css);

$css = cleanCss('@keyframes spin { from { opacity: 0 } to { opacity: 1 } } .a { color: red; }');
check('@keyframes cannot smuggle in wall-clock animation', !str_contains($css, 'opacity') && str_contains($css, 'color: red'), $css);

echo "\n== 3. the card cannot escape its own box ==\n";

$css = cleanCss('body { display: none } .a { color: red }');
check('a selector naming body is dropped', !str_contains($css, 'body'), $css);

$css = cleanCss('* { color: red } .a { color: blue }');
check('the universal selector is dropped', !str_contains($css, '* '), $css);

$css = cleanCss('.a { color: red }');
check('every surviving selector is scoped to the card', str_starts_with($css, '.' . CustomHtml::scopeClass() . ' '), $css);

// The slot is sanitized on the way through normalizeCardScene AND again in
// validateSlot. Scoping twice would produce `.cc-scope .cc-scope .a`, which
// matches nothing — every rule in every custom card would silently die.
$once = cleanCss('.a { color: red }');
$twice = CustomHtml::sanitize('<div>x</div>', $once)['css'];
check('sanitizing is idempotent', $once === $twice, $twice);

$out = clean('<div style="position: fixed; top: 0; color: red">x</div>');
check('position cannot pin the card to the viewport', !str_contains($out, 'fixed'), $out);

$css = cleanCss('.a { color: red !important }');
check('!important cannot out-rank the reveal rules', !str_contains($css, 'important'), $css);

echo "\n== 4. the flat-design law is enforced, not requested ==\n";

$out = clean('<div style="box-shadow: 0 2px 8px #000; color: red">x</div>');
check('an inline box-shadow is stripped, the colour kept', !str_contains($out, 'shadow') && str_contains($out, 'color: red'), $out);

$css = cleanCss('.a { filter: blur(4px); background: linear-gradient(red, blue); padding: 4px }');
check('filter and gradient are stripped', !str_contains($css, 'blur') && !str_contains($css, 'gradient'), $css);
check('...and the honest declarations survive', str_contains($css, 'padding: 4px'), $css);

$css = cleanCss('.a { text-shadow: 0 0 8px cyan; backdrop-filter: blur(2px); color: red }');
check('glow and backdrop-filter are stripped', !str_contains($css, 'shadow') && !str_contains($css, 'backdrop'), $css);

echo "\n== 5. what a real fragment KEEPS ==\n";

$html = '<div class="pass"><h3>Gate</h3><p data-at="0.35" data-anim="pop">14</p>'
    . '<table><tr><th>Flight</th><td>BA117</td></tr></table>'
    . '<svg viewBox="0 0 100 20"><line x1="0" y1="10" x2="90" y2="10" stroke="#5EC9E8" stroke-width="2"/>'
    . '<polygon points="90,4 100,10 90,16" fill="#5EC9E8"/></svg></div>';
$out = clean($html);
foreach (['class="pass"', '<h3>', '<table>', '<th>', '<svg', '<line', '<polygon', 'viewbox', 'stroke-width'] as $keep) {
    check("keeps {$keep}", stripos($out, $keep) !== false);
}
check('keeps the reveal cues', str_contains($out, 'data-at="0.35"') && str_contains($out, 'data-anim="pop"'), '');

// SVG needs local references or clipPath/mask/marker are unusable — a
// fragment identifier resolves in-document and fetches nothing.
$out = clean('<svg viewBox="0 0 10 10"><defs><clipPath id="c"><rect width="5" height="5"/></clipPath></defs><path d="M0 0h10v10H0z" clip-path="url(#c)"/></svg>');
check('a LOCAL svg reference survives', str_contains($out, 'url(#c)'), $out);

$out = clean('<svg viewBox="0 0 10 10"><path d="M0 0" fill="url(https://evil/x#a)"/></svg>');
check('...but a remote one does not', !str_contains($out, 'evil'), $out);

$css = cleanCss('.a { color: var(--accent); font-family: var(--font-mono) }');
check('theme variables are how a card takes the palette', str_contains($css, 'var(--accent)') && str_contains($css, 'var(--font-mono)'), $css);

echo "\n== 6. cue normalisation ==\n";

$out = clean('<div data-at="2.5">a</div><div data-at="-1">b</div><div data-at="nope">c</div>');
check('data-at is clamped to 0..1', str_contains($out, 'data-at="1.00"') && str_contains($out, 'data-at="0.00"'), $out);
check('a non-numeric data-at is dropped', !str_contains($out, 'nope'), $out);

$out = clean('<div data-anim="explode">a</div><div data-anim="pop">b</div>');
check('an unknown data-anim is dropped', !str_contains($out, 'explode') && str_contains($out, 'data-anim="pop"'), $out);

$out = clean('<div data-word="Gate!">a</div><div data-word="a">b</div>');
check('data-word is normalised to one plain word', str_contains($out, 'data-word="gate"'), $out);
check('a too-short data-word is dropped', !str_contains($out, 'data-word="a"'), $out);

echo "\n== 7. size ==\n";

$huge = '<div>' . str_repeat('<p>filler paragraph</p>', 400) . '</div>';
$result = CustomHtml::sanitize($huge, '');
check('an oversized fragment is truncated, not rejected', $result['ok'] && mb_strlen($result['html']) < mb_strlen($huge), mb_strlen($result['html']) . ' chars');
check('...and says so', (bool) array_filter($result['warnings'], fn ($w) => str_contains($w, 'truncated')));

$result = CustomHtml::sanitize('', '');
check('an empty fragment is refused', !$result['ok']);

$result = CustomHtml::sanitize('<script>only()</script>', '');
check('a fragment that is nothing but script is refused', !$result['ok']);

echo "\n== 8. the card in a storyboard ==\n";

check('custom_card is a template', ExplainerRegistry::hasTemplate('custom_card'));
check('custom_html is a content type', isset(ExplainerRegistry::contentTypes()['custom_html']));
check('html is its required field', ExplainerRegistry::requiredFields('custom_html') === ['html'], implode(',', ExplainerRegistry::requiredFields('custom_html')));
check('capped at two per video', ExplainerRegistry::maxPerVideo('custom_card') === 2, (string) ExplainerRegistry::maxPerVideo('custom_card'));

/**
 * One custom_card scene through the validator.
 *
 * With an OPENING scene in front of it, because the validator promotes scene 1
 * in place to a cold-open hook when a video has none — correct behaviour, and
 * nothing to do with this card, but it means a bespoke card can never be the
 * first scene and a one-scene fixture measures the hook rule instead.
 */
function card(array $slot, string $narration = 'A boarding pass tells you four things.'): array
{
    $out = (new ShotListValidator())->validate([
        'aspect_ratio' => '16:9',
        'scenes' => [[
            'scene_id' => 'opening',
            'duration_seconds' => 5,
            'narration' => ['text' => 'Everyone has held one of these and never read it.'],
            'layout_template' => 'single_focus',
            'slots' => ['slot_main' => [
                'content_type' => 'text_block',
                'heading' => 'The pass',
                'bullets' => ['You have held one'],
            ]],
        ], [
            'scene_id' => 'cc1',
            'duration_seconds' => 8,
            'narration' => ['text' => $narration],
            'layout_template' => 'custom_card',
            'slots' => ['slot_custom' => $slot],
        ]],
    ]);
    foreach ($out['scenes'] as $scene) {
        if (($scene['scene_id'] ?? '') === 'cc1') {
            return $scene;
        }
    }

    return [];
}

$scene = card([
    'content_type' => 'custom_html',
    'heading' => 'What a boarding pass says',
    'caption' => 'Every field is a decision',
    'html' => '<div class="p"><h3 data-at="0.2">Gate 14</h3><p data-word="seat">23A</p></div>',
    'css' => '.p { display: flex; gap: 20px; color: var(--text) }',
]);
check('a good fragment keeps the template', ($scene['layout_template'] ?? '') === 'custom_card', $scene['layout_template'] ?? '?');
$slot = $scene['slots']['slot_custom'] ?? [];
check('the stored html is the SANITISED html', ($slot['html'] ?? '') !== '' && !str_contains((string) ($slot['html'] ?? ''), '<script'));
check('the stored css is scoped', str_starts_with((string) ($slot['css'] ?? ''), '.' . CustomHtml::scopeClass()), mb_substr((string) ($slot['css'] ?? ''), 0, 40));
check('heading and caption survive', ($slot['heading'] ?? '') !== '' && ($slot['caption'] ?? '') !== '');

// A fragment that sanitizes down to nothing must not leave a blank frame.
$scene = card([
    'content_type' => 'custom_html',
    'html' => '<script>everything()</script>',
], 'The rest of the video still has to work.');
check('an unusable fragment degrades to text', ($scene['layout_template'] ?? '') === 'single_focus' && ($scene['slots']['slot_main']['content_type'] ?? '') === 'text_block', $scene['layout_template'] ?? '?');
check('...keeping the narration', ($scene['slots']['slot_main']['heading'] ?? '') !== '' || ($scene['slots']['slot_main']['bullets'] ?? []) !== []);

// Three custom cards: the third is over the cap.
$capScenes = [[
    'scene_id' => 'opening',
    'duration_seconds' => 5,
    'narration' => ['text' => 'Three things in this video were drawn by hand.'],
    'layout_template' => 'single_focus',
    'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => 'Three', 'bullets' => ['Drawn by hand']]],
]];
$scenes = (new ShotListValidator())->validate([
    'aspect_ratio' => '16:9',
    'scenes' => array_merge($capScenes, array_map(fn ($n) => [
        'scene_id' => "cc{$n}",
        'duration_seconds' => 8,
        'narration' => ['text' => "Bespoke card number {$n}, which the beat genuinely needed drawing."],
        'layout_template' => 'custom_card',
        'slots' => ['slot_custom' => [
            'content_type' => 'custom_html',
            'html' => "<div><h3>Card {$n}</h3><p>Some content that is definitely visible.</p></div>",
        ]],
    ], [1, 2, 3])),
])['scenes'];
$templates = array_count_values(array_column($scenes, 'layout_template'));
check('the cap holds at two', ($templates['custom_card'] ?? 0) === 2, json_encode($templates));

// The linter must not read a bespoke card as a static hold — its cues land
// across the scene.
$report = (new SceneBudgetLinter())->lint([[
    'scene_id' => 'cc1',
    'duration_seconds' => 12,
    'narration' => ['text' => 'A boarding pass tells you four things, one after another, as we go through them.'],
    'layout_template' => 'custom_card',
    'slots' => ['slot_custom' => ['content_type' => 'custom_html', 'html' => '<div><p data-at="0.3">Gate</p></div>']],
]], []);
$codes = array_column($report['items'] ?? [], 'code');
check('a 12s custom card is not a static hold', !in_array('static_hold', $codes, true), implode(',', $codes) ?: '(none)');

echo "\n== 9. casting ==\n";

$docs = GenericStoryboardComposerService::cardDocs();
check('the composer can describe the card', isset($docs['custom_card']));
$doc = $docs['custom_card'] ?? '';
check('the doc names it a last resort', str_contains($doc, 'LAST RESORT'));
check('the doc forbids css animation', str_contains($doc, 'never write css animations'));
check('the doc teaches the cue vocabulary', str_contains($doc, 'data-at') && str_contains($doc, 'data-word') && str_contains($doc, 'data-anim'));
check('the doc teaches the theme variables', str_contains($doc, 'var(--accent)') && str_contains($doc, 'var(--font-display)'));
check('the doc lists what NOT to use it for', str_contains($doc, 'animated_chart') && str_contains($doc, 'step_flow'));

$offered = 0;
foreach (['context', 'point', 'aspect', 'problem', 'demo_step', 'result', 'payoff', 'turning_point'] as $intent) {
    if (in_array('custom_card', GenericStoryboardComposerService::menuFor($intent), true)) {
        $offered++;
    }
}
check('it is reachable from the substantive intents', $offered === 8, "{$offered}/8");
// ...but not from every intent: a hook or a ranking reveal always has a real
// card, and a last resort offered everywhere stops being a last resort.
check('it is NOT offered for a ranking reveal', !in_array('custom_card', GenericStoryboardComposerService::menuFor('ranking_reveal'), true));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
