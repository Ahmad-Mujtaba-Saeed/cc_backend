<?php

namespace Modules\Mcp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Mcp\Models\McpOAuthClient;
use Modules\Mcp\Models\McpOAuthGrant;
use Modules\Mcp\Models\McpToken;
use Modules\Mcp\Models\McpUpload;
use Modules\Mcp\Services\McpRenderService;
use Modules\Project\Models\Project;
use Modules\Project\Support\McpOrigin;

/**
 * The studio's housekeeping, scheduled daily (Mcp ModuleServiceProvider →
 * the `scheduler` container):
 *
 *  - presenter FRAME STRIPS of videos nobody has touched for a week — GBs
 *    each, and re-extracted on demand by the next render
 *    (PresenterService::ensureFrames); previews fall back to the video;
 *  - upload links that never finished (their partial files);
 *  - preview stills older than a few days;
 *  - expired OAuth access tokens, long-revoked grants, and registered OAuth
 *    clients nobody ever authorised.
 *
 *   docker compose exec app php artisan mcp:prune [--dry-run]
 */
class PruneMcpStudioCommand extends Command
{
    protected $signature = 'mcp:prune {--dry-run : Report what would be removed, change nothing}';

    protected $description = 'Clean up MCP studio leftovers (presenter frame strips, stale uploads, preview stills, expired OAuth tokens)';

    private int $bytes = 0;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->pruneFrames($dry);
        $this->pruneUploads($dry);
        $this->prunePreviews($dry);
        $this->pruneOAuth($dry);
        $this->info(($dry ? '[dry-run] would free ' : 'Freed ') . number_format($this->bytes / 1024 / 1024, 1) . ' MB');

        return self::SUCCESS;
    }

    private function pruneFrames(bool $dry): void
    {
        $cutoff = now()->subDays((int) config('mcp.prune.frames_after_days', 7));
        $projects = Project::withTrashed()
            ->where('settings->origin', McpOrigin::ORIGIN)
            ->where('settings->mcp->mode', 'presenter')
            ->get();
        $n = 0;
        foreach ($projects as $project) {
            $frames = $project->settings['mcp']['presenter']['frames'] ?? null;
            $dir = is_array($frames) ? (string) ($frames['dir'] ?? '') : '';
            if ($dir === '' || !Storage::disk('public')->exists($dir)) {
                continue;
            }
            // Never under a render or a queued render.
            if (in_array($project->status, McpRenderService::BUSY, true)) {
                continue;
            }
            $idle = $project->trashed() || ($project->updated_at !== null && $project->updated_at->lt($cutoff));
            if (!$idle) {
                continue;
            }
            $this->bytes += $this->dirSize($dir);
            $n++;
            if ($dry) {
                continue;
            }
            Storage::disk('public')->deleteDirectory($dir);
            if (!$project->trashed()) {
                // saveQuietly + a raw settings write: pruning is not "activity",
                // so it must not bump updated_at (that is the idle clock).
                $settings = $project->settings;
                $settings['mcp']['presenter']['frames'] = null;
                Project::withoutTimestamps(fn () => $project->forceFill(['settings' => $settings])->saveQuietly());
            }
        }
        $this->line("Presenter frame strips: {$n}");
    }

    private function pruneUploads(bool $dry): void
    {
        $cutoff = now()->subHours((int) config('mcp.prune.uploads_after_hours', 72));
        $stale = McpUpload::whereIn('status', ['pending', 'uploading', 'failed'])
            ->where('updated_at', '<', $cutoff)
            ->get();
        foreach ($stale as $upload) {
            foreach (array_filter([$upload->partPath(), $upload->status === 'failed' ? $upload->path : null]) as $file) {
                if (Storage::disk('local')->exists($file)) {
                    $this->bytes += (int) Storage::disk('local')->size($file);
                    if (!$dry) {
                        Storage::disk('local')->delete($file);
                    }
                }
            }
            if (!$dry && $upload->status !== 'failed') {
                $upload->update(['status' => 'expired']);
            }
        }
        $this->line('Stale uploads: ' . $stale->count());
    }

    private function prunePreviews(bool $dry): void
    {
        $cutoff = now()->subDays((int) config('mcp.prune.previews_after_days', 3))->getTimestamp();
        $disk = Storage::disk('public');
        $n = 0;
        foreach ($disk->directories('explainer') as $projectDir) {
            $previews = $projectDir . '/mcp_preview';
            if (!$disk->exists($previews)) {
                continue;
            }
            foreach ($disk->directories($previews) as $dir) {
                $mtime = @filemtime($disk->path($dir));
                if ($mtime !== false && $mtime < $cutoff) {
                    $this->bytes += $this->dirSize($dir);
                    $n++;
                    if (!$dry) {
                        $disk->deleteDirectory($dir);
                    }
                }
            }
        }
        $this->line("Preview still folders: {$n}");
    }

    private function pruneOAuth(bool $dry): void
    {
        $expired = McpToken::whereNotNull('expires_at')->where('expires_at', '<', now()->subDay());
        $revokedGrants = McpOAuthGrant::whereNotNull('revoked_at')->where('revoked_at', '<', now()->subDays(30));
        $idleGrants = McpOAuthGrant::whereNull('revoked_at')->where('refresh_expires_at', '<', now()->subDays(1));
        $unusedClients = McpOAuthClient::whereDoesntHave('grants')->where('created_at', '<', now()->subDays(30));
        $this->line('Expired OAuth access tokens: ' . (clone $expired)->count()
            . ', dead grants: ' . ((clone $revokedGrants)->count() + (clone $idleGrants)->count())
            . ', unused clients: ' . (clone $unusedClients)->count());
        if ($dry) {
            return;
        }
        $expired->delete();
        foreach ($revokedGrants->get()->merge($idleGrants->get()) as $grant) {
            McpToken::where('grant_id', $grant->id)->delete();
            $grant->delete();
        }
        $unusedClients->delete();
    }

    private function dirSize(string $dir): int
    {
        $size = 0;
        foreach (Storage::disk('public')->allFiles($dir) as $file) {
            $size += (int) @filesize(Storage::disk('public')->path($file));
        }

        return $size;
    }
}
