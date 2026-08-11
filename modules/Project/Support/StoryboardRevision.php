<?php

namespace Modules\Project\Support;

/**
 * StoryboardRevision — the deterministic half of "tell the AI what to change".
 *
 * A storyboard the user cannot edit is only half a storyboard: they can see
 * that scene 4's chart is wrong, and their only lever is Re-analyze, which
 * throws the whole video away — every other card, every upload, every second
 * of cached voiceover. So the revision flow asks for a NOTE ("scene 4 should
 * be a comparison, and drop the timeline") and changes only the cards the note
 * is about.
 *
 * Guarantees over prompts (the v23 lesson, the same shape as
 * StoryboardTextReviewService): the model NOMINATES a small op list and then
 * writes replacement cards; everything that makes the result safe is here and
 * is pure:
 *
 *   · an op naming a scene that does not exist is refused, not guessed;
 *   · the auto-inserted system cards (outro, chapter covers) can be removed
 *     but never rewritten — the validator would silently recast them;
 *   · a revision can never empty the video (MIN_SCENES survive) and can never
 *     touch more than MAX_TOUCHED cards in one go;
 *   · an edit keeps its scene_id, so the row is UPDATED in place and every
 *     asset keyed to it stays keyed to it;
 *   · a draft that comes back without narration or slots is refused and the
 *     old card is kept, so a bad answer costs nothing;
 *   · per-video template caps are counted with the UNTOUCHED scenes owning
 *     their quota first, so an over-cap card is degraded on the scene the
 *     user changed — never on a bystander.
 *
 * Refusals are not silent: each becomes a finding the storyboard shows, in
 * the same shape as the lint report.
 */
final class StoryboardRevision
{
    /** Auto-inserted cards. Deletable on request, never rewritten. */
    public const SYSTEM_TEMPLATES = ['outro_card', 'chapter_cover'];

    /** Most cards one revision may rewrite or add. A note that wants more is a re-analysis. */
    public const MAX_TOUCHED = 12;

    /** Most cards one revision may remove. */
    public const MAX_DELETES = 6;

    /** A video must survive its own edit. */
    public const MIN_SCENES = 2;

    /** Most new cards one revision may add. */
    public const MAX_INSERTS = 6;

    // ------------------------------------------------------------- digesting

    /**
     * The storyboard as the planner sees it: enough to recognise a card the
     * user is describing ("the chart one", "the intro"), not so much that a
     * twenty-scene board blows the context.
     *
     * @param array $scenes  validator-shaped scenes, storyboard order
     * @param array<string,string> $uploads "scene::slot" => file name
     */
    public static function digest(array $scenes, array $uploads = []): string
    {
        $out = '';
        foreach (array_values($scenes) as $i => $scene) {
            $id = (string) ($scene['scene_id'] ?? '');
            $tpl = (string) ($scene['layout_template'] ?? '');
            $system = in_array($tpl, self::SYSTEM_TEMPLATES, true) ? '  [SYSTEM — do not rewrite]' : '';

            $out .= sprintf(
                "#%d %s [%s] %.1fs%s\n",
                $i + 1,
                $id,
                $tpl,
                (float) ($scene['duration_seconds'] ?? 0),
                $system
            );

            $narration = trim(self::narrationOf($scene));
            if ($narration !== '') {
                $out .= '   says: "' . mb_substr($narration, 0, 260) . "\"\n";
            }

            foreach ((array) ($scene['slots'] ?? []) as $key => $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                $out .= '   ' . $key . ': ' . self::slotSummary($slot) . "\n";
                if (isset($uploads[$id . '::' . $key])) {
                    // The single most important fact for the planner: rewriting
                    // this card throws the user's own file away.
                    $out .= '      ^ THE USER UPLOADED A FILE HERE ("'
                        . mb_substr($uploads[$id . '::' . $key], 0, 60) . "\")\n";
                }
            }
        }

        return $out;
    }

