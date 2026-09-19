<?php

namespace Modules\Project\Support;

/**
 * CinematicScene — the spec, and the deterministic repair, for a
 * `cinematic_card`: the video's key explanation staged in depth.
 *
 * ## What it is
 *
 * The user asked for the beats that carry the explanation to be cinematic —
 * Flute (@webprodigies/flute) in the renderer: every PART of the card on its
 * own 3D layer, arriving out of depth and out of focus as the narrator names
 * it, a camera that pushes in on what is being said while everything else
 * falls soft, and a pull back to the whole picture, sharp. It is the one card
 * that may blur; the flat-design law holds everywhere else.
 *
 * ## What the model decides, and what it does not
 *
 * The lesson of `custom_card` and `vector_motif` is that a model given a
 * canvas it cannot see needs a COORDINATE SYSTEM WITH GUARANTEES. So the
 * design pass says only what a director would say:
 *
 * - WHAT each part is: `text`, `stat`, `formula`, `icon` or a small `html`
 *   drawing (sanitised by {@see CustomHtml} exactly like custom_card),
 * - WHERE it sits: one of nine grid places,
 * - HOW DEEP: near / mid / far,
 * - WHEN: the narration `word` that brings it in,
 * - and the SHOT: `push` in on it, `angle` on it, or `rack` focus to it.
 *
 * Pixel positions, camera coordinates and focus distances are computed by the
 * renderer (`remotion-render/src/flute/cinematicRig.ts`) from those choices,
 * so they cannot be wrong. This class makes the choices themselves safe:
 * every enum falls back, every string is capped, a cue word that is never
 * spoken is dropped (it would otherwise hold its part back for nothing), an
 * icon that is not in the library becomes a text part, and a card with fewer
 * than two surviving parts is rejected so the validator degrades the beat to
 * text instead of shipping a near-empty stage.
 */
class CinematicScene
{
    public const MIN_ELEMENTS = 2;
    public const MAX_ELEMENTS = 6;

    public const KINDS = ['text', 'stat', 'formula', 'icon', 'html'];
    public const PLACES = [
        'top_left', 'top', 'top_right',
        'left', 'center', 'right',
        'bottom_left', 'bottom', 'bottom_right',
    ];
    public const DEPTHS = ['near', 'mid', 'far'];
    public const CAMERAS = ['push', 'angle', 'rack'];

    /** Per-part and whole-card budgets for authored html. */
    private const MAX_PART_HTML = 2500;
    private const MAX_TOTAL_HTML = 6000;

    /**
     * Repair a design into something the renderer can always draw.
     *
     * @param  array         $raw         {heading?, brief?, elements:[...], css?}
     * @param  callable|null $iconExists  fn(string $name): bool
     * @param  string        $narration   the beat's narration, for cue checks
     * @return array{ok: bool, slot: array<string, mixed>, warnings: string[]}
     */
    public static function sanitize(array $raw, ?callable $iconExists = null, string $narration = ''): array
    {
        $warnings = [];
        $spoken = self::spokenWords($narration);

        $input = is_array($raw['elements'] ?? null) ? array_values($raw['elements']) : [];
        $elements = [];
        $ids = [];
        $htmlBudget = self::MAX_TOTAL_HTML;

        foreach ($input as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (count($elements) >= self::MAX_ELEMENTS) {
                $warnings[] = 'cinematic: dropped parts past ' . self::MAX_ELEMENTS;
                break;
            }

            $el = self::element($item, $i, $iconExists, $warnings, $htmlBudget);
            if ($el === null) {
                continue;
            }

            // Unique, renderer-safe ids.
            $id = $el['id'];
            $n = 2;
            while (isset($ids[$id])) {
                $id = $el['id'] . '-' . $n++;
            }
            $ids[$id] = true;
            $el['id'] = $id;

            // A cue word the narrator never says would hold its part back to
            // the even-spread fallback anyway; keep the slot honest.
            if (isset($el['word']) && $spoken !== [] && !self::isSpoken($el['word'], $spoken)) {
                $warnings[] = "cinematic: cue word '{$el['word']}' is never spoken, dropped";
                unset($el['word']);
            }

            // A formula the narration never uses is decoration pretending to
            // be maths (the live run staged "y = A sin(wt + p)" over a script
            // with no equation in it). Keep it only when the beat talks about
            // an equation or shares a number with it; otherwise its sub line
            // — what it was meant to say — becomes a text part, or it goes.
            if ($el['kind'] === 'formula' && $spoken !== [] && !self::formulaGrounded($el['formula'], $spoken)) {
                $warnings[] = "cinematic: formula '{$el['formula']}' is not in the narration";
                if (isset($el['sub'])) {
                    $el['kind'] = 'text';
                    $el['text'] = self::line($el['sub'], 90);
                    unset($el['formula'], $el['sub']);
                } else {
                    continue;
                }
            }

            $elements[] = $el;
        }

        // Depth is the whole point: a card staged on one plane has nothing to
        // focus between. When the design put everything at one depth, spread
        // it deterministically (first part mid, then near, then far...).
        if (count($elements) >= 3 && count(array_unique(array_column($elements, 'depth'))) === 1) {
            $cycle = ['mid', 'near', 'far'];
            foreach ($elements as $k => $el) {
                $elements[$k]['depth'] = $cycle[$k % 3];
            }
            $warnings[] = 'cinematic: every part was on one plane, depths spread';
        }

        $slot = ['content_type' => 'cinematic'];
        $heading = self::line($raw['heading'] ?? '', 60);
        if ($heading !== '') {
            $slot['heading'] = $heading;
        }
        $brief = self::line($raw['brief'] ?? '', 200);
        if ($brief !== '') {
            $slot['brief'] = $brief;
        }
        $slot['elements'] = $elements;

        // One stylesheet for the card's html parts, scoped like custom_card's.
        $css = trim((string) ($raw['css'] ?? ''));
        if ($css !== '' && in_array('html', array_column($elements, 'kind'), true)) {
            $scoped = CustomHtml::sanitize('<p>css</p>', $css);
            foreach ($scoped['warnings'] as $w) {
                $warnings[] = 'cinematic css: ' . $w;
            }
            if ($scoped['ok'] && $scoped['css'] !== '') {
                $slot['css'] = $scoped['css'];
            }
        }

        $ok = count($elements) >= self::MIN_ELEMENTS;
        if (!$ok) {
            $warnings[] = 'cinematic: fewer than ' . self::MIN_ELEMENTS . ' usable parts';
        }

        return ['ok' => $ok, 'slot' => $slot, 'warnings' => $warnings];
    }

