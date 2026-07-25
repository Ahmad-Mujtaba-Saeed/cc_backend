<?php

namespace Modules\Project\Support;

/**
 * MathPlain — projects the linear math notation into readable plain text.
 *
 * PHP twin of `mathToPlain(parseMath(x))` in remotion-render/src/math/mathText.tsx,
 * for the places PHP has to put an expression somewhere that ISN'T a math card
 * and therefore won't typeset it. The renderer turns "frac{-2 pm sqrt{48}}{2}"
 * into real glyphs; a text_block bullet does not, and shows the source. Any
 * such fallback must come through here first.
 *
 * "Plain" means: real symbols (√ ± π ² …), structure flattened with slashes
 * and parens. It is a legible last resort, not a typesetter — a card is always
 * better, and the analyzer is told never to put maths in a bullet.
 *
 * Total: any string in, legible string out, never a throw. Unbalanced braces
 * degrade to their own text rather than eating the rest of the line.
 */
class MathPlain
{
    /**
     * Mirrors SYMBOLS in mathText.tsx — order matters (multi-char operators
     * before their single-char prefixes).
     *
     * The word names are bounded by LETTERS rather than \b, exactly as in the
     * TS twin: maths writes the coefficient onto the symbol ("2pi", "3theta"),
     * and \b sees no boundary between a digit and a letter.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const SYMBOLS = [
        ['/\+-/', '±'],
        ['/<=/', '≤'],
        ['/>=/', '≥'],
        ['/!=/', '≠'],
        ['/~=/', '≈'],
        ['/->/', '→'],
        ['/\*/', '×'],
        ['/(?<![A-Za-z])pm(?![A-Za-z])/', '±'],
        ['/(?<![A-Za-z])pi(?![A-Za-z])/', 'π'],
        ['/(?<![A-Za-z])theta(?![A-Za-z])/', 'θ'],
        ['/(?<![A-Za-z])alpha(?![A-Za-z])/', 'α'],
        ['/(?<![A-Za-z])beta(?![A-Za-z])/', 'β'],
        ['/(?<![A-Za-z])[Dd]elta(?![A-Za-z])/', 'Δ'],
        ['/(?<![A-Za-z])lambda(?![A-Za-z])/', 'λ'],
        ['/(?<![A-Za-z])mu(?![A-Za-z])/', 'μ'],
        ['/(?<![A-Za-z])sigma(?![A-Za-z])/', 'σ'],
        ['/(?<![A-Za-z])omega(?![A-Za-z])/', 'ω'],
        ['/(?<![A-Za-z])inf(?![A-Za-z])/', '∞'],
        ['/(?<![A-Za-z])deg(?![A-Za-z])/', '°'],
    ];

    private const SUPERSCRIPTS = [
        '0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴',
        '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹',
        '+' => '⁺', '-' => '⁻', 'n' => 'ⁿ', 'i' => 'ⁱ',
    ];

    private const SUBSCRIPTS = [
        '0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄',
        '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈', '9' => '₉',
        '+' => '₊', '-' => '₋',
    ];

    public static function toPlain(string $expr): string
    {
        // Models raised on LaTeX reach for \frac, \pm and $...$ out of habit
        // (clampMathStepsContent strips the same two things for the same
        // reason). Left in, they survive as stray backslashes on screen.
        $s = trim($expr);
        $s = trim((string) preg_replace('/^\$+|\$+$/', '', $s));
        $s = str_replace('\\', '', $s);

        return trim((string) preg_replace('/\s+/', ' ', self::walk($s)));
    }

    /** Recursive descent over frac/sqrt/^/_ , everything else is a text run. */
    private static function walk(string $s): string
    {
        $out = '';
        $run = '';
        $i = 0;
        $len = strlen($s);

        $flush = function () use (&$out, &$run): void {
            if ($run !== '') {
                $out .= self::substituteSymbols($run);
                $run = '';
            }
        };

        while ($i < $len) {
            if (self::keywordAt($s, $i, 'frac')) {
                $flush();
                [$num, $next] = self::readGroup($s, $i + 4);
                [$den, $next2] = self::readGroup($s, $next);
                $out .= self::wrap(self::walk($num)) . '/' . self::wrap(self::walk($den));
                $i = $next2;
                continue;
            }
            // Radicals reach this projection in every spelling models use —
            // `sqrt{x}`, `sqrt(x)` and the transliterated "sqrt x" — because
            // some callers hand it raw slot values (a mistake card's `wrong`
            // line) rather than validator-canonicalised ones. Reading only the
            // braced form left the letters "sqrt" in the degraded text.
            if (self::radicalAt($s, $i)) {
                $flush();
                [$body, $next] = self::readRadicand($s, $i + 4);
                $out .= '√(' . self::walk($body) . ')';
                $i = $next;
                continue;
            }
            if ($s[$i] === '^' || $s[$i] === '_') {
                $isSup = $s[$i] === '^';
                $flush();
                [$body, $next] = self::readScript($s, $i + 1);
                $out .= self::script(self::walk($body), $isSup);
                $i = $next;
                continue;
            }
            $run .= $s[$i];
            $i++;
        }
        $flush();

        return $out;
    }

    /** A keyword only counts when it is not part of a longer word ("frac" in
     *  "fraction" is prose) and is followed by its brace group. */
    private static function keywordAt(string $s, int $i, string $word): bool
    {
        $n = strlen($word);
        if (substr($s, $i, $n) !== $word) {
            return false;
        }
        if ($i > 0 && preg_match('/[A-Za-z]/', $s[$i - 1])) {
            return false;
        }

        return ($s[$i + $n] ?? '') === '{';
    }

    /** True when a real radical (not the word inside "sqrts"/"resqrt") starts
     *  here AND something root-able follows it. */
    private static function radicalAt(string $s, int $i): bool
    {
        if (strtolower(substr($s, $i, 4)) !== 'sqrt') {
            return false;
        }
        if ($i > 0 && preg_match('/[A-Za-z]/', $s[$i - 1])) {
            return false;
        }
        [$body] = self::readRadicand($s, $i + 4);

        return $body !== '';
    }

    /** The body of a radical in any of the three spellings, or '' when the
     *  word is just prose ("the sqrt of both sides" — "of" is a word, not a
     *  radicand, so the sentence is left alone).
     *
     *  @return array{0: string, 1: int} [radicand, indexAfterIt] */
    private static function readRadicand(string $s, int $at): array
    {
        $j = $at;
        $len = strlen($s);
        while ($j < $len && $s[$j] === ' ') {
            $j++;
        }
        $open = $s[$j] ?? '';
        if ($open === '{') {
            return self::readGroup($s, $j);
        }
        if ($open === '(') {
            $depth = 0;
            for ($i = $j; $i < $len; $i++) {
                if ($s[$i] === '(') {
                    $depth++;
                } elseif ($s[$i] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        return [substr($s, $j + 1, $i - $j - 1), $i + 1];
                    }
                }
            }

            return [substr($s, $j + 1), $len];
        }
        if (preg_match('/^(?:\d+\.?\d*|[A-Za-z](?![A-Za-z]))(?:\^(?:\{[^}]*\}|[A-Za-z0-9.]+))?/', substr($s, $j), $m)) {
            return [$m[0], $j + strlen($m[0])];
        }

        return ['', $at];
    }

    /** @return array{0: string, 1: int} [content, indexAfterClose] */
    private static function readGroup(string $s, int $open): array
    {
        if (($s[$open] ?? '') !== '{') {
            return ['', $open];
        }
        $depth = 0;
        for ($i = $open, $len = strlen($s); $i < $len; $i++) {
            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [substr($s, $open + 1, $i - $open - 1), $i + 1];
                }
            }
        }

        return [substr($s, $open + 1), strlen($s)];
    }

    /** A script body is either a braced group or a single token (x^2, x^n). */
    private static function readScript(string $s, int $at): array
    {
        if (($s[$at] ?? '') === '{') {
            return self::readGroup($s, $at);
        }
        if (preg_match('/^-?[A-Za-z0-9]/', substr($s, $at, 3), $m)) {
            $tok = $m[0];
            return [$tok, $at + strlen($tok)];
        }

        return ['', $at];
    }

    /** Unicode scripts when every character has one, else a caret/underscore. */
    private static function script(string $body, bool $sup): string
    {
        $map = $sup ? self::SUPERSCRIPTS : self::SUBSCRIPTS;
        $chars = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($chars as $c) {
            if (!isset($map[$c])) {
                return ($sup ? '^' : '_') . $body;
            }
            $out .= $map[$c];
        }

        return $out !== '' ? $out : '';
    }

    /** Parenthesise a fraction part only when it isn't already atomic. */
    private static function wrap(string $s): string
    {
        $t = trim($s);
        if ($t === '') {
            return $t;
        }
        if (preg_match('/^[A-Za-z0-9π²³⁰¹⁴⁵⁶⁷⁸⁹.]+$/u', $t)) {
            return $t;
        }
        if (preg_match('/^\(.*\)$/u', $t)) {
            return $t;
        }

        return "({$t})";
    }

    private static function substituteSymbols(string $s): string
    {
        $out = $s;
        foreach (self::SYMBOLS as [$re, $glyph]) {
            $out = (string) preg_replace($re, $glyph, $out);
        }

        return (string) preg_replace('/\s+°/', '°', $out);
    }
}
