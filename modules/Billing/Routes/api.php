<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\Gateways\StripeController;
use Modules\Billing\Http\Controllers\Gateways\StripeWebhookController;

// Include admin routes
require __DIR__ . '/plans.php';

Route::middleware(['auth:sanctum'])->prefix('/billing')
    ->group(function () {
        Route::get('/me', [StripeController::class, 'me']);
        Route::post('/stripe/checkout/{planId}', [StripeController::class, 'createSubscriptionSession'])
            ->middleware('throttle:10,1');
        // Called when Stripe Checkout redirects the customer back.
        Route::get('/stripe/sync', [StripeController::class, 'syncCheckout']);
        Route::post('/stripe/portal', [StripeController::class, 'portal'])->middleware('throttle:10,1');
        Route::get('/subscription/details', [StripeController::class, 'getSubscriptionDetails']);
        Route::post('/subscription/cancel', [StripeController::class, 'cancelSubscription']);
        Route::post('/subscription/change-plan/{planId}', [StripeController::class, 'changePlan'])
            ->middleware('throttle:10,1');
    });

// Public: Stripe posts events here (signature-verified). Setup: config/stripe.php.
Route::post('/billing/stripe/webhook', [StripeWebhookController::class, 'handle']);
