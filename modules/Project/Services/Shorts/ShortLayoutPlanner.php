<?php

namespace Modules\Project\Services\Shorts;

/**
 * Turns a clip's scene analysis into a vertical layout: which panels the
 * 1080x1920 canvas is split into, what part of the source each one shows,
 * and how the crop follows a face over time.
 *
 * Layouts:
 *   fill_follow               one subject, crop follows their face
 *   stack_two                 two people, one per half (podcast/interview)
 *   facecam_top_gameplay      streamer: webcam inset on top, game underneath
 *   facecam_bubble_gameplay   streamer: game fills the phone, webcam as a
 *                             framed bubble at the top
 *   two_facecams_top_gameplay two people side by side on top, game underneath
 *   blur_fit                  the whole frame over a blurred copy (screens,
 *                             wide shots, groups)
 *   gameplay_split_stock      talking head on top, library gameplay below
 *
 * All coordinates are fractions: `dest` of the canvas, crops of the source.
 */
class ShortLayoutPlanner
{
    private const CANVAS_ASPECT = 1080 / 1920;

    /** Set per call by plan(); see the `tightness` parameter there. */
    private float $tightness = 1.0;

    /** Which streamer look this short gets; rotated across a batch. */
    private int $variant = 0;

    /**
     * @param  array<string, mixed>  $analysis  ClipSceneAnalyzer::analyze()
     * @param  bool  $stockGameplay  a library gameplay loop is available and this short may use it
     * @return array<string, mixed>  the renderer's Layout plus `kind` and `reason`
     */
    public function plan(array $analysis, bool $stockGameplay, string $forced = 'auto', float $tightness = 1.0, int $variant = 0): array
    {
        $this->variant = $variant;
        // `tightness` is the style's framing knob: below 1 the crop is narrower,
        // so the subject fills more of the phone. It is clamped because a very
        // tight crop on a 16:9 source is a big upscale, and a very wide one
        // stops being a vertical crop at all.
        $this->tightness = max(0.72, min(1.15, $tightness));
        $sw = max(1, (int) ($analysis['source_width'] ?? 1920));
        $sh = max(1, (int) ($analysis['source_height'] ?? 1080));
        $vertical = $sh > $sw;
        $tracks = array_values($analysis['face_tracks'] ?? []);
        $regions = $analysis['regions'] ?? [];
        $type = (string) ($analysis['scene_type'] ?? 'other');
        $hint = (string) ($analysis['recommended_layout'] ?? '');
        $cuts = $analysis['scene_cuts'] ?? [];

        $facecams = array_values(array_filter($regions, fn ($r) => $r['role'] === 'facecam'));
        $gameplay = $this->largest(array_filter($regions, fn ($r) => in_array($r['role'], ['gameplay', 'screen', 'content'], true)));
        $bigTracks = array_values(array_filter($tracks, fn ($t) => $t['presence'] >= 0.35));

        // Where the crop should look over time, built from every detection in
        // the clip rather than from one track that fragments the moment the
        // subject turns away. `coverage` is the share of samples it can aim at
        // all; below a third there is nothing to follow and a crop would be
        // pointing at the floor.
        $camera = is_array($analysis['camera_track'] ?? null) ? $analysis['camera_track'] : null;
        $canFollow = $camera !== null && (float) ($camera['coverage'] ?? 0) >= 0.34;
        $primary = $this->primaryTrack($tracks);

        $kind = $forced !== 'auto' ? $forced : null;
        $reason = $forced !== 'auto' ? 'forced by settings' : '';

        if ($kind === null) {
            if ($vertical) {
                $kind = 'fill_follow';
                $reason = 'source is already vertical';
            } elseif ($facecams && $gameplay && count($facecams) >= 2) {
                $kind = 'two_facecams_top_gameplay';
                $reason = 'two webcam insets over gameplay';
            } elseif ($facecams && ($gameplay || !empty($analysis['webcam']))) {
                // The streamer is the point of a stream clip: whenever there
                // is a webcam, it is on screen. Three looks, rotated so a
                // batch does not come out as eight copies of one split.
                $kind = $this->variant % 3 === 2 ? 'facecam_bubble_gameplay' : 'facecam_top_gameplay';
                $reason = !empty($analysis['webcam']) ? 'detected webcam over gameplay' : 'webcam inset over gameplay';
            } elseif (in_array($type, ['screen_recording', 'presentation'], true)) {
                // The ONLY footage that genuinely cannot be cropped: text and
                // UI lose their meaning the moment part of the frame is gone.
                $kind = 'blur_fit';
                $reason = 'screen content — cropping would cut the text off';
            } elseif ($this->isTwoHander($bigTracks, $type)) {
                $kind = 'stack_two';
                $reason = 'two people, both on screen throughout';
            } elseif ($canFollow) {
                $kind = ($stockGameplay && count($bigTracks) <= 1) ? 'gameplay_split_stock' : 'fill_follow';
                $reason = sprintf('following the subject (%d%% of frames)', (int) round($camera['coverage'] * 100));
            } elseif ($type === 'gameplay' || $type === 'sports') {
                $kind = 'blur_fit';
                $reason = 'action footage — zoomed fit';
            } elseif (in_array($hint, ClipSceneAnalyzer::LAYOUTS, true)
                && !in_array($hint, ['gameplay_split_stock', 'blur_fit'], true)) {
                // blur_fit is deliberately NOT reachable from the hint: asked
                // about a crowded IRL vlog the vision model recommends it every
                // single time (7 of 7 on the first real run), and it is the one
                // layout that wastes two thirds of the screen.
                $kind = $hint;
                $reason = 'vision model suggestion';
            } else {
                // No face to follow and nothing else fits. A fill still beats a
                // blur_fit: it fills the phone, and the crop sits where the
                // people usually were rather than in the exact middle.
                $kind = 'fill_follow';
                $reason = 'nothing tracked — static framing';
            }
        }

        $layout = match ($kind) {
            'stack_two' => $this->stackTwo($bigTracks ?: $tracks, $sw, $sh, $cuts),
            'facecam_top_gameplay', 'facecam_bubble_gameplay' => $this->streamer(
                $kind,
                $facecams[0]['box'] ?? null,
                $gameplay['box'] ?? null,
                is_array($analysis['action_track'] ?? null) ? $analysis['action_track'] : null,
                $type,
                $sw,
                $sh,
                $cuts
            ),
            'two_facecams_top_gameplay' => $this->twoFacecams($facecams, $bigTracks, $gameplay['box'] ?? null, $sw, $sh),
            'blur_fit' => $this->blurFit($type, $sw, $sh, $gameplay['box'] ?? null),
            'gameplay_split_stock' => $this->stockSplit($camera ?? $primary, $sw, $sh, $cuts),
            default => $this->fill($camera ?? $primary, $sw, $sh, $cuts),
        };

        // A layout that could not be built from the data degrades to a fill.
        if ($layout === null) {
            $kind = 'fill_follow';
            $reason .= ' (fallback)';
            $layout = $this->fill($camera ?? $primary, $sw, $sh, $cuts);
        }

        return ['kind' => $kind, 'reason' => $reason] + $layout;
    }

