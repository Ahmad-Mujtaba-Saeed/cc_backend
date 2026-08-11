<?php

namespace Modules\Project\Support;

/**
 * StoryboardDiff — the diff half of idempotent re-analysis.
 *
 * Re-analyzing a script used to wipe every ExplainerAsset with the old
 * storyboard: the user's uploads (their labor) plus every cached AI artifact
 * whose regeneration costs real money (narration wavs, illustrations, slot
 * fills, sprites, stock clips). Scene NUMBERS are meaningless across runs —
 * a fresh analysis renumbers freely and the model rewrites narration — so
 * survival is decided by CONTENT: old and new scenes are matched on
 * narration + slot-text similarity, and each old asset either re-homes onto
 * its surviving scene/slot or is dropped.
 *
 * Pure planning only (arrays in, actions out); the DB/file application lives
 * in AnalyzeExplainerScriptJob so every rule here stays unit-testable.
 */
final class StoryboardDiff
{
    /**
     * Below this combined score two scenes are different content. The model
     * paraphrases narration between runs, so a surviving scene typically
     * lands 0.5-0.9 while unrelated scenes from the same video share only
     * topic vocabulary (~0.2-0.35).
     */
    private const SCENE_MATCH_MIN = 0.42;

    /**
     * An orphaned USER upload may still jump to a brand-new media slot when
     * the slot's request text clearly describes the same subject. Higher bar
     * than a scene match: misplacing an upload is worse than dropping it.
     */
    private const ORPHAN_RESCUE_MIN = 0.5;

    /**
     * @param array $newScenes validator-shaped scenes ({narration:{text}, slots, ...})
     * @param array $oldScenes rows as arrays: scene_id, order, layout_template, narration (string), slots
     * @param array $oldAssets rows as arrays: id, scene_id, slot_key, type, path, original_name
     * @return array{
     *   scene_map: array<string,string>,
     *   assets: list<array{id:int, action:string, scene_id?:string, slot_key?:string}>,
     *   stats: array{kept_uploads:int, kept_cached:int, dropped:int, rescued:int}
     * }
     */
    public static function plan(array $newScenes, array $oldScenes, array $oldAssets): array
    {
        return self::planWithMap(
            $newScenes,
            $oldScenes,
            $oldAssets,
            self::matchScenes($newScenes, $oldScenes)
        );
    }

