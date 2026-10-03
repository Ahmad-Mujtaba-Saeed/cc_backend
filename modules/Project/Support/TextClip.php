<?php

namespace Modules\Project\Support;

/**
 * Length caps that never cut a word in half.
 *
 * The validator used to cap every card field with a bare mb_substr, so a chart
 * label "Easy word, no eye contact" reached the screen as "Easy word, no ey",
 * a unit "performance" as "performa" and a timeline row "Harder questions" as
 * "Harder Questio" (project 211). A cap is a layout budget, not a place to
 * truncate language: this backs up to the last word boundary inside the
 * budget and drops a dangling connector or comma left at the end.
 *
 * A single token longer than the budget (a URL, a formula, a long compound)
 * still has to be cut — it is cut hard, exactly as before.
 */
final class TextClip
{
    /** Words that cannot end a label: "Easy word, no" reads as a typo. */
    private const DANGLING = [
        'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'for', 'with', 'in', 'on', 'at', 'by',
        'from', 'as', 'than', 'that', 'is', 'are', 'was', 'were', 'no', 'not', 'its', 'their', 'your', 'our',
    ];

    public static function clip(mixed $value, int $max): string
    {
        $s = trim((string) $value);
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        $head = mb_substr($s, 0, $max + 1);
        // The character right after the budget is a space: the budget ends
        // exactly on a word boundary, keep every word.
        $cut = mb_substr($head, $max, 1) === ' ' ? $max : null;
        if ($cut === null) {
            $lastSpace = mb_strrpos(mb_substr($head, 0, $max), ' ');
            // Only back up to a boundary that keeps most of the budget; a
            // 40-char token behind one short word is better cut hard.
            if ($lastSpace !== false && $lastSpace >= (int) floor($max * 0.45)) {
                $cut = $lastSpace;
            }
        }
        if ($cut === null) {
            return rtrim(mb_substr($s, 0, $max));
        }

        $out = rtrim(mb_substr($s, 0, $cut));
        for ($i = 0; $i < 3; $i++) {
            $out = rtrim($out, " \t,;:-–—/(&");
            $words = preg_split('/\s+/u', $out) ?: [];
            if (count($words) < 2 || !in_array(mb_strtolower(end($words)), self::DANGLING, true)) {
                break;
            }
            array_pop($words);
            $out = implode(' ', $words);
        }

        return $out !== '' ? $out : rtrim(mb_substr($s, 0, $max));
    }
}