    /**
     * The track the short is about: the analyser ranks by size, presence,
     * centrality and mouth movement and flags the winner. Ids are ordered
     * left-to-right for labelling, so `$tracks[0]` is the LEFTMOST face — which
     * on a crowded IRL shot is a stranger walking past, not the subject.
     *
     * @param  array<int, array<string, mixed>>  $tracks
     */
    private function primaryTrack(array $tracks): ?array
    {
        foreach ($tracks as $t) {
            if (!empty($t['primary'])) {
                return $t;
            }
        }
        $best = null;
        foreach ($tracks as $t) {
            if (!$best || ($t['score'] ?? 0) > ($best['score'] ?? 0)) {
                $best = $t;
            }
        }

        return $best;
    }

    /**
     * Is this really two people sharing the frame, or a crowd?
     *
     * The old test was "two tracks at least 0.22 apart, in almost any scene
     * type", and on an IRL vlog it fired constantly — there are always two
     * faces somewhere in a crowd. A stacked layout only pays off when both
     * people are actually IN the conversation: on screen most of the time, at
     * comparable distance from the lens, in a scene that is a conversation.
     *
     * @param  array<int, array<string, mixed>>  $tracks
     */
    private function isTwoHander(array $tracks, string $type): bool
    {
        if (count($tracks) < 2 || !in_array($type, ['podcast', 'interview'], true)) {
            return false;
        }
        [$a, $b] = [$tracks[0], $tracks[1]];
        if (!$this->apart($a, $b)) {
            return false;
        }
        if (($a['presence'] ?? 0) < 0.5 || ($b['presence'] ?? 0) < 0.5) {
            return false;
        }
        // Comparable size: one face twice as big as the other is a subject and
        // a bystander, not two hosts.
        $ratio = max($a['size'], $b['size']) / max(1e-6, min($a['size'], $b['size']));

        return $ratio <= 2.2;
    }

