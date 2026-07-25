<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;


Route::middleware(['auth:sanctum'])->prefix('/user')
    ->group(function () {
    Route::post('/profile-settings', [UserController::class, 'ProfileSettings']);
    Route::post('/change-password', [UserController::class, 'changePassword']);
});
