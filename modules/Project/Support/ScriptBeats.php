<?php

namespace Modules\Project\Support;

/**
 * ScriptBeats — cut a finished voice-over script into spoken beats, word for
 * word, deterministically.
 *
 * Why this exists: the composer used to WRITE the narration from a list of
 * ~20 planned phases. For a 60-second topic that is the point; for a written
 * fifteen-minute script it is a disaster — project 211 fed in 2,890 words and
 * got back 456, a four-minute video, because every phase became a one-line
 * summary of its part of the script. When the user has written the words,
 * the words are the product. The AI's job is the pictures.
 *
 * What the parser understands (the way people actually write scripts):
 *   - stage directions: `ON SCREEN: ...`, `VISUAL:`, `B-ROLL:`, `SHOT:`,
 *     `SFX:`, `MUSIC:` lines. Never spoken; the visual ones are kept as the
 *     art direction of the beat that follows them.
 *   - pause marks: `[beat]`, `[pause]`, `(pause)` — dropped, and they end a
 *     beat when it already has some words.
 *   - chapter headings: `Chapter 2 — The ...`, `Cold open (0:00–1:10)`,
 *     `# Heading` — never spoken; they start a new beat and name a chapter.
 *   - speaker prefixes (`NARRATOR:`, `VO:`) and inline `[...]` notes.
 */
class ScriptBeats
{
    /** A beat is closed once it reaches this many words... */
    private const MIN_WORDS = 20;
    /** ...and never grows past this (≈14s, the validator's scene ceiling). */
    private const MAX_WORDS = 36;

    private const DIRECTION = '/^\s*[\[(]?\s*(on[\s-]?screen|visual|visuals|b[\s-]?roll|shot|cut to|graphic|graphics|text on screen|lower third|title card|end screen|sfx|sound|music)\s*[:\-—–]\s*/iu';
    private const VISUAL_DIRECTION = '/^\s*[\[(]?\s*(on[\s-]?screen|visual|visuals|b[\s-]?roll|shot|cut to|graphic|graphics|text on screen|lower third|title card)\b/iu';
    private const PAUSE = '/^\s*[\[(]\s*(beat|pause|silence|breath|music|sfx|laughs?|beat\.?)\b[^\])]*[\])]\s*$/iu';
    private const TIMESTAMP_RANGE = '/\(?\s*\d{1,2}:\d{2}\s*[–—-]\s*\d{1,2}:\d{2}\s*\)?/u';
    private const HEADING = '/^\s*(#{1,6}\s+|(chapter|part|section|act|segment)\s+[\dIVX]+\b|(cold open|intro|introduction|outro|conclusion|recap|epilogue|prologue)\b)/iu';
    private const SPEAKER = '/^\s*(narrator|vo|v\.o\.|voice ?over|host)\s*:\s*/iu';

    /** Spoken words in a script once the directions are gone. */
    public static function spokenWords(string $script): int
    {
        $words = 0;
        foreach (self::segment($script) as $beat) {
            $words += str_word_count($beat['narration']);
        }

        return $words;
    }

    /**
     * Is this a written voice-over rather than a topic or an outline? Long
     * enough that summarising it would visibly throw the user's work away.
     */
    public static function isFullScript(string $script): bool
    {
        return self::spokenWords($script) >= 300;
    }

    /**
     * @return array<int, array{narration: string, hint: string, chapter: string}>
     */
    public static function segment(string $script): array
    {
        $beats = [];
        $words = [];       // sentences of the open beat
        $count = 0;
        $hint = '';        // visual direction for the open/next beat
        $chapter = '';

        $close = function () use (&$beats, &$words, &$count, &$hint, &$chapter): void {
            $text = trim(implode(' ', $words));
            if ($text !== '') {
                $beats[] = ['narration' => $text, 'hint' => $hint, 'chapter' => $chapter];
                $hint = '';
            }
            $words = [];
            $count = 0;
        };

        $lines = preg_split('/\R/u', str_replace("\u{00A0}", ' ', $script)) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match(self::PAUSE, $line)) {
                if ($count >= (int) (self::MIN_WORDS * 0.6)) {
                    $close();
                }
                continue;
            }