    /**
     * The same asset decisions, but against a scene map the caller ALREADY
     * knows.
     *
     * Content fingerprinting exists because a fresh analysis renumbers scenes
     * and paraphrases narration, so survival has to be guessed. A targeted
     * revision has no such problem: it edits named scenes in place and knows
     * exactly which ids survived, so guessing would only introduce a way to be
     * wrong (a heavily rewritten scene scoring below the match threshold would
     * silently drop the upload sitting on it). Same rules, certain map.
     *
     * @param array<string,string> $sceneMap old scene_id => new scene_id;
     *                                       an id absent from it did not survive
     * @param bool $allowRescue  may an orphaned upload jump to a NEW scene?
     *                           True for a re-analysis (nothing else can save
     *                           it); false for a targeted revision, where
     *                           moving a file onto a scene the user never
     *                           mentioned is a worse surprise than losing it
     */
    public static function planWithMap(
        array $newScenes,
        array $oldScenes,
        array $oldAssets,
        array $sceneMap,
        bool $allowRescue = true
    ): array {
        $newById = [];
        foreach ($newScenes as $scene) {
            $id = (string) ($scene['scene_id'] ?? '');
            if ($id !== '') {
                $newById[$id] = $scene;
            }
        }
        $oldById = [];
        foreach ($oldScenes as $scene) {
            $id = (string) ($scene['scene_id'] ?? '');
            if ($id !== '') {
                $oldById[$id] = $scene;
            }
        }

        // Uploads claim their targets before generated assets so a user file
        // always beats a stale AI fill contending for the same slot.
        $assets = array_values($oldAssets);
        usort($assets, function (array $a, array $b): int {
            $ra = self::rank($a);
            $rb = self::rank($b);
            return $ra === $rb
                ? ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0))
                : ($ra <=> $rb);
        });

        $claimed = [];
        $actions = [];
        $stats = ['kept_uploads' => 0, 'kept_cached' => 0, 'dropped' => 0, 'rescued' => 0];

        foreach ($assets as $asset) {
            $decision = self::decide($asset, $sceneMap, $newById, $oldById, $claimed, $allowRescue);
            if ($decision === null) {
                $actions[] = ['id' => (int) ($asset['id'] ?? 0), 'action' => 'drop'];
                $stats['dropped']++;
                continue;
            }
            [$sceneId, $slotKey, $rescued] = $decision;
            if (!str_starts_with($slotKey, '__')) {
                $claimed[$sceneId . '::' . $slotKey] = true;
            }
            $actions[] = [
                'id' => (int) ($asset['id'] ?? 0),
                'action' => 'keep',
                'scene_id' => $sceneId,
                'slot_key' => $slotKey,
            ];
            self::rank($asset) === 0 ? $stats['kept_uploads']++ : $stats['kept_cached']++;
            if ($rescued) {
                $stats['rescued']++;
            }
        }

        return ['scene_map' => $sceneMap, 'assets' => $actions, 'stats' => $stats];
    }

    /**
     * Match old scenes to new by content fingerprint. Greedy best-score
     * assignment; order distance breaks exact ties (duplicate-content scenes
     * pair with their positional twin instead of crossing).
     *
     * @return array<string,string> old scene_id => new scene_id
     */
    public static function matchScenes(array $newScenes, array $oldScenes): array
    {
        $old = self::signatures($oldScenes);
        $new = self::signatures($newScenes);

        $pairs = [];
        foreach ($old as $oid => $a) {
            foreach ($new as $nid => $b) {
                $score = 0.55 * self::dice($a['narration'], $b['narration'])
                    + 0.35 * self::dice($a['slots'], $b['slots'])
                    + ($a['template'] !== '' && $a['template'] === $b['template'] ? 0.10 : 0.0);
                if ($score < self::SCENE_MATCH_MIN) {
                    continue;
                }
                $pairs[] = [$score, abs($a['order'] - $b['order']), (string) $oid, (string) $nid];
            }
        }

        usort($pairs, function (array $x, array $y): int {
            return $x[0] === $y[0] ? ($x[1] <=> $y[1]) : ($y[0] <=> $x[0]);
        });

        $map = [];
        $taken = [];
        foreach ($pairs as [$score, $delta, $oid, $nid]) {
            if (isset($map[$oid]) || isset($taken[$nid])) {
                continue;
            }
            $map[$oid] = $nid;
            $taken[$nid] = true;
        }

        return $map;
    }

    /**
     * Re-key a scene-id-indexed settings map through the scene map, keeping
     * any __project__ entry. Used for vlm_retry_suffix: a retry nudge for an
     * old scene must neither poison whatever NEW scene reuses its number nor
     * be lost for a scene that survived (a stable nudge keeps the image
     * prompt hash stable, which is the whole point of idempotency).
     */
    public static function rekeyByScene(array $map, array $sceneMap): array
    {
        $out = [];
        if (array_key_exists('__project__', $map)) {
            $out['__project__'] = $map['__project__'];
        }
        foreach ($sceneMap as $oldId => $newId) {
            if (array_key_exists((string) $oldId, $map)) {
                $out[(string) $newId] = $map[(string) $oldId];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------- decisions

    /**
     * @param array<string,bool> $claimed media targets already taken ("scene::slot")
     * @return array{0:string,1:string,2:bool}|null [newSceneId, newSlotKey, rescued]; null = drop
     */
    private static function decide(
        array $asset,
        array $sceneMap,
        array $newById,
        array $oldById,
        array $claimed,
        bool $allowRescue = true
    ): ?array {
        $oldScene = (string) ($asset['scene_id'] ?? '');
        $slotKey = (string) ($asset['slot_key'] ?? '');
        if ($oldScene === '' || $slotKey === '') {
            return null;
        }

        // Project-level assets (the shared ambient backdrop) never belonged
        // to a scene — their own content hash decides reuse downstream.
        if ($oldScene === '__project__') {
            return [$oldScene, $slotKey, false];
        }

        $newScene = $sceneMap[$oldScene] ?? null;

        // Internal per-scene caches (__narration__, __illustration__) ride
        // the scene match; the downstream hash checks re-synthesize on any
        // content change, so keeping them is always safe and often free.
        if (str_starts_with($slotKey, '__')) {
            return $newScene === null ? null : [$newScene, $slotKey, false];
        }

        // Scenario sprites hang off "<slot>#spriteN" of a non-media slot;
        // they survive whenever the base slot still exists on the matched
        // scene (the sprite prompt hash re-cuts a changed entity anyway).
        if (str_contains($slotKey, '#sprite')) {
            if ($newScene === null) {
                return null;
            }
            $base = (string) strstr($slotKey, '#', true);
            $slots = $newById[$newScene]['slots'] ?? [];
            return isset($slots[$base]) ? [$newScene, $slotKey, false] : null;
        }

        if ($newScene !== null) {
            $target = self::slotTarget($asset, $newById[$newScene]['slots'] ?? []);
            if ($target !== null && empty($claimed[$newScene . '::' . $target])) {
                return [$newScene, $target, false];
            }
        }

        // Orphan rescue: only a user's own upload is precious enough to jump
        // scenes — and only when a new slot asks for the same subject.
        if ($allowRescue && self::rank($asset) === 0) {
            $rescued = self::rescueTarget($asset, $oldById[$oldScene] ?? [], $newById, $claimed);
            if ($rescued !== null) {
                return [$rescued[0], $rescued[1], true];
            }
        }

        return null;
    }

    /** Pick the slot on the MATCHED new scene this asset re-homes to. */
    private static function slotTarget(array $asset, array $newSlots): ?string
    {
        $slotKey = (string) $asset['slot_key'];
        $name = (string) ($asset['original_name'] ?? '');

        $mediaSlots = [];
        foreach ($newSlots as $key => $slot) {
            if (in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                $mediaSlots[(string) $key] = $slot;
            }
        }

        // A cached stock clip is only valid where the SAME query survives —
        // the fetcher skips slots that already have a file, so re-homing a
        // clip under a changed query would freeze the stale footage in.
        if (str_starts_with($name, 'stock:')) {
            $slot = $mediaSlots[$slotKey] ?? null;
            $query = trim((string) ($slot['stock_query'] ?? ''));
            return ($slot !== null && $query !== '' && $name === 'stock:' . md5($query))
                ? $slotKey
                : null;
        }

        if (isset($mediaSlots[$slotKey])) {
            // An AI fill must not squat a slot that became stock footage —
            // fill_missing_media skips stock slots, so nothing would ever
            // reconcile it; the fetcher must see the slot empty.
            if (str_starts_with($name, 'slot-fill:')
                && trim((string) ($mediaSlots[$slotKey]['stock_query'] ?? '')) !== ''
            ) {
                return null;
            }
            return $slotKey;
        }

        // Uploads only: the scene matched but was recast with different slot
        // keys — when the new scene has exactly ONE media slot the intent is
        // unambiguous. Generated assets just regenerate instead.
        if (self::rank($asset) === 0 && count($mediaSlots) === 1) {
            return array_key_first($mediaSlots);
        }

        return null;
    }

    /**
     * The old scene vanished — hunt every unclaimed new media slot for one
     * whose request text describes the same subject as the slot the user
     * uploaded into.
     *
     * @return array{0:string,1:string}|null [sceneId, slotKey]
     */
    private static function rescueTarget(
        array $asset,
        array $oldScene,
        array $newById,
        array $claimed
    ): ?array {
        $oldSlot = ($oldScene['slots'] ?? [])[(string) $asset['slot_key']] ?? null;
        $oldTokens = self::slotRequestTokens(is_array($oldSlot) ? $oldSlot : null);
        if ($oldTokens === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($newById as $sceneId => $scene) {
            foreach (($scene['slots'] ?? []) as $key => $slot) {
                if (!in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                    continue;
                }
                if (!empty($claimed[$sceneId . '::' . $key])) {
                    continue;
                }
                $score = self::dice($oldTokens, self::slotRequestTokens($slot));
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [(string) $sceneId, (string) $key];
                }
            }
        }

        return ($best !== null && $bestScore >= self::ORPHAN_RESCUE_MIN) ? $best : null;
    }

    /**
     * Is this row a file the USER put there (rather than one the pipeline
     * generated or cached)? The distinction decides who wins a contested slot
     * here, and elsewhere decides what is worth warning someone about.
     *
     * @param array{slot_key?: string, original_name?: string} $asset
     */
    public static function isUpload(array $asset): bool
    {
        return self::rank($asset) === 0;
    }

    /** 0 = user upload, 1 = generated media (fill/stock/sprite), 2 = internal cache. */
    private static function rank(array $asset): int
    {
        $slotKey = (string) ($asset['slot_key'] ?? '');
        if (str_starts_with($slotKey, '__')) {
            return 2;
        }
        if (str_contains($slotKey, '#sprite')) {
            return 1;
        }
        $name = (string) ($asset['original_name'] ?? '');
        foreach (['slot-fill:', 'stock:', 'sprite:'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return 1;
            }
        }
        return 0;
    }

    // ----------------------------------------------------------- fingerprint

    /**
     * @return array<string, array{narration:array, slots:array, template:string, order:int}>
     */
    private static function signatures(array $scenes): array
    {
        $out = [];
        $position = 0;
        foreach ($scenes as $scene) {
            $id = (string) ($scene['scene_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $narration = $scene['narration'] ?? '';
            if (is_array($narration)) {
                $narration = (string) ($narration['text'] ?? '');
            }
            $strings = [];
            self::stringLeaves($scene['slots'] ?? [], $strings);
            $out[$id] = [
                'narration' => self::tokens((string) $narration),
                'slots' => self::tokens(implode(' ', $strings)),
                'template' => (string) ($scene['layout_template'] ?? ''),
                'order' => (int) ($scene['order'] ?? $position),
            ];
            $position++;
        }
        return $out;
    }

    /**
     * Every asset_request reads "a photo of the X" — the framing words would
     * let two requests for DIFFERENT subjects clear the rescue bar on
     * "photo/of/console" alone, so only subject words may vote.
     */
    private const REQUEST_STOPWORDS = [
        'an', 'the', 'of', 'on', 'in', 'at', 'to', 'for', 'with', 'and', 'or',
        'photo', 'image', 'picture', 'illustration', 'graphic', 'shot',
        'video', 'clip', 'footage', 'close', 'up', 'view', 'showing',
    ];

    /** Text the slot uses to DESCRIBE the wanted media (not scene prose). */
    private static function slotRequestTokens(?array $slot): array
    {
        if ($slot === null) {
            return [];
        }
        $tokens = self::tokens(
            (string) ($slot['asset_request']['description'] ?? '') . ' '
            . (string) ($slot['label'] ?? '') . ' '
            . (string) ($slot['heading'] ?? '') . ' '
            . (string) ($slot['stock_query'] ?? '')
        );
        return array_values(array_diff($tokens, self::REQUEST_STOPWORDS));
    }

    private static function stringLeaves($value, array &$out): void
    {
        if (is_string($value)) {
            $out[] = $value;
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if ($key === 'content_type') {
                continue; // structural, identical across unrelated scenes
            }
            self::stringLeaves($item, $out);
        }
    }

    /** @return list<string> unique lowercase word/number tokens */
    private static function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_filter($parts, fn ($t) => mb_strlen($t) >= 2 || is_numeric($t));
        return array_values(array_unique($parts));
    }

    /** Sørensen–Dice coefficient over two token sets. */
    private static function dice(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        return $inter === 0 ? 0.0 : (2.0 * $inter) / (count($a) + count($b));
    }
}
