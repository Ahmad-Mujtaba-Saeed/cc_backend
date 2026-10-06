<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        // No login page to send guests to (the UI is the Next.js app): an
        // unauthenticated request just gets a JSON 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This backend is an API (the UI is the Next.js app), so errors are
        // always JSON — an unauthenticated request gets a 401, never a
        // redirect to a login page that doesn't exist.
        $exceptions->shouldRenderJsonWhen(fn ($request) => true);
    })->create();
