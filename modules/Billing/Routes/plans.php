<?php

use Modules\Billing\Http\Controllers\PlanController;

Route::middleware(['auth:sanctum', 'permission:manage-plans'])
    ->prefix('admin/billing')
    ->group(function () {

        Route::get('/plans', [PlanController::class, 'index']);
        Route::post('/plans', [PlanController::class, 'store']);
        Route::put('/plans/{plan}', [PlanController::class, 'update']);
        Route::delete('/plans/{plan}', [PlanController::class, 'destroy']);
});

Route::get('/billing/plans', [PlanController::class, 'activePlans']);