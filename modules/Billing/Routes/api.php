<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\Gateways\StripeController;
use Modules\Billing\Http\Controllers\Gateways\StripeWebhookController;
// Include admin routes
require __DIR__ . '/plans.php';


Route::middleware(['auth:sanctum'])->prefix('/billing')
    ->group(function () {
    Route::get('/me', [StripeController::class, 'me']);
    Route::get('/stripe/create-subscription-session/{planId}', [StripeController::class, 'createSubscriptionSession']);
    Route::get('/customer/credit',[StripeController::class , 'getStripeCredit']);
    Route::get('/subscription/details', [StripeController::class, 'getSubscriptionDetails']);
    Route::post('/subscription/cancel', [StripeController::class, 'cancelSubscription']);
    Route::get('/subscription/payment-method', [StripeController::class, 'getPaymentMethod']);
    Route::delete('/subscription/payment-method/{id}', [StripeController::class, 'deletePaymentMethod']);
    Route::get('/subscription/payment-method-intent/{customerId}', [StripeController::class, 'createSetupIntent']);
    Route::post('/subscription/payment-method-default/{customerId}', [StripeController::class, 'makeDefaultPaymentMethod']);
    Route::post('/subscription/change-plan/{planId}', [StripeController::class, 'changePlan']); 
});

Route::post('/billing/stripe/webhook', [StripeWebhookController::class, 'handle']);