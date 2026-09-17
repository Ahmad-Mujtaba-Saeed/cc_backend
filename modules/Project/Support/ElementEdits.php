<?php

namespace Modules\Project\Support;

/**
 * The storyboard stage's hand edits for one scene, cleaned to exactly the
 * shape the renderer reads (remotion-render/src/components/Editable.tsx).
 *
 * Everything the browser sends is clamped or dropped here: unknown fields,
 * out-of-range numbers, malformed colours, ids that are not element paths.
 * Values that equal "as designed" (no offset, scale 1, ...) are dropped too,
 * so an element nudged back to where it started stops carrying an edit.
 */
final class ElementEdits
{
    public const MAX_ELEMENTS = 200;

    private const ID = '/^[A-Za-z0-9_-]{1,40}(\.[A-Za-z0-9_-]{1,40}){0,3}$/';

    private const CASES = ['upper', 'lower', 'title', 'none'];

    private const ALIGNS = ['left', 'center', 'right'];

    /** @return array<string, array<string, mixed>> */
    public static function clean(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $id => $edit) {
            if (!is_string($id) || !preg_match(self::ID, $id) || !is_array($edit)) {
                continue;
            }
            $clean = self::cleanOne($edit);
            if ($clean !== []) {
                $out[$id] = $clean;
            }
            if (count($out) >= self::MAX_ELEMENTS) {
                break;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function cleanOne(array $e): array
    {
        $c = [];

        // [field, min, max, neutral value (dropped), decimals]
        $numbers = [
            ['x', -1.5, 1.5, 0.0, 4],
            ['y', -1.5, 1.5, 0.0, 4],
            ['scale', 0.2, 5.0, 1.0, 3],
            ['rotate', -180.0, 180.0, 0.0, 1],
            ['opacity', 0.05, 1.0, 1.0, 2],
            ['tracking', -0.1, 0.5, null, 3],
        ];
        foreach ($numbers as [$key, $lo, $hi, $neutral, $round]) {
            if (!isset($e[$key]) || !is_numeric($e[$key])) {
                continue;
            }
            $v = round(max($lo, min($hi, (float) $e[$key])), $round);
            if ($neutral === null || abs($v - $neutral) > 1e-9) {
                $c[$key] = $v;
            }
        }

        if (isset($e['weight']) && is_numeric($e['weight'])) {
            $c['weight'] = (int) (round(max(100, min(900, (float) $e['weight'])) / 100) * 100);
        }

        foreach (['italic', 'underline', 'hidden'] as $flag) {
            if (!array_key_exists($flag, $e) || $e[$flag] === null) {
                continue;
            }
            $on = filter_var($e[$flag], FILTER_VALIDATE_BOOLEAN);
            // hidden=false is the default and carries nothing; the style flags
            // keep an explicit false (it can switch OFF a layout's own italic).
            if ($on || $flag !== 'hidden') {
                $c[$flag] = $on;
            }
        }

        if (isset($e['color']) && is_string($e['color']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $e['color'])) {
            $c['color'] = strtolower($e['color']);
        }
        if (isset($e['case']) && in_array($e['case'], self::CASES, true)) {
            $c['case'] = $e['case'];
        }
        if (isset($e['align']) && in_array($e['align'], self::ALIGNS, true)) {
            $c['align'] = $e['align'];
        }
        if (isset($e['text']) && is_string($e['text'])) {
            $text = trim(preg_replace('/\s+/u', ' ', $e['text']) ?? '');
            if ($text !== '') {
                $c['text'] = mb_substr($text, 0, 300);
            }
        }

        return $c;
    }
}
