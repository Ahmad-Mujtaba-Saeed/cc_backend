<?php

namespace Modules\Project\Support;

/**
 * CustomHtml — the sanitizer behind the `custom_card` escape hatch.
 *
 * Every other card in the registry is a fixed shape the renderer draws. This
 * one lets the planner AUTHOR the visual: a small self-contained fragment of
 * HTML (inline SVG included) plus a little CSS, for the beat that genuinely
 * has no card — a boarding pass, a chat thread, a scoreboard, a nutrition
 * label, a keyboard, a stack of tabs.
 *
 * That is a real capability and a real hazard, so this class is written as if
 * the fragment were hostile, because in the general case it is: it is machine
 * output, it lands inside the render browser, and the storyboard revision pass
 * lets it be rewritten later. Nothing is "probably fine".
 *
 * THE RULES, and why each one exists:
 *
 *  1. **Allowlist, never blocklist.** Tags and attributes not named here are
 *     dropped. A blocklist of "dangerous" tags is a list of the attacks
 *     someone already thought of.
 *  2. **No script, ever.** `<script>`, every `on*` handler, `javascript:` and
 *     `data:` URLs. Note that `<svg>` carries its own script surface —
 *     `<script>`, `<foreignObject>`, `<use href>`, `<animate onbegin>` — so
 *     the SVG allowlist is a list of DRAWING elements only.
 *  3. **No network.** No `src`, no `href` to anywhere, no `url()` in CSS, no
 *     `@import`. A fragment must be able to render with the machine
 *     unplugged: a card that silently fetches is a card that renders
 *     differently on the VPS than it did in the probe.
 *  4. **The flat-design law is enforced, not requested.** copilot.md forbids
 *     blur, shadow, gradient, glow and grain everywhere else in this product;
 *     a card that could opt out through free-form CSS would make the law
 *     advisory. Those properties are stripped from declarations.
 *  5. **The CSS is SCOPED.** Every selector is prefixed with the card's own
 *     class, so a fragment cannot restyle the caption track, the watermark or
 *     the next scene. Selectors that cannot be scoped safely are dropped.
 *  6. **Size caps.** A fragment is a card, not a website.
 *
 * The output is a value object the validator stores on the slot; the renderer
 * mounts it verbatim and never re-sanitizes, because sanitizing at render
 * would mean two implementations that can disagree.
 */
class CustomHtml
{
    public const MAX_HTML = 6000;
    public const MAX_CSS = 2500;
    /** A fragment past this many elements is a page, not a card. */
    public const MAX_NODES = 160;

    /**
     * Structural HTML. No media, no links, no forms, no embedded browsing
     * contexts — those are the tags that fetch, navigate or execute.
     */
    private const HTML_TAGS = [
        'div', 'span', 'p', 'section', 'header', 'footer', 'main', 'article', 'aside',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'mark',
        'br', 'hr', 'code', 'pre', 'kbd', 'samp', 'var', 'abbr', 'time', 'q', 'blockquote', 'cite',
        'figure', 'figcaption', 'label', 'output', 'data', 'ruby', 'rt', 'rp', 'wbr',
    ];

    /**
     * SVG DRAWING elements only. `foreignObject` re-enters HTML parsing,
     * `use` dereferences a URL, `script`/`set`/`animate` execute or mutate —
     * none of them are here, and none of them are needed to draw.
     */
    private const SVG_TAGS = [
        'svg', 'g', 'defs', 'title', 'desc', 'symbol',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath',
        'clippath', 'mask', 'marker', 'pattern',
    ];

    /** Attributes legal on any element. */
    private const GLOBAL_ATTRS = ['class', 'id', 'style', 'title', 'lang', 'dir', 'role'];

    /**
     * The reveal vocabulary — the whole animation contract for a custom card.
     *
     * `data-at` is a 0..1 point in the scene, `data-word` is a word in the
     * narration the element should land on, and `data-anim` is how it arrives.
     * The renderer emits per-frame CSS keyed on these attribute VALUES, so an
     * author never writes an animation and a fragment can never run one.
     */
    private const DATA_ATTRS = ['data-at', 'data-word', 'data-anim'];

    public const ANIMS = ['fade', 'rise', 'pop', 'slide', 'grow', 'none'];

