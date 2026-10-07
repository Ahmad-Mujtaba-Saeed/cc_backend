<?php

namespace Modules\Project\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Hero scenes — bookkeeping (pure apart from reading stored modules).
 *
 * A hero scene is one beat of a video whose card is REPLACED at render time
 * by a Remotion component a code model wrote for that beat alone
 * (Services\HeroSceneService writes them; remotion-render/src/hero/* runs
 * them behind a guard, a sandboxed runtime and a CSP). The card is never
 * deleted: it is the fallback the renderer drops back to, and what the
 * dashboard Player shows.
 *
 * State lives in `settings.hero_scenes[scene_id]`:
 *   status        ready | failed | pending
 *   file          explainer/{project}/hero/{scene_id}.json  ({code, js})
 *   content_hash  of the narration + slots it was written for
 *   model, attempts, cost_usd, review {score, issues}, stills[], error, updated_at
 */
final class HeroScenes
{
    /** Cards a hero never replaces: structural, maths, or already bespoke. */
    private const NEVER = [
        'chapter_cover', 'outro_card', 'cinematic_card', 'custom_card', 'interface_morph',
        'math_steps', 'geometry_diagram', 'function_plot', 'formula_anatomy', 'scenario_diagram',
        'practice_card', 'common_mistake', 'map_card', 'phone_mockup', 'photo_stack', 'image_grid',
        'quote_portrait',
    ];

    /** Cards whose content is a picture-in-waiting — the best raw material for a motion graphic. */
    private const RICH = [
        'stat_spotlight', 'big_counter', 'scale_comparison', 'proportion_flow', 'pictogram_percent',
        'versus_card', 'before_after', 'step_flow', 'cycle_diagram', 'timeline_card', 'animated_chart',
        'hierarchy_card', 'layer_stack', 'venn_card', 'spectrum_card', 'quadrant_map', 'decision_tree',
        'single_focus', 'labeled_diagram', 'progress_meter', 'list_ranking',
    ];

    /**
     * Which scenes become heroes: the strongest beats, never two adjacent,
     * at most one in the opening pair, never on the maths board, never on a
     * scene that shows the user's own picture or footage.
     *
     * @param  array<int, array>  $scenes  ordered, validator shape
     * @return string[] scene ids, in video order
     */
    public static function cast(array $scenes, int $max, string $compositionMode): array
    {
        if ($max <= 0 || $compositionMode === 'math_board') {
            return [];
        }

        $scored = [];
        foreach (array_values($scenes) as $i => $scene) {
            $template = (string) ($scene['layout_template'] ?? '');
            if (in_array($template, self::NEVER, true) || self::showsMedia($scene)) {
                continue;
            }
            $narration = (string) ($scene['narration']['text'] ?? $scene['narration'] ?? '');
            $words = str_word_count($narration);
            if ($words < 8 || (float) ($scene['duration_seconds'] ?? 0) < 4.0) {
                continue;
            }
            $score = 0.0;
            if ($i <= 1) {
                $score += 3; // the hook is what keeps viewers watching
            }
            if (in_array($template, self::RICH, true)) {
                $score += 2;
            }
            if (preg_match('/\d/', $narration)) {
                $score += 1;
            }
            if ($words >= 12 && $words <= 45) {
                $score += 1;
            }
            $score -= $i * 0.01; // stable tie-break: earlier first
            $scored[] = ['i' => $i, 'id' => (string) $scene['scene_id'], 'score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $picked = [];
        foreach ($scored as $c) {
            if (count($picked) >= $max) {
                break;
            }
            $clash = false;
            foreach ($picked as $p) {
                if (abs($p['i'] - $c['i']) <= 1 || ($p['i'] <= 1 && $c['i'] <= 1)) {
                    $clash = true;
                    break;
                }
            }
            if (!$clash) {
                $picked[] = $c;
            }
        }
        usort($picked, fn ($a, $b) => $a['i'] <=> $b['i']);

        return array_map(fn ($p) => $p['id'], $picked);
    }

    /** Does a scene carry a user/stock picture or video? Those stay as they are. */
    public static function showsMedia(array $scene): bool
    {
        foreach ((array) ($scene['slots'] ?? []) as $slot) {
            if (in_array($slot['content_type'] ?? null, ['image', 'video'], true) && !empty($slot['asset_ref'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the hero was written FOR — a change means it may no longer match.
     *
     * Only the WORDS (narration + on-screen copy): the same scene reaches this
     * as a DB row, as the validator's array and as the assembler's (which adds
     * resolved asset refs), and a hash over the raw slots would call all
     * three different.
     */
    public static function contentHash(array $scene): string
    {
        $narration = $scene['narration'] ?? '';

        return substr(md5(json_encode([
            trim((string) (is_array($narration) ? ($narration['text'] ?? '') : $narration)),
            self::onScreenCopy($scene),
        ])), 0, 16);
    }

    /**
     * The card's on-screen copy, flattened: headings, labels, values, bullets.
     * Handed to the model as the words it MAY put on screen.
     *
     * @return string[]
     */
    public static function onScreenCopy(array $scene): array
    {
        $skip = ['content_type', 'layout', 'icon', 'dock', 'style', 'camera_move', 'asset_ref', 'transition',
            'variant', 'mood', 'color', 'shape', 'kind', 'place', 'depth', 'anim', 'path', 'image_path', 'sprite_path'];
        $out = [];
        $walk = function ($value, string $key = '') use (&$walk, &$out, $skip): void {
            if (in_array($key, $skip, true) || count($out) >= 24) {
                return;
            }
            if (is_string($value)) {
                $v = trim($value);
                if ($v !== '' && mb_strlen($v) <= 90 && !preg_match('#^(https?:)?//|\.(png|jpe?g|webp|mp4)$#i', $v)) {
                    $out[] = $v;
                }
            } elseif (is_int($value) || is_float($value)) {
                if ($key !== '' && !in_array($key, ['width_pct', 'x', 'y', 'w', 'h', 'order'], true)) {
                    $out[] = "{$key}: {$value}";
                }
            } elseif (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, is_string($k) ? $k : $key);
                }
            }
        };
        $walk($scene['slots'] ?? []);

        return array_values(array_unique($out));
    }

    public static function storagePath(int|string $projectId, string $sceneId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $sceneId);

        return "explainer/{$projectId}/hero/{$safe}.json";
    }

    /** The stored module for a ready hero, or null. */
    public static function module(array $settings, string $sceneId): ?array
    {
        $entry = $settings['hero_scenes'][$sceneId] ?? null;
        if (!is_array($entry) || ($entry['status'] ?? null) !== 'ready' || empty($entry['file'])) {
            return null;
        }
        $disk = Storage::disk('public');
        if (!$disk->exists((string) $entry['file'])) {
            return null;
        }
        $data = json_decode((string) $disk->get((string) $entry['file']), true);

        return is_array($data) && !empty($data['js']) ? $data : null;
    }

    /**
     * Put each ready hero onto its scene in a render payload. The maths board
     * never carries one; everything else keeps its card as the fallback.
     *
     * @param  array<int, array>  $scenes  payload scenes (assets already resolved)
     * @param  callable(string): string  $publicUrl
     */
    public static function attach(array $scenes, array $settings, bool $captions, callable $publicUrl): array
    {
        if (empty($settings['hero_scenes']) || ($settings['composition_mode'] ?? '') === 'math_board') {
            return $scenes;
        }
        foreach ($scenes as $i => $scene) {
            $module = self::module($settings, (string) ($scene['scene_id'] ?? ''));
            if ($module === null) {
                continue;
            }
            $assets = [];
            if (!empty($scene['illustration_url'])) {
                $assets['illustration'] = (string) $scene['illustration_url'];
            }
            $scenes[$i]['hero'] = ['js' => (string) $module['js'], 'captions' => $captions, 'assets' => $assets];
            // The recorded clip the dashboard Player shows in the hero's place.
            $clip = (string) ($settings['hero_scenes'][(string) $scene['scene_id']]['clip'] ?? '');
            if ($clip !== '' && Storage::disk('public')->exists($clip)) {
                $scenes[$i]['hero']['preview_url'] = $publicUrl($clip);
                $scenes[$i]['hero']['preview_frames'] = (int) ($settings['hero_scenes'][(string) $scene['scene_id']]['clip_frames'] ?? 0);
            }
            // The hero owns the frame: the word-synced punchline overlay would
            // land on top of a composition that did not plan for it.
            unset($scenes[$i]['punchline']);
        }

        return $scenes;
    }
}