    /** A slot that still waits for the design pass (a brief, no parts yet). */
    public static function isPending(array $slot): bool
    {
        $elements = $slot['elements'] ?? null;

        return (!is_array($elements) || count($elements) < self::MIN_ELEMENTS)
            && trim((string) ($slot['brief'] ?? '')) !== '';
    }

    /** One part, or null when nothing usable is left of it. */
    private static function element(array $item, int $i, ?callable $iconExists, array &$warnings, int &$htmlBudget): ?array
    {
        $kind = strtolower(trim((string) ($item['kind'] ?? '')));
        $text = self::line($item['text'] ?? ($item['label'] ?? ''), 90);
        $sub = self::line($item['sub'] ?? '', 70);

        if (!in_array($kind, self::KINDS, true)) {
            if ($text === '') {
                $warnings[] = "cinematic: dropped part {$i} of unknown kind '{$kind}'";
                return null;
            }
            $kind = 'text';
        }

        $el = ['id' => self::slug((string) ($item['id'] ?? ''), $i), 'kind' => $kind];

        switch ($kind) {
            case 'stat':
                $value = self::line($item['text'] ?? ($item['value'] ?? ''), 16);
                if ($value === '') {
                    $warnings[] = "cinematic: dropped stat part {$i} with no figure";
                    return null;
                }
                $el['text'] = $value;
                break;

            case 'formula':
                $formula = self::line($item['formula'] ?? '', 60);
                if ($formula === '') {
                    if ($text === '') {
                        return null;
                    }
                    $el['kind'] = 'text';
                    $el['text'] = $text;
                    break;
                }
                $el['formula'] = $formula;
                break;

            case 'icon':
                $icon = strtolower(trim((string) ($item['icon'] ?? '')));
                if ($icon === '' || ($iconExists !== null && !$iconExists($icon))) {
                    // No picture to draw: the label alone is still a part.
                    if ($text === '') {
                        $warnings[] = "cinematic: dropped icon part {$i} ('{$icon}' is not in the library)";
                        return null;
                    }
                    $warnings[] = "cinematic: icon '{$icon}' is not in the library, part {$i} kept as text";
                    $el['kind'] = 'text';
                    $el['text'] = self::line($text, 28);
                    break;
                }
                $el['icon'] = $icon;
                if ($text !== '') {
                    $el['text'] = self::line($text, 28);
                }
                break;

            case 'html':
                $html = trim((string) ($item['html'] ?? ''));
                if (mb_strlen($html) > self::MAX_PART_HTML) {
                    $html = mb_substr($html, 0, self::MAX_PART_HTML);
                    $warnings[] = "cinematic: html part {$i} truncated";
                }
                if ($html === '' || mb_strlen($html) > $htmlBudget) {
                    if ($text === '') {
                        $warnings[] = "cinematic: dropped html part {$i}";
                        return null;
                    }
                    $el['kind'] = 'text';
                    $el['text'] = $text;
                    break;
                }
                $clean = CustomHtml::sanitize($html);
                foreach ($clean['warnings'] as $w) {
                    $warnings[] = "cinematic html part {$i}: {$w}";
                }
                if (!$clean['ok']) {
                    if ($text === '') {
                        return null;
                    }
                    $el['kind'] = 'text';
                    $el['text'] = $text;
                    break;
                }
                $htmlBudget -= mb_strlen($clean['html']);
                $el['html'] = $clean['html'];
                break;

            case 'text':
            default:
                if ($text === '') {
                    $warnings[] = "cinematic: dropped empty text part {$i}";
                    return null;
                }
                $el['text'] = $text;
        }

        if ($sub !== '' && $el['kind'] !== 'html') {
            $el['sub'] = $sub;
        }

        $place = strtolower(trim((string) ($item['place'] ?? '')));
        $el['place'] = in_array($place, self::PLACES, true) ? $place : 'center';
        $depth = strtolower(trim((string) ($item['depth'] ?? '')));
        $el['depth'] = in_array($depth, self::DEPTHS, true) ? $depth : 'mid';
        $camera = strtolower(trim((string) ($item['camera'] ?? '')));
        $el['camera'] = in_array($camera, self::CAMERAS, true) ? $camera : 'push';

        $word = self::cueWord((string) ($item['word'] ?? ''));
        if ($word !== '') {
            $el['word'] = $word;
        }
        if (isset($item['at']) && is_numeric($item['at'])) {
            $el['at'] = round(max(0.0, min(1.0, (float) $item['at'])), 3);
        }

        return $el;
    }

