<?php

namespace Modules\Project\Support;

/**
 * SpeechDictionary — what the narrator SAYS, as opposed to what is written.
 *
 * Written English is full of shorthand that no human reads aloud literally:
 * "90 km/h" is "ninety kilometres per hour", "e.g." is "for example", "1980s"
 * is "the nineteen eighties". A TTS engine given the shorthand reads the
 * shorthand — "km slash h" — and one such line undoes the credibility of an
 * otherwise good explainer.
 *
 * Two sources, in order of specificity:
 *
 *   1. PER-PROJECT HINTS from the analyzer: the proper nouns and jargon only
 *      this video's topic knows ("Nguyen", "Xiaomi", "Gouda"). The model
 *      proposes; this class decides whether each hint is safe to apply.
 *   2. The BUILT-IN table below: units, abbreviations and symbols that behave
 *      the same way in every video.
 *
 * Applied ONLY to the text handed to the TTS engine. The stored narration, the
 * captions and the on-screen text keep their written form — the viewer reads
 * "90 km/h" while the narrator says it properly. Same rule {@see MathSpeech}
 * follows for notation, and this runs on every video rather than only maths.
 *
 * Deliberately conservative: an unrecognised token is left exactly as written,
 * because a wrong guess is worse than a symbol the engine merely reads flatly.
 */
class SpeechDictionary
{
    /**
     * Regex → spoken replacement. Order matters: the compound units run before
     * the bare ones so "km/h" never degrades into "kilometres slash h".
     *
     * Every unit pattern requires the unit to FOLLOW A NUMBER. That is the
     * guard that keeps "5 m" (five metres) apart from "m" as an algebraic
     * variable, and "3 s" apart from a plural s — the same trap the units lint
     * had to solve.
     */
    private const PATTERNS = [
        // --- compound units -------------------------------------------------
        ['/(\d)\s*km\/h\b/iu', '$1 kilometres per hour'],
        ['/(\d)\s*mph\b/iu', '$1 miles per hour'],
        ['/(\d)\s*m\/s(?:\^?2|²)\b/iu', '$1 metres per second squared'],
        ['/(\d)\s*m\/s\b/iu', '$1 metres per second'],
        ['/(\d)\s*km\/s\b/iu', '$1 kilometres per second'],
        ['/(\d)\s*kg\/m(?:\^?3|³)\b/iu', '$1 kilograms per cubic metre'],
        // --- plain units ----------------------------------------------------
        ['/(\d)\s*km\b/iu', '$1 kilometres'],
        ['/(\d)\s*cm\b/iu', '$1 centimetres'],
        ['/(\d)\s*mm\b/iu', '$1 millimetres'],
        ['/(\d)\s*kg\b/iu', '$1 kilograms'],
        ['/(\d)\s*mg\b/iu', '$1 milligrams'],
        ['/(\d)\s*ml\b/iu', '$1 millilitres'],
        ['/(\d)\s*GHz\b/u', '$1 gigahertz'],
        ['/(\d)\s*MHz\b/u', '$1 megahertz'],
        ['/(\d)\s*kWh\b/iu', '$1 kilowatt hours'],
        ['/(\d)\s*kW\b/u', '$1 kilowatts'],
        ['/(\d)\s*MW\b/u', '$1 megawatts'],
        ['/(\d)\s*GB\b/u', '$1 gigabytes'],
        ['/(\d)\s*TB\b/u', '$1 terabytes'],
        ['/(\d)\s*MB\b/u', '$1 megabytes'],
        ['/(\d)\s*%/u', '$1 percent'],
        ['/(\d)\s*°C\b/u', '$1 degrees celsius'],
        ['/(\d)\s*°F\b/u', '$1 degrees fahrenheit'],
        // --- decades: "1980s" is spoken, never spelled ----------------------
        ['/\b(1[89]|20)(\d0)s\b/u', '$1$2s'],
        // --- abbreviations --------------------------------------------------
        ['/\be\.g\.\s*/iu', 'for example, '],
        ['/\bi\.e\.\s*/iu', 'that is, '],
        ['/\betc\./iu', 'et cetera'],
        ['/\bvs\.?(?=\s|$)/iu', 'versus'],
        ['/\bapprox\./iu', 'approximately'],
        ['/\bDr\.\s+/u', 'Doctor '],
        ['/\bProf\.\s+/u', 'Professor '],
        ['/\bSt\.\s+(?=[A-Z])/u', 'Saint '],
        ['/\bNo\.\s*(?=\d)/u', 'number '],
        ['/\bfig\.\s*(?=\d)/iu', 'figure '],
        // --- symbols that read as words -------------------------------------
        ['/\s&\s/u', ' and '],
        ['/(\d)\s*x\s*(?=\d)/iu', '$1 times '],
    ];

    /**
     * Rewrite a narration line for the engine.
     *
     * @param array<int, array{term?: string, say?: string}> $hints Per-project
     *        pronunciations from the analyzer. Junk entries are ignored.
     */
    public static function forSpeech(string $text, array $hints = []): string
    {
        $out = $text;

        // Project hints first: they are the specific knowledge, and a built-in
        // rule must never pre-empt the one thing the analyzer knew about THIS
        // video's vocabulary.
        foreach (self::usableHints($hints) as $term => $say) {
            $out = (string) preg_replace(
                '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu',
                $say,
                $out
            );
        }

        foreach (self::PATTERNS as [$pattern, $replacement]) {
            $out = (string) preg_replace($pattern, $replacement, $out);
        }

        // Collapse any double spacing the substitutions introduced.
        return trim((string) preg_replace('/[ \t]{2,}/u', ' ', $out));
    }

    /**
     * The hints worth trusting, as term => spoken form.
     *
     * A hint is a REWRITE OF THE SPOKEN LINE, so the guards matter more than
     * the quantity: a term shorter than two characters would match inside
     * other words, a runaway `say` would replace a word with a sentence, and
     * a hint identical to its term is just noise. Twelve is plenty for one
     * video's proper nouns; beyond that the model is guessing.
     *
     * @param array<int, mixed> $hints
     * @return array<string, string>
     */
    public static function usableHints(array $hints): array
    {
        $out = [];
        foreach ($hints as $hint) {
            if (!is_array($hint)) {
                continue;
            }
            $term = trim((string) ($hint['term'] ?? ''));
            $say = trim((string) ($hint['say'] ?? ''));
            if (mb_strlen($term) < 2 || $say === '' || mb_strlen($say) > max(24, mb_strlen($term) * 3)) {
                continue;
            }
            if (mb_strtolower($term) === mb_strtolower($say)) {
                continue;
            }
            // The spoken form is read aloud verbatim: letters, spaces and
            // hyphens only, so a stray "|" or markup can never reach the voice.
            if (!preg_match('/^[\p{L}\p{N} \-\']+$/u', $say)) {
                continue;
            }
            $out[$term] = $say;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }
}