    /** One compact line describing a slot's current content. */
    public static function slotSummary(array $slot): string
    {
        $type = (string) ($slot['content_type'] ?? '?');

        if (in_array($type, ['image', 'video'], true)) {
            $want = trim((string) ($slot['asset_request']['description'] ?? ''));
            $stock = trim((string) ($slot['stock_query'] ?? ''));
            $note = $stock !== '' ? "stock footage of \"{$stock}\"" : ($want !== '' ? "\"{$want}\"" : 'unspecified');

            return "{$type} — {$note}";
        }

        // Everything else is structured content the renderer draws: the JSON
        // itself is the clearest possible summary, and the planner only has to
        // recognise it, not reproduce it.
        $body = $slot;
        unset($body['content_type'], $body['dock'], $body['width_pct'], $body['camera_move']);
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $type . ' — ' . mb_substr((string) $json, 0, 260);
    }

    /** The full body of one scene, for the call that rewrites it. */
    public static function sceneJson(array $scene): string
    {
        return (string) json_encode([
            'scene_id' => (string) ($scene['scene_id'] ?? ''),
            'layout_template' => (string) ($scene['layout_template'] ?? ''),
            'seconds' => round((float) ($scene['duration_seconds'] ?? 0), 1),
            'narration' => self::narrationOf($scene),
            'slots' => (array) ($scene['slots'] ?? []),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------- planning

    /**
     * Clamp the model's op list into something legal and minimal.
     *
     * @param array $raw     the model's `ops`
     * @param array $scenes  the CURRENT storyboard
     * @return array{ops: array, findings: array}
     */
    public static function normalizePlan(array $raw, array $scenes): array
    {
        $byId = [];
        foreach ($scenes as $scene) {
            $byId[(string) ($scene['scene_id'] ?? '')] = $scene;
        }
        $contentScenes = 0;
        foreach ($scenes as $scene) {
            if (!in_array((string) ($scene['layout_template'] ?? ''), self::SYSTEM_TEMPLATES, true)) {
                $contentScenes++;
            }
        }

        $findings = [];
        $refuse = function (string $what, string $why, ?string $sceneId = null) use (&$findings): void {
            $findings[] = [
                'severity' => 'info',
                'code' => 'revision_refused',
                'scene_id' => $sceneId,
                'message' => "Skipped \"{$what}\": {$why}.",
            ];
        };

        $edits = [];
        $deletes = [];
        $moves = [];
        $inserts = [];
        $seenEdit = [];
        $seenMove = [];

        foreach ($raw as $op) {
            if (!is_array($op)) {
                continue;
            }
            $kind = strtolower(trim((string) ($op['op'] ?? '')));
            $sceneId = (string) ($op['scene_id'] ?? '');
            $instruction = trim((string) ($op['instruction'] ?? ''));
            $scene = $byId[$sceneId] ?? null;
            $isSystem = $scene !== null
                && in_array((string) ($scene['layout_template'] ?? ''), self::SYSTEM_TEMPLATES, true);

            switch ($kind) {
                case 'edit':
                    if ($scene === null) {
                        $refuse("edit {$sceneId}", 'there is no such scene');
                        break;
                    }
                    if ($isSystem) {
                        $refuse("edit {$sceneId}", 'the end card and chapter covers are generated, not written', $sceneId);
                        break;
                    }
                    if (isset($seenEdit[$sceneId])) {
                        break; // one rewrite per scene; the first wins
                    }
                    if (count($edits) + count($inserts) >= self::MAX_TOUCHED) {
                        $refuse("edit {$sceneId}", 'one revision may change at most ' . self::MAX_TOUCHED . ' cards', $sceneId);
                        break;
                    }
                    $seenEdit[$sceneId] = true;
                    $edits[] = ['op' => 'edit', 'scene_id' => $sceneId, 'key' => $sceneId, 'instruction' => $instruction];
                    break;

                case 'delete':
                case 'remove':
                    if ($scene === null) {
                        $refuse("remove {$sceneId}", 'there is no such scene');
                        break;
                    }
                    if (isset($deletes[$sceneId])) {
                        break;
                    }
                    if (count($deletes) >= self::MAX_DELETES) {
                        $refuse("remove {$sceneId}", 'one revision may remove at most ' . self::MAX_DELETES . ' cards', $sceneId);
                        break;
                    }
                    // Never leave a video that cannot be watched. System cards
                    // are not content, so removing the end card never counts
                    // against the floor.
                    if (!$isSystem) {
                        if ($contentScenes - 1 < self::MIN_SCENES) {
                            $refuse("remove {$sceneId}", 'a video needs at least ' . self::MIN_SCENES . ' scenes', $sceneId);
                            break;
                        }
                        $contentScenes--;
                    }
                    $deletes[$sceneId] = ['op' => 'delete', 'scene_id' => $sceneId];
                    break;

                case 'move':
                    if ($scene === null) {
                        $refuse("move {$sceneId}", 'there is no such scene');
                        break;
                    }
                    if ($isSystem) {
                        $refuse("move {$sceneId}", 'the end card and chapter covers are placed automatically', $sceneId);
                        break;
                    }
                    if (isset($seenMove[$sceneId])) {
                        break;
                    }
                    $after = self::anchor($op, $byId);
                    if ($after === $sceneId) {
                        $refuse("move {$sceneId}", 'a scene cannot be placed after itself', $sceneId);
                        break;
                    }
                    if ($after === null) {
                        $refuse("move {$sceneId}", 'the scene it should follow does not exist', $sceneId);
                        break;
                    }
                    $seenMove[$sceneId] = true;
                    $moves[] = ['op' => 'move', 'scene_id' => $sceneId, 'after' => $after];
                    break;

                case 'insert':
                case 'add':
                    if ($instruction === '') {
                        $refuse('add a scene', 'no brief was given for it');
                        break;
                    }
                    if (count($inserts) >= self::MAX_INSERTS
                        || count($edits) + count($inserts) >= self::MAX_TOUCHED
                    ) {
                        $refuse('add a scene', 'one revision may add at most ' . self::MAX_INSERTS . ' cards');
                        break;
                    }
                    $after = self::anchor($op, $byId);
                    if ($after === null) {
                        $refuse('add a scene', 'the scene it should follow does not exist');
                        break;
                    }
                    $inserts[] = [
                        'op' => 'insert',
                        'after' => $after,
                        'key' => 'new_' . (count($inserts) + 1),
                        'instruction' => $instruction,
                    ];
                    break;

                default:
                    // An invented op earns no finding — it is noise, not intent.
                    break;
            }
        }

        // A scene being removed does not also get rewritten or moved.
        $edits = array_values(array_filter($edits, fn ($e) => !isset($deletes[$e['scene_id']])));
        $moves = array_values(array_filter($moves, fn ($m) => !isset($deletes[$m['scene_id']])));

        return [
            'ops' => array_merge($edits, $inserts, array_values($deletes), $moves),
            'findings' => $findings,
        ];
    }

    /** Resolve an op's anchor: an existing scene id, or '' meaning "at the very start". */
    private static function anchor(array $op, array $byId): ?string
    {
        $after = trim((string) ($op['after'] ?? ''));
        if ($after === '' || strtolower($after) === 'start' || strtolower($after) === 'none') {
            return '';
        }

        return isset($byId[$after]) ? $after : null;
    }

    // ------------------------------------------------------------- applying

    /**
     * Assemble the revised storyboard.
     *
     * @param array $scenes    the CURRENT storyboard (validator-shaped)
     * @param array $ops       a normalizePlan() op list
     * @param array $drafts    key => raw scene body from the rewrite call
     * @param ShotListValidator $validator  validates each rewritten card
     * @param array $options   math_mode, aspect_ratio, revision (int, for new ids),
     *                         final (bool) — see below
     *
     * Two of the rules have a first-pass and a last-pass form. A draft that
     * changed the card without writing new narration, or that left a picture
     * panel with nothing to shoot, is REFUSED on the first pass so the caller
     * can name the fault and ask again; on the final pass the same draft is
     * accepted and repaired deterministically, because "we asked twice and
     * gave up" must not mean the user's request quietly did nothing.
     *
     * @return array{
     *   scenes: array, changed: string[], added: string[], removed: string[],
     *   moved: string[], findings: array, summary: string,
     *   rejected: array<string,string>
     * }
     */
    public static function apply(
        array $scenes,
        array $ops,
        array $drafts,
        ShotListValidator $validator,
        array $options = []
    ): array {
        $mathMode = ($options['math_mode'] ?? false) === true;
        $vOptions = ['math_mode' => $mathMode, 'aspect_ratio' => (string) ($options['aspect_ratio'] ?? '16:9')];
        $revision = max(1, (int) ($options['revision'] ?? 1));
        $final = ($options['final'] ?? false) === true;

        $findings = [];
        $refuse = function (string $message, ?string $sceneId = null) use (&$findings): void {
            $findings[] = [
                'severity' => 'info',
                'code' => 'revision_refused',
                'scene_id' => $sceneId,
                'message' => $message,
            ];
        };

        $byIndex = [];
        foreach (array_values($scenes) as $i => $scene) {
            $byIndex[(string) ($scene['scene_id'] ?? '')] = $i;
        }
        $scenes = array_values($scenes);

        $changed = [];
        $added = [];
        $removed = [];
        $moved = [];
        $rejected = [];

        /**
         * Did the validator have to recast the card the draft asked for?
         *
         * It only ever changes layout_template when the content it was given
         * is unusable, and the result is reliably worse than what the user
         * already had: a versus_card whose comparison did not parse comes back
         * as a two-panel split holding the SAME sentence twice. So a recast is
         * treated as a failed draft — the old card stays, the caller gets one
         * chance to try again with the reason named, and the user is told if
         * it still cannot be built.
         */
        /**
         * Check a validated card, and on the final pass repair what can be
         * repaired. Returns the (possibly repaired) scene plus a rejection
         * reason, which is null once the scene is fit to ship.
         *
         * @return array{0: array, 1: string|null}
         */
        $vet = function (array $validated, string $requested) use ($final, &$findings): array {
            $got = (string) ($validated['layout_template'] ?? '');
            if ($got !== $requested) {
                // The validator only ever recasts a card whose content did not
                // parse, and the result is reliably worse than what was there
                // (a versus_card that failed to parse comes back as a split
                // holding the SAME sentence twice). Nothing to repair.
                return [$validated, "the content sent for \"{$requested}\" was not usable, so it collapsed into \"{$got}\""];
            }

            // A media slot the draft forgot is back-filled by the validator
            // with a generic placeholder — and that placeholder becomes the
            // IMAGE PROMPT verbatim, so the card would ship with an AI picture
            // of "Background visual for this scene" and an upload box asking
            // the user for one. Caught live on a versus_card that answered
            // with its comparison and neither panel.
            $blank = self::blankMediaSlots($validated);
            if ($blank !== []) {
                if (!$final) {
                    return [$validated, 'you left ' . implode(' and ', $blank)
                        . ' without an asset_request.description, and "' . $requested . '" needs one for every '
                        . 'picture panel — name a real photographable subject for each'];
                }
                $validated = self::describeBlankMedia($validated, $blank);
                $findings[] = [
                    'severity' => 'info',
                    'code' => 'revision_media_guessed',
                    'scene_id' => (string) ($validated['scene_id'] ?? ''),
                    'message' => 'The picture panel(s) on scene ' . ($validated['scene_id'] ?? '')
                        . ' came back without a description, so each one is asking for its own label — '
                        . 'check what it wants before you upload or generate.',
                ];
            }

            return [$validated, null];
        };

        // ---- 1. rewrites (in place: the scene_id, and therefore every asset
        //         keyed to it, survives untouched) ---------------------------
        foreach ($ops as $op) {
            if (($op['op'] ?? '') !== 'edit') {
                continue;
            }
            $sceneId = (string) $op['scene_id'];
            $idx = $byIndex[$sceneId] ?? null;
            if ($idx === null) {
                continue;
            }
            $old = $scenes[$idx];
            $draft = $drafts[$sceneId] ?? null;
            if (!is_array($draft)) {
                $refuse("Scene {$sceneId} was left as it was — the rewrite came back empty.", $sceneId);
                continue;
            }

            $built = self::buildRaw($draft, $old, $sceneId, false, $final);
            if ($built['raw'] === null) {
                $refuse("Scene {$sceneId} was left as it was — {$built['error']}.", $sceneId);
                if ($built['retryable']) {
                    $rejected[$sceneId] = $built['error'];
                }
                continue;
            }
            if ($built['warning'] !== null) {
                $findings[] = [
                    'severity' => 'warn',
                    'code' => 'revision_narration_stale',
                    'scene_id' => $sceneId,
                    'message' => "Scene {$sceneId} has a new card but its old voiceover — {$built['warning']}",
                ];
            }
            $built = $built['raw'];

            [$next, $reason] = $vet(
                $validator->validateOne($built, (int) ($old['order'] ?? $idx + 1), $vOptions),
                (string) $built['layout_template']
            );
            if ($reason !== null) {
                $rejected[$sceneId] = $reason;
                $refuse("Scene {$sceneId} was left as it was — {$reason}.", $sceneId);
                continue;
            }

            if (self::identical($old, $next)) {
                // Nothing actually moved. Saying "changed" would be a lie, and
                // marking it changed would needlessly re-bill its narration.
                continue;
            }
            $scenes[$idx] = $next;
            $changed[] = $sceneId;
        }

        // ---- 2. removals -----------------------------------------------------
        $dropped = [];
        foreach ($ops as $op) {
            if (($op['op'] ?? '') === 'delete') {
                $dropped[(string) $op['scene_id']] = true;
            }
        }
        if ($dropped !== []) {
            $kept = [];
            foreach ($scenes as $scene) {
                $id = (string) $scene['scene_id'];
                if (isset($dropped[$id])) {
                    $removed[] = $id;
                    continue;
                }
                $kept[] = $scene;
            }
            $scenes = $kept;
            $changed = array_values(array_diff($changed, $removed));
        }

        // ---- 3. re-ordering ---------------------------------------------------
        foreach ($ops as $op) {
            if (($op['op'] ?? '') !== 'move') {
                continue;
            }
            $sceneId = (string) $op['scene_id'];
            $after = (string) $op['after'];
            if (isset($dropped[$sceneId]) || ($after !== '' && isset($dropped[$after]))) {
                continue;
            }
            $scenes = self::relocate($scenes, $sceneId, $after);
            $moved[] = $sceneId;
        }

        // ---- 4. new cards ------------------------------------------------------
        $existing = [];
        foreach ($scenes as $scene) {
            $existing[(string) $scene['scene_id']] = true;
        }
        $n = 0;
        foreach ($ops as $op) {
            if (($op['op'] ?? '') !== 'insert') {
                continue;
            }
            $draft = $drafts[(string) $op['key']] ?? null;
            if (!is_array($draft)) {
                $refuse('A new scene was requested but came back empty, so nothing was added.');
                continue;
            }

            do {
                $n++;
                $newId = "scene_r{$revision}_{$n}";
            } while (isset($existing[$newId]));
            $existing[$newId] = true;

            $built = self::buildRaw($draft, [], $newId, true, $final);
            if ($built['raw'] === null) {
                $refuse("A new scene was skipped — {$built['error']}.");
                if ($built['retryable']) {
                    $rejected[(string) $op['key']] = $built['error'];
                }
                unset($existing[$newId]);
                continue;
            }
            $built = $built['raw'];

            [$scene, $reason] = $vet(
                $validator->validateOne($built, count($scenes) + 1, $vOptions),
                (string) $built['layout_template']
            );
            if ($reason !== null) {
                $rejected[(string) $op['key']] = $reason;
                $refuse("A new scene could not be added — {$reason}.");
                unset($existing[$newId]);
                continue;
            }

            $after = (string) $op['after'];
            $scenes = self::insertAfter($scenes, $scene, isset($dropped[$after]) ? '' : $after);
            $added[] = $newId;
        }

        // ---- 5. per-video caps, charged to the scenes the user changed --------
        $touched = array_flip(array_merge($changed, $added));
        $counts = [];
        foreach ($scenes as $scene) {
            $tpl = (string) $scene['layout_template'];
            if (!isset($touched[(string) $scene['scene_id']])) {
                $counts[$tpl] = ($counts[$tpl] ?? 0) + 1;
            }
        }
        foreach ($scenes as $i => $scene) {
            $id = (string) $scene['scene_id'];
            if (!isset($touched[$id])) {
                continue;
            }
            $tpl = (string) $scene['layout_template'];
            $max = $mathMode
                ? ExplainerRegistry::mathModeMaxPerVideo($tpl)
                : ExplainerRegistry::maxPerVideo($tpl);
            if ($max !== null && ($counts[$tpl] ?? 0) + 1 > $max) {
                $scenes[$i] = $validator->degradeCard($scene);
                $findings[] = [
                    'severity' => 'info',
                    'code' => 'revision_capped',
                    'scene_id' => $id,
                    'message' => "This video already uses \"{$tpl}\" {$max} time(s), so scene {$id} was recast as \""
                        . $scenes[$i]['layout_template'] . '" with the same content.',
                ];
                $tpl = (string) $scenes[$i]['layout_template'];
            }
            $counts[$tpl] = ($counts[$tpl] ?? 0) + 1;
        }

        // ---- 6. the end card is always last -----------------------------------
        // A new scene anchored "after the ending", or an ending dragged up by a
        // move, would put content behind the outro where nobody sees it.
        // appendOutro guarantees this for a fresh storyboard; a revision has to
        // re-assert it because it never re-runs that pass.
        $outro = [];
        $rest = [];
        foreach ($scenes as $scene) {
            if ((string) ($scene['layout_template'] ?? '') === 'outro_card') {
                $outro[] = $scene;
                continue;
            }
            $rest[] = $scene;
        }
        $scenes = array_merge($rest, $outro);

        // ---- 7. renumber ------------------------------------------------------
        foreach ($scenes as $i => &$scene) {
            $scene['order'] = $i + 1;
        }
        unset($scene);

        return [
            'scenes' => array_values($scenes),
            'changed' => array_values(array_unique($changed)),
            'added' => array_values(array_unique($added)),
            'removed' => array_values(array_unique($removed)),
            'moved' => array_values(array_unique($moved)),
            'findings' => $findings,
            'rejected' => $rejected,
            'summary' => self::summarize($changed, $added, $removed, $moved),
        ];
    }

    /**
     * Turn one model draft into a raw scene the validator can chew, inheriting
     * everything the draft did not speak about from the card being replaced.
     *
     * @return array{raw: array|null, error: string|null, retryable: bool, warning: string|null}
     *         raw null = keep what was there; `retryable` marks the failures
     *         worth naming back to the model for one more attempt (as opposed
     *         to "it sent the same card again", which a retry cannot improve)
     */
    private static function buildRaw(
        array $draft,
        array $old,
        string $sceneId,
        bool $isNew = false,
        bool $final = false
    ): array {
        $reject = fn (string $error, bool $retryable = true) => [
            'raw' => null, 'error' => $error, 'retryable' => $retryable, 'warning' => null,
        ];
        $warning = null;

        $template = trim((string) ($draft['layout_template'] ?? ''));
        if ($template === '') {
            $template = (string) ($old['layout_template'] ?? '');
        }
        if (in_array($template, self::SYSTEM_TEMPLATES, true)) {
            return $reject("\"{$template}\" is inserted automatically and cannot be chosen; pick a different card");
        }
        if (!ExplainerRegistry::hasTemplate($template)) {
            return $reject("\"{$template}\" is not a card this system can draw; pick one from the list");
        }

        $oldNarration = trim(self::narrationOf($old));
        $narration = trim((string) ($draft['narration'] ?? ''));
        $slots = is_array($draft['slots'] ?? null) ? $draft['slots'] : [];
        $sameCard = $template === (string) ($old['layout_template'] ?? '');

        if ($isNew && ($narration === '' || $slots === [])) {
            return $reject('it came back with no narration or no content');
        }
        if (!$isNew && $slots === [] && ($narration === '' || $narration === $oldNarration)) {
            return $reject('the rewrite changed nothing', false);
        }
        // A different card under the old voice contradicts itself: the viewer
        // hears the content that was just replaced. Caught live — a
        // single_focus about "no moving parts" became a speed/price/durability
        // comparison and went on talking about moving parts.
        //
        // Worth one more ask, but not worth abandoning the user's request
        // over: on the last pass the card change lands and the stale voice
        // becomes a warning they can act on.
        if (!$isNew && !$sameCard && $narration === '') {
            if (!$final) {
                return $reject(
                    'you changed the card but sent no narration, so the voice would still be describing the '
                    . 'content you replaced — send narration that matches the new card'
                );
            }
            $warning = 'the rewrite never sent new narration, so the voiceover still describes the old card. '
                . 'Ask again naming the wording you want.';
        }
        if ($narration === '') {
            $narration = $oldNarration;
        }

        // A rewrite that only reworded the narration keeps the card it had.
        if ($slots === [] && $template === (string) ($old['layout_template'] ?? '')) {
            $slots = (array) ($old['slots'] ?? []);
        }

        // Timing. An explicit number is clamped and honoured; otherwise a
        // REWRITE inherits the length the card already had — which may be one
        // the user set by hand on the storyboard, and a note about wording has
        // no business resetting it. A brand-new card sends 0, meaning "let the
        // content decide" (paceDuration takes the max of the caller's number
        // and its own estimate, so any guess would become a floor).
        $seconds = (float) ($draft['seconds'] ?? $draft['duration_seconds'] ?? 0);
        $seconds = $seconds > 0
            ? max(3.0, min(20.0, $seconds))
            : ($isNew ? 0.0 : (float) ($old['duration_seconds'] ?? 0));

        $mood = (string) ($draft['mood'] ?? ($old['mood'] ?? ''));

        return [
            'raw' => [
                'scene_id' => $sceneId,
                'layout_template' => $template,
                'duration_seconds' => $seconds,
                'narration' => ['text' => $narration],
                'slots' => $slots,
                // Pacing furniture the model was never asked about: a rewritten
                // card keeps the cut it was entered with.
                'transition' => (string) ($draft['transition'] ?? ($old['transition'] ?? '')),
                'relation' => $old['relation'] ?? null,
                'mood' => $mood !== '' ? $mood : 'neutral',
            ],
            'error' => null,
            'retryable' => false,
            'warning' => $warning,
        ];
    }

    /**
     * Give every blank picture panel something to shoot, from what the card
     * already says: its own label, then its heading, then the opening of the
     * narration. Anything is better than the validator's "Background visual
     * for this scene", which becomes the image prompt AND the upload request.
     *
     * @param string[] $keys
     */
    private static function describeBlankMedia(array $scene, array $keys): array
    {
        $fallback = trim((string) ($scene['narration']['text'] ?? ''));
        $fallback = implode(' ', array_slice(preg_split('/\s+/', $fallback) ?: [], 0, 12));

        foreach ($keys as $key) {
            $slot = $scene['slots'][$key] ?? null;
            if (!is_array($slot)) {
                continue;
            }
            $description = trim((string) ($slot['label'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($slot['heading'] ?? ''));
            }
            if ($description === '') {
                $description = $fallback;
            }
            if ($description === '') {
                continue; // nothing honest to say; leave the placeholder alone
            }
            $slot['asset_request'] = ['description' => mb_substr($description, 0, 160)];
            $scene['slots'][$key] = $slot;
        }

        return $scene;
    }

    /**
     * The validator's two back-fill placeholders for a picture nobody
     * described. Both become the IMAGE PROMPT verbatim and the text of the
     * upload request, so both count as "nothing to shoot".
     */
    private const MEDIA_PLACEHOLDERS = [
        'background visual for this scene', // fillMissingSlot: the slot was absent
        'visual for this scene',            // validateMediaSlot: present, but unlabelled
    ];

    /**
     * Media slots left with nothing to shoot: no description at all, or one of
     * the placeholders above. Stock slots are exempt — the pipeline fetches
     * those from the query.
     *
     * @return string[] slot keys
     */
    private static function blankMediaSlots(array $scene): array
    {
        $blank = [];

        foreach ((array) ($scene['slots'] ?? []) as $key => $slot) {
            if (!is_array($slot) || !in_array($slot['content_type'] ?? '', ['image', 'video'], true)) {
                continue;
            }
            if (trim((string) ($slot['stock_query'] ?? '')) !== '') {
                continue;
            }
            $description = trim((string) ($slot['asset_request']['description'] ?? ''));
            if ($description === '' || in_array(mb_strtolower($description), self::MEDIA_PLACEHOLDERS, true)) {
                $blank[] = (string) $key;
            }
        }

        return $blank;
    }

    /** Move a scene so it directly follows $after ('' = to the very front). */
    private static function relocate(array $scenes, string $sceneId, string $after): array
    {
        $subject = null;
        $rest = [];
        foreach ($scenes as $scene) {
            if ((string) $scene['scene_id'] === $sceneId) {
                $subject = $scene;
                continue;
            }
            $rest[] = $scene;
        }
        if ($subject === null) {
            return $scenes;
        }

        return self::insertAfter($rest, $subject, $after);
    }

    /** Splice a scene in directly after $after ('' = to the very front). */
    private static function insertAfter(array $scenes, array $scene, string $after): array
    {
        if ($after === '') {
            array_unshift($scenes, $scene);

            return $scenes;
        }

        $out = [];
        $placed = false;
        foreach ($scenes as $existing) {
            $out[] = $existing;
            if (!$placed && (string) $existing['scene_id'] === $after) {
                $out[] = $scene;
                $placed = true;
            }
        }
        if (!$placed) {
            // The anchor was removed by this same revision — the end of the
            // board is the honest place for it.
            $out[] = $scene;
        }

        return $out;
    }

    /** Did the rewrite actually change anything the renderer will see? */
    private static function identical(array $a, array $b): bool
    {
        $strip = function (array $s): array {
            return [
                'layout_template' => (string) ($s['layout_template'] ?? ''),
                'narration' => trim(self::narrationOf($s)),
                'slots' => (array) ($s['slots'] ?? []),
                'duration' => round((float) ($s['duration_seconds'] ?? 0), 2),
                'mood' => (string) ($s['mood'] ?? ''),
            ];
        };

        return json_encode($strip($a)) === json_encode($strip($b));
    }

    private static function narrationOf(array $scene): string
    {
        $narration = $scene['narration'] ?? '';

        return is_array($narration) ? (string) ($narration['text'] ?? '') : (string) $narration;
    }

    /** @param string[] $changed @param string[] $added @param string[] $removed @param string[] $moved */
    private static function summarize(array $changed, array $added, array $removed, array $moved): string
    {
        $parts = [];
        $plural = fn (int $n, string $word) => $n . ' ' . $word . ($n === 1 ? '' : 's');

        if ($changed !== []) {
            $parts[] = 'rewrote ' . $plural(count(array_unique($changed)), 'card');
        }
        if ($added !== []) {
            $parts[] = 'added ' . $plural(count($added), 'card');
        }
        if ($removed !== []) {
            $parts[] = 'removed ' . $plural(count($removed), 'card');
        }
        if ($moved !== []) {
            $parts[] = 'moved ' . $plural(count($moved), 'card');
        }
        if ($parts === []) {
            return 'Nothing was changed.';
        }

        $last = array_pop($parts);
        $text = $parts === [] ? $last : implode(', ', $parts) . ' and ' . $last;

        return ucfirst($text) . '. Every other scene — and everything you uploaded to it — was left untouched.';
    }
}
