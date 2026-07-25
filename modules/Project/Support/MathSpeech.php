<?php

namespace Modules\Project\Support;

/**
 * MathSpeech — turns stray math NOTATION in a narration line into the words a
 * narrator would actually say, so a user-edited script with "x^2" or "sqrt(9)"
 * in it isn't read out as "x caret two" or "s-q-r-t".
 *
 * This runs ONLY on worked-math videos (auto_visuals_auto), and ONLY on the
 * text handed to the TTS engine — the stored narration and the on-screen math
 * cards keep their symbols. The script writer is already told to spell math in
 * words; this is the safety net for hand-edited scripts and the occasional
 * literal the model leaves in.
 *
 * Deliberately conservative: it converts the unambiguous, common patterns and
 * leaves anything it doesn't recognise exactly as it was (a wrong guess would
 * be worse than a symbol the engine mispronounces).
 */
class MathSpeech
{
    /** @var array<string, string> Whole-symbol → spoken word. */
    private const SYMBOLS = [
        'π' => ' pi ',
        'θ' => ' theta ',
        '±' => ' plus or minus ',
        '≤' => ' less than or equal to ',
        '≥' => ' greater than or equal to ',
        '≠' => ' not equal to ',
        '≈' => ' approximately ',
        '×' => ' times ',
        '÷' => ' divided by ',
        '√' => ' square root of ',
        '∞' => ' infinity ',
        '°' => ' degrees ',
    ];

    /** @var array<int, array{0: string, 1: string}> Regex → replacement. */
    private const PATTERNS = [
        // sqrt{x} / sqrt(x) / sqrt x  → "square root of x"
        ['/\bsqrt\s*[\{\(]\s*([^\}\)]+?)\s*[\}\)]/i', 'the square root of $1'],
        ['/\bsqrt\s+(\w+)/i', 'the square root of $1'],
        // frac{a}{b} → "a over b"
        ['/\bfrac\s*\{\s*([^\}]+?)\s*\}\s*\{\s*([^\}]+?)\s*\}/i', '$1 over $2'],
        // Common power words first (read naturally).
        ['/\^\s*2\b/', ' squared'],
        ['/\^\s*3\b/', ' cubed'],
        // x^{n} / x^n → "to the power of n"
        ['/\^\s*\{\s*([^\}]+?)\s*\}/', ' to the power of $1'],
        ['/\^\s*(-?\d+)/', ' to the power of $1'],
        // a_{n} / a_n → "a sub n"
        ['/_\s*\{\s*([^\}]+?)\s*\}/', ' sub $1'],
        ['/_\s*(\w+)/', ' sub $1'],
        // n! → "n factorial" (only after a word/number, not "!=")
        ['/(\w)!(?!=)/', '$1 factorial'],
        // "3 * 4" / "3*4" → "3 times 4" (both sides numeric-ish)
        ['/(\d)\s*\*\s*(\d)/', '$1 times $2'],
        // "a >= b" style ASCII operators the substitution table misses.
        ['/\s*<=\s*/', ' less than or equal to '],
        ['/\s*>=\s*/', ' greater than or equal to '],
        ['/\s*!=\s*/', ' not equal to '],
        ['/\s*\+-\s*/', ' plus or minus '],
    ];

    /**
     * Rewrite one narration line for speech. Idempotent-ish and total: on any
     * unexpected input it returns the line unchanged.
     */
    public static function forSpeech(string $text): string
    {
        $out = $text;

        foreach (self::SYMBOLS as $glyph => $word) {
            $out = str_replace($glyph, $word, $out);
        }
        foreach (self::PATTERNS as [$re, $rep]) {
            $result = preg_replace($re, $rep, $out);
            if ($result !== null) {
                $out = $result;
            }
        }

        // Collapse the whitespace the substitutions introduce.
        $out = (string) preg_replace('/\s{2,}/', ' ', $out);

        return trim($out);
    }
}
