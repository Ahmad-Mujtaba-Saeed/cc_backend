<?php

namespace Modules\Mcp\Processors;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Mcp\Services\McpNarration;
use Modules\Mcp\Services\McpPreviewService;
use Modules\Mcp\Services\McpRenderService;
use Modules\Mcp\Services\PresenterService;
use Modules\Project\Processors\ExplainerVideoProcessor;
use Modules\Project\Services\SrtExportService;
use Modules\Project\Support\McpOrigin;

/**
 * The render job for a video the user's own model built over MCP.
 *
 * A strict subset of the explainer pipeline: every step that would call a
 * paid model (scene stylist, punchlines, AI illustrations and slot fills,
 * diagram-label vision, scenario sprites, the frame-review pass, the YouTube
 * kit copy, the thumbnail concept) is simply not here — the model already did
 * that work. What remains is free: the self-hosted voice, free stock b-roll,
 * the renderer, loudness mastering, captions and a frame-grab thumbnail.
 */
class McpExplainerProcessor extends ExplainerVideoProcessor
{
    public const STEPS = [
        'mcp_preflight' => 'Checking the scenes',
        'generate_narration' => 'Recording the voice-over',
        'fetch_stock' => 'Fetching stock b-roll',
        'assemble_storyboard' => 'Assembling the timeline',
        'render_video' => 'Rendering the video',
        'master_audio' => 'Mastering audio',
        'export_captions' => 'Exporting captions',
        'generate_thumbnail' => 'Making the thumbnail',
    ];

    public function process(): bool
    {
        try {
            $presenter = McpOrigin::isPresenter($this->project);

            if ($this->runProcessingStep('mcp_preflight', fn () => $this->preflight(), 4, self::STEPS['mcp_preflight'], 'Scenes checked', 'Scene check failed') === false) {
                return false;
            }

            if (!$presenter) {
                if ($this->runProcessingStep('generate_narration', fn () => $this->voiceScenes(), 12, self::STEPS['generate_narration'], 'Voice-over recorded', 'Voice-over failed') === false) {
                    return false;
                }
            }

            $this->runProcessingStep('fetch_stock', fn () => $this->fetchStockFootage(), 16, self::STEPS['fetch_stock'], 'Stock b-roll ready', 'Stock b-roll skipped');

            $scenes = $this->runProcessingStep('assemble_storyboard', fn () => $this->assembleScenes(), 20, self::STEPS['assemble_storyboard'], 'Timeline assembled', 'Failed to assemble the timeline');
            if ($scenes === false) {
                return false;
            }

            if ($this->runProcessingStep('render_video', fn () => $this->renderVideo($scenes), 88, self::STEPS['render_video'], 'Video rendered', 'Video rendering failed') === false) {
                return false;
            }

            $this->runProcessingStep('master_audio', fn () => $this->masterAudio(), 93, self::STEPS['master_audio'], 'Audio mastered', 'Audio mastering skipped');

            $this->runProcessingStep(
                'export_captions',
                fn () => $presenter ? $this->presenterCaptions() : $this->exportCaptions(),
                96,
                self::STEPS['export_captions'],
                'Captions exported',
                'Caption export skipped'
            );

            $this->runProcessingStep('generate_thumbnail', fn () => $this->frameThumbnail(), 98, self::STEPS['generate_thumbnail'], 'Thumbnail ready', 'Thumbnail skipped');

            $this->handleSuccess();

            return true;
        } catch (\Throwable $e) {
            Log::error('McpExplainerProcessor failed: ' . $e->getMessage(), [
                'project_id' => $this->project->id,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->handleFailure($e->getMessage());

            return false;
        }
    }

    /**
     * The same gate render_video ran, again (the storyboard may not have
     * changed, but a queued job should never trust a check made minutes ago),
     * plus a smoke test of every custom scene the model rendered unpreviewed.
     */
    protected function preflight(): bool
    {
        $report = (new McpRenderService($this->project->user))->preflight($this->project->fresh());
        if ($report['blocking'] !== []) {
            $this->project->update(['error_message' => implode(' ', $report['blocking'])]);

            return false;
        }

        if (McpOrigin::isPresenter($this->project)) {
            PresenterService::ensureFrames($this->project->fresh());
            $this->project->refresh();
        }

        $failed = [];
        $preview = new McpPreviewService();
        foreach ((array) ($this->project->settings['mcp']['smoke_test'] ?? []) as $sceneId) {
            try {
                $run = $preview->run($this->project->fresh(), (string) $sceneId, [0.1, 0.6], 0.35);
                if ($run['status'] === 'failed') {
                    $failed[] = "{$sceneId}: " . implode(' | ', array_slice($run['hard'], 0, 2));
                }
            } catch (\Throwable $e) {
                $failed[] = "{$sceneId}: " . $e->getMessage();
            }
        }
        if ($failed !== []) {
            $this->project->update(['error_message' => 'These scenes fail to render — fix them and render again: ' . implode(' ; ', $failed)]);

            return false;
        }

        return true;
    }

    /** Record every narrated scene's voice (cached ones are reused) and re-time it. */
    protected function voiceScenes(): bool
    {
        $failed = [];
        foreach ($this->project->explainerScenes()->orderBy('order')->get() as $scene) {
            $result = McpNarration::ensure($this->project->fresh(), $scene);
            if (!$result['ok']) {
                $failed[] = (string) $scene->scene_id;
            }
        }
        if ($failed !== []) {
            $this->project->update(['error_message' => 'The voice-over could not be recorded for scene(s) ' . implode(', ', $failed) . '. The voice engine may be down — try again shortly.']);

            return false;
        }

        $seconds = (float) $this->project->explainerScenes()->sum('duration_seconds');
        $max = (int) config('mcp.max_video_seconds', 900);
        if ($seconds > $max + 30) {
            $this->project->update(['error_message' => sprintf('With the voice recorded the video runs %.1f minutes — over the %d-minute limit. Shorten the narration.', $seconds / 60, $max / 60)]);

            return false;
        }

        return true;
    }

    /** Captions for a presenter video come straight from its transcript. */
    protected function presenterCaptions(): bool
    {
        try {
            $words = PresenterService::transcript($this->project)['words'] ?? [];
            $srt = (new SrtExportService())->exportWords($words, $this->project->output_path ?: $this->outputPath);
            if ($srt !== null) {
                $settings = $this->project->settings ?? [];
                $settings['srt_path'] = $srt;
                $this->project->update(['settings' => $settings]);
            }
        } catch (\Throwable $e) {
            Log::warning('McpExplainerProcessor: presenter captions failed (non-fatal): ' . $e->getMessage());
        }

        return true;
    }

    /** A frame grab a fifth of the way in — no paid thumbnail concept. */
    protected function frameThumbnail(): bool
    {
        try {
            $rel = $this->project->output_path ?: $this->outputPath;
            if (!Storage::disk('public')->exists($rel)) {
                return true;
            }
            $duration = (float) $this->ffprobe->format(Storage::disk('public')->path($rel))->get('duration');
            $t = (int) max(1, min(30, floor($duration * 0.2)));
            $this->generateThumbnail(sprintf('%02d:%02d:%02d', intdiv($t, 3600), intdiv($t % 3600, 60), $t % 60), $rel);
        } catch (\Throwable $e) {
            Log::warning('McpExplainerProcessor: thumbnail failed (non-fatal): ' . $e->getMessage());
        }

        return true;
    }

    protected function getProcessingSteps(): array
    {
        return self::STEPS;
    }
}
