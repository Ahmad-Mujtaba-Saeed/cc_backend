<?php

namespace Modules\Mcp\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Mcp\Server\ToolException;
use Modules\Mcp\Server\ToolResult;
use Modules\Mcp\Support\McpPayload;
use Modules\Mcp\Support\McpScenes;
use Modules\Project\Models\Project;
use Modules\Project\Services\HeroSceneService;
use Modules\Project\Services\RemotionRenderService;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\ExplainerSceneAssembler;
use Modules\Project\Support\McpOrigin;

/**
 * preview_scene: the model's eyes.
 *
 * Records the scene's voice (so timing is real, not estimated), renders a few
 * frames of the scene ALONE through the real renderer in the video's real
 * look, and hands the frames back as images together with what the renderer
 * measured: text running off the frame, text too small or overlapping
 * (src/hero/audit.tsx), code that threw and fell back to the plain card, slow
 * or empty frames. The model looks, fixes, and previews again — the loop
 * every good motion designer runs, minus the waiting.
 *
 * The render job reuses run() as a smoke test for scenes the model rendered
 * without previewing (render_video force=true).
 */
final class McpPreviewService
{
    /** @param  float[]  $fractions  points in the scene, 0..1 */
    public function preview(Project $project, string $sceneId, array $fractions, int $userId): ToolResult
    {
        $this->quota($userId);
        $run = $this->run($project, $sceneId, $fractions, 0.5);

        $lines = [
            "Scene {$sceneId}: {$run['status']}. Length {$run['seconds']} s ({$run['frames']} frames at {$run['fps']} fps), frame {$run['width']}×{$run['height']} (previews are half size).",
        ];
        if ($run['voice'] !== null) {
            $lines[] = $run['voice']['ok']
                ? 'Voice recorded' . ($run['voice']['cached'] ? ' (cached)' : '') . '. Word timings (word@frame): ' . self::wordLine((array) $run['voice']['words'], $run['fps'])
                : 'Voice could not be recorded (' . ($run['voice']['error'] ?? 'unknown') . '); timing is an estimate.';
        } elseif ($run['presenter']) {
            $lines[] = 'Words in this window (word@scene-frame): ' . self::wordLine($run['words'], $run['fps']);
        }
        if ($run['hard'] !== []) {
            $lines[] = "MUST FIX:\n- " . implode("\n- ", $run['hard']);
        }
        if ($run['soft'] !== []) {
            $lines[] = "SHOULD FIX:\n- " . implode("\n- ", array_slice($run['soft'], 0, 10));
        }
        // ONE image, sized to fit the client's tool-result cap: several frames
        // become a labelled contact sheet; a single requested moment comes
        // back larger. (Full-size frames individually blew past Claude.ai's
        // ~150k-character cap and the client dropped every one of them.)
        $items = [];
        $order = [];
        foreach ($run['stills'] as $i => $s) {
            $abs = Storage::disk('public')->path($s['path']);
            $pct = (int) round(($run['fractions'][$i] ?? 0) * 100);
            $items[] = ['path' => $abs, 'label' => sprintf('%d  %d%%  f%d', $i + 1, $pct, $s['frame'])];
            $order[] = sprintf('%d) %d%% (frame %d)', $i + 1, $pct, $s['frame']);
        }
        $image = $items === [] ? null : \Modules\Mcp\Support\McpImages::sheet($items, null, count($items) === 1 ? 1280 : 1400);

        if ($image !== null) {
            $lines[] = count($items) === 1
                ? 'Attached: the frame at ' . $order[0] . '.'
                : 'Attached: ONE contact sheet of ' . count($items) . ' frames, left to right then top to bottom — ' . implode(', ', $order)
                    . '. Each tile is labelled. For a closer look at one moment, call preview_scene with a single `at` value.';
            $lines[] = $run['status'] === 'passed'
                ? 'Judge it as a viewer would: is the idea instantly clear, is the type big and calm, does it move with the words, is anything clipped or crowded? If it is merely OK, improve it; otherwise move on.'
                : 'Fix the points above (upsert_scene with the whole corrected code), then preview again.';
        } else {
            $lines[] = 'NO IMAGE could be attached this time (the frames rendered but could not be encoded). Rely on the measured problems above, and tell the user they can open the frames below.';
        }
        // Full-size stills for the human (the model gets the sheet above).
        $urls = array_map(fn ($s) => Storage::disk('public')->url($s['path']), $run['stills']);
        if ($urls !== []) {
            $lines[] = 'Full-size frames (for the user to open): ' . implode(' ', $urls);
        }

        $result = ToolResult::text(implode("\n\n", $lines));
        if ($image !== null) {
            $result->addImage($image, 'image/jpeg');
        }

        return $result;
    }

