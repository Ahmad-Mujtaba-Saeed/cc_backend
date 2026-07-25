<?php

namespace App\Core;

use Illuminate\Support\ServiceProvider;

abstract class BaseModuleServiceProvider extends ServiceProvider
{
    protected string $moduleName;
    protected string $modulePath;

    public function boot()
    {
        $this->loadRoutes();
        $this->loadMigrations();
        $this->loadConfigs();
    }

    protected function loadRoutes()
    {
        $routesPath = $this->modulePath . '/Routes';

        if (file_exists($routesPath . '/api.php')) {
            $this->app->router
                ->middleware('api')
                ->prefix('api')
                ->group(function () use ($routesPath) {
                    $this->loadRoutesFrom($routesPath . '/api.php');
                });
        }

        if (file_exists($routesPath . '/web.php')) {
            $this->app->router
                ->middleware('web')
                ->group(function () use ($routesPath) {
                    $this->loadRoutesFrom($routesPath . '/web.php');
                });
        }
    }

    protected function loadMigrations()
    {
        $path = $this->modulePath . '/Database/migrations';
        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    protected function loadConfigs()
    {
        $path = $this->modulePath . '/Config';
        if (is_dir($path)) {
            foreach (glob($path . '/*.php') as $file) {
                $this->mergeConfigFrom($file, basename($file, '.php'));
            }
        }
    }
}
