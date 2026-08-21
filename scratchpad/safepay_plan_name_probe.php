<?php

/**
 * Probes what Safepay's plan validator actually accepts for `name` / `product`.
 *
 * Safepay rejects some strings with `name: must be in a valid format` but does
 * not document the pattern, so this asks the sandbox directly. Run it inside
 * the app container, where SAFEPAY_SECRET_KEY is set:
 *
 *   php scratchpad/safepay_plan_name_probe.php
 *
 * Every plan it manages to create is archived again immediately, so it leaves
 * nothing subscribable behind. Point it at SANDBOX, not production.
 */

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Modules\Billing\Services\Safepay\SafepayClient;
use Modules\Billing\Services\Safepay\SafepayException;
use Modules\Billing\Services\SafepayPlanService;

$client = new SafepayClient();

if (!$client->configured()) {
    fwrite(STDERR, "SAFEPAY_SECRET_KEY is not set — run this inside the app container.\n");
    exit(1);
}

if ($client->environment() === 'production') {
    fwrite(STDERR, "Refusing to run against production. Set SAFEPAY_ENV=sandbox.\n");
    exit(1);
}

$currency = $client->currency();

/** @var array<string,string> label => candidate name */
$nameCandidates = [
    'plain word' => 'Starter',
    'two words' => 'Starter Monthly',
    'digits' => 'Starter 2 Monthly',
    'underscore' => 'Starter_Monthly',
    'hyphen' => 'Starter-Monthly',
    'parentheses (what failed)' => 'Starter (Monthly)',
    'ampersand' => 'Starter & Co',
    'period' => 'Starter Monthly.',
    'slash' => 'Starter/Monthly',
    'apostrophe' => "Starter's Plan",
    'colon' => 'Starter: Monthly',
    'long 80 chars' => str_repeat('Ab', 40),
];

$productCandidates = [
    'slug underscore' => 'viralforge_subscription',
    'slug hyphen' => 'viralforge-subscription',
    'plain word' => 'subscription',
    'with space' => 'viralforge subscription',
];

$created = [];

function attempt(SafepayClient $client, string $currency, array $overrides): ?string
{
    $payload = array_merge([
        'name' => 'Probe',
        'amount' => 100,
        'currency' => $currency,
        'interval' => 'MONTH',
        'interval_count' => 1,
        'product' => 'probe',
        'type' => 'RECURRING',
        // Never subscribable, even in the window before it is archived.
        'active' => false,
    ], $overrides);

    return $client->createPlan($payload);
}

function probe(string $heading, array $candidates, string $field, SafepayClient $client, string $currency, array &$created): array
{
    echo "\n{$heading}\n";
    $accepted = [];

    foreach ($candidates as $label => $value) {
        try {
            $planId = attempt($client, $currency, [$field => $value]);
            $created[] = $planId;
            $accepted[] = $value;
            printf("  ACCEPT  %-28s %s\n", $label, var_export($value, true));
        } catch (SafepayException $e) {
            printf("  REJECT  %-28s %s\n      -> %s\n", $label, var_export($value, true), $e->getMessage());
        }
    }

    return $accepted;
}

echo "Safepay plan-format probe (env: " . $client->environment() . ", currency: {$currency})\n";

$goodNames = probe('NAME candidates', $nameCandidates, 'name', $client, $currency, $created);
$goodProducts = probe('PRODUCT candidates', $productCandidates, 'product', $client, $currency, $created);

// ------------------------------------------------------- what we ship today
echo "\nWhat SafepayPlanService::safeName() produces for the seeded plans\n";
foreach (['Starter (Monthly)', 'Creator (Yearly)', 'Studio (Monthly)'] as $displayName) {
    $safe = SafepayPlanService::safeName($displayName);
    $ok = in_array($safe, $goodNames, true) ? 'looks accepted' : 'UNVERIFIED by the probe above';
    printf("  %-20s -> %-24s (%s)\n", $displayName, var_export($safe, true), $ok);
}
printf(
    "  product %-12s -> %s\n",
    var_export(config('safepay.product'), true),
    var_export(SafepayPlanService::safeProduct((string) config('safepay.product')), true)
);

// ------------------------------------------------------------------ cleanup
echo "\nArchiving " . count($created) . " probe plan(s)\n";
foreach ($created as $planId) {
    try {
        $client->archivePlan($planId);
        echo "  archived {$planId}\n";
    } catch (SafepayException $e) {
        echo "  COULD NOT ARCHIVE {$planId}: " . $e->getMessage() . " (archive it by hand in the dashboard)\n";
    }
}

echo "\nDone. Widen or tighten SafepayPlanService::safeName() to match the ACCEPT rows.\n";
