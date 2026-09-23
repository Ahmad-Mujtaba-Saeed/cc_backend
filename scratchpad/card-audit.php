<?php

/**
 * card-audit — did each beat get the card its CONTENT asked for?
 *
 *   docker compose exec app php scratchpad/card-audit.php 202 203 204 205
 *
 * Two questions, both answered from the storyboards themselves:
 *
 *  1. COVERAGE — of every card the registry ships, which ones ever appear?
 *     A card nobody casts is either badly described to the composer or not
 *     offered by the menu of any intent the planner actually writes.
 *  2. FIT — for each scene, what does the narration LOOK like it wants? The
 *     signals below are deliberately crude and deliberately conservative: they
 *     only fire on shapes a person would call obvious (two named options, a
 *     run of years, a list of steps, a share of a whole). Anything they cannot
 *     read is left alone rather than guessed at.
 *
 * No LLM, no network.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Project\Models\Project;
use Modules\Project\Support\ExplainerRegistry;

/** Cards that carry a beat's content; covers/outros are furniture. */
const FURNITURE = ['chapter_cover', 'outro_card'];

/** The generic cards — what a beat lands on when nothing better was chosen. */
const GENERIC = ['single_focus', 'full_bleed_with_side_panel', 'full_bleed_with_banner', 'image_grid'];

/**
 * What this beat looks like it wants, in priority order. Returns [card, why]
 * or null when the shape is not obvious enough to call.
 */
function wanted(string $narration, array $slots): ?array
{
    $n = ' ' . mb_strtolower($narration) . ' ';
    $has = fn (string $re) => preg_match($re, $n) === 1;
    $count = fn (string $re) => preg_match_all($re, $n);

    // A run of years, or an explicit decade: a timeline.
    if ($count('/\b(1[89]\d{2}|20[0-2]\d)\b/') >= 1 && $has('/\b(then|later|by|after|until|decade|century|years later)\b/')) {
        return ['timeline_card', 'dates + a sense of "then"'];
    }
    // Two named options weighed against each other.
    if ($has('/\b(versus|vs\.?)\b/') || ($has('/\b(renting|renter)\b/') && $has('/\b(buying|owner|buyer)\b/'))
        || $has('/\bon one side\b.*\bon the other\b/')) {
        return ['versus_card', 'two options weighed'];
    }
    // A belief being corrected.
    if ($has('/\b(myth|people think|everyone tells you|commonly believed|actually|in fact|is mostly wrong|nobody reads)\b/')
        && $has('/\b(but|however|really|truth)\b/')) {
        return ['myth_fact', 'a belief corrected'];
    }
    // The commonest mistakes.
    if ($has('/\b(mistake|gets? it wrong|the error|people forget|trap)\b/')) {
        return ['common_mistake', 'a named mistake'];
    }
    // An ordered procedure.
    if ($count('/\b(first|second|third|fourth|then|next|finally)\b/') >= 3) {
        return ['step_flow', 'an ordered procedure'];
    }
    // A whole split into named shares.
    if ($count('/\b(a third|a quarter|a fifth|half|percent|per cent)\b/') >= 2
        && $has('/\b(of (it|the|your|that)|goes to|slice|share|rest)\b/')) {
        return ['proportion_flow', 'a whole split into shares'];
    }
    // A single number that IS the beat.
    if ($has('/\b(about|roughly|around|nearly)\s+[\d.]+\s*(percent|per cent|million|billion|thousand|dollars|pounds|cents)\b/')
        && str_word_count($narration) < 40) {
        return ['stat_spotlight', 'one number carries the beat'];
    }
    // Two things of wildly different size.
    if ($has('/\b(six dollars a ton|sixteen cents|times (bigger|smaller|more|less)|compared with)\b/')) {
        return ['scale_comparison', 'two magnitudes compared'];
    }
    // A decision with conditions.
    if ($has('/\bif\b/') && $has('/\b(then|otherwise|depends on)\b/') && $count('/\bif\b/') >= 2) {
        return ['decision_tree', 'a conditional decision'];
    }
    // A thing whose PARTS are being named.
    if ($has('/\b(parts?|labell?ed|the top|underneath|on the back|diagram)\b/') && $has('/\b(serving size|ingredients|panel|label)\b/')) {
        return ['labeled_diagram', 'parts of one object named'];
    }
    // A list of actions to take.
    if ($has('/\b(so:|in that order|do this|steps?)\b/') && $count('/,/') >= 3) {
        return ['checklist_card', 'a list of actions'];
    }

    return null;
}

$ids = array_map('intval', array_slice($argv, 1));
if ($ids === []) {
    exit("usage: card-audit.php <project_id> [more ids]\n");
}

$used = [];
$mismatches = [];
$beats = 0;

foreach ($ids as $id) {
    $project = Project::find($id);
    if (!$project) {
        continue;
    }
    echo "\n=== {$id}: {$project->title}\n";
    foreach ($project->explainerScenes()->orderBy('order')->get() as $scene) {
        $template = (string) $scene->layout_template;
        $used[$template] = ($used[$template] ?? 0) + 1;
        if (in_array($template, FURNITURE, true)) {
            continue;
        }
        $beats++;
        $narration = (string) $scene->narration;
        $want = wanted($narration, (array) ($scene->slots ?? []));
        $flag = '   ';
        if ($want !== null && $want[0] !== $template) {
            // Only a real miss when the beat landed on a GENERIC card: a
            // different specific card is a judgement call, not a fault.
            if (in_array($template, GENERIC, true)) {
                $flag = ' ->';
                $mismatches[] = [$id, $scene->scene_id, $template, $want[0], $want[1], $narration];
            }
        }
        printf("%s %-28s %s\n", $flag, $template, mb_substr(preg_replace('/\s+/', ' ', $narration), 0, 92));
        if ($flag === ' ->') {
            printf("     wanted %s (%s)\n", $want[0], $want[1]);
        }
    }
}

echo "\n\n=== COVERAGE ===\n";
$all = array_keys(ExplainerRegistry::all()['templates']);
$never = array_values(array_diff($all, array_keys($used), FURNITURE));
arsort($used);
printf("%d of %d cards appeared across %d beats\n\n", count($used), count($all), $beats);
foreach ($used as $card => $n) {
    printf("  %-30s %d\n", $card, $n);
}
printf("\nnever cast (%d):\n", count($never));
foreach (array_chunk($never, 3) as $row) {
    echo '  ' . implode('  ', array_map(fn ($c) => str_pad($c, 28), $row)) . "\n";
}

printf("\n=== FIT: %d beats landed on a generic card when the content named one ===\n", count($mismatches));
foreach ($mismatches as [$id, $sceneId, $got, $want, $why, $narration]) {
    printf("  %d/%-9s %-26s -> %-20s %s\n", $id, $sceneId, $got, $want, $why);
}