    /** Per-tag attributes, on top of the globals. */
    private const TAG_ATTRS = [
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'time' => ['datetime'],
        'data' => ['value'],
        'abbr' => ['title'],
        'ol' => ['start', 'reversed', 'type'],
        // ---- SVG geometry: numbers and path data, nothing that resolves ----
        'svg' => ['viewbox', 'width', 'height', 'xmlns', 'preserveaspectratio', 'fill', 'stroke'],
        'g' => ['transform', 'fill', 'stroke', 'stroke-width', 'opacity', 'clip-path', 'mask'],
        'defs' => [],
        'symbol' => ['viewbox'],
        'path' => ['d', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'transform', 'fill-rule', 'clip-rule', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'opacity', 'transform'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'opacity', 'transform'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'opacity', 'transform'],
        'line' => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-dasharray', 'opacity', 'transform', 'marker-start', 'marker-mid', 'marker-end'],
        'polyline' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linejoin', 'opacity', 'transform'],
        'polygon' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linejoin', 'opacity', 'transform'],
        'text' => ['x', 'y', 'dx', 'dy', 'fill', 'font-size', 'font-family', 'font-weight', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'opacity', 'transform'],
        'tspan' => ['x', 'y', 'dx', 'dy', 'fill', 'font-size', 'font-weight', 'text-anchor', 'opacity'],
        'clippath' => ['clippathunits'],
        'mask' => ['maskunits', 'maskcontentunits', 'x', 'y', 'width', 'height'],
        'marker' => ['markerwidth', 'markerheight', 'refx', 'refy', 'orient', 'markerunits', 'viewbox'],
        'pattern' => ['patternunits', 'x', 'y', 'width', 'height', 'viewbox'],
    ];

    /**
     * CSS properties a card may set. Layout, type, colour and flat borders.
     *
     * Absent on purpose: `position` (fixed/sticky escape the card),
     * `animation`/`transition` (timing belongs to the scene clock, not to the
     * fragment), `content` (a vector for injected text), `background-image`,
     * `filter`, `box-shadow`, `text-shadow`, `backdrop-filter`, `mix-blend-mode`.
     */
    private const CSS_PROPS = [
        'display', 'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis',
        'justify-content', 'align-items', 'align-self', 'align-content', 'gap', 'row-gap', 'column-gap', 'order',
        'grid-template-columns', 'grid-template-rows', 'grid-column', 'grid-row', 'grid-area', 'grid-auto-flow', 'grid-auto-rows',
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-width', 'border-style', 'border-color', 'border-radius',
        'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius',
        'background', 'background-color', 'color', 'opacity',
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant',
        'line-height', 'letter-spacing', 'word-spacing', 'text-align', 'text-transform', 'text-decoration',
        'white-space', 'word-break', 'overflow-wrap', 'text-overflow', 'vertical-align',
        'list-style', 'list-style-type', 'list-style-position',
        'table-layout', 'border-collapse', 'border-spacing',
        'overflow', 'overflow-x', 'overflow-y', 'box-sizing', 'aspect-ratio',
        'transform', 'transform-origin', 'writing-mode',
        'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-dasharray', 'stroke-dashoffset',
        'text-anchor', 'dominant-baseline',
    ];

    /**
     * Value fragments that are never legal, whatever the property.
     *
     * `url(` and `image-set(` fetch; `expression(` is legacy IE script;
     * `-moz-binding` was a script vector; `attr(` can read attributes into
     * generated content; `position: fixed` escapes the card's box.
     */
    private const BANNED_VALUE = [
        'url(', 'image-set(', 'expression(', 'javascript:', 'data:', '-moz-binding',
        '@import', '@charset', 'behavior:', 'attr(', 'element(', 'var(--_',
    ];

    /**
     * Flat-design law (copilot.md): these are stripped from any declaration
     * rather than failing the card, because a fragment is otherwise fine and
     * the model reaches for a shadow out of habit.
     */
    private const FLAT_BANNED = [
        'box-shadow', 'text-shadow', 'filter', 'backdrop-filter', 'mix-blend-mode',
        'background-image', 'background-blend-mode', 'text-emphasis', 'mask-image',
    ];

