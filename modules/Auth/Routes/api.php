<?php
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\FirebaseController;

Route::prefix('auth')->group(function () {
    Route::post('/firebase', [FirebaseController::class, 'authenticate']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});
