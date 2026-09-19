<?php

namespace Modules\Project\Services\Shorts;

use App\Services\PythonAIService;
use Illuminate\Support\Facades\Log;

/**
 * What is on screen in one cut clip, and where.
 *
 * Two readers, merged:
 *
 *  1. The Python analyser (/analyze-clip) — deterministic, frame-accurate:
 *     tracked face boxes over time, scene cuts, motion, loudness peaks, and a
 *     few downscaled frames written to disk.
 *  2. The vision model (services.openai.shorts_vision_model, gpt-4o-mini by
 *     default) looks at those frames with the transcript and says what KIND of
 *     scene this is — a two-person podcast, a streamer's facecam over
 *     gameplay, a screen recording — and marks the regions a layout needs
 *     (facecam box, gameplay area, each person). It also points at the frames
 *     with a visible reaction, which is where a meme edit hits hardest.
 *
 * The result is plain data for ShortLayoutPlanner and ShortEditDirector. Every
 * part degrades: no vision answer means the face tracks decide alone; no face
 * tracks means a centred crop.
 */
class ClipSceneAnalyzer
{
    public const SCENE_TYPES = [
        'podcast', 'interview', 'talking_head', 'gameplay_facecam', 'gameplay',
        'screen_recording', 'vlog', 'sports', 'reaction', 'presentation', 'other',
    ];

    public const LAYOUTS = [
        'fill_follow', 'stack_two', 'facecam_top_gameplay', 'two_facecams_top_gameplay',
        'blur_fit', 'gameplay_split_stock',
    ];

    private const REGION_ROLES = ['facecam', 'person', 'gameplay', 'screen', 'content'];

