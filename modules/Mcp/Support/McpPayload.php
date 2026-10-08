<?php

namespace Modules\Mcp\Support;

use Illuminate\Support\Facades\Storage;
use Modules\Mcp\Services\PresenterService;
use Modules\Project\Models\Project;
use Modules\Project\Support\McpOrigin;

/**
 * The MCP-specific half of the render payload, applied by
 * RemotionRenderService::buildRenderPayload to every MCP video (render AND
 * preview, so a preview frame is the real render's frame).
 *
 *  - composition: plain slides (each scene full-frame, the model's own cuts),
 *    or `presenter` — the user's recording as the spine of the video;
 *  - custom scenes get the video's whole media shelf as named <Asset>s;
 *  - presenter scenes are windows of the recording: their words come from the
 *    Whisper transcript, and gaps between the model's scenes are filled with
 *    plain presenter footage so the timeline covers the recording exactly.
 */
final class McpPayload
{
    /**
     * Set by the preview service around a one-scene payload build: the window
     * [start, end] of the recording the preview shows. Null for a real render.
     *
     * @var array{0: float, 1: float}|null
     */
    public static ?array $previewWindow = null;

    public static function decorate(array $shot, Project $project, callable $publicUrl): array
    {
        $settings = $project->settings ?? [];
        $meta = (array) ($settings['mcp']['scenes'] ?? []);
        $presenter = McpOrigin::isPresenter($settings);

        $shot['canvas'] = null;
        $shot['chapters'] = null;
        $shot['composition_mode'] = $presenter ? 'presenter' : 'slides';

        // The media shelf, as named pictures every custom scene may draw.
        $images = [];
        foreach ((array) ($settings['mcp']['media'] ?? []) as $name => $item) {
            if (($item['kind'] ?? 'image') === 'image' && !empty($item['path']) && Storage::disk('public')->exists($item['path'])) {
                $images[(string) $name] = $publicUrl((string) $item['path']);
            }
        }

        foreach ($shot['scenes'] as &$scene) {
            $m = (array) ($meta[(string) ($scene['scene_id'] ?? '')] ?? []);
            unset($scene['punchline']);
            if (!empty($scene['hero']) && is_array($scene['hero'])) {
                $scene['hero']['assets'] = array_merge($images, (array) ($scene['hero']['assets'] ?? []));
            }
            if ($presenter) {
                $start = (float) ($m['start'] ?? 0);
                $end = (float) ($m['end'] ?? $start + (float) ($scene['duration_seconds'] ?? 1));
                $scene['presenter_layout'] = (string) ($m['presenter_layout'] ?? 'full');
                $scene['pip_corner'] = (string) ($m['pip_corner'] ?? 'bottom_right');
                $scene['pip_shape'] = (string) ($m['pip_shape'] ?? 'rounded');
                $scene['split_side'] = (string) ($m['split_side'] ?? 'left');
                $scene['graphics'] = ($m['kind'] ?? 'none') !== 'none';
                $scene['start_seconds'] = round($start, 3);
                $scene['duration_seconds'] = round(max(0.05, $end - $start), 3);
                $scene['narration_words'] = PresenterService::wordsBetween($project, $start, $end);
                $scene['transition'] = 'none';
                unset($scene['narration_audio_url'], $scene['narration_audio_path']);
            }
        }
        unset($scene);

        if ($presenter) {
            $p = (array) ($settings['mcp']['presenter'] ?? []);
            $window = self::$previewWindow;
            $shot['presenter'] = [
                'video_url' => !empty($p['video_path']) ? $publicUrl((string) $p['video_path']) : null,
                // Stills need no sound; the render plays the whole recording once.
                'audio_url' => ($window === null && !empty($p['audio_path'])) ? $publicUrl((string) $p['audio_path']) : null,
                'duration' => (float) ($p['duration'] ?? 0),
                'width' => (int) ($p['width'] ?? 1920),
                'height' => (int) ($p['height'] ?? 1080),
                'offset_seconds' => $window !== null ? round((float) $window[0], 3) : 0,
                // The 30 fps JPEG strip the renderer draws (PresenterService::extractFrames).
                'frames' => !empty($p['frames']['count'])
                    ? ['url_prefix' => $publicUrl((string) $p['frames']['dir']) . '/f_', 'count' => (int) $p['frames']['count'], 'fps' => (int) ($p['frames']['fps'] ?? 30)]
                    : null,
            ];
            $scenes = array_values($shot['scenes']);
            usort($scenes, fn ($a, $b) => ($a['start_seconds'] ?? 0) <=> ($b['start_seconds'] ?? 0));
            $shot['scenes'] = $window === null
                ? self::tile($scenes, (float) ($p['duration'] ?? 0), $project)
                : $scenes;
        }

        return $shot;
    }

    /**
     * Lay the scenes end to end over the whole recording: a gap becomes a
     * plain presenter scene (full frame, no graphics), a hairline gap is
     * absorbed by the scene before it, the tail runs to the last frame.
     */
    public static function tile(array $scenes, float $duration, Project $project): array
    {
        $out = [];
        $cursor = 0.0;
        $gapN = 1;
        $gap = function (float $from, float $to) use (&$gapN, $project): array {
            return [
                'scene_id' => 'gap_' . ($gapN++),
                'order' => 0,
                'duration_seconds' => round($to - $from, 3),
                'narration' => ['text' => PresenterService::textBetween($project, $from, $to)],
                'layout_template' => 'single_focus',
                'slots' => ['slot_main' => ['content_type' => 'text_block', 'heading' => ' ', 'bullets' => []]],
                'transition' => 'none',
                'mood' => 'neutral',
                'presenter_layout' => 'full',
                'graphics' => false,
                'start_seconds' => round($from, 3),
                'narration_words' => PresenterService::wordsBetween($project, $from, $to),
            ];
        };

        foreach ($scenes as $scene) {
            $start = (float) ($scene['start_seconds'] ?? 0);
            $end = $start + (float) ($scene['duration_seconds'] ?? 0);
            if ($end <= $cursor + 0.01) {
                continue; // fully covered already (should not happen: upsert refuses overlaps)
            }
            if ($start > $cursor + 0.12) {
                $out[] = $gap($cursor, $start);
            } elseif ($out !== [] && $start > $cursor) {
                // Absorb a hairline gap into the previous scene.
                $last = count($out) - 1;
                $out[$last]['duration_seconds'] = round($start - (float) $out[$last]['start_seconds'], 3);
            }
            $start = max($start, $cursor);
            $scene['start_seconds'] = round($start, 3);
            $scene['duration_seconds'] = round($end - $start, 3);
            $out[] = $scene;
            $cursor = $end;
        }
        if ($duration > $cursor + 0.12) {
            $out[] = $gap($cursor, $duration);
        } elseif ($out !== [] && $duration > $cursor) {
            $last = count($out) - 1;
            $out[$last]['duration_seconds'] = round($duration - (float) $out[$last]['start_seconds'], 3);
        }
        foreach ($out as $i => &$scene) {
            $scene['order'] = $i + 1;
        }
        unset($scene);

        return $out;
    }
}
