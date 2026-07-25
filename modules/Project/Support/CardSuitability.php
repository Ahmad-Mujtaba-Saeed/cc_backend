<?php

namespace Modules\Project\Support;

/**
 * Does this beat actually contain what its card needs?
 *
 * The validator already guarantees card SHAPE — a timeline has >=2 nodes, a
 * chart has >=2 numbers — but shape is not suitability. Project 42 cast
 * `timeline_card` on "ranked by relevance, page speed, content quality": a
 * criteria LIST with no chronology in it. The payload was structurally legal,
 * so every clamp passed it through and the viewer got a timeline of non-dates.
 *
 * This is the content precondition the casting menus never had. It is
 * deliberately NARROW and high-precision: only cards whose whole premise is a
 * kind of data are gated, and each test asks for the weakest possible evidence
 * that the data is really there. A false degrade costs a good card; a missed
 * one ships a nonsense frame, but degrading honest content would be worse, so
 * every rule errs toward keeping the card.
 *
 * Everything else is already covered upstream and must NOT be duplicated here —
 * a second rule with a different degrade target is a regression, not a
 * backstop. normalizeCardScene already refuses a `big_counter` whose heading
 * carries no figure (the other half of the project-42 report), clampChartContent
 * refuses a chart without >=2 numerics, clampPictogramContent needs a share.
 */
class CardSuitability
{
    /**
     * Era markers that carry chronology without a numeral ("Today", "Ancient
     * Rome"). Deliberately short — a vague noun is not a date.
     */
    private const ERA_WORDS = [
        'today', 'now', 'present', 'nowadays', 'future', 'tomorrow', 'yesterday',
        'ancient', 'antiquity', 'prehistory', 'prehistoric', 'medieval', 'modern',
    ];

    /**
     * Does this text read as a point in time? Any ONE of: a 3-4 digit year or
     * decade, a BC/AD year, a month name, a quarter, a "Day 3"-style ordinal
     * period, a numeric date, an Nth century, a circa year, or an era word.
     */
    public static function looksChronological(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }

        $patterns = [
            '/\b\d{3,4}s?\b/',                                   // 1969, 1990s, 800
            '/\b\d{1,4}\s*(bc|bce|ad|ce)\b/',                    // 44 BC, 1200 CE
            '/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)/', // Mar 2024, January
            '/\bq[1-4]\b/',                                      // Q3
            '/\b(day|week|month|year|hour|minute|phase|stage|round|era|age)\s*\d+/', // Day 3
            '/\b\d{1,2}[\/\-.]\d{1,2}([\/\-.]\d{2,4})?\b/',      // 3/2024, 12-05
            '/\b\d{1,2}(st|nd|rd|th)\s+century\b/',              // 19th century
            '/\bc\.?\s*\d{3,4}\b/',                              // c. 1500
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $t) === 1) {
                return true;
            }
        }

        foreach (self::ERA_WORDS as $w) {
            if (str_contains($t, $w)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why this template cannot carry this scene's content, or null when the
     * cast is sound. The string is used verbatim in the validator warning.
     */
    public static function unfitReason(string $template, array $slots): ?string
    {
        switch ($template) {
            case 'timeline_card':
                return self::timelineReason($slots['slot_timeline'] ?? []);
        }

        return null;
    }

    /**
     * A timeline needs CHRONOLOGY, not just rows. At least 2 nodes must carry
     * a time marker — one dated node among five unlabelled ones is a list that
     * happens to mention a year.
     *
     * The marker is looked for in the node's `date` first and its `label`
     * second: models routinely write {date: "", label: "1969 — the landing"},
     * and degrading that would be a false positive on a real timeline.
     */
    private static function timelineReason($slot): ?string
    {
        if (!is_array($slot)) {
            return null;
        }
        $nodes = (array) ($slot['nodes'] ?? []);
        if (count($nodes) < 2) {
            return null; // shape problem, not suitability — clamps own this
        }

        $dated = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $date = (string) ($node['date'] ?? '');
            $label = (string) ($node['label'] ?? '');
            if (self::looksChronological($date) || self::looksChronological($label)) {
                $dated++;
            }
        }

        if ($dated >= 2) {
            return null;
        }

        return "timeline nodes carry no dates ({$dated} of " . count($nodes) . ' are chronological)';
    }

}
