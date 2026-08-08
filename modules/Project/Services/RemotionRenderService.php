<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Project\Models\Project;
use Modules\Project\Support\ExplainerRegistry;
use Symfony\Component\Process\Process;

/**
 * RemotionRenderService
 *
 * HTTP client for the standalone Node/Remotion render service (repo root:
 * remotion-render/). Mirrors the existing {@see \App\Services\PythonAIService}
 * pattern: Laravel POSTs a fully-resolved shot list + an absolute output path,
 * the Node service renders the MP4 to that path (same machine / shared volume)
 * and returns when done.
 *
 * Asset references inside the shot list are sent as public HTTP URLs so the
 * headless Chromium inside Remotion can fetch them directly.
 */
class RemotionRenderService
{
    /** Storyboard mood → Pixabay audio search category. */
    private const MOOD_MUSIC_CATEGORIES = [
        'neutral' => 'corporate',
        'upbeat' => 'happy',
        'calm' => 'relaxing',
        'dramatic' => 'cinematic',
        'tense' => 'cinematic',
        'inspirational' => 'emotional',
        'suspense' => 'cinematic',
    ];

    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.remotion.url', 'http://localhost:3020'), '/');
        $this->timeout = (int) config('services.remotion.timeout', 1800);
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(5)->get("{$this->baseUrl}/health")->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Render the given shot list to the project's output path.
     *
     * @param  array  $scenes  Validated scenes WITH asset_ref populated for image slots.
     * @param  string|null  $aspectOverride  Render at a different aspect than
     *         the project's own (§10.6 aspect variants) — same storyboard,
     *         different frame.
     * @return array{success: bool, output_path?: string, error?: string}
     */
    public function render(Project $project, array $scenes, string $outputRelativePath, ?string $aspectOverride = null): array
    {
        $aspect = $aspectOverride ?? ($project->aspect_ratio ?? '16:9');
        [$width, $height] = $this->dimensionsFor($aspect);

        $outputAbsolutePath = Storage::disk('public')->path($outputRelativePath);
        $this->ensureDir(dirname($outputAbsolutePath));

        $payload = $this->buildRenderPayload($project, $scenes, $outputAbsolutePath, $aspect, $width, $height);

        Log::info('RemotionRenderService: dispatching render', [
            'project_id' => $project->id,
            'scene_count' => count($scenes),
            'output' => $outputAbsolutePath,
            'dimensions' => "{$width}x{$height}",
        ]);

        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/render", $payload);
        } catch (\Throwable $e) {
            Log::error('RemotionRenderService: request threw', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Render service unreachable: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            Log::error('RemotionRenderService: render failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
            return ['success' => false, 'error' => "Render service error (HTTP {$response->status()})"];
        }

        $data = $response->json();
        if (!($data['success'] ?? false)) {
            return ['success' => false, 'error' => $data['error'] ?? 'Unknown render error'];
        }

        return ['success' => true, 'output_path' => $outputRelativePath];
    }

    /**
     * Build the render-service payload — the ONE shot list that both the MP4
     * render and the storyboard's still preview consume. Sharing it is the
     * whole point: a preview built from a parallel payload would drift from
     * the real render the first time either side changed.
     */
    public function buildRenderPayload(
        Project $project,
        array $scenes,
        string $outputAbsolutePath,
        string $aspect,
        int $width,
        int $height
    ): array {
        $settings = $project->settings ?? [];
        $theme = $this->resolveTheme($settings);
        $punchlines = is_array($settings['punchlines'] ?? null) ? $settings['punchlines'] : [];
        $styles = is_array($settings['scene_styles'] ?? null) ? $settings['scene_styles'] : [];

        $payload = [
            'project_id' => (string) $project->id,
            'output_path' => $outputAbsolutePath,
            'fps' => ExplainerRegistry::fps(),
            'width' => $width,
            'height' => $height,
            'shot_list' => [
                'project_id' => (string) $project->id,
                'aspect_ratio' => $aspect,
                'theme' => $theme,
                'music' => $this->resolveMusic($project, $scenes),
                // Sound-effects layer (flight whooshes, bullet pops, punchline
                // hits) — on by default, tunable per project.
                'sfx' => [
                    'enabled' => ($settings['sfx_enabled'] ?? true) !== false,
                    'volume' => (float) ($settings['sfx_volume'] ?? 1.0),
                    // 'auto' | 'studio' | 'procedural' — the render service
                    // vets a studio pack against its own filesystem and falls
                    // back to procedural when incomplete (SFX_CREDITS.md).
                    'pack' => (string) ($settings['sfx_pack'] ?? 'auto'),
                ],
                // Karaoke caption track (copilot.md §4.4). Default: on for
                // portrait (sound-off social feeds), off for landscape.
                'captions' => [
                    'enabled' => (bool) ($settings['captions_enabled'] ?? ($aspect === '9:16')),
                ],
                // Mood backdrop field (§11.5): a whisper-quiet geometric
                // texture on the flat colour field, keyed per scene mood.
                // Default on; the renderer only obeys an explicit true, so
                // pre-field payloads render byte-identically.
                'backdrop' => [
                    'enabled' => ($settings['backdrop_enabled'] ?? true) !== false,
                ],
                // Chapter progress chip (§10.3, default off) + brand kit
                // (§10.4): the logo watermark ships as a URL; the brand
                // COLOUR was already folded into the theme when it passed
                // the contrast gate (resolveTheme).
                'chapter_chip' => ['enabled' => (bool) ($settings['chapter_chip'] ?? false)],
                'brand' => !empty($settings['brand']['logo_path'])
                    ? ['logo_url' => $this->publicUrl((string) $settings['brand']['logo_path'])]
                    : null,
                // Typography pack (§4.7), motion style (§2.5) and skin
                // (§11.2); 'auto' resolves server-side so the renderer only
                // ever sees concrete names.
                'motion_style' => $this->resolveMotionStyle($settings, $scenes),
                'skin' => self::resolveSkin($settings),
                'font_pack' => $this->resolveFontPack($settings),
                // Cinematic canvas journey: mode + the director's world plan.
                // When the plan is missing in canvas mode, the renderer builds
                // a deterministic layout itself, so old projects still work.
                'composition_mode' => $settings['composition_mode'] ?? ExplainerRegistry::defaultCompositionMode(),
                // Math-board skin: explicit pick wins over the topic-picked
                // auto; anything unknown renders as the slate default.
                'board_style' => in_array($settings['board_style'] ?? null, ExplainerRegistry::boardStyleNames(), true)
                    ? $settings['board_style']
                    : (string) ($settings['board_style_auto'] ?? 'slate'),
                'canvas' => $this->resolveCanvasPlan($settings['canvas_plan'] ?? null),
                // Hybrid mode: the Composition Director's chapter plan, each
                // canvas chapter carrying its own world plan.
                'chapters' => $this->resolveChapterPlan($settings['chapter_plan'] ?? null),
                'scenes' => array_map(fn ($scene) => $this->resolveSceneAssets($scene, $punchlines, $styles), $scenes),
            ],
        ];

        // Per-chapter accent shift (§11.4, default off): each chapter after
        // the first gets the accent hue-rotated ±20° (alternating direction,
        // so successive chapters never drift monotonically off-palette),
        // computed HERE — the renderer just paints the hex (runtime
        // hue-rotate filters are banned inside the camera world).
        if (($settings['accent_shift'] ?? false) && !empty($payload['shot_list']['chapters']['chapters'])) {
            foreach ($payload['shot_list']['chapters']['chapters'] as $k => &$chapter) {
                if ($k > 0) {
                    $chapter['accent'] = $this->shiftHue((string) $theme['accent'], $k % 2 === 1 ? 20 : -20);
                }
            }
            unset($chapter);
        }

        return $payload;
    }

    /**
     * Freeze one composition frame to a PNG (storyboard style preview). Uses
     * {@see buildRenderPayload} so the still is the real renderer's own
     * output, not a mock-up.
     *
     * @param  int  $frame  composition frame (the caller maps scene → frame
     *         through ExplainerTimingMap, so both agree on the clock)
     * @return array{success: bool, output_path?: string, error?: string, render_seconds?: float}
     */
    public function previewStill(Project $project, array $scenes, string $outputRelativePath, int $frame, float $scale = 0.5): array
    {
        $aspect = $project->aspect_ratio ?? '16:9';
        [$width, $height] = $this->dimensionsFor($aspect);

        $outputAbsolutePath = Storage::disk('public')->path($outputRelativePath);

        // Deliberately NOT ensureDir() here. Unlike the queued render, a
        // preview is triggered by a WEB request running as www-data, which has
        // no write permission on the worker-created storage tree — creating
        // the directory from PHP is what used to fail with "mkdir(): Permission
        // denied". It is also unnecessary: the render service writes the PNG
        // itself and already mkdir -p's the target directory on its own side.
        $payload = $this->buildRenderPayload($project, $scenes, $outputAbsolutePath, $aspect, $width, $height);
        $payload['frame'] = max(0, $frame);
        $payload['scale'] = $scale;

        try {
            // Short timeout on purpose: a preview is interactive. A warm
            // bundle answers in seconds; anything longer means the service is
            // busy with a real render and the UI should just say so.
            $response = Http::timeout((int) config('services.remotion.preview_timeout', 180))
                ->post("{$this->baseUrl}/preview", $payload);
        } catch (\Throwable $e) {
            Log::warning('RemotionRenderService: preview request threw: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Preview service unreachable'];
        }

        if (!$response->successful() || !($response->json('success') ?? false)) {
            Log::warning('RemotionRenderService: preview failed', [
                'project_id' => $project->id,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 300),
            ]);

            return ['success' => false, 'error' => $response->json('error') ?? "Preview error (HTTP {$response->status()})"];
        }

        return [
            'success' => true,
            'output_path' => $outputRelativePath,
            'render_seconds' => (float) ($response->json('render_seconds') ?? 0),
        ];
    }