    private function apart(array $a, array $b): bool
    {
        return abs($a['cx'] - $b['cx']) > 0.22;
    }

    private function largest(array $regions): ?array
    {
        $best = null;
        foreach ($regions as $r) {
            if (!$best || $r['box'][2] * $r['box'][3] > $best['box'][2] * $best['box'][3]) {
                $best = $r;
            }
        }

        return $best;
    }

    /**
     * Source-width fraction of a crop that fills a dest rect at full source
     * height, narrowed (or widened) by the style's framing knob.
     *
     * Never wider than the frame: at tightness > 1 on a source that is already
     * only just wide enough, the extra would be crop window outside the video.
     */
    private function fullHeightWidth(float $destAspect, int $sw, int $sh): float
    {
        return min(1.0, ($sh * $destAspect) / $sw * $this->tightness);
    }

    /**
     * Camera keys for one face track: hold still inside a dead zone, move when
     * the face leaves it, and snap (never glide) across a scene cut.
     *
     * @return array<int, array{t: float, cx: float, cy: float, jump?: bool}>
     */
    public function followKeys(?array $track, float $cropWidth, array $cuts, float $headroom = 0.0): array
    {
        if (!$track || empty($track['points'])) {
            return [['t' => 0.0, 'cx' => 0.5, 'cy' => 0.5]];
        }

        // The camera path arrives median-smoothed and rate-limited from the
        // analyser, so it only needs a light dead zone here; a raw face track
        // is noisy per-sample and needs a wide one or the crop vibrates.
        $preSmoothed = ($track['id'] ?? null) === 'camera';
        $dead = max(0.02, $cropWidth * ($preSmoothed ? 0.09 : 0.18));
        $keys = [];
        $cam = null;
        $lastT = -1.0;
        foreach ($track['points'] as $p) {
            $cx = (float) $p['x'] + (float) $p['w'] / 2;
            $cy = (float) $p['y'] + (float) $p['h'] / 2 + $headroom;
            $t = (float) $p['t'];
            $crossedCut = false;
            foreach ($cuts as $c) {
                if ($c > $lastT && $c <= $t) {
                    $crossedCut = true;
                    break;
                }
            }
            if ($cam === null) {
                $cam = ['cx' => $cx, 'cy' => $cy];
                $keys[] = ['t' => 0.0, 'cx' => round($cx, 4), 'cy' => round($cy, 4)];
            } elseif ($crossedCut) {
                $cam = ['cx' => $cx, 'cy' => $cy];
                $keys[] = ['t' => round($t, 3), 'cx' => round($cx, 4), 'cy' => round($cy, 4), 'jump' => true];
            } elseif (abs($cx - $cam['cx']) > $dead || abs($cy - $cam['cy']) > $dead * 1.4) {
                // Move most of the way, not all of it: a calmer camera.
                $cam = ['cx' => $cam['cx'] + ($cx - $cam['cx']) * 0.85, 'cy' => $cam['cy'] + ($cy - $cam['cy']) * 0.85];
                $keys[] = ['t' => round($t, 3), 'cx' => round($cam['cx'], 4), 'cy' => round($cam['cy'], 4)];
            }
            $lastT = $t;
        }

        return $keys;
    }

    private function fill(?array $track, int $sw, int $sh, array $cuts): array
    {
        $width = $this->fullHeightWidth(self::CANVAS_ASPECT, $sw, $sh);

        return [
            'background' => 'black',
            'divider' => null,
            'panels' => [[
                'dest' => [0, 0, 1, 1],
                'source' => 'main',
                'primary' => true,
                'track' => ['width' => round($width, 4), 'keys' => $this->followKeys($track, $width, $cuts)],
            ]],
        ];
    }