    /**
     * Render and measure one scene.
     *
     * @return array{status: string, hard: string[], soft: string[], stills: array, fractions: float[], at: int[],
     *               frames: int, fps: int, seconds: float, width: int, height: int, voice: ?array,
     *               presenter: bool, words: array}
     */
    public function run(Project $project, string $sceneId, array $fractions, float $scale): array
    {
        $scene = $project->explainerScenes()->where('scene_id', $sceneId)->first();
        if (!$scene) {
            throw new ToolException("Scene {$sceneId} does not exist.");
        }
        $meta = (array) ($project->settings['mcp']['scenes'][$sceneId] ?? []);
        if (($meta['kind'] ?? '') === 'custom' && ($meta['code_status'] ?? '') !== 'ok') {
            throw new ToolException("Scene {$sceneId}'s code does not compile yet: " . implode(' | ', (array) ($meta['code_errors'] ?? [])) . ' — fix it with upsert_scene first.');
        }

        $presenter = McpOrigin::isPresenter($project);
        $voice = null;
        if ($presenter) {
            PresenterService::ensureFrames($project);
        } else {
            $voice = McpNarration::ensure($project->fresh(), $scene);
            $scene->refresh();
        }
        $project->refresh();

        $fps = ExplainerRegistry::resolveFps($project->settings ?? []);
        $aspect = $project->aspect_ratio ?? '16:9';
        [$width, $height] = RemotionRenderService::dimensionsForAspect($aspect);

        // One scene, through the SAME payload builder the render uses.
        $assembled = ExplainerSceneAssembler::assemble($project, true);
        $one = null;
        foreach ($assembled['scenes'] as $s) {
            if ((string) $s['scene_id'] === $sceneId) {
                $one = $s;
                break;
            }
        }
        if ($one === null) {
            throw new ToolException('The scene could not be assembled.');
        }

        McpPayload::$previewWindow = $presenter ? [(float) ($meta['start'] ?? 0), (float) ($meta['end'] ?? 0)] : null;
        try {
            $payload = (new RemotionRenderService())->buildRenderPayload($project, [$one], '/tmp/mcp-preview.mp4', $aspect, $width, $height);
        } finally {
            McpPayload::$previewWindow = null;
        }
        $shot = $payload['shot_list'];
        $payloadScene = $shot['scenes'][0] ?? null;
        if ($payloadScene === null) {
            throw new ToolException('The scene produced no frames.');
        }
        if (!empty($payloadScene['hero'])) {
            $payloadScene['hero']['audit'] = true; // measure its text
        }
        $payloadScene['transition'] = 'none';
        $shot['scenes'] = [$payloadScene];
        $shot['music'] = null;
        if (!$presenter) {
            $shot['composition_mode'] = 'slides';
        }

        $frames = (int) max(2, round(((float) ($payloadScene['duration_seconds'] ?? 4)) * $fps));
        $fractions = array_values(array_unique(array_map(fn ($f) => max(0.0, min(1.0, (float) $f)), $fractions ?: [0.08, 0.35, 0.65, 0.95])));
        $fractions = array_slice($fractions, 0, 6);
        $at = array_map(fn ($f) => (int) round($f * ($frames - 1)), $fractions);

        $rel = "explainer/{$project->id}/mcp_preview/" . preg_replace('/[^A-Za-z0-9_-]/', '_', $sceneId) . '-' . substr(md5(microtime()), 0, 6);
        Storage::disk('public')->makeDirectory($rel);
        $url = \Modules\Mcp\Support\McpRouting::renderUrl();

        try {
            $r = Http::timeout(240)->post("{$url}/hero/test", [
                'shot_list' => $shot,
                'frames' => $at,
                'output_dir' => Storage::disk('public')->path($rel),
                'fps' => $fps,
                'width' => $width,
                'height' => $height,
                'scale' => $scale,
            ]);
        } catch (\Throwable $e) {
            throw new ToolException('The render service could not be reached for the preview: ' . $e->getMessage());
        }
        $json = (array) $r->json();
        if (!($json['success'] ?? false)) {
            $this->record($project, $sceneId, 'failed', ['render failed: ' . (string) ($json['error'] ?? 'unknown')], 0);
            throw new ToolException('The preview render failed: ' . mb_substr((string) ($json['error'] ?? 'unknown error'), 0, 400));
        }

        // What the renderer reported.
        $hard = [];
        $soft = [];
        foreach ((array) ($json['hero_errors'] ?? []) as $e) {
            $e = (string) $e;
            if (preg_match('/\[hero\] audit: (off-frame|small|overlap): (.*)$/s', $e, $m)) {
                if ($m[1] === 'off-frame') {
                    $hard[] = 'Text off-frame: ' . mb_substr($m[2], 0, 220);
                } else {
                    $soft[] = ucfirst($m[1]) . ' text: ' . mb_substr($m[2], 0, 220);
                }
                continue;
            }
            $hard[] = 'Your code threw at render time, so the scene FELL BACK to a plain title card: ' . mb_substr($e, 0, 300);
        }
        $stills = [];
        $slowest = 0;
        foreach ((array) ($json['stills'] ?? []) as $s) {
            $stills[] = ['frame' => (int) ($s['frame'] ?? 0), 'path' => $rel . '/' . basename((string) ($s['output_path'] ?? ''))];
            $slowest = max($slowest, (int) ($s['ms'] ?? 0));
        }
        if (count($stills) < count($at)) {
            $hard[] = (count($at) - count($stills)) . ' frame(s) could not be drawn at all.';
        }
        // Each still pays a page load (~1-3 s), so only a clearly slow frame
        // means the scene itself is heavy (same bar the hero acceptance uses).
        $fullMs = $slowest;
        if ($slowest > 15000) {
            $hard[] = "A frame took {$slowest} ms to draw — far too slow for a " . round($frames / $fps) . " s scene at {$fps} fps. Cut element counts, SVG filters and blur.";
        } elseif ($slowest > 9000) {
            $soft[] = "A frame took {$slowest} ms to draw; keep the scene lighter (fewer filters/elements/blur) so long videos render in reasonable time.";
        }
        $flat = 0;
        foreach ($stills as $s) {
            if (HeroSceneService::isFlat(Storage::disk('public')->path($s['path']))) {
                $flat++;
            }
        }
        if ($flat >= 2 || ($flat >= 1 && count($stills) === 1)) {
            $soft[] = "{$flat} sampled frame(s) are a single flat colour — make something visible from the first frame and keep it on screen.";
        }
        foreach (array_slice((array) ($json['browser_errors'] ?? []), 0, 3) as $b) {
            if (!preg_match('/favicon|DevTools/i', (string) $b)) {
                $soft[] = 'Browser: ' . mb_substr((string) $b, 0, 200);
            }
        }

        $status = $hard !== [] ? 'failed' : ($soft !== [] ? 'issues' : 'passed');
        $this->record($project, $sceneId, $status, array_merge($hard, $soft), $fullMs);
        $this->prune($project, $sceneId, $rel);

        return [
            'status' => $status,
            'hard' => $hard,
            'soft' => $soft,
            'stills' => $stills,
            'fractions' => $fractions,
            'at' => $at,
            'frames' => $frames,
            'fps' => $fps,
            'seconds' => round($frames / $fps, 2),
            'width' => $width,
            'height' => $height,
            'voice' => $voice,
            'presenter' => $presenter,
            'words' => (array) ($payloadScene['narration_words'] ?? []),
        ];
    }

