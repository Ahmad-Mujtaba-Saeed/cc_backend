<?php

namespace Modules\Project\Support;

/**
 * ChapterPlanValidator
 *
 * Hard-repairs the Composition Director's chapter grouping the same way
 * ShotListValidator repairs scenes: the LLM only gets a vote on WHERE the
 * chapter boundaries fall and which mode each run uses — never on scene
 * ordering or coverage. Mirrored by normalizeChapters() in the Remotion
 * renderer, so even a stale payload can't crash a render.
 *
 * Guarantees on output:
 *  - every scene id appears exactly once, in storyboard order;
 *  - chapters are contiguous runs;
 *  - mode ∈ registry chapter modes; canvas chapters have >= the registry
 *    minimum of scenes (a journey of one stop is just a slide);
 *  - chapter count <= registry max (smallest neighbours merged);
 *  - transition_in ∈ registry transitions ('none' on the first chapter, and
 *    on any chapter too short to afford the overlap);
 *  - the embedded per-chapter canvas plans are NOT built here — the
 *    CompositionDirectorService attaches them after validation.
 */
class ChapterPlanValidator
{
    /** @var string[] */
    private array $warnings = [];

    /**
     * @param  array  $plan  Raw LLM output: {chapters: [{id, mode, scene_ids, transition_in, reason}]}
     * @param  string[]  $sceneIds  Every scene id, in storyboard order.
     * @return array{plan: array, warnings: string[]}
     */
    public function validate(array $plan, array $sceneIds): array
    {
        $this->warnings = [];

        $declared = is_array($plan['chapters'] ?? null) ? array_values($plan['chapters']) : [];
        if (empty($declared) || empty($sceneIds)) {
            $this->warn('Chapter plan empty -> single-chapter fallback.');
            return ['plan' => $this->fallbackPlan($sceneIds), 'warnings' => $this->warnings];
        }

        $modes = ExplainerRegistry::chapterModes();
        $transitions = ExplainerRegistry::transitions();
        $minCanvas = ExplainerRegistry::minCanvasChapterScenes();

        // Which chapter index claims each scene — first claim wins.
        $claimedBy = [];
        foreach ($declared as $idx => $ch) {
            foreach ((array) ($ch['scene_ids'] ?? []) as $sid) {
                $sid = (string) $sid;
                if ($sid !== '' && !array_key_exists($sid, $claimedBy)) {
                    $claimedBy[$sid] = $idx;
                }
            }
        }

        // Walk scenes in storyboard order; split runs where the claim changes.
        // Unclaimed scenes stick to the current run.
        $runs = [];
        foreach ($sceneIds as $sid) {
            $idx = $claimedBy[$sid] ?? null;
            $last = count($runs) - 1;
            if ($last >= 0 && ($idx === null || $idx === $runs[$last]['idx'])) {
                $runs[$last]['scene_ids'][] = $sid;
            } elseif ($last >= 0 && $runs[$last]['idx'] === null && $idx !== null) {
                $runs[$last]['idx'] = $idx;
                $runs[$last]['scene_ids'][] = $sid;
            } else {
                $runs[] = ['idx' => $idx, 'scene_ids' => [$sid]];
            }
        }

        if (count($runs) !== count($declared)) {
            $this->warn('Chapter boundaries rebuilt from scene order (coverage/contiguity repair).');
        }

        // Materialise chapters from the runs.
        $chapters = [];
        foreach ($runs as $i => $run) {
            $source = $run['idx'] !== null ? ($declared[$run['idx']] ?? []) : [];

            $mode = (string) ($source['mode'] ?? 'slides');
            if (!in_array($mode, $modes, true)) {
                $this->warn("Chapter " . ($i + 1) . ": unknown mode \"{$mode}\" -> slides.");
                $mode = 'slides';
            }
            if ($mode === 'canvas' && count($run['scene_ids']) < $minCanvas) {
                $this->warn('Chapter ' . ($i + 1) . ': canvas chapter below minimum size -> slides.');
                $mode = 'slides';
            }

            $transition = (string) ($source['transition_in'] ?? ExplainerRegistry::defaultChapterTransition());
            if (!in_array($transition, $transitions, true)) {
                $transition = ExplainerRegistry::defaultChapterTransition();
            }

            $chapters[] = [
                'id' => 'ch_' . ($i + 1),
                'mode' => $mode,
                'scene_ids' => $run['scene_ids'],
                'transition_in' => $i === 0 ? 'none' : $transition,
                'reason' => mb_substr(trim((string) ($source['reason'] ?? '')), 0, 60),
            ];
        }

        // Cap the chapter count by merging the smallest chapter into its
        // smaller neighbour (mode of the larger side wins).
        $max = max(1, ExplainerRegistry::maxChapters());
        while (count($chapters) > $max) {
            $smallest = 0;
            foreach ($chapters as $i => $ch) {
                if (count($ch['scene_ids']) < count($chapters[$smallest]['scene_ids'])) {
                    $smallest = $i;
                }
            }
            $into = $smallest === 0 ? 1 : $smallest - 1;
            $merged = $chapters[min($into, $smallest)];
            $other = $chapters[max($into, $smallest)];
            $merged['scene_ids'] = array_merge($merged['scene_ids'], $other['scene_ids']);
            if (count($other['scene_ids']) > count($chapters[min($into, $smallest)]['scene_ids'])) {
                $merged['mode'] = $other['mode'];
            }
            array_splice($chapters, min($into, $smallest), 2, [$merged]);
            $this->warn('Merged chapters to respect the maximum of ' . $max . '.');
        }

        // Re-id after any merges and demote undersized canvas chapters again.
        foreach ($chapters as $i => &$ch) {
            $ch['id'] = 'ch_' . ($i + 1);
            if ($i === 0) {
                $ch['transition_in'] = 'none';
            }
            if ($ch['mode'] === 'canvas' && count($ch['scene_ids']) < $minCanvas) {
                $ch['mode'] = 'slides';
            }
        }
        unset($ch);

        return [
            'plan' => ['version' => 1, 'chapters' => $chapters],
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Chapter act breaks (copilot.md §5.5 / §3.4): insert a chapter_cover
     * scene at every chapter boundary as its own single-scene slides
     * mini-chapter, entered with the line_sweep act-break cut. Only fires on
     * videos of ≥ the registry minimum length with ≥ the minimum chapter
     * count; idempotent (an already-covered boundary is left alone); covers
     * carry NO narration — the video breathes there instead.
     *
     * @param  array  $plan    A validated chapter plan.
     * @param  array  $scenes  Full validated scene arrays, storyboard order.
     * @return array{plan: array, scenes: array, inserted: int}
     */
    public function insertCovers(array $plan, array $scenes): array
    {
        $conf = ExplainerRegistry::coversConfig();
        $chapters = is_array($plan['chapters'] ?? null) ? array_values($plan['chapters']) : [];

        $total = 0.0;
        foreach ($scenes as $s) {
            $total += (float) ($s['duration_seconds'] ?? 0);
        }
        if (count($chapters) < $conf['min_chapters'] || $total < $conf['min_video_seconds']) {
            return ['plan' => $plan, 'scenes' => $scenes, 'inserted' => 0];
        }

        $byId = [];
        foreach ($scenes as $s) {
            $byId[(string) $s['scene_id']] = $s;
        }

        $firstHeading = function (array $scene): string {
            foreach (($scene['slots'] ?? []) as $slot) {
                $h = trim((string) ($slot['heading'] ?? ''));
                if ($h !== '') {
                    return $h;
                }
            }
            return '';
        };

        $newChapters = [$chapters[0]];
        $insertBefore = []; // first scene id of a chapter => its cover scene
        $inserted = 0;

        for ($k = 1; $k < count($chapters); $k++) {
            $ch = $chapters[$k];
            $firstId = (string) ($ch['scene_ids'][0] ?? '');
            $first = $byId[$firstId] ?? null;

            // Never two covers in a row / never re-cover a covered boundary:
            // skip when this chapter opens on a cover OR the previous chapter
            // closes on one (i.e. it IS an already-inserted cover chapter).
            $prevIds = (array) ($chapters[$k - 1]['scene_ids'] ?? []);
            $prevLast = $byId[(string) end($prevIds)] ?? null;
            if (
                $first === null
                || ($first['layout_template'] ?? '') === 'chapter_cover'
                || ($prevLast['layout_template'] ?? '') === 'chapter_cover'
            ) {
                $newChapters[] = $ch;
                continue;
            }

            $ordinal = $k + 1;
            $coverId = "scene_cover_{$ordinal}";
            $title = mb_substr(
                trim((string) ($ch['reason'] ?? '')) ?: $firstHeading($first) ?: "Chapter {$ordinal}",
                0,
                48
            );

            $insertBefore[$firstId] = [
                'scene_id' => $coverId,
                'order' => 0, // renumbered below
                'duration_seconds' => $conf['duration_seconds'],
                'narration' => ['text' => ''],
                'layout_template' => 'chapter_cover',
                'slots' => ['slot_cover' => [
                    'content_type' => 'text_block',
                    'heading' => $title,
                    'bullets' => [],
                    'label' => 'Chapter ' . str_pad((string) $ordinal, 2, '0', STR_PAD_LEFT),
                    'reveal' => 'all_at_once',
                ]],
                'transition' => 'none',
                'relation' => 'new_chapter',
                'mood' => $first['mood'] ?? ExplainerRegistry::defaultMood(),
            ];

            $newChapters[] = [
                'id' => 'cover', // re-id'd below
                'mode' => 'slides',
                'scene_ids' => [$coverId],
                'transition_in' => 'line_sweep',
                'reason' => 'act break',
            ];
            $newChapters[] = $ch;
            $inserted++;
        }

        if ($inserted === 0) {
            return ['plan' => $plan, 'scenes' => $scenes, 'inserted' => 0];
        }

        // Splice covers into the scene list and renumber.
        $outScenes = [];
        foreach ($scenes as $s) {
            $sid = (string) $s['scene_id'];
            if (isset($insertBefore[$sid])) {
                $outScenes[] = $insertBefore[$sid];
            }
            $outScenes[] = $s;
        }
        foreach ($outScenes as $i => &$s) {
            $s['order'] = $i + 1;
        }
        unset($s);

        foreach ($newChapters as $i => &$ch) {
            $ch['id'] = 'ch_' . ($i + 1);
        }
        unset($ch);

        $plan['chapters'] = $newChapters;

        return ['plan' => $plan, 'scenes' => $outScenes, 'inserted' => $inserted];
    }

    /**
     * Deterministic fallback: one canvas chapter over everything — exactly the
     * pre-hybrid canvas_journey behaviour.
     *
     * @param  string[]  $sceneIds
     */
    public function fallbackPlan(array $sceneIds): array
    {
        return [
            'version' => 1,
            'chapters' => [
                [
                    'id' => 'ch_1',
                    'mode' => 'canvas',
                    'scene_ids' => array_values(array_map('strval', $sceneIds)),
                    'transition_in' => 'none',
                    'reason' => 'fallback',
                ],
            ],
        ];
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