    private function stackTwo(array $tracks, int $sw, int $sh, array $cuts): ?array
    {
        if (count($tracks) < 2) {
            return null;
        }
        // Left person on top reads naturally.
        usort($tracks, fn ($a, $b) => $a['cx'] <=> $b['cx']);
        $half = 1080 / 960;
        $panels = [];
        foreach ([$tracks[0], $tracks[1]] as $i => $t) {
            // Head and shoulders: the face takes about a third of the crop.
            $width = max(0.22, min($this->fullHeightWidth($half, $sw, $sh), (float) $t['size'] * 3.0));
            $panels[] = [
                'dest' => [0, $i * 0.5, 1, 0.5],
                'source' => 'main',
                'primary' => $i === 0,
                'track' => ['width' => round($width, 4), 'keys' => $this->followKeys($t, $width, $cuts, (float) $t['size'] * 0.35)],
                'label' => $t['id'] ?? null,
            ];
        }

        return ['background' => 'black', 'divider' => ['color' => '#000000', 'thickness' => 6], 'panels' => $panels];
    }

    /**
     * A streamer layout: the webcam and the game, both always on screen.
     *
     * The game panel is not a fixed centre crop any more: it follows the
     * action (the centroid of what moved, webcam masked out) and is kept on
     * the far side of the webcam so the streamer is never shown twice.
     */
    private function streamer(string $kind, ?array $cam, ?array $game, ?array $action, string $type, int $sw, int $sh, array $cuts): ?array
    {
        if (!$cam) {
            return null;
        }
        // Where the game is: the vision model's area if it gave one, otherwise
        // the frame minus the webcam's side.
        if (!$game) {
            $camLeft = $cam[0] + $cam[2] / 2 < 0.5;
            $game = $camLeft ? [$cam[0] + $cam[2], 0, 1 - $cam[0] - $cam[2], 1] : [0, 0, $cam[0], 1];
            if ($game[2] < 0.45) {
                $game = [0, 0, 1, 1];
            }
        }
        $screen = in_array($type, ['screen_recording', 'presentation'], true);
        $camPx = ($cam[2] * $sw) / max(1, $cam[3] * $sh);

        if ($kind === 'facecam_bubble_gameplay') {
            $bw = 0.66;
            $bh = min(0.34, $bw * 1080 / max(0.8, min(2.0, $camPx)) / 1920);
            $gamePanel = $this->gamePanel([0, 0, 1, 1], $game, $action, $screen, $sw, $sh, $cuts);

            return [
                'background' => 'black',
                'divider' => null,
                'panels' => [
                    $gamePanel,
                    [
                        'dest' => [round((1 - $bw) / 2, 4), 0.05, $bw, round($bh, 4)],
                        'source' => 'main',
                        'crop' => $cam,
                        'inset' => true,
                        'primary' => true,
                        'radius' => 36,
                        'border' => '6px solid #ffffff',
                    ],
                ],
            ];
        }

        // Classic split; every other short gives the streamer a taller panel.
        $camH = $this->variant % 3 === 1 ? 0.46 : 0.4;

        return [
            'background' => 'black',
            'divider' => ['color' => '#000000', 'thickness' => 8],
            'panels' => [
                ['dest' => [0, 0, 1, $camH], 'source' => 'main', 'crop' => $cam, 'inset' => true, 'primary' => true],
                $this->gamePanel([0, $camH, 1, 1 - $camH], $game, $action, $screen, $sw, $sh, $cuts),
            ],
        ];
    }

    /** The game half of a streamer layout, following the action inside `game`. */
    private function gamePanel(array $dest, array $game, ?array $action, bool $screen, int $sw, int $sh, array $cuts): array
    {
        if ($screen || !$action) {
            return ['dest' => $dest, 'source' => 'main', 'crop' => $this->centreCrop($game, ($dest[2] * 1080) / ($dest[3] * 1920), $sw, $sh)]
                + ($screen ? ['fit' => 'contain'] : []);
        }
        $aspect = ($dest[2] * 1080) / ($dest[3] * 1920);
        $width = min($game[2], $this->fullHeightWidth($aspect, $sw, $sh));
        $keys = $this->followKeys($action, $width, $cuts);
        // Keep the crop inside the game area so the webcam never shows twice.
        $lo = $game[0] + $width / 2;
        $hi = $game[0] + $game[2] - $width / 2;
        foreach ($keys as $i => $k) {
            $keys[$i]['cx'] = round($hi >= $lo ? max($lo, min($hi, $k['cx'])) : $game[0] + $game[2] / 2, 4);
        }

        return ['dest' => $dest, 'source' => 'main', 'track' => ['width' => round($width, 4), 'keys' => $keys]];
    }