    /** Value substrings that carry the same forbidden looks. */
    private const FLAT_BANNED_VALUE = ['gradient(', 'blur(', 'drop-shadow(', 'inset '];

    /**
     * Sanitize a fragment.
     *
     * @return array{ok: bool, html: string, css: string, warnings: string[]}
     */
    public static function sanitize(?string $html, ?string $css = null): array
    {
        $warnings = [];

        $html = trim((string) $html);
        if ($html === '') {
            return ['ok' => false, 'html' => '', 'css' => '', 'warnings' => ['custom card had no html']];
        }
        if (mb_strlen($html) > self::MAX_HTML) {
            $html = mb_substr($html, 0, self::MAX_HTML);
            $warnings[] = 'html truncated to ' . self::MAX_HTML . ' chars';
        }

        $cleanHtml = self::sanitizeHtml($html, $warnings);
        if (trim(strip_tags($cleanHtml)) === '' && !str_contains($cleanHtml, '<svg')) {
            return ['ok' => false, 'html' => '', 'css' => '', 'warnings' => array_merge($warnings, ['nothing survived sanitizing'])];
        }

        $cleanCss = self::sanitizeCss((string) $css, $warnings);

        return ['ok' => true, 'html' => $cleanHtml, 'css' => $cleanCss, 'warnings' => $warnings];
    }

    /** The class every fragment is wrapped in, and every selector scoped to. */
    public static function scopeClass(): string
    {
        return 'cc-scope';
    }

    // ------------------------------------------------------------------ html

    private static function sanitizeHtml(string $html, array &$warnings): string
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // A fragment, not a document: wrap it so DOMDocument does not invent
        // <html>/<body>, and force UTF-8 so a stray Latin-1 byte cannot end the
        // parse early. LIBXML_NONET is belt-and-braces — nothing in the
        // allowlist can reference an external entity anyway.
        $ok = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="cc-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok) {
            $warnings[] = 'html could not be parsed';

