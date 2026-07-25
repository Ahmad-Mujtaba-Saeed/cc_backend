<?php

namespace Modules\Project\Support;

/**
 * ScenarioLayout — which SHAPE a scenario_diagram sketch takes.
 *
 * A projectile drawn as a straight left-to-right chain is a wrong drawing:
 * the whole point of the setup sketch is that it looks like the situation.
 * The renderer supports four shapes; the model is asked to pick one, and
 * when it doesn't, the shape is inferred from the problem's own words —
 * deterministically, so a thrown ball can never end up flat again.
 *
 *   line    — the default: A→B distances, two things approaching, sequences.
 *   arc     — up and back down: projectiles, thrown/kicked/launched things.
 *   climb   — rising: rockets ascending, tanks filling, growth to a peak.
 *   fall    — dropping: released objects, draining, descent.
 *   compare — two (or more) things run side by side on parallel lanes:
 *             A vs B, faster/cheaper/bigger, "which one …", "when are they equal".
 *   split   — one source fans out into outcomes: probability trees, the ways
 *             a thing can happen, work shared between helpers, a split of a whole.
 *   cycle   — a closed loop of stages that returns to where it began: a
 *             repeating process, a clock/orbit/lap, "back to the start".
 */
class ScenarioLayout
{
    public const KINDS = ['line', 'arc', 'climb', 'fall', 'compare', 'split', 'cycle'];

    /**
     * Normalize an explicit choice, or infer one from the scenario's text
     * (labels, values, question, heading, narration — whatever the caller
     * has). Always returns a member of KINDS.
     */
    public static function resolve(?string $explicit, string $text): string
    {
        $explicit = strtolower(trim((string) $explicit));
        if (in_array($explicit, self::KINDS, true)) {
            return $explicit;
        }

        return self::infer($text);
    }

    /** Keyword inference. Order matters: arc first — a projectile's text
     *  mentions both rising AND falling, and the arc IS both. The three
     *  non-motion shapes (cycle/split/compare) are matched on deliberately
     *  narrow, unambiguous language so the classic "two cars approaching"
     *  and "A→B distance" problems stay on the default line. */
    public static function infer(string $text): string
    {
        if (preg_match('/projectile|thrown|throw|toss|kick|launch|fired|catapult|trajectory|\bpeak\b|\bapex\b|max(imum)?\s*height|h\s*max|ball.*\b(air|up)\b/i', $text)) {
            return 'arc';
        }
        // Closed loop: something that comes back to where it started. "orbit"
        // and "revolution" are unambiguous; "loop"/"cycle"/"lap" too.
        if (preg_match('/\bcycle(s|d|ing)?\b|\bloop(s|ed|ing)?\b|\borbit(s|ing)?\b|revolution|\blap(s)?\b|\brepeat(s|ed|ing)?\b|round\s*trip|back\s+to\s+(the\s+)?(start|beginning)|returns?\s+to\s+(its|the)\s+start|recurring|periodic/i', $text)) {
            return 'cycle';
        }
        // One source fanning out into outcomes/branches.
        if (preg_match('/probabilit|\bchance\b|tree\s*diagram|\boutcomes?\b|number\s+of\s+ways|\bways\s+to\b|branch(es|ing)?|splits?\s+into|shared?\s+(between|among)|divided?\s+(between|among)|each\s+(child|person|group)\s+gets/i', $text)) {
            return 'split';
        }
        // Two rivals measured against each other.
        if (preg_match('/\bversus\b|\bvs\.?\b|compared?\s+to|which\s+(one\s+)?is\s+(faster|slower|cheaper|bigger|smaller|better|more|cheapest|fastest)|who\s+(wins|is\s+faster)|\bcheaper\b|better\s+(deal|value|buy)|which\s+plan|which\s+option/i', $text)) {
            return 'compare';
        }
        if (preg_match('/\bfall(s|ing|en)?\b|\bdrop(s|ped|ping)?\b|descend|\bdrain(s|ing)?\b|\bsink(s|ing)?\b/i', $text)) {
            return 'fall';
        }
        if (preg_match('/\brise(s|n|ing)?\b|\bclimb(s|ed|ing)?\b|ascend|\bfill(s|ed|ing)?\b|growth|\bgrow(s|ing)?\b|increas/i', $text)) {
            return 'climb';
        }

        return 'line';
    }
}