    private function twoFacecams(array $facecams, array $tracks, ?array $game, int $sw, int $sh): ?array
    {
        $boxes = array_map(fn ($f) => $f['box'], array_slice($facecams, 0, 2));
        if (count($boxes) < 2) {
            // One inset holding two people: split it by the faces inside it.
            if (count($boxes) === 1 && count($tracks) >= 2) {
                $b = $boxes[0];
                $boxes = [[$b[0], $b[1], $b[2] / 2, $b[3]], [$b[0] + $b[2] / 2, $b[1], $b[2] / 2, $b[3]]];
            } else {
                return null;
            }
        }
        usort($boxes, fn ($a, $b) => $a[0] <=> $b[0]);
        $game ??= [0, 0, 1, 1];

        return [
            'background' => 'black',
            'divider' => ['color' => '#000000', 'thickness' => 8],
            'panels' => [
                ['dest' => [0, 0, 0.5, 0.34], 'source' => 'main', 'crop' => $boxes[0], 'inset' => true, 'primary' => true],
                ['dest' => [0.5, 0, 0.5, 0.34], 'source' => 'main', 'crop' => $boxes[1], 'inset' => true],
                ['dest' => [0, 0.34, 1, 0.66], 'source' => 'main', 'crop' => $this->centreCrop($game, 1080 / 1267, $sw, $sh)],
            ],
        ];
    }

    /** A crop of `box` narrowed to roughly the dest aspect around its centre (no stretching). */
    private function centreCrop(array $box, float $destAspect, int $sw, int $sh): array
    {
        [$x, $y, $w, $h] = $box;
        $aspect = ($w * $sw) / max(1e-6, $h * $sh);
        if ($aspect > $destAspect * 1.6) {
            // Very wide gameplay: keep the middle 62% so the action stays big.
            $nw = $w * 0.62;
            $x += ($w - $nw) / 2;
            $w = $nw;
        }

        return [round($x, 4), round($y, 4), round($w, 4), round($h, 4)];
    }

    /**
     * The whole frame over a blurred copy of itself.
     *
     * This is the layout of last resort and it is expensive: a 16:9 frame laid
     * whole into 9:16 fills barely a third of the phone, and the other two
     * thirds are blur. The first shipped run put ALL SEVEN shorts here, and the
     * result was a postage stamp of video floating in a smear — so the caller
     * now reaches this only for screen content and untrackable action.
     *
     * Two things make it less bad when it does happen: anything that is not
     * text gets cropped toward 4:3 first, which buys back half the wasted
     * height; and the panel sits above centre so the space underneath is a
     * caption area by design instead of dead blur.
     */
    private function blurFit(string $type, int $sw, int $sh, ?array $area): array
    {
        $crop = $area ?? [0, 0, 1, 1];
        $textual = in_array($type, ['screen_recording', 'presentation'], true);
        if (in_array($type, ['gameplay', 'sports'], true)) {
            // Action reads better a little closer than the full frame.
            $crop = [$crop[0] + $crop[2] * 0.12, $crop[1], $crop[2] * 0.76, $crop[3]];
        } elseif (!$textual) {
            // Trade edge-of-frame context for height. Not for slides or a
            // screen share: there, the edge of the frame IS the content.
            $crop = [$crop[0] + $crop[2] * 0.15, $crop[1], $crop[2] * 0.70, $crop[3]];
        }
        $aspect = ($crop[2] * $sw) / max(1, $crop[3] * $sh);
        $h = min(0.72, 1 / max(0.2, $aspect) * self::CANVAS_ASPECT);

        return [
            'background' => 'blur',
            'divider' => null,
            'panels' => [[
                'dest' => [0, round(max(0.06, 0.40 - $h / 2), 4), 1, round($h, 4)],
                'source' => 'main',
                'crop' => array_map(fn ($v) => round($v, 4), $crop),
                'primary' => true,
                'radius' => 0,
            ]],
        ];
    }

    private function stockSplit(?array $track, int $sw, int $sh, array $cuts): array
    {
        $aspect = 1080 / 1152;
        $width = $this->fullHeightWidth($aspect, $sw, $sh);

        return [
            'background' => 'black',
            'divider' => ['color' => '#000000', 'thickness' => 6],
            'panels' => [
                [
                    'dest' => [0, 0, 1, 0.6],
                    'source' => 'main',
                    'primary' => true,
                    'track' => ['width' => round($width, 4), 'keys' => $this->followKeys($track, $width, $cuts)],
                ],
                ['dest' => [0, 0.6, 1, 0.4], 'source' => 'gameplay'],
            ],
        ];
    }
}
