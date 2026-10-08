<?php

namespace Modules\Mcp\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Mcp\Server\ToolException;
use Modules\Mcp\Support\McpRouting;
use Modules\Project\Jobs\ProcessVideoJob;
use Modules\Project\Models\Project;
use Modules\Project\Support\ExplainerRegistry;
use Modules\Project\Support\McpOrigin;
use Modules\User\Models\User;

/**
 * render_video / get_render_status.
 *
 * MCP renders are free, so what guards the render host is a set of plain
 * limits: one render at a time per user, a daily quota, the 15-minute cap,
 * and no scene the sandbox has not seen work (or will test first).
 */
final class McpRenderService
{
    public function __construct(private User $user)
    {
    }

    /** Statuses meaning a render is in flight (waiting in the queue, or running). */
    public const BUSY = ['queued', 'processing', 'analyzing'];

    /** A render that sat queued past the TTL was lost (queue flushed): it may be requested again. */
    public static function isStaleQueued(Project $project): bool
    {
        return $project->status === 'queued'
            && $project->updated_at !== null
            && $project->updated_at->lt(now()->subHours((int) config('mcp.queued_ttl_hours', 24)));
    }

    public function start(Project $project, bool $force): array
    {
        if (in_array($project->status, self::BUSY, true) && !self::isStaleQueued($project)) {
            throw new ToolException('This video is already ' . ($project->status === 'queued' ? 'waiting in the render queue' : 'rendering') . '. Poll get_render_status.');
        }

        $busy = Project::where('user_id', $this->user->id)
            ->where('settings->origin', McpOrigin::ORIGIN)
            ->whereIn('status', ['queued', 'processing'])
            ->where('id', '!=', $project->id)
            ->first();
        if ($busy) {
            throw new ToolException("Another of your videos (#{$busy->id} \"{$busy->title}\") is rendering. One render at a time — wait for it (get_render_status video_id={$busy->id}).");
        }

        $quotaKey = "mcp:renders:{$this->user->id}:" . now()->format('Ymd');
        $limit = (int) config('mcp.renders_per_day', 5);
        $used = (int) Cache::get($quotaKey, 0);
        if ($used >= $limit) {
            throw new ToolException("Daily render limit reached ({$limit} renders a day while the studio is free). It resets at midnight UTC.");
        }

        $report = $this->preflight($project);
        if ($report['blocking'] !== []) {
            throw new ToolException('The video cannot render yet.', ['fix_these' => $report['blocking']]);
        }
        if ($report['unpreviewed'] !== [] && !$force) {
            throw new ToolException(
                'These custom scenes were never previewed with their current code: ' . implode(', ', $report['unpreviewed'])
                . '. preview_scene each one (you should look at every scene before rendering), or call render_video again with force=true to let the render test them itself (a scene that fails then stops the render).',
                ['unpreviewed' => $report['unpreviewed']]
            );
        }

        $settings = $project->settings ?? [];
        $settings['mcp']['smoke_test'] = $report['unpreviewed'];
        $settings['mcp']['renders_requested'] = (int) ($settings['mcp']['renders_requested'] ?? 0) + 1;
        $settings['mcp']['render_requested_at'] = now()->toIso8601String();

        // `queued`, not `processing`: the job may wait behind other studio
        // renders on the MCP queue for hours, and the stale-project reaper
        // fails anything sitting in a live status that long. ProcessVideoJob
        // flips it to processing when it actually starts.
        $staleBefore = now()->subHours((int) config('mcp.queued_ttl_hours', 24));
        $claimed = Project::whereKey($project->id)
            ->where(fn ($q) => $q->whereNotIn('status', self::BUSY)
                ->orWhere(fn ($q2) => $q2->where('status', 'queued')->where('updated_at', '<', $staleBefore)))
            ->update(['status' => 'queued', 'progress' => 0, 'updated_at' => now()]);
        if ($claimed === 0) {
            throw new ToolException('This video is already rendering.');
        }
        $project->refresh();
        $project->update([
            'settings' => $settings,
            'error_message' => null,
            'failed_step' => null,
            'processing_state' => null,
            'output_path' => null,
        ]);

        try {
            // The studio's own queue + worker (mcp-worker), never the paid one.
            McpRouting::dispatch(new ProcessVideoJob($project->fresh()));
        } catch (\Throwable $e) {
            Project::whereKey($project->id)->update(['status' => 'draft']);
            Log::error('MCP render dispatch failed', ['project_id' => $project->id, 'error' => $e->getMessage()]);
            throw new ToolException('The render could not be queued — try again in a minute.');
        }
        Cache::put($quotaKey, $used + 1, now()->addDays(2));

        $seconds = $report['seconds'];
        $fps = ExplainerRegistry::resolveFps($settings);
        // Measured on the dev host: ~8.5 s of wall clock per video second at 60 fps.
        $estimate = (int) max(2, ceil($seconds * ($fps >= 60 ? 8.5 : 4.5) / 60));
        $ahead = self::queueAhead($project->fresh());

        return [
            'status' => 'queued',
            'video_id' => $project->id,
            'video_seconds' => round($seconds, 1),
            'fps' => $fps,
            'queue_position' => $ahead + 1,
            'estimated_minutes' => $estimate,
            'renders_left_today' => max(0, $limit - $used - 1),
            'next' => ($ahead > 0 ? "There are {$ahead} studio render(s) ahead of this one, so it starts after them. " : '')
                . 'Tell the user the render is queued and roughly how long it takes. Then poll get_render_status every minute or two (not faster) until it says completed, and give the user the video link.',
        ];
    }

