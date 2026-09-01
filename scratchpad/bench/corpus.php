<?php

/**
 * The bench corpus — 30 scripts, each with the storyboard a careful human
 * (Claude) would build from it.
 *
 * Item 5 of the 2026-08-31 user program: "you need to perform ultimate testing
 * atleast 20-30 scripts etc and you compare it with how claude would handle
 * this script". The comparison has to be written down BEFORE the run, or it is
 * not a comparison — it is a reading of whatever came back. So every case
 * carries a rubric authored from the script alone:
 *
 *   id             stable slug; the run files key on it
 *   title          what a user would have typed as the project title
 *   target_seconds the length the user asked for
 *   guide          optional user brief (only vreato-demo has one, on purpose)
 *   script         the pasted script, in the register users actually paste
 *   route_only     true for the routing probes: what is under test is which
 *                  pipeline the script lands in, not the card-by-card cast
 *
 *   rubric.shape      acceptable story spines. More than one is often
 *                     defensible; the score only punishes a spine the script
 *                     cannot support.
 *   rubric.scenes     [min, max] scene count for that length
 *   rubric.math_mode  whether MathTopicService should route it to the board.
 *                     The science probes are here to assert FALSE.
 *   rubric.must       groups of cards; at least one per group must be cast or
 *                     the beat it names was thrown away. This is the "how
 *                     would Claude storyboard it" half — each group names a
 *                     beat with a right answer in the registry.
 *   rubric.should     the same, scored as partial credit.
 *   rubric.never      card => why it would be wrong HERE. A cast is a real
 *                     defect (the project-42 timeline-of-non-dates failure).
 *   rubric.media_min  share of scenes that must carry an image/video slot.
 *   rubric.note       free text for the reader of the report.
 *
 * The rubrics are opinions, and they are meant to be argued with — but they
 * are FIXED opinions, written before any run, which is the only way a score
 * moving between runs means anything.
 */

$cases = array_merge(
    require __DIR__ . '/cases-1.php',
    require __DIR__ . '/cases-2.php'
);

// Fail loudly on a corpus mistake rather than scoring against a typo: every
// card named in a rubric must be a template the registry actually has.
$known = \Modules\Project\Support\ExplainerRegistry::templateNames();
$ids = [];
foreach ($cases as $case) {
    $id = (string) ($case['id'] ?? '');
    if ($id === '' || isset($ids[$id])) {
        throw new \RuntimeException("bench corpus: missing or duplicate id '{$id}'");
    }
    $ids[$id] = true;

    $named = [];
    foreach (($case['rubric']['must'] ?? []) as $g) {
        $named = array_merge($named, $g['cards']);
    }
    foreach (($case['rubric']['should'] ?? []) as $g) {
        $named = array_merge($named, $g['cards']);
    }
    $named = array_merge($named, array_keys($case['rubric']['never'] ?? []));
    foreach ($named as $tpl) {
        if (!in_array($tpl, $known, true)) {
            throw new \RuntimeException("bench corpus [{$id}]: unknown template '{$tpl}'");
        }
    }
}

return $cases;
