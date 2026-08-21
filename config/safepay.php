<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Safepay
    |--------------------------------------------------------------------------
    |
    | Credentials live in the Safepay merchant dashboard:
    |   Developers → API      : Public API Key ("sec_…") + Private API Secret Key
    |   Developers → Endpoints : the webhook shared secret
    |
    | Sandbox and production are entirely separate accounts with their own keys.
    |
    */

    // 'sandbox' or 'production'. Drives every base URL below.
    'environment' => env('SAFEPAY_ENV', 'sandbox'),

    // Public API key (starts with "sec_"). Identifies the merchant account and
    // is the `merchant_api_key` echoed back on every webhook event.
    'api_key' => env('SAFEPAY_API_KEY'),

    // Private API secret key — sent as the X-SFPY-MERCHANT-SECRET header.
    'secret_key' => env('SAFEPAY_SECRET_KEY'),

    // Shared secret used to verify the X-SFPY-SIGNATURE header (HMAC-SHA512).
    'webhook_secret' => env('SAFEPAY_WEBHOOK_SECRET'),

    // Currency every plan is created in. Three-letter ISO code, uppercase.
    // A Pakistani Safepay account is usually PKR-only; USD requires it to be
    // explicitly enabled for your merchant account.
    'currency' => env('SAFEPAY_CURRENCY', 'USD'),

    // The `product` field on a Safepay plan — a free-form grouping label.
    'product' => env('SAFEPAY_PRODUCT', 'viralforge-subscription'),

    // Where hosted Subscriptions Checkout returns the customer.
    // Both default to the billing page of the Next.js frontend.
    'redirect_url' => env('SAFEPAY_REDIRECT_URL'),
    'cancel_url' => env('SAFEPAY_CANCEL_URL'),

    'timeout' => (int) env('SAFEPAY_TIMEOUT', 30),

    /*
    | Resolved endpoints. `api_base` serves the REST API; `checkout_base` serves
    | the hosted checkout pages — in production these are different hosts.
    */
    'api_base' => env('SAFEPAY_ENV', 'sandbox') === 'production'
        ? 'https://api.getsafepay.com'
        : 'https://sandbox.api.getsafepay.com',

    'checkout_base' => env('SAFEPAY_ENV', 'sandbox') === 'production'
        ? 'https://getsafepay.com'
        : 'https://sandbox.api.getsafepay.com',
];
