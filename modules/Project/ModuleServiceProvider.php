<?php

namespace Modules\Project;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Project\Models\Project;
use Modules\Project\Observers\ProjectObserver;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Register the module services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap the module services.
     */
    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerMigrations();
        $this->registerObservers();
        $this->registerCommands();
    }

    /**
     * Register the module console commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Project\Console\ReapStaleProjectsCommand::class,
            ]);
        }
    }

    /**
     * Register the module routes.
     */
    protected function registerRoutes(): void
    {
        Route::middleware(['api'])
            ->prefix('api')
            ->group(__DIR__ . '/Routes/api.php');
    }

    /**
     * Register the module migrations.
     */
    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Database/migrations');
    }

    /**
     * Register the module observers.
     */
    protected function registerObservers(): void
    {
        Project::observe(ProjectObserver::class);
    }
}