    /** Collapse whitespace, strip tags, cap. */
    private static function line(mixed $value, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) (is_scalar($value) ? $value : ''))) ?? '');

        return mb_substr($s, 0, $max);
    }

    private static function slug(string $id, int $i): string
    {
        $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $id) ?? '');
        $s = trim($s, '-');

        return $s !== '' && preg_match('/^[a-z0-9]/', $s) ? mb_substr($s, 0, 24) : "p{$i}";
    }

    /** The first word of a cue, normalised the way the renderer matches it. */
    private static function cueWord(string $word): string
    {
        $first = preg_split('/\s+/', trim($word))[0] ?? '';
        $n = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $first) ?? '');

        return strlen($n) >= 3 ? mb_substr($n, 0, 24) : '';
    }

    /** @return string[] normalised narration words */
    private static function spokenWords(string $narration): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($narration)) ?: [] as $w) {
            $n = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $w) ?? '');
            if ($n !== '') {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * Mirror of the renderer's spokenAt(): a spoken word that starts with the
     * cue, or a cue that starts with a spoken word differing only by an
     * inflection (at most two letters).
     */
    private static function isSpoken(string $cue, array $spoken): bool
    {
        $cueStem = self::stem($cue);
        foreach ($spoken as $n) {
            if (str_starts_with($n, $cue)) {
                return true;
            }
            if (strlen($n) >= 3 && strlen($n) >= strlen($cue) - 2 && str_starts_with($cue, $n)) {
                return true;
            }
            if (strlen($cueStem) >= 3 && str_starts_with(self::stem($n), $cueStem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the narration actually use this formula? Yes when it talks about
     * an equation at all, or when a number in the formula is also spoken.
     */
    private static function formulaGrounded(string $formula, array $spoken): bool
    {
        // Only unambiguous maths words: "times" is also "thousands of times a
        // second", which grounded an invented sine wave in the live run.
        $vocabulary = ['formula', 'formulas', 'equation', 'equations', 'equals', 'squared', 'cubed', 'multiplied', 'divided'];
        foreach ($spoken as $n) {
            if (in_array($n, $vocabulary, true)) {
                return true;
            }
        }
        preg_match_all('/\d+(?:\.\d+)?/', $formula, $m);
        foreach ($m[0] as $number) {
            if (strlen($number) >= 1 && in_array($number, $spoken, true) && !in_array($number, ['0', '1', '2'], true)) {
                return true;
            }
        }

        return false;
    }

    /** A light plural stem — mirror of the renderer's narrationBeats stem(). */
    private static function stem(string $w): string
    {
        if (strlen($w) > 4 && str_ends_with($w, 'ies')) {
            return substr($w, 0, -3) . 'y';
        }
        if (strlen($w) > 4 && str_ends_with($w, 'es')) {
            return substr($w, 0, -2);
        }
        if (strlen($w) > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) {
            return substr($w, 0, -1);
        }

        return $w;
    }
}
