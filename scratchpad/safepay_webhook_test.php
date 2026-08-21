<?php

/**
 * End-to-end check of the Safepay webhook path against the scratch database.
 * Boots the real app, posts signed events at the real route, asserts on state.
 */

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

$app = require $base . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\SafepayCheckoutSession;
use Modules\Billing\Models\Subscription;
use Modules\User\Models\User;

$secret = getenv('SAFEPAY_WEBHOOK_SECRET');

$fails = 0;
function check(string $label, $actual, $expected)
{
    global $fails;
    $ok = $actual === $expected;
    if (!$ok) {
        $fails++;
    }
    printf("  %s %-52s got %s\n", $ok ? 'PASS' : 'FAIL', $label, var_export($actual, true));
}

function post(array $payload, string $secret)
{
    global $kernel;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $request = Request::create('/api/billing/safepay/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SFPY_SIGNATURE' => hash_hmac('sha512', $body, $secret),
    ], $body);

    return $kernel->handle($request);
}

// ---------------------------------------------------------------- fixtures
DB::table('safepay_events')->delete();
SafepayCheckoutSession::query()->delete();
Subscription::query()->delete();
Payment::query()->delete();
User::where('email', 'sp-test@example.com')->delete();
Plan::where('safepay_plan_id', 'plan_test_001')->delete();

$plan = Plan::create([
    'name' => 'Creator (Monthly)',
    'price' => 15.00,
    'daily_credits' => 300,
    'tier' => 'creator-test',
    'currency' => 'USD',
    'interval' => 'month',
    'interval_count' => 1,
    'safepay_plan_id' => 'plan_test_001',
    'trial_period_days' => 0,
    'features' => ['a'],
    'is_active' => true,
]);

$user = User::create([
    'name' => 'SP Test',
    'phone' => '0000000001',
    'email' => 'sp-test@example.com',
    'password' => bcrypt('secret1234'),
]);

$reference = 'ref-1111-2222';
SafepayCheckoutSession::create([
    'reference' => $reference,
    'user_id' => $user->id,
    'plan_id' => $plan->id,
    'safepay_plan_id' => 'plan_test_001',
    'status' => 'pending',
]);

$now = time();

// ------------------------------------------------- 1. subscription.created
echo "\n1. subscription.created (resolved by reference)\n";
$created = [
    'token' => 'evt_test_created',
    'version' => '2.0.0',
    'merchant_api_key' => 'sec_test',
    'type' => 'subscription.created',
    'data' => [
        'id' => 'sub_test_001',
        'plan_id' => 'plan_test_001',
        'reference' => $reference,
        'customer_email' => 'someone-else@example.com', // deliberately not the user's
        'user_id' => 'user_sfpy_001',
        'status' => 'ACTIVE',
        'amount' => 1500,
        'currency' => 'USD',
        'started_at' => ['seconds' => $now],
        'current_period_start_date' => ['seconds' => $now],
        'current_period_end_date' => ['seconds' => $now + 2592000],
    ],
];
$res = post($created, $secret);
check('http status', $res->getStatusCode(), 200);

$sub = Subscription::where('sub_id', 'sub_test_001')->first();
check('subscription created', $sub !== null, true);
check('bound to the right user', $sub?->user_id, $user->id);
check('status normalised', $sub?->status, 'active');
check('plan resolved', (int) $sub?->type_id, $plan->id);
check('period end recorded', $sub?->ends_at !== null, true);
check('shopper id stored on user', $user->fresh()->safepay_customer_id, 'user_sfpy_001');
check('checkout session closed', SafepayCheckoutSession::find($reference)?->status, 'completed');
check('credits granted', (int) $user->fresh()->credits, 300);
check('activeSubscription() sees it', $user->fresh()->activeSubscription() !== null, true);

// -------------------------------------------------------- 2. redelivery
echo "\n2. redelivery of the same event\n";
$res = post($created, $secret);
check('answered 200', $res->getStatusCode(), 200);
check('marked duplicate', json_decode($res->getContent(), true)['status'] ?? null, 'duplicate');
check('still one subscription row', Subscription::count(), 1);

// ------------------------------------------------- 3. bad signature
echo "\n3. tampered signature\n";
$body = json_encode($created, JSON_UNESCAPED_SLASHES);
$bad = Request::create('/api/billing/safepay/webhook', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_X_SFPY_SIGNATURE' => hash_hmac('sha512', $body, 'the-wrong-secret'),
], $body);
check('rejected', $kernel->handle($bad)->getStatusCode(), 400);

// ------------------------------- 4. subscription.payment.succeeded
echo "\n4. subscription.payment.succeeded (recurring charge)\n";
$res = post([
    'token' => 'evt_test_paid',
    'type' => 'subscription.payment.succeeded',
    'data' => [
        'id' => 'sub_test_001',
        'plan_id' => 'plan_test_001',
        'customer_email' => 'sp-test@example.com',
        'status' => 'ACTIVE',
        'amount' => 1500,
        'currency' => 'USD',
        'current_period_start_date' => ['seconds' => $now + 2592000],
        'current_period_end_date' => ['seconds' => $now + 5184000],
        'transaction_id' => 'txn_test_001',
        'transaction_status' => 'COMPLETE',
    ],
], $secret);
check('http status', $res->getStatusCode(), 200);

$payment = Payment::where('payment_transaction_id', 'txn_test_001')->first();
check('payment recorded', $payment !== null, true);
check('amount in major units', (float) $payment?->payment_amount, 15.0);
check('gateway stamped', $payment?->payment_gateway, 'safepay');
check('linked to plan', $payment?->related_type_id, $plan->id);

// ------------------------------------------------ 5. subscription.canceled
echo "\n5. subscription.canceled\n";
$res = post([
    'token' => 'evt_test_cancelled',
    'type' => 'subscription.canceled',
    'data' => [
        'id' => 'sub_test_001',
        'plan_id' => 'plan_test_001',
        'customer_email' => 'sp-test@example.com',
        'status' => 'CANCELED',
        'amount' => 1500,
        'currency' => 'USD',
        'canceled_at' => ['seconds' => $now],
    ],
], $secret);
check('http status', $res->getStatusCode(), 200);
check('status cancelled', Subscription::where('sub_id', 'sub_test_001')->first()?->status, 'cancelled');
check('no longer active', $user->fresh()->activeSubscription() === null, true);
check('credits revoked', (int) $user->fresh()->credits, 0);

// ------------------------------- 6. unresolvable user -> retryable 500
echo "\n6. event for an unknown customer\n";
$res = post([
    'token' => 'evt_test_orphan',
    'type' => 'subscription.created',
    'data' => [
        'id' => 'sub_test_orphan',
        'plan_id' => 'plan_unknown_999',
        'customer_email' => 'nobody@example.com',
        'status' => 'ACTIVE',
    ],
], $secret);
check('asks Safepay to retry', $res->getStatusCode(), 500);
check('claim released for retry', DB::table('safepay_events')->where('id', 'evt_test_orphan')->exists(), false);

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "{$fails} CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
