<?php

/**
 * Unit-ish checks of SafepayClient against faked HTTP responses shaped like the
 * real API docs (envelope unwrapping, the bare-string TBT, error surfaces,
 * checkout URL construction).
 */

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Modules\Billing\Services\Safepay\SafepayClient;
use Modules\Billing\Services\Safepay\SafepayException;
use Modules\Billing\Services\SafepayPlanService;

$fails = 0;
function check(string $label, $actual, $expected)
{
    global $fails;
    $ok = $actual === $expected;
    if (!$ok) {
        $fails++;
        printf("  FAIL %-46s got %s want %s\n", $label, var_export($actual, true), var_export($expected, true));

        return;
    }
    printf("  PASS %-46s got %s\n", $label, var_export($actual, true));
}

$config = [
    'environment' => 'sandbox',
    'api_key' => 'sec_public',
    'secret_key' => 'private_secret',
    'currency' => 'USD',
    'timeout' => 10,
    'api_base' => 'https://sandbox.api.getsafepay.com',
    'checkout_base' => 'https://sandbox.api.getsafepay.com',
];

Http::fake([
    '*/client/plans/v1/' => Http::response(['data' => ['request_id' => '', 'plan_id' => 'plan_abc']], 200),
    '*/client/passport/v1/token' => Http::response(['data' => 'tbt_token_xyz'], 200),
    '*/client/subscriptions/v1/sub_ok' => Http::response([
        'data' => ['request_id' => '', 'subscription' => ['token' => 'sub_ok', 'status' => 'ACTIVE']],
    ], 200),
    '*/client/subscriptions/v1/sub_missing' => Http::response(['error' => 'subscription not found'], 404),
    '*/client/plans/v1/denied/' => Http::response([
        'data' => null,
        'status' => ['errors' => ['unauthorized', 'bad secret'], 'message' => 'fail'],
    ], 401),
]);

$client = new SafepayClient($config);

echo "\n1. plan creation\n";
check('plan token returned', $client->createPlan([
    'name' => 'Creator', 'amount' => 1500, 'currency' => 'USD',
    'interval' => 'MONTH', 'interval_count' => 1, 'product' => 'p',
]), 'plan_abc');

$sent = Http::recorded()[0][0];
check('authenticates with merchant secret', $sent->header('X-SFPY-MERCHANT-SECRET')[0], 'private_secret');
check('sends amount in minor units', json_decode($sent->body(), true)['amount'], 1500);
check('defaults to RECURRING', json_decode($sent->body(), true)['type'], 'RECURRING');

echo "\n2. time-based token (bare string in `data`)\n";
check('token unwrapped', $client->timeBasedToken(), 'tbt_token_xyz');

echo "\n3. subscription retrieval\n";
check('subscription unwrapped', $client->findSubscription('sub_ok')['status'], 'ACTIVE');

echo "\n4. error surfaces\n";
try {
    $client->findSubscription('sub_missing');
    check('404 raises', false, true);
} catch (SafepayException $e) {
    check('404 message', $e->getMessage(), 'subscription not found');
    check('404 status', $e->status, 404);
}

try {
    $client->request('get', '/client/plans/v1/denied/');
    check('401 raises', false, true);
} catch (SafepayException $e) {
    check('401 message', $e->getMessage(), 'unauthorized - bad secret');
}

echo "\n5. checkout URL\n";
$url = $client->subscriptionCheckoutUrl('plan_abc', 'ref-9', 'https://app.test/ok', 'https://app.test/no');
$parts = parse_url($url);
parse_str($parts['query'], $q);
check('host', $parts['host'], 'sandbox.api.getsafepay.com');
check('path', $parts['path'], '/checkout/subscribe');
check('env', $q['env'], 'sandbox');
check('plan_id', $q['plan_id'], 'plan_abc');
check('auth_token is the TBT', $q['auth_token'], 'tbt_token_xyz');
check('reference', $q['reference'], 'ref-9');
check('redirect_url', $q['redirect_url'], 'https://app.test/ok');
check('cancel_url', $q['cancel_url'], 'https://app.test/no');

echo "\n6. helpers\n";
check('interval month', SafepayPlanService::interval('month'), 'MONTH');
check('interval year', SafepayPlanService::interval('year'), 'YEAR');
check('status ACTIVE', SafepayClient::normaliseStatus('ACTIVE'), 'active');
check('status TRAILING', SafepayClient::normaliseStatus('TRAILING'), 'trialing');
check('status CANCELED', SafepayClient::normaliseStatus('CANCELED'), 'cancelled');
check('status UNPAID', SafepayClient::normaliseStatus('UNPAID'), 'past_due');
check('protobuf timestamp', SafepayClient::timestamp(['seconds' => 1698758572, 'nanos' => 1])->toDateTimeString(), '2023-10-31 13:22:52');
check('rfc3339 timestamp', SafepayClient::timestamp('2023-09-26T07:14:24Z')->toDateTimeString(), '2023-09-26 07:14:24');
check('null timestamp', SafepayClient::timestamp(null), null);

echo "\n7. unconfigured client refuses to call out\n";
try {
    (new SafepayClient(['secret_key' => null] + $config))->timeBasedToken();
    check('raises', false, true);
} catch (SafepayException $e) {
    check('clear message', str_contains($e->getMessage(), 'SAFEPAY_SECRET_KEY'), true);
}

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "{$fails} CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
