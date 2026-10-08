<?php

namespace Modules\Mcp;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Claude / MCP video studio: a Model Context Protocol server that lets a
 * user's own LLM build and render explainer videos with our tools.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Dashboard + upload routes under /api.
        Route::middleware(['api'])->prefix('api')->group(__DIR__ . '/Routes/api.php');
        // The MCP endpoint at /mcp: stateless (no session, no CSRF).
        Route::middleware(['api'])->group(__DIR__ . '/Routes/mcp.php');

        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Mcp\Console\WarmMcpStudioCommand::class,
                \Modules\Mcp\Console\PruneMcpStudioCommand::class,
            ]);
        }

        // Daily housekeeping (run by the `scheduler` container's schedule:work):
        // old presenter frame strips, abandoned uploads, preview stills,
        // expired OAuth tokens.
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            $schedule->command('mcp:prune')->dailyAt('03:30')->withoutOverlapping()->runInBackground();
        });
    }
}