    /**
     * @return array{blocking: string[], unpreviewed: string[], seconds: float}
     */
    public function preflight(Project $project): array
    {
        $settings = $project->settings ?? [];
        $meta = (array) ($settings['mcp']['scenes'] ?? []);
        $rows = $project->explainerScenes()->orderBy('order')->get();
        $blocking = [];
        $unpreviewed = [];

        if ($rows->isEmpty()) {
            $blocking[] = 'The video has no scenes yet — write them with upsert_scene.';
        }
        $presenter = McpOrigin::isPresenter($settings);
        if ($presenter && (($settings['mcp']['presenter']['status'] ?? '') !== 'ready')) {
            $blocking[] = 'The presenter recording is not ready (' . ($settings['mcp']['presenter']['status'] ?? 'awaiting_upload') . ').';
        }

        $seconds = 0.0;
        foreach ($rows as $row) {
            $id = (string) $row->scene_id;
            $m = (array) ($meta[$id] ?? []);
            $seconds += (float) $row->duration_seconds;
            if (($m['kind'] ?? '') === 'custom') {
                if (($m['code_status'] ?? '') !== 'ok') {
                    $blocking[] = "Scene {$id}: its code does not compile — fix it with upsert_scene.";
                    continue;
                }
                $preview = (array) ($m['preview'] ?? []);
                $current = ($preview['code_hash'] ?? null) === ($m['code_hash'] ?? null);
                if (!$current || empty($preview['status'])) {
                    $unpreviewed[] = $id;
                } elseif ($preview['status'] === 'failed') {
                    $blocking[] = "Scene {$id}: its last preview FAILED (" . implode(' | ', array_slice((array) ($preview['notes'] ?? []), 0, 2)) . '). Fix it and preview again.';
                }
            }
        }
        if ($presenter) {
            $seconds = (float) ($settings['mcp']['presenter']['duration'] ?? $seconds);
        }
        $max = (int) config('mcp.max_video_seconds', 900);
        if ($seconds > $max + 20) {
            $blocking[] = sprintf('The video is %.1f minutes long; the limit is %d minutes. Shorten narration or remove scenes.', $seconds / 60, $max / 60);
        }

        return ['blocking' => $blocking, 'unpreviewed' => $unpreviewed, 'seconds' => $seconds];
    }

    public function status(Project $project): array
    {
        $out = [
            'video_id' => $project->id,
            'title' => $project->title,
            'status' => match ($project->status) {
                'processing' => 'rendering',
                'completed' => 'completed',
                'failed' => 'failed',
                default => 'not rendered',
            },
            'progress_percent' => (int) $project->progress,
        ];
        if ($project->status === 'queued') {
            $out['status'] = 'queued';
            $out['queue_position'] = self::queueAhead($project) + 1;
            $out['next'] = "Waiting in the studio render queue (videos render one at a time). Check again in a few minutes.";
        }
        if ($project->status === 'processing') {
            $state = (array) ($project->processing_state ?? []);
            $done = array_keys(array_filter($state, fn ($v) => is_array($v) && !empty($v['completed'])));
            foreach (\Modules\Mcp\Processors\McpExplainerProcessor::STEPS as $key => $label) {
                if (!in_array($key, $done, true)) {
                    $out['step'] = $label;
                    break;
                }
            }
            $out['next'] = 'Still rendering — check again in a minute or two.';
        }
        if ($project->status === 'failed') {
            $out['error'] = $project->error_message;
            $out['failed_step'] = $project->failed_step;
            $out['next'] = 'Read the error, fix the scenes it names, and render_video again.';
        }
        if ($project->status === 'completed' && $project->output_path && Storage::disk('public')->exists($project->output_path)) {
            $out['duration_seconds'] = $project->duration ? round((float) $project->duration, 1) : null;
            $out['video_url'] = self::signedDownload($project, 'video');
            if (!empty($project->settings['srt_path'])) {
                $out['captions_srt_url'] = self::signedDownload($project, 'srt');
            }
            if ($project->thumbnail_path) {
                $out['thumbnail_url'] = self::signedDownload($project, 'thumbnail');
            }
            $out['dashboard_url'] = rtrim((string) config('app.frontend_url'), '/') . '/dashboard/claude?video=' . $project->id;
            $out['links_expire'] = now()->addDays(7)->toIso8601String();
            $out['next'] = 'Give the user video_url (a direct download, valid 7 days) and dashboard_url (always works while they are signed in).';
        }

        return $out;
    }

    /** Studio renders waiting or running ahead of this one on the MCP queue. */
    public static function queueAhead(Project $project): int
    {
        $requested = (string) ($project->settings['mcp']['render_requested_at'] ?? '');
        $others = Project::where('settings->origin', McpOrigin::ORIGIN)
            ->whereIn('status', ['queued', 'processing'])
            ->where('id', '!=', $project->id)
            ->get(['id', 'status', 'settings']);
        $ahead = 0;
        foreach ($others as $other) {
            $at = (string) ($other->settings['mcp']['render_requested_at'] ?? '');
            if ($other->status === 'processing' || ($at !== '' && $at < $requested)) {
                $ahead++;
            }
        }

        return $ahead;
    }

    /** A 7-day signed download link (the same signed route the dashboard uses). */
    public static function signedDownload(Project $project, string $kind): string
    {
        $relative = URL::temporarySignedRoute('explainer.download', now()->addDays(7), ['project' => $project->id, 'kind' => $kind], false);

        return rtrim((string) config('mcp.public_url', config('app.url')), '/') . $relative;
    }
}