    private function quota(int $userId): void
    {
        $key = "mcp:previews:{$userId}:" . now()->format('YmdH');
        $used = (int) Cache::get($key, 0);
        $limit = (int) config('mcp.previews_per_hour', 150);
        if ($used >= $limit) {
            throw new ToolException("Preview limit reached ({$limit} per hour). Continue writing scenes and preview again later, or render.");
        }
        Cache::put($key, $used + 1, now()->addHours(2));
    }

    private function record(Project $project, string $sceneId, string $status, array $notes, int $slowest): void
    {
        McpVideoService::locked($project, function (Project $p) use ($sceneId, $status, $notes, $slowest) {
            $s = $p->settings ?? [];
            if (!isset($s['mcp']['scenes'][$sceneId])) {
                return;
            }
            $s['mcp']['scenes'][$sceneId]['preview'] = [
                'status' => $status,
                'code_hash' => $s['mcp']['scenes'][$sceneId]['code_hash'] ?? null,
                'at' => now()->toIso8601String(),
                'notes' => array_slice($notes, 0, 8),
                'slowest_ms' => $slowest,
            ];
            $p->update(['settings' => $s]);
        });
    }

    private function prune(Project $project, string $sceneId, string $keep): void
    {
        $disk = Storage::disk('public');
        $base = "explainer/{$project->id}/mcp_preview";
        $prefix = $base . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $sceneId) . '-';
        foreach ($disk->directories($base) as $d) {
            if (str_starts_with($d, $prefix) && $d !== $keep) {
                $disk->deleteDirectory($d);
            }
        }
    }

    private static function wordLine(array $words, int $fps): string
    {
        if ($words === []) {
            return '(none)';
        }
        $parts = [];
        foreach (array_slice($words, 0, 160) as $w) {
            $parts[] = preg_replace('/\s+/', '', (string) ($w['word'] ?? '')) . '@' . (int) round(((float) ($w['start'] ?? 0)) * $fps);
        }

        return implode(' ', $parts) . (count($words) > 160 ? ' …' : '');
    }
}
