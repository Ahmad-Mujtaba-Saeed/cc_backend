<?php

namespace Modules\Project\Support;

/**
 * MathTruth — is this written equation actually TRUE?
 *
 * The mistake card prints one line labelled WRONG and one labelled RIGHT, and
 * both labels are claims the video makes to a viewer who is there precisely
 * because they are unsure. Getting them the wrong way round — or "correcting"
 * a true line with a false one — is worse than never running the beat, and it
 * is exactly the error a language model makes fluently.
 *
 * So both lines are evaluated. An equation with no unknown is settled outright
 * ("sqrt{9+16} = 3+4" is 5 = 7, false); an equation in ONE unknown is sampled
 * at several awkward values, which is enough to refute a false identity
 * ("(x+3)^2 = x^2 + 9" fails at every point) and enough to accept a true one.
 *
 * Both answers are used to REJECT a payload, so both are deliberately
 * conservative: FALSE needs a clear violation, TRUE needs every usable sample
 * to hold, and the band between them returns null — unknown. Anything outside
 * the calculator grammar (two unknowns, prose, an inequality) is null too.
 * A guarantee that guessed would be worse than no guarantee at all.
 *
 * Shares the linear-notation rewriter with {@see PracticeCheck}, which settles
 * the neighbouring question ("does this ANSWER satisfy this problem?").
 */
class MathTruth
{
    /**
     * Sample points for an identity check. Deliberately awkward: whole numbers
     * make too many false identities accidentally true (x^2 = 2x holds at 2,
     * and 0 and 1 flatter almost anything).
     */
    private const SAMPLES = [0.37, 1.41, 2.72, 4.13, 7.61];

    /**
     * How many distinct unknowns the equation carries, or null when it is not
     * a single parseable equation.
     *
     * Callers need this to know how far to TRUST a false verdict. A line with
     * no unknown is pure arithmetic and a false verdict on it is a fact
     * ("sqrt{9+16} = 3+4" is simply wrong). A line WITH an unknown may be a
     * conditional equation rather than a claimed identity — "x^2 = 2x" is a
     * perfectly good line of working that happens to be false at most x — so
     * a false verdict there means much less.
     */
    public static function unknowns(string $equation): ?int
    {
        $sides = explode('=', $equation);
        if (count($sides) !== 2) {
            return null;
        }

        return count(self::letters(self::calc($sides[0]) . self::calc($sides[1])));
    }

    private static function calc(string $side): string
    {
        return PracticeCheck::calcSrc($side);
    }

    /** @return array<int, string> distinct unknown letters, lowercased. */
    private static function letters(string $src): array
    {
        $stripped = (string) preg_replace(
            '/(?<![A-Za-z])(?:sin|cos|tan|sqrt|abs|log|ln|exp|pi|e)(?![A-Za-z])/i',
            '',
            $src
        );
        if (!preg_match_all('/[A-Za-z]/', $stripped, $ls)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $ls[0])));
    }

    /**
     * True / false / null (not settleable here).
     */
    public static function holds(string $equation): ?bool
    {
        // Exactly one '=' and no inequality: this settles equations only.
        if (preg_match('/[<>≤≥≠]|!=|<=|>=/u', $equation)) {
            return null;
        }
        $sides = explode('=', $equation);
        if (count($sides) !== 2) {
            return null;
        }

        $lhsSrc = self::calc($sides[0]);
        $rhsSrc = self::calc($sides[1]);
        if ($lhsSrc === '' || $rhsSrc === '') {
            return null;
        }

        // At most ONE unknown, and it becomes x for the evaluator.
        $letters = self::letters($lhsSrc . $rhsSrc);
        if (count($letters) > 1) {
            return null;
        }
        if (count($letters) === 1) {
            $toX = fn (string $s): string => (string) preg_replace(
                '/(?<![A-Za-z])' . preg_quote($letters[0], '/') . '(?![A-Za-z])/i',
                'x',
                $s
            );
            $lhsSrc = $toX($lhsSrc);
            $rhsSrc = $toX($rhsSrc);
        }

        $lhs = TinyExpr::compile($lhsSrc);
        $rhs = TinyExpr::compile($rhsSrc);
        if ($lhs === null || $rhs === null) {
            return null;
        }

        // A constant equation is settled by one evaluation; an equation in x
        // needs the samples, and a sample that lands outside a domain (a log
        // of a negative, a division by zero) is skipped rather than counted.
        $points = $letters === [] ? [0.0] : self::SAMPLES;
        $usable = 0;
        foreach ($points as $x) {
            $l = $lhs($x);
            $r = $rhs($x);
            if (!is_finite($l) || !is_finite($r)) {
                continue;
            }
            $usable++;
            $tol = max(0.05, 0.01 * max(abs($l), abs($r)));
            if (abs($l - $r) > $tol) {
                return false; // one clear violation is a refutation
            }
        }

        if ($usable === 0 || ($letters !== [] && $usable < 2)) {
            return null; // not enough evidence to call it true
        }

        return true;
    }
}
