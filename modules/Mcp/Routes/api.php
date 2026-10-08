<?php

use Illuminate\Support\Facades\Route;
use Modules\Mcp\Http\Controllers\McpDashboardController;
use Modules\Mcp\Http\Controllers\McpUploadController;

// Loaded under /api (see Modules\Mcp\ModuleServiceProvider).

// The Connect Claude page — the signed-in user's own connections and videos.
Route::middleware('auth:sanctum')->prefix('mcp')->group(function () {
    Route::get('/overview', [McpDashboardController::class, 'overview']);
    Route::post('/tokens', [McpDashboardController::class, 'createToken'])->middleware('throttle:20,1');
    Route::delete('/tokens/{id}', [McpDashboardController::class, 'revokeToken'])->whereNumber('id');
    Route::get('/videos', [McpDashboardController::class, 'videos']);
    Route::get('/videos/{id}', [McpDashboardController::class, 'video'])->whereNumber('id');

    // OAuth consent (the dashboard's /oauth/consent page) + connection revoke.
    Route::get('/oauth/requests/{id}', [\Modules\Mcp\OAuth\OAuthController::class, 'showRequest'])->where('id', '[A-Za-z0-9]{40}');
    Route::post('/oauth/requests/{id}/approve', [\Modules\Mcp\OAuth\OAuthController::class, 'approve'])->where('id', '[A-Za-z0-9]{40}')->middleware('throttle:30,1');
    Route::post('/oauth/requests/{id}/deny', [\Modules\Mcp\OAuth\OAuthController::class, 'deny'])->where('id', '[A-Za-z0-9]{40}');
    Route::delete('/oauth/grants/{id}', [\Modules\Mcp\OAuth\OAuthController::class, 'revokeGrant'])->whereNumber('id');
});

// Upload links the model hands the user. The link token is the grant — no
// session, so the upload page also works on a phone that never signed in.
Route::prefix('mcp/upload/{token}')->where(['token' => '[A-Za-z0-9]{20,100}'])->group(function () {
    Route::get('/', [McpUploadController::class, 'show'])->middleware('throttle:120,1');
    Route::post('/chunk', [McpUploadController::class, 'chunk'])->middleware('throttle:600,1');
    Route::post('/complete', [McpUploadController::class, 'complete'])->middleware('throttle:30,1');
});
