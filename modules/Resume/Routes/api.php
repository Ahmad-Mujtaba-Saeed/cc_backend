<?php

use Illuminate\Support\Facades\Route;
use Modules\Resume\Http\Controllers\ResumeController;


Route::middleware(['auth:sanctum'])->prefix('/resume')
    ->group(function () {
    Route::post('/create-empty', [ResumeController::class, 'createEmpty']);
    Route::post('/parse-resume', [ResumeController::class, 'parseResumeOCRPyScript']);
    // Route::post('/parse-resume-gpt', [ResumeController::class, 'parseResumeGPT']);
    Route::get('/{id}', [ResumeController::class, 'show']);
    Route::put('/{id}', [ResumeController::class, 'update']);
    Route::delete('/{id}', [ResumeController::class, 'delete']);

    Route::get('/{id}/download', [ResumeController::class, 'download']);
});

Route::get('/resume/{id}/download-doc', [ResumeController::class, 'downloadDoc']);