    public function __construct(private PythonAIService $python = new PythonAIService())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $clipPath, string $framesDir, string $transcript, int $projectId, int $clipIndex): array
    {
        $raw = $this->runPython($clipPath, $framesDir, $projectId);
        $vision = $raw ? $this->runVision($raw, $transcript, $clipIndex) : null;

        $analysis = $this->merge($raw, $vision);

        Log::info('[SHORTS] Scene analysed', [
            'project_id' => $projectId,
            'clip' => $clipIndex,
            'scene_type' => $analysis['scene_type'],
            'layout_hint' => $analysis['recommended_layout'],
            'people' => $analysis['people_on_screen'],
            'face_tracks' => count($analysis['face_tracks']),
            'camera_coverage' => $analysis['camera_track']['coverage'] ?? null,
            'regions' => array_map(fn ($r) => $r['role'], $analysis['regions']),
            'vision' => $vision !== null,
        ]);

        return $analysis;
    }

    /** @return array<string, mixed>|null */
    private function runPython(string $clipPath, string $framesDir, int $projectId): ?array
    {
        try {
            $response = $this->python->makeRequest('POST', '/analyze-clip', [
                'video_path' => $clipPath,
                'frames_dir' => $framesDir,
                'interval_seconds' => 0.35,
                'max_samples' => 400,
                'vlm_frames' => 8,
                'vlm_width' => 640,
                'project_id' => $projectId,
            ]);
            if (empty($response['success'])) {
                Log::warning('[SHORTS] Clip analysis unavailable', ['error' => $response['error'] ?? null]);
                return null;
            }

            // The analyser writes under its own mount; map back to ours.
            foreach ($response['vlm_frames'] ?? [] as $i => $frame) {
                $response['vlm_frames'][$i]['path'] = self::toLocalPath((string) ($frame['path'] ?? ''));
            }

            return $response;
        } catch (\Throwable $e) {
            Log::warning('[SHORTS] Clip analysis failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public static function toLocalPath(string $path): string
    {
        if (str_starts_with($path, '/app/') && !is_file($path)) {
            $candidate = '/var/www/' . substr($path, strlen('/app/'));
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $path;
    }

    /** @return array<string, mixed>|null */
    private function runVision(array $raw, string $transcript, int $clipIndex): ?array
    {
        $model = (string) config('services.openai.shorts_vision_model', 'gpt-4o-mini');
        $frames = array_values(array_filter($raw['vlm_frames'] ?? [], fn ($f) => is_file($f['path'] ?? '')));
        if (!$frames) {
            return null;
        }

        $tracks = array_map(fn ($t) => sprintf(
            '%s: centre x=%.2f y=%.2f, size %.2f of width, on screen %d%% of the time',
            $t['id'],
            $t['cx'],
            $t['cy'],
            $t['size'],
            (int) round($t['presence'] * 100)
        ), $raw['face_tracks'] ?? []);

        $instructions = <<<TXT
You are the assistant editor for a vertical (9:16) short cut from a longer 16:9 video. Study the numbered frames (in time order) and the transcript, then describe the footage so an automatic editor can lay it out.

Scene types: podcast (2+ people talking at a table/mics), interview, talking_head (one person to camera), gameplay_facecam (video game footage with a streamer's webcam overlay), gameplay (game footage, no webcam), screen_recording (desktop/app/slides), vlog (handheld, outdoors, IRL), sports, reaction (someone reacting to other content shown on screen), presentation (stage/lecture), other.

Regions: mark every area a vertical layout might crop to, as boxes in 0-1 frame coordinates [x, y, w, h] (x,y = top-left). Roles:
- facecam: a webcam/camera inset overlaid on other content (usually a small rectangle in a corner)
- person: a person filmed directly (not an inset) — one box per person, head and shoulders
- gameplay: the game footage area (excluding any facecam inset)
- screen: a shared screen / slides / browser area
- content: anything else worth keeping (the thing being reacted to, a product)
Boxes must hug the region; if the frame is split into panels, give each panel.

Layouts (pick the one that will look best on a phone):
- fill_follow: crop tightly and follow one subject
- stack_two: two people each get half the height (podcast/interview with 2 people apart)
- facecam_top_gameplay: the facecam on top, the gameplay under it
- two_facecams_top_gameplay: two facecams/people side by side on top, gameplay under
- blur_fit: keep the whole frame visible over a blurred copy (screens, wide shots, 3+ people, text-heavy)
- gameplay_split_stock: one talking person on top, unrelated satisfying gameplay below (only for a single static talking head with little visual interest)

Also list reaction_frames: frames where a face shows a strong, meme-able reaction (shock, laughing, screaming, disgust, jaw drop), with a 2-5 word label.

Answer ONLY with JSON:
{"scene_type": "...", "people_on_screen": 0, "source_is_split_screen": false, "regions": [{"role": "person", "label": "host, left", "box": [0.1, 0.2, 0.3, 0.5], "frames": [1, 2]}], "recommended_layout": "...", "visual_summary": "one sentence", "setting": "short phrase", "mood": "hype|funny|serious|emotional|educational|chill|tense", "reaction_frames": [{"frame": 3, "label": "shocked face"}], "on_screen_text": false, "brand_safe": true}
TXT;

        $content = [['type' => 'text', 'text' => $instructions]];
        $content[] = ['type' => 'text', 'text' => 'Transcript: ' . mb_substr(trim($transcript), 0, 1500)];
        if ($tracks) {
            $content[] = ['type' => 'text', 'text' => "Face tracker (approximate):\n" . implode("\n", $tracks)];
        }
        foreach ($frames as $i => $frame) {
            $uri = ShortsLlm::imageDataUri($frame['path']);
            if ($uri === null) {
                continue;
            }
            $content[] = ['type' => 'text', 'text' => sprintf('Frame %d (t=%.1fs):', $i + 1, $frame['t'])];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $uri, 'detail' => 'low']];
        }

        $answer = ShortsLlm::json(
            $model,
            [['role' => 'user', 'content' => $content]],
            900,
            'shorts_scene_vision',
            'low',
            120
        );
        if (!$answer) {
            return null;
        }

        // Frame numbers back to seconds, for the director.
        $answer['_frame_times'] = array_map(fn ($f) => (float) $f['t'], $frames);

        return $answer;
    }

    /** @return array<string, mixed> */
    private function merge(?array $raw, ?array $vision): array
    {
        $tracks = array_values($raw['face_tracks'] ?? []);
        $camera = is_array($raw['camera_track'] ?? null) ? $raw['camera_track'] : null;
        $width = (int) ($raw['width'] ?? 1920);
        $height = (int) ($raw['height'] ?? 1080);

        $sceneType = (string) ($vision['scene_type'] ?? '');
        if (!in_array($sceneType, self::SCENE_TYPES, true)) {
            $sceneType = $this->guessSceneType($raw, $tracks);
        }

        $regions = [];
        foreach ((array) ($vision['regions'] ?? []) as $r) {
            if (!is_array($r) || !in_array($r['role'] ?? null, self::REGION_ROLES, true)) {
                continue;
            }
            $box = self::cleanBox($r['box'] ?? null);
            if ($box === null) {
                continue;
            }
            $regions[] = [
                'role' => (string) $r['role'],
                'label' => mb_substr(trim((string) ($r['label'] ?? '')), 0, 60),
                'box' => $box,
            ];
        }
        $regions = self::reconcileFacecams(array_slice($regions, 0, 8), $tracks, $width, $height);
        $webcam = is_array($raw['webcam'] ?? null) ? self::cleanBox($raw['webcam']['box'] ?? null) : null;
        if ($webcam) {
            [$regions, $sceneType] = self::applyWebcam($regions, $sceneType, $webcam);
        }

        $layout = (string) ($vision['recommended_layout'] ?? '');
        if (!in_array($layout, self::LAYOUTS, true)) {
            $layout = '';
        }

        $times = $vision['_frame_times'] ?? [];
        $reactions = [];
        foreach ((array) ($vision['reaction_frames'] ?? []) as $rf) {
            $idx = (int) ($rf['frame'] ?? 0) - 1;
            if (!isset($times[$idx])) {
                continue;
            }
            $reactions[] = [
                't' => (float) $times[$idx],
                'label' => mb_substr(trim((string) ($rf['label'] ?? 'reaction')), 0, 40),
            ];
        }

        $people = (int) ($vision['people_on_screen'] ?? 0);
        if ($people <= 0) {
            $people = count($tracks);
        }

        return [
            'source_width' => $width,
            'source_height' => $height,
            'duration' => (float) ($raw['duration'] ?? 0),
            'scene_type' => $sceneType,
            'people_on_screen' => max(0, min(12, $people)),
            'source_is_split_screen' => (bool) ($vision['source_is_split_screen'] ?? false),
            'regions' => $regions,
            'recommended_layout' => $layout,
            'visual_summary' => mb_substr(trim((string) ($vision['visual_summary'] ?? '')), 0, 240),
            'setting' => mb_substr(trim((string) ($vision['setting'] ?? '')), 0, 80),
            'mood' => mb_substr(trim((string) ($vision['mood'] ?? '')), 0, 20),
            'reaction_moments' => $reactions,
            'on_screen_text' => (bool) ($vision['on_screen_text'] ?? false),
            'face_tracks' => $tracks,
            // Where the crop should look at each sample, built from ALL
            // detections rather than one track (see _camera_path in
            // clip_analysis.py). Null only when nothing was ever detected.
            'camera_track' => $camera,
            // The streamer's webcam found from pixels (edge persistence around
            // the one face that never moves), and where the game action is.
            'webcam' => $webcam,
            'action_track' => is_array($raw['action_track'] ?? null) ? $raw['action_track'] : null,
            'faces_typical' => (int) ($raw['faces_typical'] ?? 0),
            'scene_cuts' => array_values(array_map('floatval', $raw['scene_cuts'] ?? [])),
            'audio_peaks' => array_values($raw['audio_peaks'] ?? []),
            'energy' => array_values($raw['energy'] ?? []),
            'motion_mean' => (float) ($raw['motion_mean'] ?? 0),
            'faces_per_frame' => (float) ($raw['faces_per_frame'] ?? 0),
            'vision_used' => $vision !== null,
        ];
    }

    /** No vision answer: infer what we can from motion and faces. */
    private function guessSceneType(?array $raw, array $tracks): string
    {
        if (!$raw) {
            return 'other';
        }
        $motion = (float) ($raw['motion_mean'] ?? 0);
        $n = count($tracks);
        if ($n >= 2) {
            return 'podcast';
        }
        if ($n === 1) {
            $t = $tracks[0];
            // A small face in a corner over a busy frame is a facecam.
            if ($t['size'] < 0.12 && $motion > 0.06 && ($t['cx'] < 0.3 || $t['cx'] > 0.7)) {
                return 'gameplay_facecam';
            }
            return 'talking_head';
        }

        return $motion > 0.08 ? 'gameplay' : 'other';
    }

    /**
     * A webcam the analyser FOUND beats any box the vision model guessed: the
     * model can tell a stream has a webcam but not where it is (project 195:
     * the same top-left placeholder on 7 of 8 clips). The detected box replaces
     * every facecam region, and footage with a webcam inset is a stream even
     * when the model called it plain gameplay or "other".
     *
     * @param  array<int, array<string, mixed>>  $regions
     * @param  array{0: float, 1: float, 2: float, 3: float}  $webcam
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    public static function applyWebcam(array $regions, string $sceneType, array $webcam): array
    {
        $regions = array_values(array_filter($regions, fn ($r) => $r['role'] !== 'facecam'));
        array_unshift($regions, ['role' => 'facecam', 'label' => 'webcam (detected)', 'box' => $webcam]);
        if (in_array($sceneType, ['gameplay', 'other', 'reaction', 'sports'], true)) {
            $sceneType = $sceneType === 'reaction' ? 'reaction' : 'gameplay_facecam';
        }

        return [array_slice($regions, 0, 8), $sceneType];
    }

    /**
     * A stream's webcam does not move between clips, but a clip where the
     * streamer looked away or leaned out has no steady face to find it from
     * (3 of 8 on project 195). When the clips that DID find it agree, every
     * other stream clip in the batch gets the same box.
     *
     * @param  array<int, array<string, mixed>>  $analyses
     * @return array<int, array<string, mixed>>
     */
    public static function shareWebcam(array $analyses): array
    {
        $found = array_values(array_filter(array_map(fn ($a) => $a['webcam'] ?? null, $analyses)));
        if (!$found) {
            return $analyses;
        }
        $median = [];
        for ($i = 0; $i < 4; $i++) {
            $v = array_column($found, $i);
            sort($v);
            $median[$i] = $v[intdiv(count($v), 2)];
        }
        $agree = array_filter($found, fn ($b) => self::iou($b, $median) >= 0.6);
        if (count($agree) * 2 <= count($found)) {
            return $analyses;   // the clips disagree: nothing trustworthy to share
        }
        foreach ($analyses as $i => $a) {
            if (!empty($a['webcam']) || !in_array($a['scene_type'] ?? '', ['gameplay_facecam', 'gameplay', 'reaction', 'other', 'sports'], true)) {
                continue;
            }
            $analyses[$i]['webcam'] = $median;
            [$analyses[$i]['regions'], $analyses[$i]['scene_type']] = self::applyWebcam($a['regions'] ?? [], (string) $a['scene_type'], $median);
            $analyses[$i]['webcam_shared'] = true;
        }

        return $analyses;
    }

    private static function iou(array $a, array $b): float
    {
        $x1 = max($a[0], $b[0]);
        $y1 = max($a[1], $b[1]);
        $x2 = min($a[0] + $a[2], $b[0] + $b[2]);
        $y2 = min($a[1] + $a[3], $b[1] + $b[3]);
        $inter = max(0, $x2 - $x1) * max(0, $y2 - $y1);
        $union = $a[2] * $a[3] + $b[2] * $b[3] - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Check every vision "facecam" box against the face detector.
     *
     * The vision model reads the frame at 640px and its boxes are guesses: on
     * project 195 (a Wolverine stream) it put the facecam on the boss health
     * bar in the top-left, while YuNet had the streamer's face at 99% coverage
     * in the webcam further down — and the short's whole top panel was a health
     * bar. A webcam face is the one face that never moves, so when a facecam box
     * does not contain the steadiest small face, the box is rebuilt around that
     * face instead. No steady face → the vision box stands (nothing better).
     *
     * @param  array<int, array<string, mixed>>  $regions
     * @param  array<int, array<string, mixed>>  $tracks
     * @return array<int, array<string, mixed>>
     */
    public static function reconcileFacecams(array $regions, array $tracks, int $width, int $height): array
    {
        $anchor = self::steadyFace($tracks);
        if ($anchor === null) {
            return $regions;
        }
        foreach ($regions as $i => $r) {
            if ($r['role'] !== 'facecam') {
                continue;
            }
            [$x, $y, $w, $h] = $r['box'];
            $inside = $anchor['cx'] >= $x && $anchor['cx'] <= $x + $w
                && $anchor['cy'] >= $y && $anchor['cy'] <= $y + $h;
            if ($inside) {
                continue;
            }
            // Head and shoulders at roughly the facecam panel's shape (1080x768),
            // the face a little above centre the way webcams are framed.
            $bw = max(0.14, min(0.4, $anchor['size'] * 6.0));
            $bh = min(0.6, $bw * ($width / max(1, $height)) / (1080 / 768));
            $regions[$i]['box'] = self::cleanBox([
                max(0.0, min(1.0 - $bw, $anchor['cx'] - $bw / 2)),
                max(0.0, min(1.0 - $bh, $anchor['cy'] - $bh * 0.45)),
                $bw,
                $bh,
            ]) ?? $r['box'];
            $regions[$i]['label'] = trim($r['label'] . ' (snapped to face)');
        }

        return $regions;
    }

    /**
     * The face that sits still for most of the clip — a webcam inset, not a
     * game character or a passer-by. Null when no face is both present and
     * steady enough to trust over the vision model.
     *
     * @param  array<int, array<string, mixed>>  $tracks
     */
    private static function steadyFace(array $tracks): ?array
    {
        $best = null;
        foreach ($tracks as $t) {
            $pts = $t['points'] ?? [];
            if (($t['presence'] ?? 0) < 0.3 || count($pts) < 4 || ($t['size'] ?? 1) > 0.2) {
                continue;
            }
            $xs = array_map(fn ($p) => (float) $p['x'] + (float) $p['w'] / 2, $pts);
            $ys = array_map(fn ($p) => (float) $p['y'] + (float) $p['h'] / 2, $pts);
            sort($xs);
            sort($ys);
            $n = count($xs);
            $q = fn ($a, $f) => $a[(int) floor(($n - 1) * $f)];
            $spread = max($q($xs, 0.9) - $q($xs, 0.1), $q($ys, 0.9) - $q($ys, 0.1));
            if ($spread > 0.04) {
                continue;
            }
            if (!$best || $t['presence'] > $best['presence']) {
                $best = $t;
            }
        }

        return $best;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    public static function cleanBox(mixed $box): ?array
    {
        if (!is_array($box) || count($box) < 4) {
            return null;
        }
        $v = array_map(fn ($n) => is_numeric($n) ? (float) $n : NAN, array_slice(array_values($box), 0, 4));
        foreach ($v as $n) {
            if (is_nan($n)) {
                return null;
            }
        }
        // Accept percentages too.
        if (max($v) > 1.5) {
            $v = array_map(fn ($n) => $n / 100, $v);
        }
        [$x, $y, $w, $h] = $v;
        $x = max(0.0, min(1.0, $x));
        $y = max(0.0, min(1.0, $y));
        $w = max(0.0, min(1.0 - $x, $w));
        $h = max(0.0, min(1.0 - $y, $h));
        if ($w < 0.03 || $h < 0.03) {
            return null;
        }

        return [round($x, 4), round($y, 4), round($w, 4), round($h, 4)];
    }
}