            if (preg_match(self::DIRECTION, $line)) {
                // A new visual starts a new beat, so the picture and the words
                // it belongs to stay together.
                if (preg_match(self::VISUAL_DIRECTION, $line)) {
                    if ($count > 0) {
                        $close();
                    }
                    $direction = trim(preg_replace(self::DIRECTION, '', $line) ?? '', " \t[]()");
                    $hint = trim($hint . ($hint !== '' ? ' ' : '') . $direction);
                }
                continue;
            }

            $isHeading = preg_match(self::HEADING, $line)
                || (preg_match(self::TIMESTAMP_RANGE, $line) && str_word_count($line) <= 14);
            if ($isHeading) {
                if ($count > 0) {
                    $close();
                }
                $title = preg_replace(self::TIMESTAMP_RANGE, '', $line) ?? $line;
                $title = trim(preg_replace('/^#{1,6}\s+/u', '', $title) ?? $title, " \t—–-:");
                // "Chapter 1 — The third-of-a-second problem" -> the name part.
                if (preg_match('/^(chapter|part|section|act|segment)\s+[\dIVX]+\s*[—–:\-.]\s*(.+)$/iu', $title, $m)) {
                    $title = trim($m[2]);
                }
                $chapter = mb_substr($title, 0, 80);
                continue;
            }

            $line = preg_replace(self::SPEAKER, '', $line) ?? $line;
            // Inline stage notes: "[beat]", "[smiles]", "(pause)".
            $line = trim(preg_replace('/\[[^\]]{0,80}\]|\((?:pause|beat|laughs?|sighs?)[^)]*\)/iu', ' ', $line) ?? $line);
            $line = trim(preg_replace('/\s{2,}/u', ' ', $line) ?? $line);
            if ($line === '' || !preg_match('/\p{L}/u', $line)) {
                continue;
            }

            foreach (self::sentences($line) as $sentence) {
                $n = str_word_count($sentence);
                if ($count > 0 && $count + $n > self::MAX_WORDS) {
                    $close();
                }
                $words[] = $sentence;
                $count += $n;
                if ($count >= self::MIN_WORDS) {
                    $close();
                }
            }
        }
        $close();

        return self::mergeCrumbs($beats);
    }

    /** @return string[] */
    private static function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?…])["”’)]*\s+(?=["“‘(]?[\p{Lu}\d])/u', $text) ?: [$text];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            // A run-on sentence longer than a whole beat is split at a comma
            // or dash near the middle, so no scene outlives its ceiling.
            while (str_word_count($part) > self::MAX_WORDS) {
                $w = preg_split('/\s+/u', $part);
                $cut = (int) floor(count($w) / 2);
                for ($i = $cut; $i < count($w) - 4; $i++) {
                    if (preg_match('/[,;:—–]$/u', $w[$i])) {
                        $cut = $i + 1;
                        break;
                    }
                }
                $out[] = implode(' ', array_slice($w, 0, $cut));
                $part = implode(' ', array_slice($w, $cut));
            }
            $out[] = $part;
        }

        return $out;
    }

    /**
     * A beat of two or three words ("Don't look away.") is a legitimate punchy
     * opener, but a string of them is a slideshow; fold a crumb into its
     * neighbour inside the same chapter unless it carries its own visual.
     */
    private static function mergeCrumbs(array $beats): array
    {
        $out = [];
        foreach ($beats as $beat) {
            $prevIndex = count($out) - 1;
            $small = str_word_count($beat['narration']) < 6;
            if ($small && $prevIndex >= 0 && $beat['hint'] === ''
                && $out[$prevIndex]['chapter'] === $beat['chapter']
                && str_word_count($out[$prevIndex]['narration']) + str_word_count($beat['narration']) <= self::MAX_WORDS) {
                $out[$prevIndex]['narration'] .= ' ' . $beat['narration'];
                continue;
            }
            $out[] = $beat;
        }

        return $out;
    }
}
