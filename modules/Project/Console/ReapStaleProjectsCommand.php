<?php

namespace Modules\Project\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Services\CreditService;
use Modules\Project\Models\Project;
use Throwable;

/**
 * Release projects wedged in a live status by a job that died without ever
 * reporting failure.
 *
 * Both jobs mark their project failed on a thrown exception, and both now have
 * a failed() handler — but neither can run when the PROCESS itself is killed:
 * an OOM, a `docker compose restart worker` mid-render, a SIGKILL. The project
 * then keeps `analyzing`/`processing` forever, the dashboard spins with no way
 * back, and any render charge is never refunded. Project 13 sat on
 * 'processing' for 13 days exactly this way, despite ProcessVideoJob having a
 * failed() handler — which is why a handler alone is not enough.
 *
 * Liveness is judged on `updated_at`: both pipelines write progress
 * continuously, so a genuinely running project is touched every few seconds.
 * The default threshold is deliberately far beyond the worst legitimate run
 * (ProcessVideoJob is tries=3 x timeout=3600s = 3h of runtime) so a slow render
 * is never killed by this command.
 *
 * Runs on worker boot — a restart is the single most common way rows are
 * orphaned, and there is no scheduler process in this stack.
 */
class ReapStaleProjectsCommand extends Command
{
    /** Statuses that mean "a job should be working on this right now". */
    private const LIVE_STATUSES = ['analyzing', 'processing'];

    protected $signature = 'projects:reap-stale
                            {--minutes=240 : How long a project may sit untouched in a live status before it is considered dead}
                            {--dry-run : List what would be reaped without changing anything}';

    protected $description = 'Fail projects stuck in analyzing/processing whose job died without reporting failure';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);

        $stale = Project::whereIn('status', self::LIVE_STATUSES)
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($stale->isEmpty()) {
            $this->info("No stale projects (nothing untouched in a live status for {$minutes}+ min).");

            return self::SUCCESS;
        }

        $this->warn(($dryRun ? '[dry-run] ' : '') . "Reaping {$stale->count()} stale project(s):");

        $credits = app(CreditService::class);

        foreach ($stale as $project) {
            $idle = (int) round($project->updated_at->diffInMinutes(now()));
            $this->line("  #{$project->id}  {$project->status}  idle {$idle} min");

            if ($dryRun) {
                continue;
            }

            $project->update([
                'status' => 'failed',
                'error_message' => "Interrupted: the job stopped without reporting a result (idle {$idle} min). Please try again.",
            ]);

            // Idempotent, and a no-op for projects that were never charged —
            // an interrupted render must not silently keep the user's credits.
            try {
                $credits->refund($project);
            } catch (Throwable $e) {
                Log::error("projects:reap-stale: refund failed for project {$project->id}: " . $e->getMessage());
                $this->error("    refund failed: {$e->getMessage()}");
            }

            Log::warning('projects:reap-stale: released a wedged project', [
                'project_id' => $project->id,
                'idle_minutes' => $idle,
            ]);
        }

        return self::SUCCESS;
    }
}