    /**
     * The scheme this render paints with. The brand kit's colour (§10.4) may
     * override the accent, but ONLY when it holds ≥4.5:1 contrast against
     * both the paper and the ink — an unreadable brand accent is ignored
     * (the storyboard shows a notice via brandColorApplied()).
     */
    public function resolveTheme(array $settings): array
    {
        $theme = ExplainerRegistry::colorScheme($settings['color_scheme'] ?? null);
        if (self::brandColorApplied($settings, $theme)) {
            $theme['accent'] = strtoupper((string) $settings['brand']['color']);
        }

        return $theme;
    }

    /** Does the configured brand colour pass the §10.4 contrast gate? */
    public static function brandColorApplied(array $settings, ?array $theme = null): bool
    {
        $color = (string) ($settings['brand']['color'] ?? '');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            return false;
        }
        $theme = $theme ?? ExplainerRegistry::colorScheme($settings['color_scheme'] ?? null);

        return \Modules\Project\Support\SceneBudgetLinter::contrastRatio($color, (string) ($theme['bg_from'] ?? '#000000')) >= 4.5
            && \Modules\Project\Support\SceneBudgetLinter::contrastRatio($color, (string) ($theme['text'] ?? '#FFFFFF')) >= 4.5;
    }

    /**
     * Thumbnail generator (§10.5): renderStill the dedicated ThumbnailComp at
     * 1280×720 AND 1080×1920 next to the video. Returns relative paths keyed
     * by orientation, or null when the service call failed (caller falls back
     * to an ffmpeg frame grab).
     *
     * @return array{landscape: string, portrait: string}|null
     */
    public function renderThumbnail(Project $project, ?string $heroRelativePath, string $outputRelativeMp4, ?string $equation = null): ?array
    {
        $settings = $project->settings ?? [];
        $base = preg_replace('/\.mp4$/i', '', $outputRelativeMp4) ?: $outputRelativeMp4;
        $targets = [
            'landscape' => ['path' => "{$base}_thumb.png", 'w' => 1280, 'h' => 720],
            'portrait' => ['path' => "{$base}_thumb_portrait.png", 'w' => 1080, 'h' => 1920],
        ];

        $props = [
            'title' => (string) $project->title,
            'kicker' => '',
            'theme' => $this->resolveTheme($settings),
            'hero_url' => $heroRelativePath ? $this->publicUrl($heroRelativePath) : null,
            'equation' => $equation ?: null,
            'font_pack' => $this->resolveFontPack($settings),
        ];

        $out = [];
        foreach ($targets as $key => $t) {
            try {
                $response = Http::timeout(120)->post("{$this->baseUrl}/thumbnail", [
                    'output_path' => Storage::disk('public')->path($t['path']),
                    'props' => $props + ['width' => $t['w'], 'height' => $t['h']],
                ]);
                if (!$response->successful() || !($response->json('success') ?? false)) {
                    Log::warning('RemotionRenderService: thumbnail render failed', [
                        'project_id' => $project->id,
                        'status' => $response->status(),
                    ]);

                    return null;
                }
                $out[$key] = $t['path'];
            } catch (\Throwable $e) {
                Log::warning('RemotionRenderService: thumbnail request threw: ' . $e->getMessage());

                return null;
            }
        }

        return ['landscape' => $out['landscape'], 'portrait' => $out['portrait']];
    }

    /** Rotate a #rrggbb hue by `deg` degrees (saturation/lightness kept). */
    private function shiftHue(string $hex, float $deg): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) !== 6) {
            return $hex;
        }
        [$r, $g, $b] = [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0) {
            return $hex; // achromatic — nothing to rotate
        }
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $hue = match ($max) {
            $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        } * 60;

        $hue = fmod($hue + $deg + 360, 360);

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r2, $g2, $b2] = match (true) {
            $hue < 60 => [$c, $x, 0],
            $hue < 120 => [$x, $c, 0],
            $hue < 180 => [0, $c, $x],
            $hue < 240 => [0, $x, $c],
            $hue < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return sprintf(
            '#%02X%02X%02X',
            (int) round(($r2 + $m) * 255),
            (int) round(($g2 + $m) * 255),
            (int) round(($b2 + $m) * 255)
        );
    }

    /**
     * The concrete motion style for this render (copilot.md §2.5): the
     * user's explicit pick, else the auto-theme suggestion stamped at
     * analyze, else the mood map over the dominant scene mood.
     */
    private function resolveMotionStyle(array $settings, array $scenes): string
    {
        $names = ExplainerRegistry::motionStyleNames();
        $explicit = (string) ($settings['motion_style'] ?? ExplainerRegistry::defaultMotionStyle());
        if (in_array($explicit, $names, true)) {
            return $explicit;
        }

        $auto = (string) ($settings['motion_style_auto'] ?? '');
        if (in_array($auto, $names, true)) {
            return $auto;
        }

        $moods = array_count_values(array_map(fn ($s) => (string) ($s['mood'] ?? 'neutral'), $scenes));
        arsort($moods);

        return ExplainerRegistry::motionStyleForMood((string) (array_key_first($moods) ?? 'neutral'));
    }

    /**
     * The concrete skin (§11.2): explicit pick, else auto suggestion, else
     * flat. Public static so the storyboard payload exposes the SAME
     * resolution (`skin_resolved`) the render uses — the UI needs the answer
     * to know whether the blueprint skin's fixed palette has taken the
     * colour scheme out of play, and must never re-implement the rule.
     */
    public static function resolveSkin(array $settings): string
    {
        $names = ExplainerRegistry::skinNames();
        // Unset means "auto" — the analyze-time suggestion decides.
        $explicit = (string) ($settings['skin'] ?? 'auto');
        if (in_array($explicit, $names, true)) {
            return $explicit;
        }

        $auto = (string) ($settings['skin_auto'] ?? '');

        return in_array($auto, $names, true) ? $auto : 'flat';
    }

    /**
     * The concrete font pack for this render (copilot.md §4.7). 'auto'
     * follows the auto-theme suggestion, then the resolved motion style
     * (classic→classic pack, swiss→tech), with the print skin always taking
     * the serif pack and the blueprint skin the mono-flavoured tech pack.
     */
    private function resolveFontPack(array $settings): string
    {
        $pack = (string) ($settings['font_pack'] ?? ExplainerRegistry::defaultFontPack());
        if (in_array($pack, ExplainerRegistry::fontPackNames(), true)) {
            return $pack;
        }

        $skin = self::resolveSkin($settings);
        if ($skin === 'print') {
            return 'classic';
        }
        // Blueprint labels are mono by design — the tech pack carries the
        // mono-flavoured faces. An explicit pack (above) still wins.
        if ($skin === 'blueprint') {
            return 'tech';
        }

        $auto = (string) ($settings['font_pack_auto'] ?? '');
        if (in_array($auto, ExplainerRegistry::fontPackNames(), true)) {
            return $auto;
        }

        $style = (string) ($settings['motion_style_auto'] ?? '');
        if (!in_array($style, ExplainerRegistry::motionStyleNames(), true)) {
            $style = (string) ($settings['motion_style'] ?? '');
        }

        return ExplainerRegistry::fontPackForStyle($style);
    }

    /**
     * Replace each image slot's stored relative path with a public URL the
     * render service can fetch over HTTP; enrich the scene with everything
     * the renderer needs for deterministic, narration-synced playback.
     *
     * @param  array<string, array{text: string, style?: string}>  $punchlines  keyed by scene_id
     * @param  array<string, array>  $styles  scene stylist output keyed by scene_id
     */
    private function resolveSceneAssets(array $scene, array $punchlines = [], array $styles = []): array
    {
        // Presentation personality from the scene stylist (renderer-facing
        // keys only — the illustration brief already did its job upstream).
        $style = $styles[(string) ($scene['scene_id'] ?? '')] ?? null;
        if (is_array($style)) {
            $scene['style'] = [
                'variant' => (string) ($style['variant'] ?? 'editorial'),
                'kicker' => (string) ($style['kicker'] ?? ''),
                'highlight' => array_values((array) ($style['highlight'] ?? [])),
            ];
        }

        // NB: iterate a real local variable, not `$scene['slots'] ?? []`. A
        // by-reference foreach over a `??` expression binds to a temporary copy,
        // so the asset_ref writes below would be silently discarded (this is what
        // left image slots without a `url`, rendering only the placeholder text).
        $slots = $scene['slots'] ?? [];
        foreach ($slots as $slotKey => &$slot) {
            // Scenario sprites: per-entity alpha cutouts become URLs the
            // browser bundle can load.
            if (($slot['content_type'] ?? null) === 'scenario') {
                foreach ((array) ($slot['entities'] ?? []) as $i => $entity) {
                    $spritePath = $entity['sprite_path'] ?? null;
                    if ($spritePath) {
                        $slot['entities'][$i]['sprite_url'] = $this->publicUrl($spritePath);
                    }
                }
            }
            if (in_array($slot['content_type'] ?? null, ['image', 'video'], true)) {
                $ref = $slot['asset_ref'] ?? null;
                $path = is_array($ref) ? ($ref['path'] ?? null) : $ref;
                $type = is_array($ref) ? ($ref['type'] ?? null) : null;
                $slot['asset_ref'] = $path
                    ? ['url' => $this->publicUrl($path), 'path' => $path, 'type' => $type ?? $slot['content_type']]
                    : null;

                if ($slot['asset_ref'] && preg_match('/\.(mp4|webm|mov|m4v)$/i', (string) $path)) {
                    // Variable-frame-rate uploads (phone/screen recordings kept
                    // by the old `-c copy` remux) render as stuck, repeating
                    // frames in the html5 <Video> path — repair them in place
                    // once, then ship the real duration + dimensions so the
                    // clip can <Loop> safely and fit its slot without cropping.
                    $this->ensureRenderSafeVideo($path);
                    $info = $this->probeVideo($path);
                    if ($info) {
                        $slot['asset_ref']['duration_seconds'] = $info['duration'];
                        $slot['asset_ref']['width'] = $info['width'];
                        $slot['asset_ref']['height'] = $info['height'];

                        // Deterministic playback: pre-extract the clip to a
                        // 30fps JPEG sequence (cached, idempotent). Rendering
                        // stills can never seek backwards/stutter the way a
                        // per-frame-seeked <video> element can. Only the part
                        // of the clip this scene actually shows is extracted.
                        $frames = $this->ensureFrameSequence($path, $info, (float) ($scene['duration_seconds'] ?? 6));
                        if ($frames) {
                            $slot['asset_ref']['frames'] = $frames;
                        }
                    }
                } elseif ($slot['asset_ref']) {
                    // Real pixel dimensions let the slot CONTAIN a mismatched
                    // image (portrait photo in a landscape region) over a
                    // blurred fill instead of centre-cropping it to bits.
                    [$w, $h] = $this->probeImageDimensions($path);
                    $slot['asset_ref']['width'] = $w;
                    $slot['asset_ref']['height'] = $h;

                    // Smart crop (§8): the saliency focal point steers
                    // object-position when the slot covers, so a crop never
                    // beheads the subject. Cached in a sidecar per image.
                    $focus = $this->ensureImageFocus((string) $path);
                    if ($focus !== null) {
                        $slot['asset_ref']['focus'] = $focus;
                    }
                }
            }
        }
        unset($slot);
        $scene['slots'] = $slots;

        // Scene-level focus: the first focused media slot's point, used by
        // the mask_wipe_circle presentation as its reveal origin.
        foreach ($slots as $slot) {
            if (!empty($slot['asset_ref']['focus'])) {
                $scene['focus'] = $slot['asset_ref']['focus'];
                break;
            }
        }

        // Optional AI-generated ambient background (text-only scenes).
        if (!empty($scene['ambient_image_path'])) {
            $scene['ambient_image_url'] = $this->publicUrl($scene['ambient_image_path']);
        }

        // Optional AI-generated illustration (media-less scenes in canvas mode).
        if (!empty($scene['illustration_image_path'])) {
            $scene['illustration_url'] = $this->publicUrl($scene['illustration_image_path']);
        }

        // Optional fal Chatterbox narration audio.
        if (!empty($scene['narration_audio_path'])) {
            $scene['narration_audio_url'] = $this->publicUrl($scene['narration_audio_path']);
        }

        // Word-level narration timings (Kokoro sidecar written next to the wav).
        $words = [];
        if (!empty($scene['narration_audio_path'])) {
            $sidecar = preg_replace('/\.wav$/i', '.words.json', (string) $scene['narration_audio_path']);
            if ($sidecar && Storage::disk('public')->exists($sidecar)) {
                $decoded = json_decode((string) Storage::disk('public')->get($sidecar), true);
                if (is_array($decoded)) {
                    $words = $decoded;
                    $scene['narration_words'] = $decoded;
                }
            }
        }

        // Narration-synced punchline: align the chosen phrase to the real word
        // timestamps; without timings, distribute it across the narration.
        $pick = $punchlines[(string) ($scene['scene_id'] ?? '')] ?? null;
        if (is_array($pick) && trim((string) ($pick['text'] ?? '')) !== '') {
            $service = new PunchlineService();
            $timing = $service->align((string) $pick['text'], $words)
                ?? $service->distributeAcross(
                    (string) $pick['text'],
                    max(1.0, (float) ($scene['duration_seconds'] ?? 6) - 0.6)
                );
            $scene['punchline'] = [
                'text' => (string) $pick['text'],
                'style' => (string) ($pick['style'] ?? 'glass'),
                'start' => $timing['start'],
                'end' => $timing['end'],
                'words' => $timing['words'],
            ];
        }

        return $scene;
    }

    /**
     * Extract a slot video into a cached 30fps JPEG frame sequence next to the
     * source file (`<name>_frames30/frame_%05d.jpg`). Idempotent: a stamp file
     * records the source's mtime and how many seconds were extracted, so a
     * re-uploaded video — or the same clip reused by a longer scene — is
     * re-extracted exactly once. Returns null (fall back to <Video>) only when
     * the clip is unreadable or extraction fails.
     *
     * Only the HEAD of the clip is extracted. A slot video always starts at
     * time 0 when its scene comes on screen and unmounts when the scene leaves,
     * so nothing past the scene's own window is ever visible — extracting a
     * 159s stock clip in full would mean ~4.8k JPEGs to show 11s of them. The
     * previous code gave up on any clip over 45s and let it fall through to
     * <Video>, whose per-frame seek stalls on long files and takes the whole
     * render down with a delayRender() timeout.
     *
     * @param  float  $sceneSeconds  duration of the scene this slot belongs to
     * @return array{url_prefix: string, count: int, fps: int}|null
     */
    private function ensureFrameSequence(string $relativePath, array $info, float $sceneSeconds): ?array
    {
        try {
            $duration = (float) ($info['duration'] ?? 0);
            if ($duration <= 0) {
                return null;
            }

            // On-screen life of a slot video, per CanvasJourney's mediaFrom /
            // mediaUntil: from 0.2s before its scene starts until 0.5s into the
            // flight away from it, and a flight is capped at 2.4s. Four seconds
            // of pad covers that worst case. The 90s ceiling only bites on
            // absurdly long scenes, where the strip loops rather than hangs.
            $cap = min($duration, max(2.0, $sceneSeconds) + 4.0, 90.0);
            $capStr = number_format($cap, 3, '.', '');

            $abs = Storage::disk('public')->path($relativePath);
            $dirRel = preg_replace('/\.[^.\/]+$/', '', $relativePath) . '_frames30';
            $dirAbs = Storage::disk('public')->path($dirRel);
            $stamp = $dirAbs . '/.source';
            $sourceMtime = (string) @filemtime($abs);

            $count = 0;
            if (is_file($stamp)) {
                // Stamp is "<mtime>:<extracted seconds>". Stamps written before
                // head-only extraction hold the mtime alone and covered the
                // whole clip, so treat a missing second field as full coverage.
                $parts = explode(':', trim((string) @file_get_contents($stamp)), 2);
                $covered = isset($parts[1]) ? (float) $parts[1] : $duration;
                if (($parts[0] ?? '') === $sourceMtime && $covered >= $cap - 0.001) {
                    $count = count(glob($dirAbs . '/frame_*.jpg') ?: []);
                }
            }

            if ($count < 1) {
                // (Re)extract: wipe any stale frames first.
                if (is_dir($dirAbs)) {
                    foreach (glob($dirAbs . '/frame_*.jpg') ?: [] as $old) {
                        @unlink($old);
                    }
                } else {
                    @mkdir($dirAbs, 0775, true);
                }

                $ffmpeg = new Process([
                    'ffmpeg', '-v', 'error', '-y', '-i', $abs,
                    '-t', $capStr,
                    // Cap at 1920 (the widest the renderer ever outputs, 16:9
                    // landscape) rather than downscaling every upload to
                    // 1600 regardless of the render target — a 1080p/4K
                    // source used to lose real sharpness here for no reason.
                    '-vf', 'fps=30,scale=w=min(1920\\,iw):h=-2',
                    '-q:v', '2', $dirAbs . '/frame_%05d.jpg',
                ]);
                $ffmpeg->setTimeout(600);
                $ffmpeg->run();

                $count = count(glob($dirAbs . '/frame_*.jpg') ?: []);
                if (!$ffmpeg->isSuccessful() || $count < 1) {
                    Log::warning('remotion: frame extraction failed, falling back to <Video>', [
                        'path' => $relativePath,
                        'error' => trim($ffmpeg->getErrorOutput()),
                    ]);
                    return null;
                }
                @file_put_contents($stamp, $sourceMtime . ':' . $capStr);
                Log::info('remotion: extracted video frame sequence', [
                    'path' => $dirRel,
                    'frames' => $count,
                    'seconds' => $cap,
                    'clip_seconds' => $duration,
                ]);
            }

            return [
                'url_prefix' => $this->publicUrl($dirRel) . '/frame_',
                'count' => $count,
                'fps' => 30,
            ];
        } catch (\Throwable $e) {
            Log::warning('remotion: frame extraction error', ['path' => $relativePath, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Give every decorative prop in the canvas plan a public URL the render
     * service can fetch. Props without a resolved library path are dropped.
     */
    private function resolveCanvasPlan(?array $plan): ?array
    {
        if (!is_array($plan) || empty($plan['items'])) {
            return $plan;
        }

        foreach ($plan['items'] as &$item) {
            $props = [];
            foreach (($item['props'] ?? []) as $prop) {
                if (!empty($prop['path'])) {
                    $prop['url'] = $this->publicUrl($prop['path']);
                    $props[] = $prop;
                }
            }
            $item['props'] = $props;
        }
        unset($item);

        return $plan;
    }

    /**
     * Resolve each canvas chapter's embedded world plan (prop URLs etc.) the
     * same way the whole-video plan is resolved.
     */
    private function resolveChapterPlan(?array $plan): ?array
    {
        if (!is_array($plan) || empty($plan['chapters'])) {
            return $plan;
        }

        foreach ($plan['chapters'] as &$chapter) {
            if (!empty($chapter['canvas'])) {
                $chapter['canvas'] = $this->resolveCanvasPlan($chapter['canvas']);
            }
        }
        unset($chapter);

        return $plan;
    }

    /**
     * Pick a background-music track for the video based on its dominant scene
     * mood. Returns null when disabled or when the library is empty.
     */
    private function resolveMusic(Project $project, array $scenes): ?array
    {
        $settings = $project->settings ?? [];
        if (($settings['music_enabled'] ?? true) === false) {
            return null;
        }

        $category = strtolower(trim((string) ($settings['music_category'] ?? 'auto')));
        if ($category === 'none') {
            return null;
        }

        // Dominant mood across scenes.
        $counts = [];
        foreach ($scenes as $scene) {
            $mood = $scene['mood'] ?? 'neutral';
            $counts[$mood] = ($counts[$mood] ?? 0) + 1;
        }
        arsort($counts);
        $dominantMood = array_key_first($counts) ?: 'neutral';

        // Pixabay first ('auto' maps the storyboard's dominant mood onto a
        // search category), then the legacy local library, so installs
        // without a Pixabay key keep their old behaviour.
        $pixabayCategory = ($category === '' || $category === 'auto')
            ? (self::MOOD_MUSIC_CATEGORIES[$dominantMood] ?? 'corporate')
            : $category;

        $chosenTrack = isset($settings['music_track_id']) && $settings['music_track_id'] !== ''
            ? (string) $settings['music_track_id']
            : null;

        // Whichever music source the admin selected (Pixabay or Jamendo),
        // then the legacy local library, so installs with no provider key at
        // all keep their old behaviour.
        $track = $settings['music_track']
            ?? MusicProviderFactory::make()->pickTrack($pixabayCategory, (int) $project->id, $chosenTrack)
            ?? (new BackgroundMusicService())->pickTrackForMood($dominantMood, (int) $project->id, ExplainerRegistry::moods());

        if (!$track) {
            return null;
        }

        // Last line of defence: Remotion fails the ENTIRE render when an audio
        // src 404s ("Could not play audio … MediaError"), so a bed whose file
        // is not on disk must become silence here, never a URL. The providers
        // already verify their downloads; this catches a file deleted between
        // the pick and the render, and any future caller that forgets.
        if (!(new MusicLibraryService())->isHealthy($track)) {
            Log::warning('RemotionRenderService: music track missing on disk — rendering without a bed', [
                'project_id' => $project->id,
                'track' => $track,
            ]);

            return null;
        }

        return [
            'url' => $this->publicUrl($track),
            // Quiet bed: the Remotion side also ducks this under narration.
            'volume' => (float) ($settings['music_volume'] ?? \Modules\Project\Contracts\MusicProviderInterface::DEFAULT_VOLUME),
            'mood' => $dominantMood,
        ];
    }

    /**
     * One ffprobe pass: duration, dimensions, codec and whether the stream is
     * variable-frame-rate (r_frame_rate disagreeing with avg_frame_rate).
     *
     * @return array{duration: ?float, width: ?int, height: ?int, codec: string, pix_fmt: string, fps: float, vfr: bool}|null
     */
    private function probeVideo(string $relativePath): ?array
    {
        try {
            $abs = Storage::disk('public')->path($relativePath);
            $probe = new Process([
                'ffprobe', '-v', 'error', '-select_streams', 'v:0',
                '-show_entries', 'stream=codec_name,pix_fmt,width,height,r_frame_rate,avg_frame_rate:format=duration',
                '-of', 'json', $abs,
            ]);
            $probe->setTimeout(30);
            $probe->run();
            $data = json_decode($probe->getOutput(), true);
            $stream = $data['streams'][0] ?? null;
            if (!$stream) {
                return null;
            }

            $rate = function (?string $frac): float {
                if (!$frac) {
                    return 0.0;
                }
                if (!str_contains($frac, '/')) {
                    return (float) $frac;
                }
                [$n, $d] = explode('/', $frac, 2);
                return (float) $d > 0 ? (float) $n / (float) $d : 0.0;
            };

            $r = $rate($stream['r_frame_rate'] ?? null);
            $avg = $rate($stream['avg_frame_rate'] ?? null);
            $duration = (float) ($data['format']['duration'] ?? 0);

            return [
                'duration' => $duration > 0 ? $duration : null,
                'width' => isset($stream['width']) ? (int) $stream['width'] : null,
                'height' => isset($stream['height']) ? (int) $stream['height'] : null,
                'codec' => (string) ($stream['codec_name'] ?? ''),
                'pix_fmt' => (string) ($stream['pix_fmt'] ?? ''),
                'fps' => $avg > 0 ? $avg : $r,
                // VFR when the container's nominal rate and the real average
                // disagree meaningfully — the classic phone/screen recording.
                'vfr' => $r > 0 && $avg > 0 && abs($r - $avg) / max($r, $avg) > 0.02,
            ];
        } catch (\Throwable $e) {
            Log::warning('remotion: ffprobe failed', ['path' => $relativePath, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Repair a slot video the html5 <Video> render path can't play smoothly:
     * variable frame rate, exotic codecs/pixel formats or very high fps all
     * come out as stuck/duplicated frames. Transcodes IN PLACE to constant
     * 30fps h264/yuv420p (capped at 1920px wide) exactly once — after the
     * rewrite the file probes clean and later renders skip straight through.
     * Best-effort: on any failure the original file is kept.
     */
    private function ensureRenderSafeVideo(string $relativePath): void
    {
        try {
            $info = $this->probeVideo($relativePath);
            if (!$info) {
                return;
            }
            $safe = !$info['vfr']
                && $info['codec'] === 'h264'
                && $info['pix_fmt'] === 'yuv420p'
                && $info['fps'] > 0 && $info['fps'] <= 61;
            if ($safe) {
                return;
            }

            $abs = Storage::disk('public')->path($relativePath);
            $tmp = $abs . '.cfr.mp4';
            $ffmpeg = new Process([
                'ffmpeg', '-v', 'error', '-y', '-i', $abs,
                '-vf', 'fps=30,scale=w=min(1920\\,iw):h=-2',
                '-c:v', 'libx264', '-preset', 'slow', '-crf', '16', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-movflags', '+faststart', $tmp,
            ]);
            $ffmpeg->setTimeout(600);
            $ffmpeg->run();

            if ($ffmpeg->isSuccessful() && is_file($tmp) && filesize($tmp) > 0) {
                rename($tmp, $abs);
                Log::info('remotion: normalized slot video to CFR for render', ['path' => $relativePath]);
            } else {
                @unlink($tmp);
                Log::warning('remotion: CFR normalization failed, keeping original', [
                    'path' => $relativePath,
                    'error' => trim($ffmpeg->getErrorOutput()),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('remotion: CFR normalization error', ['path' => $relativePath, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cached saliency focus of an image (§8 smart crop). The FastAPI
     * /smart-crop result lives in a `<image>.focus.json` sidecar so each
     * image is analysed exactly once; on service failure nothing is cached
     * (a later render retries) and the caller gets null — focus is always
     * decorative.
     *
     * @return array{fx: float, fy: float}|null
     */
    private function ensureImageFocus(string $relativePath): ?array
    {
        try {
            $sidecar = $relativePath . '.focus.json';
            if (Storage::disk('public')->exists($sidecar)) {
                $decoded = json_decode((string) Storage::disk('public')->get($sidecar), true);
                if (is_array($decoded) && isset($decoded['fx'], $decoded['fy'])) {
                    return ['fx' => (float) $decoded['fx'], 'fy' => (float) $decoded['fy']];
                }
            }

            $focus = (new \App\Services\PythonAIService())->smartCrop(Storage::disk('public')->path($relativePath));
            if ($focus !== null) {
                Storage::disk('public')->put($sidecar, json_encode($focus));
            }

            return $focus;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{0: ?int, 1: ?int} [width, height]
     */
    private function probeImageDimensions(string $relativePath): array
    {
        try {
            $abs = Storage::disk('public')->path($relativePath);
            $size = @getimagesize($abs);
            if (is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0) {
                return [(int) $size[0], (int) $size[1]];
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return [null, null];
    }

    private function publicUrl(string $relativePath): string
    {
        // Prefer an explicitly configured public base (so the Node service can
        // reach Laravel even when APP_URL is an internal hostname).
        $base = config('services.remotion.asset_base_url') ?: config('app.url');
        $base = rtrim((string) $base, '/');

        return $base . '/storage/' . ltrim($relativePath, '/');
    }

    private function dimensionsFor(string $aspectRatio): array
    {
        return match ($aspectRatio) {
            '9:16' => [1080, 1920],
            '1:1' => [1080, 1080],
            default => [1920, 1080],
        };
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