            return '';
        }

        $root = $doc->getElementById('cc-root');
        if ($root === null) {
            // LIBXML_HTML_NOIMPLIED can leave the wrapper as the first child.
            $root = $doc->documentElement;
        }
        if ($root === null) {
            return '';
        }

        $nodes = 0;
        self::walk($root, $nodes, $warnings);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Depth-first clean. Disallowed elements are REPLACED BY THEIR TEXT rather
     * than deleted with their subtree: a fragment that wrapped its content in
     * one unknown tag should lose the tag, not the content.
     */
    private static function walk(\DOMNode $node, int &$nodes, array &$warnings): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $child->parentNode?->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue; // text nodes are content
            }

            $tag = strtolower($child->tagName);

            // script/style carry executable or unscoped payloads: the whole
            // subtree goes, text included — unwrapping a <script> would paste
            // its source into the card as visible text.
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'template', 'noscript', 'form', 'input', 'button', 'select', 'textarea'], true)) {
                $warnings[] = "custom card: <{$tag}> removed";
                $child->parentNode?->removeChild($child);
                continue;
            }

            if (!in_array($tag, self::HTML_TAGS, true) && !in_array($tag, self::SVG_TAGS, true)) {
                $warnings[] = "custom card: <{$tag}> unwrapped";
                self::walk($child, $nodes, $warnings);
                self::unwrap($child);
                continue;
            }

            if (++$nodes > self::MAX_NODES) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            self::cleanAttributes($child, $tag, $warnings);
            self::walk($child, $nodes, $warnings);
        }
    }

    /** Replace an element with its children, in place. */
    private static function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    private static function cleanAttributes(\DOMElement $el, string $tag, array &$warnings): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::DATA_ATTRS, self::TAG_ATTRS[$tag] ?? []);

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = (string) $attr->nodeValue;

            // Every on* handler, however it is spelled. This is checked before
            // the allowlist so the warning names the real problem.
            if (str_starts_with($name, 'on')) {
                $warnings[] = "custom card: {$name} handler removed";
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            if (!in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if ($name === 'style') {
                $clean = self::sanitizeDeclarations($value, $warnings);
                if ($clean === '') {
                    $el->removeAttribute($attr->nodeName);
                } else {
                    $el->setAttribute('style', $clean);
                }
                continue;
            }

            if ($name === 'data-at') {
                $at = is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : null;
                if ($at === null) {
                    $el->removeAttribute($attr->nodeName);
                } else {
                    // Two decimals so the renderer emits one CSS rule per
                    // distinct cue instead of one per element.
                    $el->setAttribute('data-at', number_format($at, 2, '.', ''));
                }
                continue;
            }

            if ($name === 'data-anim') {
                if (!in_array(strtolower(trim($value)), self::ANIMS, true)) {
                    $el->removeAttribute($attr->nodeName);
                } else {
                    $el->setAttribute('data-anim', strtolower(trim($value)));
                }
                continue;
            }

            if ($name === 'data-word') {
                $word = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s-]/u', '', $value) ?? ''));
                $word = trim(explode(' ', $word)[0] ?? '');
                if (mb_strlen($word) < 3) {
                    $el->removeAttribute($attr->nodeName);
                } else {
                    $el->setAttribute('data-word', $word);
                }
                continue;
            }

            // A LOCAL reference — url(#gradient-id) — is how SVG points at a
            // clipPath, mask, marker or pattern defined in the same fragment.
            // It resolves inside the document and fetches nothing, and without
            // it those four elements are in the allowlist but unusable. The
            // pattern is deliberately exact: a fragment identifier and nothing
            // else, so `url(#x) , url(http://…)` cannot ride along behind it.
            if (in_array($name, ['fill', 'stroke', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end'], true)
                && preg_match('/^url\(#[A-Za-z][A-Za-z0-9_:.-]*\)$/', trim($value)) === 1
            ) {
                continue;
            }

            // Any remaining allowlisted attribute still may not smuggle a URL
            // or a script scheme through (an `id`/`class` is free text).
            $lower = strtolower($value);
            foreach (['javascript:', 'data:', 'url(', 'expression(', '<'] as $bad) {
                if (str_contains($lower, $bad)) {
                    $warnings[] = "custom card: {$name} value removed";
                    $el->removeAttribute($attr->nodeName);
                    break;
                }
            }
        }
    }

    // ------------------------------------------------------------------- css

    /**
     * Sanitize and SCOPE a stylesheet.
     *
     * The grammar accepted is deliberately tiny: a flat list of
     * `selector { declarations }` rules. No at-rules of any kind — no
     * `@import` (fetches), no `@media` (the card is one fixed frame size), no
     * `@keyframes` (timing belongs to the scene clock). Anything that does not
     * parse as a plain rule is dropped rather than guessed at.
     */
    private static function sanitizeCss(string $css, array &$warnings): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }
        if (mb_strlen($css) > self::MAX_CSS) {
            $css = mb_substr($css, 0, self::MAX_CSS);
            $warnings[] = 'css truncated to ' . self::MAX_CSS . ' chars';
        }

        // Comments can hide a closing brace and change how the rest parses.
        $css = preg_replace('#/\*.*?\*/#s', ' ', $css) ?? '';

        // At-rules go FIRST, whole. Left in place they poison the rule scan:
        // `@import url("…"); .a { color: red }` used to lose the `.a` rule too,
        // because the scanner read `import url("…"); .a` as one selector and
        // dropped the pair. A block at-rule is removed with its body, so a
        // `@media` rule cannot silently hoist its contents out of the query.
        $hadAtRules = str_contains($css, '@');
        $css = self::stripAtRules($css);

        $scope = '.' . self::scopeClass();
        $out = [];

        // One pass, rule by rule. A stray `@media { ... }` block therefore
        // fails to match as a rule and its inner text is skipped along with it.
        if (preg_match_all('/([^{}@]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $rule) {
                $selector = self::scopeSelector(trim($rule[1]), $scope, $warnings);
                if ($selector === null) {
                    continue;
                }
                $declarations = self::sanitizeDeclarations($rule[2], $warnings);
                if ($declarations === '') {
                    continue;
                }
                $out[] = $selector . ' { ' . $declarations . ' }';
            }
        }

        if ($hadAtRules) {
            $warnings[] = 'custom card: at-rules removed from css';
        }

        return implode("\n", $out);
    }

    /**
     * Remove every at-rule: statement forms (`@import …;`) outright, and block
     * forms (`@media … { … }`) together with their body, brace-matched so a
     * nested rule cannot survive the removal of its wrapper.
     */
    private static function stripAtRules(string $css): string
    {
        $out = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            if ($css[$i] !== '@') {
                $out .= $css[$i];
                $i++;
                continue;
            }

            // Scan forward for whichever comes first: the `;` that ends a
            // statement at-rule, or the `{` that opens a block one.
            $j = $i + 1;
            while ($j < $length && $css[$j] !== ';' && $css[$j] !== '{') {
                $j++;
            }
            if ($j >= $length) {
                break; // unterminated — drop the remainder
            }
            if ($css[$j] === ';') {
                $i = $j + 1;
                continue;
            }

            $depth = 0;
            while ($j < $length) {
                if ($css[$j] === '{') {
                    $depth++;
                } elseif ($css[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $j++;
                        break;
                    }
                }
                $j++;
            }
            $i = $j;
        }

        return $out;
    }

    /**
     * Prefix a selector list with the card's scope class.
     *
     * A selector that names anything outside the card is not "fixed" by
     * prefixing — `body .caption` prefixed is still nonsense — so the
     * characters allowed are limited to what can only ever match inside the
     * fragment. Null means "drop this rule".
     */
    private static function scopeSelector(string $selector, string $scope, array &$warnings): ?string
    {
        if ($selector === '' || mb_strlen($selector) > 240) {
            return null;
        }

        $parts = [];
        foreach (explode(',', $selector) as $one) {
            $one = trim(preg_replace('/\s+/', ' ', $one) ?? '');
            if ($one === '') {
                continue;
            }
            // Classes, ids, tag names, descendant/child/sibling combinators and
            // a few structural pseudo-classes. No attribute selectors (they can
            // read the data-* cues the renderer owns), no `*`, no `:not(`
            // gymnastics, nothing with a bracket or a quote in it.
            if (!preg_match('/^[a-zA-Z0-9 .#>_+~:()\-]+$/', $one)) {
                $warnings[] = 'custom card: unsupported selector dropped';
                continue;
            }
            if (preg_match('/\b(html|body|:root|:host)\b/i', $one) || str_contains($one, '*')) {
                $warnings[] = 'custom card: selector reaching outside the card dropped';
                continue;
            }
            // Idempotent. A slot is sanitized on the way through
            // normalizeCardScene AND again through validateSlot, and a second
            // prefix would produce `.cc-scope .cc-scope .pass` — a selector
            // that matches nothing, so every rule in the card would quietly
            // stop applying.
            $parts[] = str_starts_with($one, $scope . ' ') || $one === $scope
                ? $one
                : $scope . ' ' . $one;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Clean one declaration block (also used for inline `style` attributes).
     */
    private static function sanitizeDeclarations(string $block, array &$warnings): string
    {
        $out = [];
        foreach (explode(';', $block) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || !str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $lowerValue = strtolower($value);

            if ($property === '' || $value === '') {
                continue;
            }
            // A card may DEFINE its own custom properties (--x: 4px) but the
            // theme's own tokens are read-only from in here.
            if (str_starts_with($property, '--')) {
                continue;
            }
            if (in_array($property, self::FLAT_BANNED, true)) {
                $warnings[] = "custom card: {$property} stripped (flat design)";
                continue;
            }
            if (!in_array($property, self::CSS_PROPS, true)) {
                continue;
            }
            foreach (self::BANNED_VALUE as $bad) {
                if (str_contains($lowerValue, $bad)) {
                    $warnings[] = "custom card: {$property} value rejected";
                    continue 2;
                }
            }
            foreach (self::FLAT_BANNED_VALUE as $bad) {
                if (str_contains($lowerValue, $bad)) {
                    $warnings[] = "custom card: {$property} value stripped (flat design)";
                    continue 2;
                }
            }
            // `!important` would let a fragment out-rank the reveal rules the
            // renderer writes for data-at / data-word, which is the one place
            // the card is not allowed to have the last word. (`position` needs
            // no check here — it is absent from CSS_PROPS precisely because
            // fixed and sticky escape the card's box.)
            if (str_contains($lowerValue, '!important')) {
                continue;
            }
            if (mb_strlen($value) > 160) {
                continue;
            }

            $out[] = $property . ': ' . $value;
        }

        return implode('; ', $out);
    }
}
