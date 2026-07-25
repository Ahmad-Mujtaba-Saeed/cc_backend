<?php

namespace Modules\Project\Support;

/**
 * PracticeCheck — can this answer be DISPROVED against this problem?
 *
 * The practice card hands the viewer a problem and then gives the answer, and
 * a wrong answer there is the one unforgivable failure of the whole template:
 * it teaches the error to someone who just did the work. A language model
 * writes a plausible problem and a plausible answer with identical confidence,
 * so the answer is not taken on faith wherever the arithmetic can actually be
 * settled — it is substituted back into the problem and evaluated, the same
 * guarantees-over-prompts rule the substitute-back synthesizer uses.
 *
 * Pure and static so both sides of the pipeline share ONE definition of
 * "wrong": the composer (which retries a disproved answer while it still has
 * the model on the line) and the validator (which refuses to print one that
 * arrived any other way).
 *
 * The check is deliberately narrow. It applies to a single equation in one
 * unknown with a numeric answer; a word problem, a symbolic answer or anything
 * outside the calculator grammar returns null and is left alone. A guarantee
 * that guessed would be worse than no guarantee at all.
 */
class PracticeCheck
{
    /**
     * Rewrite one side of a problem from the card's linear notation into the
     * TinyExpr calculator grammar: frac{a}{b} -> ((a)/(b)), sqrt{a} -> sqrt(a),
     * x^{n+1} -> x^(n+1), and the typeset symbols a model likes to send back
     * (× ÷ − π) into their ASCII originals.
     */
    public static function calcSrc(string $side): string
    {
        $s = strtr(trim($side), ['−' => '-', '×' => '*', '·' => '*', '÷' => '/', 'π' => 'pi']);
        // frac{a}{b} may nest, so rewrite innermost-first until it settles.
        for ($i = 0; $i < 4; $i++) {
            $next = (string) preg_replace('/\bfrac\{([^{}]*)\}\{([^{}]*)\}/', '(($1)/($2))', $s);
            if ($next === $s) {
                break;
            }
            $s = $next;
        }
        $s = (string) preg_replace('/\b(sqrt|abs|sin|cos|tan|log|ln|exp)\{([^{}]*)\}/', '$1($2)', $s);
        $s = (string) preg_replace('/\^\{([^{}]*)\}/', '^($1)', $s);

        return trim(rtrim(trim($s), '.?'));
    }

    /**
     * The residual (lhs - rhs after substituting the answer), or null when the
     * problem is not checkable here. `$tol` receives the tolerance the residual
     * should be judged against.
     *
     * The tolerance is slope-scaled, because a legitimately ROUNDED answer
     * (x = 3.16 for sqrt(10)) leaves a residual proportional to the local
     * slope; rejecting that would throw away correct work.
     */
    public static function residual(string $prompt, string $answer, ?float &$tol = null): ?float
    {
        $ans = strtr(trim($answer), ['−' => '-']);
        $var = null;
        if (preg_match('/^\s*([A-Za-z])\s*=\s*(-?\d+(?:\.\d+)?)\s*$/', $ans, $m)) {
            $var = strtolower($m[1]);
            $value = (float) $m[2];
        } elseif (preg_match('/^-?\d+(?:\.\d+)?$/', $ans)) {
            $value = (float) $ans;
        } else {
            return null; // "38 km", "it doubles" — nothing to substitute
        }

        // The equation may sit behind a prose lead-in ("Solve for x: 2x+3=11").
        $body = $prompt;
        if (str_contains($body, ':')) {
            $body = substr($body, (int) strrpos($body, ':') + 1);
        }
        $sides = explode('=', $body);
        if (count($sides) !== 2) {
            return null;
        }

        $lhsSrc = self::calcSrc($sides[0]);
        $rhsSrc = self::calcSrc($sides[1]);
        if ($lhsSrc === '' || $rhsSrc === '') {
            return null;
        }

        // Which letter is the unknown? The answer names it, or the equation
        // must contain exactly one letter that is not a function or constant.
        $strip = fn (string $s): string => (string) preg_replace(
            '/(?<![A-Za-z])(?:sin|cos|tan|sqrt|abs|log|ln|exp|pi|e)(?![A-Za-z])/i',
            '',
            $s
        );
        $letters = [];
        if (preg_match_all('/[A-Za-z]/', $strip($lhsSrc) . $strip($rhsSrc), $ls)) {
            $letters = array_values(array_unique(array_map('strtolower', $ls[0])));
        }
        if (count($letters) !== 1) {
            return null; // no unknown, or free coefficients: nothing to evaluate
        }
        if ($var !== null && !in_array($var, $letters, true)) {
            return null; // the answer names an unknown the problem never uses
        }
        $var = $letters[0];

        $toX = fn (string $s): string => (string) preg_replace(
            '/(?<![A-Za-z])' . preg_quote($var, '/') . '(?![A-Za-z])/i',
            'x',
            $s
        );
        $lhs = TinyExpr::compile($toX($lhsSrc));
        $rhs = TinyExpr::compile($toX($rhsSrc));
        if ($lhs === null || $rhs === null) {
            return null;
        }

        $g = fn (float $x): float => $lhs($x) - $rhs($x);
        $residual = $g($value);
        if (!is_finite($residual)) {
            return null;
        }

        $h = 1e-4;
        $slope = ($g($value + $h) - $g($value - $h)) / (2 * $h);
        $tol = max(0.06, (is_finite($slope) ? abs($slope) : 0) * 0.02);

        return $residual;
    }

    /**
     * True only when the answer is PROVABLY wrong for the problem. Unknown is
     * never wrong: everything unverifiable passes.
     */
    public static function disproves(string $prompt, string $answer, ?string &$why = null): bool
    {
        $tol = null;
        $residual = self::residual($prompt, $answer, $tol);
        if ($residual === null || abs($residual) <= (float) $tol) {
            return false;
        }

        $off = rtrim(rtrim(number_format(abs($residual), 3, '.', ''), '0'), '.');
        $why = "answer '{$answer}' does not satisfy '{$prompt}' (off by {$off})";

        return true;
    }
}
