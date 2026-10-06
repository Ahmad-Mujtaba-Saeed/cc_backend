<?php

/*
|--------------------------------------------------------------------------
| Stripe (subscriptions)
|--------------------------------------------------------------------------
|
| Keys: Stripe Dashboard > Developers > API keys. Use the sk_test_ key until
| the whole flow has been through a test checkout.
|
| Webhook: Developers > Webhooks > Add endpoint
|   URL     https://<api-host>/api/billing/stripe/webhook
|   Events  checkout.session.completed
|           customer.subscription.created / updated / deleted
|           customer.subscription.paused / resumed
|           invoice.paid / invoice.payment_failed
| and put its signing secret (whsec_...) in STRIPE_WEBHOOK_SECRET.
| Locally: `stripe listen --forward-to localhost:8086/api/billing/stripe/webhook`
| prints a whsec_ for the session.
|
| Customer portal (cards, invoices): configure once under
| Settings > Billing > Customer portal.
*/

return [
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

    // Plans are priced in this currency (lowercase, as Stripe wants it).
    'currency' => strtolower(env('STRIPE_CURRENCY', 'usd')),

    // Where Checkout and the portal send the customer back to. Default: the
    // frontend's billing page, which confirms the session on return.
    'success_url' => env('STRIPE_SUCCESS_URL'),
    'cancel_url' => env('STRIPE_CANCEL_URL'),
    'portal_return_url' => env('STRIPE_PORTAL_RETURN_URL'),
];
