<?php

use Illuminate\Support\Facades\Route;
use Modules\AccessControl\Http\Controllers\PermissionController;
use Modules\AccessControl\Http\Controllers\RoleController;
use Modules\AccessControl\Http\Controllers\AccessControlController;
use Modules\AccessControl\Http\Controllers\ApiCredentialController;
use Modules\AccessControl\Http\Controllers\CostController;
use Modules\AccessControl\Http\Controllers\SettingController;

Route::middleware(['auth:sanctum', 'permission:manage-roles'])
    ->prefix('access-control')
    ->group(function () {
        Route::apiResource('permissions', PermissionController::class);
        Route::apiResource('roles', RoleController::class);

        // User-role assignment management
        Route::get('user-roles', [AccessControlController::class, 'indexUserRoles']);
        Route::post('user-roles', [AccessControlController::class, 'assignRoleToUser']);
        Route::delete('user-roles', [AccessControlController::class, 'removeRoleFromUser']);
        Route::put('user-roles/sync', [AccessControlController::class, 'syncUserRoles']);
    });

// Runtime application settings (admin only).
Route::middleware(['auth:sanctum', 'permission:manage-settings'])
    ->prefix('admin')
    ->group(function () {
        Route::get('settings', [SettingController::class, 'index']);
        Route::put('settings', [SettingController::class, 'update']);

        // Provider API key pool (multiple keys per provider with failover).
        Route::post('credentials', [ApiCredentialController::class, 'store']);
        Route::put('credentials/{credential}', [ApiCredentialController::class, 'update']);
        Route::post('credentials/{credential}/default', [ApiCredentialController::class, 'makeDefault']);
        Route::delete('credentials/{credential}', [ApiCredentialController::class, 'destroy']);

        // Render cost ledger (real money spent per render on AI services).
        Route::get('costs', [CostController::class, 'index']);
        Route::get('costs/projects/{projectId}', [CostController::class, 'project'])->whereNumber('projectId');
        Route::put('costs/rates', [CostController::class, 'updateRates']);
    });

// Public read for the marketing landing page (which design variant is live).
Route::get('public/landing', [SettingController::class, 'landing']);