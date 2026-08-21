<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\Gateways\SafepayController;
use Modules\Billing\Http\Controllers\Gateways\SafepayWebhookController;

// Include admin routes
require __DIR__ . '/plans.php';

Route::middleware(['auth:sanctum'])->prefix('/billing')
    ->group(function () {
        Route::get('/me', [SafepayController::class, 'me']);
        Route::get('/safepay/create-subscription-session/{planId}', [SafepayController::class, 'createSubscriptionSession']);
        // Called when Safepay redirects the customer back from hosted checkout.
        Route::get('/safepay/sync', [SafepayController::class, 'syncCheckout']);
        Route::get('/subscription/details', [SafepayController::class, 'getSubscriptionDetails']);
        Route::post('/subscription/cancel', [SafepayController::class, 'cancelSubscription']);
        Route::post('/subscription/change-plan/{planId}', [SafepayController::class, 'changePlan']);
    });

// Public: Safepay posts events here. Configure this URL under
// Developers > Endpoints in the Safepay dashboard.
Route::post('/billing/safepay/webhook', [SafepayWebhookController::class, 'handle']);
