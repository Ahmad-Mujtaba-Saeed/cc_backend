<?php

namespace Modules\Project\Services\Shorts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Builds the `ViralShort` props (remotion-render src/shorts/types.ts) and asks
 * the render service to turn them into an MP4.
 */
class ViralShortRenderService
{
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.remotion.url', 'http://localhost:3020'), '/');
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

    /** Public URL of a file on the public disk, as the render host fetches it. */
    public static function assetUrl(string $relativePath): string
    {
        $base = rtrim((string) (config('services.remotion.asset_base_url') ?: config('app.url')), '/');
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($relativePath, '/'))));

        return $base . '/storage/' . $encoded;
    }

    /**
     * @param  array<string, mixed>  $layout  ShortLayoutPlanner::plan()
     * @param  array<string, mixed>  $style  ShortStylePlanner style
     * @param  array<string, mixed>  $edit  ShortEditDirector::direct()
     * @param  array<string, mixed>  $analysis
     * @param  array{url: string, duration: float}|null  $gameplay
     * @return array<string, mixed>
     */
    public function buildProps(
        string $clipRelativePath,
        float $clipDuration,
        array $analysis,
        array $layout,
        array $style,
        array $edit,
        ?array $gameplay,
        bool $captionsEnabled,
        ?array $music = null
    ): array {
        $rendererStyle = ShortStylePlanner::forRenderer($style);
        $rendererStyle['caption']['y'] = $this->captionY($layout, (float) $rendererStyle['caption']['y']);

        $panels = $layout['panels'];
        $hasGameplayPanel = (bool) array_filter($panels, fn ($p) => $p['source'] === 'gameplay');
        if (!$gameplay && $hasGameplayPanel) {
            // No loop to show: a stock-gameplay layout loses its bottom panel
            // and the speaker takes the whole frame.
            $panels = array_values(array_filter($panels, fn ($p) => $p['source'] !== 'gameplay'));
            if (count($panels) === 1) {
                $panels[0]['dest'] = [0, 0, 1, 1];
            }
        }

        return [
            'width' => 1080,
            'height' => 1920,
            'fps' => 30,
            'video' => [
                'url' => self::assetUrl($clipRelativePath),
                'width' => (int) ($analysis['source_width'] ?? 1920),
                'height' => (int) ($analysis['source_height'] ?? 1080),
                'duration' => round($clipDuration, 3),
            ],
            'gameplay' => $gameplay ? [
                'url' => $gameplay['url'],
                'startFrom' => 0,
                'duration' => round((float) $gameplay['duration'], 3),
            ] : null,
            'segments' => $edit['segments'],
            'layout' => [
                'kind' => (string) $layout['kind'],
                'panels' => $panels,
                'background' => $layout['background'] ?? 'black',
                'divider' => $layout['divider'] ?? null,
            ],
            'style' => $rendererStyle,
            'words' => $edit['words'],
            'captionsEnabled' => $captionsEnabled,
            'hook' => $edit['hook'],
            'events' => $this->takeovers($this->placeCards($edit['events'], (string) $layout['kind']), (string) $layout['kind']),
            // Mixed inside the composition rather than by the ffmpeg pass that
            // runs after a render: in here the bed is on the same clock as the
            // edit, so it survives the freezes and slow-mo the director adds.
            'music' => $music ? [
                'url' => self::assetUrl($music['path']),
                'volume' => round((float) $music['volume'], 4),
            ] : null,
            'sourceVolume' => 1,
        ];
    }

    /**
     * Captions sit where they read best for the layout: on the seam of a
     * split, under a fitted frame, or wherever the style put them on a fill.
     */
    private function captionY(array $layout, float $styleY): float
    {
        switch ($layout['kind'] ?? '') {
            case 'stack_two':
                return 0.5;
            case 'gameplay_split_stock':
                return 0.6;
            case 'facecam_top_gameplay':
                // On the seam, wherever this short's split put it.
                $p = $layout['panels'][0]['dest'] ?? [0, 0, 1, 0.4];
                return round($p[1] + $p[3], 3);
            case 'facecam_bubble_gameplay':
                return 0.68;
            case 'two_facecams_top_gameplay':
                return 0.36;
            case 'blur_fit':
                $p = $layout['panels'][0]['dest'] ?? [0, 0.3, 1, 0.3];
                return round(min(0.86, $p[1] + $p[3] + 0.09), 3);
            default:
                return $styleY;
        }
    }

    /**
     * Reaction takeovers on stream layouts: the director's strongest punch-ins
     * mark the moments the streamer reacts hardest, and on a stream those are
     * the moments to hand the whole screen to the webcam — the signature move
     * of every good stream clip. At most two, never back to back, each long
     * enough to read the face (1.3-2.4s).
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function takeovers(array $events, string $kind): array
    {
        if (!in_array($kind, ['facecam_top_gameplay', 'facecam_bubble_gameplay'], true)) {
            return $events;
        }
        $zooms = array_values(array_filter($events, fn ($e) => ($e['type'] ?? '') === 'zoom' && ($e['start'] ?? 0) >= 1.5));
        usort($zooms, fn ($a, $b) => ($b['scale'] ?? 1) <=> ($a['scale'] ?? 1));
        $picked = [];
        foreach ($zooms as $z) {
            $start = max(0.0, (float) $z['start'] - 0.15);
            $end = $start + max(1.3, min(2.4, (float) $z['end'] - (float) $z['start'] + 0.6));
            foreach ($picked as $p) {
                if ($start < $p['end'] + 6.0 && $end > $p['start'] - 6.0) {
                    continue 2;
                }
            }
            $picked[] = ['type' => 'takeover', 'start' => round($start, 3), 'end' => round($end, 3)];
            if (count($picked) >= 2) {
                break;
            }
        }

        return array_merge($events, $picked);
    }

    /**
     * Context cards go where they cannot cover a face. The director alternates
     * top/bottom without knowing the layout, and on a facecam split the top
     * card landed squarely on the streamer's face (project 195). Split layouts
     * keep the face in the top panel, so their cards go to the bottom.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function placeCards(array $events, string $kind): array
    {
        $place = match ($kind) {
            'facecam_top_gameplay', 'facecam_bubble_gameplay', 'two_facecams_top_gameplay', 'gameplay_split_stock', 'stack_two' => 'bottom',
            default => null,
        };
        if ($place === null) {
            return $events;
        }
        foreach ($events as $i => $e) {
            if (($e['type'] ?? '') === 'context') {
                $events[$i]['place'] = $place;
            }
        }

        return $events;
    }

    /**
     * @return array{success: bool, error?: string, render_seconds?: float}
     */
    public function render(array $props, string $outputAbsolutePath, int $projectId, int $clipIndex): array
    {
        try {
            $response = Http::timeout($this->timeout)->post("{$this->baseUrl}/render-short", [
                'project_id' => $projectId,
                'clip' => $clipIndex,
                'output_path' => $outputAbsolutePath,
                'props' => $props,
            ]);
            if (!$response->successful() || !$response->json('success')) {
                return ['success' => false, 'error' => (string) ($response->json('error') ?? $response->body())];
            }

            return ['success' => true, 'render_seconds' => (float) $response->json('render_seconds', 0)];
        } catch (\Throwable $e) {
            Log::warning('[SHORTS] Render service call failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
