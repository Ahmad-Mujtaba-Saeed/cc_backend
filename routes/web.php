<?php

use Illuminate\Support\Facades\Route;

// This backend only serves the API (routes under /api, from each module) and
// static /storage files; the UI is the Next.js app. `/up` is the health check.
Route::get('/', fn () => response()->json(['service' => config('app.name'), 'status' => 'ok']));

// Ops/debug routes that used to live here (/migrate, /seed, /optimize-clear,
// /storage-link, /logs, /test-route, /log-test, /test-module) were PUBLIC: anyone
// could reseed the database or read laravel.log. Use artisan instead, e.g.
//   docker compose exec app php artisan migrate --force
//   docker compose exec app tail -200 storage/logs/laravel.log
// (the worker already runs `migrate --force` on every start).
