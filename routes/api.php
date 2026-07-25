<?php

use Illuminate\Support\Facades\Route;


Route::get('/test', function () {
    return 'Hello from the test route!';
});

Route::prefix('v1')->group(function () {
    // Auth Module routes are loaded automatically
    
    // Protected API routes
    Route::middleware('auth:sanctum')->group(function () {
        // Add other module routes here
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            // Admin routes
        });
        
        Route::prefix('user')->group(function () {
            // User routes
        });
    });
    
    // Public API routes for other modules
    Route::prefix('products')->group(function () {
        // Product module public routes
    });
});