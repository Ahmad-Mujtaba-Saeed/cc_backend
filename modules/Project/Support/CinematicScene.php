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
 * - WHAT each part is: a `visual` (a sentence naming the thing to DRAW, which
 *   {@see FlowVisualService} hands to the image model) or, for a piece that is
 *   only words, `text`, `stat`, `formula` or `icon`,
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

    public const KINDS = ['text', 'stat', 'formula', 'icon', 'visual'];
    /** The parts an image model DRAWS for this script (FlowVisualService). */
    public const DRAWN = ['visual'];
    public const TONES = ['plain', 'accent', 'bad', 'muted'];
    public const LINK_STYLES = ['curve', 'elbow', 'straight'];
    public const MAX_LINKS = 8;
    public const PLACES = [
        'top_left', 'top', 'top_right',
        'left', 'center', 'right',
        'bottom_left', 'bottom', 'bottom_right',
    ];
    public const DEPTHS = ['near', 'mid', 'far'];
    public const CAMERAS = ['push', 'angle', 'rack'];

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
        $idMap = [];

        foreach ($input as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (count($elements) >= self::MAX_ELEMENTS) {
                $warnings[] = 'cinematic: dropped parts past ' . self::MAX_ELEMENTS;
                break;
            }

            $el = self::element($item, $i, $iconExists, $warnings);
            if ($el === null) {
                continue;
            }

            // Unique, renderer-safe ids. Links name parts by the id the model
            // gave them, so the first part to claim a base id keeps it.
            $base = $el['id'];
            $id = $base;
            $n = 2;
            while (isset($ids[$id])) {
                $id = $base . '-' . $n++;
            }
            $ids[$id] = true;
            $el['id'] = $id;
            $idMap[$base] ??= $id;
            $idMap[$id] ??= $id;

            // A change whose cue is never spoken still happens — on its
            // fallback timing — so only the word goes.
            foreach ($el['then'] ?? [] as $k => $patch) {
                if (isset($patch['word']) && $spoken !== [] && !self::isSpoken($patch['word'], $spoken)) {
                    $warnings[] = "cinematic: change cue '{$patch['word']}' is never spoken, dropped";
                    unset($el['then'][$k]['word']);
                }
            }

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

        $links = self::links($raw['links'] ?? [], $idMap, array_column($elements, 'id'), $warnings);
        if ($links !== []) {
            $slot['links'] = $links;
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
    private static function element(array $item, int $i, ?callable $iconExists, array &$warnings): ?array
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

            case 'visual':
                // The picture is drawn later (FlowVisualService) from this
                // sentence; the words around it are typeset by the renderer.
                $subject = self::subject(self::line($item['prompt'] ?? ($item['subject'] ?? ''), 180));
                if ($subject === '') {
                    $warnings[] = "cinematic: dropped visual {$i} with nothing to draw";
                    return null;
                }
                $el['prompt'] = $subject;
                $title = self::line($item['title'] ?? ($item['label'] ?? $text), 20);
                if ($title !== '') {
                    $el['title'] = $title;
                }
                $status = self::line($item['status'] ?? '', 12);
                if ($status !== '') {
                    $el['status'] = $status;
                }
                $note = self::line($item['note'] ?? ($item['caption'] ?? ''), 40);
                if ($note !== '') {
                    $el['note'] = $note;
                }
                $tone = self::tone($item['tone'] ?? null);
                if ($tone !== null) {
                    $el['tone'] = $tone;
                }
                // Set by the drawing pass, kept across re-validation so a
                // storyboard is never redrawn for free.
                $drawn = trim((string) ($item['image_path'] ?? ''));
                if ($drawn !== '' && preg_match('#^[A-Za-z0-9_./-]{4,160}\.png$#', $drawn) === 1 && !str_contains($drawn, '..')) {
                    $el['image_path'] = $drawn;
                }
                break;

            case 'text':
            default:
                if ($text === '') {
                    $warnings[] = "cinematic: dropped empty text part {$i}";
                    return null;
                }
                $el['text'] = $text;
        }

        if ($sub !== '' && !in_array($el['kind'], self::DRAWN, true)) {
            $el['sub'] = $sub;
        }

        $then = self::patches($item['then'] ?? null);
        if ($then !== []) {
            $el['then'] = $then;
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

    /**
     * A subject the image model can actually draw.
     *
     * It cannot letter: every "labelled X", "with the word Y" or "with text"
     * comes back as scribbled pseudo-writing inside the drawing, which then
     * keys out as noise. The words belong to the renderer (title, status,
     * note), so they are stripped here rather than trusted to a prompt rule.
     */
    private static function subject(string $subject): string
    {
        $clean = preg_replace(
            [
                '/,?\s*(?:with|showing|including|and)?\s*(?:the\s+)?(?:words?|text|letters?|numbers?|labels?|captions?|titles?)\b[^,.]*/i',
                '/\s*\b(?:labell?ed|marked|titled|captioned|annotated|named)\b[^,.]*/i',
            ],
            '',
            $subject
        );
        $clean = trim(preg_replace('/\s{2,}/', ' ', (string) $clean), " \t\n\r\0\x0B,;-");

        // Never scrub a subject down to nothing: a bare "labelled diagram" is
        // still better handed over than dropped.
        return $clean !== '' ? $clean : trim($subject);
    }

    /** Collapse whitespace, strip tags, cap. */
    private static function line(mixed $value, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) (is_scalar($value) ? $value : ''))) ?? '');

        // Trim AFTER capping: a cut that lands on a space would otherwise
        // change again on the next pass (sanitizing must be idempotent).
        return rtrim(mb_substr($s, 0, $max));
    }

    private static function slug(string $id, int $i): string
    {
        $key = self::idKey($id);

        return $key !== '' ? $key : "p{$i}";
    }

    /** A renderer-safe id (Flute ids start alphanumeric), or '' when none. */
    private static function idKey(string $id): string
    {
        $s = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $id) ?? ''), '-');

        return $s !== '' && preg_match('/^[a-z0-9]/', $s) ? mb_substr($s, 0, 24) : '';
    }

    /** The first word of a cue, normalised the way the renderer matches it. */
    private static function cueWord(string $word): string
    {
        // The first word long enough to match: a cue of "B cells" is "cells",
        // not the "b" that can never be matched (and silently lost the cue).
        foreach (preg_split('/\s+/', trim($word)) ?: [] as $token) {
            $n = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $token) ?? '');
            if (strlen($n) >= 3) {
                return mb_substr($n, 0, 24);
            }
        }

        return '';
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

    private static function iconInto(array &$el, array $item, ?callable $iconExists, array &$warnings, int $i): void
    {
        $icon = strtolower(trim((string) ($item['icon'] ?? '')));
        if ($icon === '') {
            return;
        }
        if ($iconExists !== null && !$iconExists($icon)) {
            $warnings[] = "cinematic: icon '{$icon}' on part {$i} is not in the library, dropped";
            return;
        }
        $el['icon'] = $icon;
    }

    private static function tone(mixed $tone): ?string
    {
        $t = strtolower(trim((string) (is_scalar($tone) ? $tone : '')));

        return in_array($t, self::TONES, true) ? $t : null;
    }

    /** 0..1 from a share or a percentage; null when not a number. */
    private static function unit(mixed $v): ?float
    {
        if (is_string($v)) {
            $v = rtrim(trim($v), '%');
        }
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        // 1.7 is a share that overshot, not 1.7 percent; 34 is a percentage.
        if ($f >= 2 && $f <= 100) {
            $f /= 100;
        }

        return round(max(0.0, min(1.0, $f)), 3);
    }

    /**
     * A part's later changes: at most two, each with its cue and ONLY the
     * fields that change. A change that changes nothing is dropped.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function patches(mixed $then): array
    {
        $out = [];
        foreach (is_array($then) ? $then : [] as $p) {
            if (!is_array($p) || count($out) >= 2) {
                continue;
            }
            $patch = [];
            $status = self::line($p['status'] ?? '', 16);
            if ($status !== '') {
                $patch['status'] = $status;
            }
            $tone = self::tone($p['tone'] ?? null);
            if ($tone !== null) {
                $patch['tone'] = $tone;
            }
            $note = self::line($p['note'] ?? '', 40);
            if ($note !== '') {
                $patch['note'] = $note;
            }
            $text = self::line($p['text'] ?? '', 40);
            if ($text !== '') {
                $patch['text'] = $text;
            }
            if ($patch === []) {
                continue;
            }
            $word = self::cueWord((string) ($p['word'] ?? ''));
            if ($word !== '') {
                $patch['word'] = $word;
            }
            if (isset($p['at']) && is_numeric($p['at'])) {
                $patch['at'] = round(max(0.0, min(1.0, (float) $p['at'])), 3);
            }
            $out[] = $patch;
        }

        return $out;
    }

    /**
     * The connections between parts. Both ends must be surviving parts (by
     * the id the model used), never a part to itself, never the same pair
     * twice, at most eight.
     *
     * @param  array<string, string> $idMap  model id -> final id
     * @return array<int, array<string, mixed>>
     */
    private static function links(mixed $raw, array $idMap, array $finalIds, array &$warnings): array
    {
        $out = [];
        $seen = [];
        $alive = array_flip($finalIds);
        foreach (is_array($raw) ? $raw : [] as $l) {
            if (!is_array($l)) {
                continue;
            }
            if (count($out) >= self::MAX_LINKS) {
                $warnings[] = 'cinematic: dropped links past ' . self::MAX_LINKS;
                break;
            }
            $from = $idMap[self::idKey((string) ($l['from'] ?? ''))] ?? null;
            $to = $idMap[self::idKey((string) ($l['to'] ?? ''))] ?? null;
            if ($from === null || $to === null || !isset($alive[$from], $alive[$to]) || $from === $to) {
                $warnings[] = 'cinematic: dropped a link between parts that do not exist';
                continue;
            }
            if (isset($seen["{$from}>{$to}"])) {
                continue;
            }
            $seen["{$from}>{$to}"] = true;
            $link = ['from' => $from, 'to' => $to];
            $label = self::line($l['label'] ?? '', 24);
            if ($label !== '') {
                $link['label'] = $label;
            }
            $style = strtolower(trim((string) ($l['style'] ?? '')));
            if (in_array($style, self::LINK_STYLES, true)) {
                $link['style'] = $style;
            }
            $tone = self::tone($l['tone'] ?? null);
            if ($tone !== null) {
                $link['tone'] = $tone;
            }
            if (isset($l['flow'])) {
                $link['flow'] = (bool) $l['flow'];
            }
            $out[] = $link;
        }

        return $out;
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
