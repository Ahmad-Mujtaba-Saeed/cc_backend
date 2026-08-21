<?php

namespace Modules\Billing\Services\Safepay;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for the Safepay API (https://apidocs.getsafepay.com).
 *
 * Every server-to-server call authenticates with the merchant's *private*
 * secret key in the `X-SFPY-MERCHANT-SECRET` header. Responses are wrapped in
 * a `{"data": ...}` envelope; errors come back either as `{"error": "..."}` or
 * as `{"data": null, "status": {"errors": [...], "message": "fail"}}`.
 *
 * Safepay has no "create subscription" endpoint — a customer subscribes by
 * completing hosted Subscriptions Checkout, whose URL we build here.
 */
class SafepayClient
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('safepay');
    }

    /** True once the merchant secret key is present in the environment. */
    public function configured(): bool
    {
        return !empty($this->config['secret_key']);
    }

    public function environment(): string
    {
        return $this->config['environment'] ?? 'sandbox';
    }

    public function currency(): string
    {
        return strtoupper($this->config['currency'] ?? 'PKR');
    }

    // ----------------------------------------------------------------- Plans

    /**
     * Create a plan. Returns the new plan token ("plan_...").
     *
     * `amount` must already be in the currency's lowest denomination.
     */
    public function createPlan(array $payload): string
    {
        $data = $this->request('post', '/client/plans/v1/', $payload + [
            'type' => 'RECURRING',
            'active' => true,
        ]);

        $planId = $data['plan_id'] ?? null;
        if (!$planId) {
            throw new SafepayException('Safepay did not return a plan_id.', null, is_array($data) ? $data : []);
        }

        return $planId;
    }

    /** Retrieve a plan object. */
    public function findPlan(string $planId): array
    {
        $data = $this->request('get', "/client/plans/v1/{$planId}/");

        return $data['plan'] ?? [];
    }

    /**
     * Update the mutable fields of a plan.
     *
     * Safepay only accepts name, description, product, active and
     * trial_period_days here — amount and interval are immutable, so a price
     * change means creating a fresh plan and archiving the old one.
     */
    public function updatePlan(string $planId, array $payload): void
    {
        $this->request('put', "/client/plans/v1/{$planId}/", $payload);
    }

    /** Archive a plan: no new subscribers, existing ones unaffected. One-way. */
    public function archivePlan(string $planId): void
    {
        $this->request('delete', "/client/plans/v1/{$planId}/");
    }

    // --------------------------------------------------------- Subscriptions

    /** Retrieve a subscription object. */
    public function findSubscription(string $subscriptionId): array
    {
        $data = $this->request('get', "/client/subscriptions/v1/{$subscriptionId}");

        return $data['subscription'] ?? [];
    }

    /**
     * Search this merchant's subscriptions, newest first.
     *
     * Used only as a reconciliation fallback for when the webhook has not
     * landed yet — the webhook remains the source of truth.
     */
    public function searchSubscriptions(array $params = []): array
    {
        $data = $this->request('get', '/client/subscriptions/v1/search', $params + [
            'limit' => 20,
            'page' => 1,
            'sort_by' => 'created_at',
            'direction' => 'DESC',
        ]);

        return $data['subscriptions'] ?? [];
    }

    /**
     * Update a subscription — used to schedule cancellation at period end
     * (`['cancel_at_period_end' => true]`) or to pause collection.
     */
    public function updateSubscription(string $subscriptionId, array $payload): array
    {
        $data = $this->request('put', "/client/subscriptions/v1/{$subscriptionId}", $payload);

        return $data['subscription'] ?? [];
    }

    /**
     * Cancel a subscription immediately and irreversibly. Only TRIALING,
     * INCOMPLETE and ACTIVE subscriptions can be cancelled.
     */
    public function cancelSubscription(string $subscriptionId): array
    {
        $data = $this->request('post', "/client/subscriptions/v1/{$subscriptionId}/cancel", []);

        return $data['subscription'] ?? [];
    }

    // -------------------------------------------------------------- Checkout

    /**
     * Mint a Time-Based Token. Valid for two minutes and required to open
     * hosted checkout, so it must be generated per checkout redirect.
     */
    public function timeBasedToken(): string
    {
        $data = $this->request('post', '/client/passport/v1/token', []);

        // This endpoint returns the token as a bare string in `data`.
        $token = is_string($data) ? $data : ($data['token'] ?? null);
        if (!$token) {
            throw new SafepayException('Safepay did not return a time-based token.');
        }

        return $token;
    }

    /**
     * Build the hosted Subscriptions Checkout URL a customer is redirected to
     * in order to subscribe to `$planId`.
     *
     * `reference` is our own identifier; Safepay echoes it back on the
     * subscription webhooks so we can tie the subscription to a local user.
     */
    public function subscriptionCheckoutUrl(
        string $planId,
        string $reference,
        string $redirectUrl,
        string $cancelUrl
    ): string {
        $query = http_build_query([
            'env' => $this->environment() === 'production' ? 'production' : 'sandbox',
            'plan_id' => $planId,
            'auth_token' => $this->timeBasedToken(),
            'reference' => $reference,
            'cancel_url' => $cancelUrl,
            'redirect_url' => $redirectUrl,
        ]);

        return rtrim($this->config['checkout_base'], '/') . '/checkout/subscribe?' . $query;
    }

    // ------------------------------------------------------------- Internals

    /**
     * Issue an authenticated request and unwrap the `data` envelope.
     *
     * @return mixed the contents of `data` (an array, or a string for the TBT)
     */
    public function request(string $method, string $path, ?array $payload = null)
    {
        if (!$this->configured()) {
            throw new SafepayException('Safepay is not configured: set SAFEPAY_SECRET_KEY.');
        }

        $url = rtrim($this->config['api_base'], '/') . $path;

        $request = Http::withHeaders([
            'X-SFPY-MERCHANT-SECRET' => $this->config['secret_key'],
            'Accept' => 'application/json',
        ])->timeout((int) ($this->config['timeout'] ?? 30))->asJson();

        /** @var Response $response */
        $response = match ($method) {
            'get' => $request->get($url, $payload ?? []),
            'post' => $request->post($url, $payload ?? []),
            'put' => $request->put($url, $payload ?? []),
            'delete' => $request->delete($url, $payload ?? []),
            default => throw new SafepayException("Unsupported HTTP method {$method}."),
        };

        $body = $response->json();

        if ($response->failed()) {
            $message = $this->errorMessage($body) ?? "Safepay request failed with HTTP {$response->status()}.";
            Log::error('Safepay API error', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new SafepayException($message, $response->status(), is_array($body) ? $body : []);
        }

        // A 200 can still carry a failure envelope.
        if (is_array($body) && data_get($body, 'status.message') === 'fail') {
            throw new SafepayException($this->errorMessage($body) ?? 'Safepay request failed.', 200, $body);
        }

        return is_array($body) && array_key_exists('data', $body) ? $body['data'] : $body;
    }

    private function errorMessage($body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        if (!empty($body['error'])) {
            return is_string($body['error']) ? $body['error'] : json_encode($body['error']);
        }

        $errors = data_get($body, 'status.errors');
        if (is_array($errors) && $errors) {
            return implode(' - ', array_map('strval', $errors));
        }

        if (!empty($body['details']) && is_array($body['details'])) {
            return json_encode($body['details']);
        }

        return null;
    }

    /**
     * Safepay serialises timestamps either as protobuf-style
     * `{"seconds": ..., "nanos": ...}` or as an RFC-3339 string. Normalise both.
     */
    public static function timestamp($value): ?CarbonImmutable
    {
        if (empty($value)) {
            return null;
        }

        if (is_array($value)) {
            $seconds = $value['seconds'] ?? null;

            return $seconds ? CarbonImmutable::createFromTimestampUTC((int) $seconds) : null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Map a Safepay subscription status onto the lowercase vocabulary the rest
     * of the app (and User::activeSubscription) already speaks.
     */
    public static function normaliseStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE' => 'active',
            // Safepay's docs spell the trial state both ways.
            'TRIALING', 'TRAILING' => 'trialing',
            'INCOMPLETE' => 'incomplete',
            'PAUSED' => 'paused',
            'CANCELED', 'CANCELLED' => 'cancelled',
            'ENDED', 'EXPIRED' => 'ended',
            'UNPAID', 'PAST_DUE' => 'past_due',
            'FAILED' => 'failed',
            default => strtolower((string) $status) ?: 'unknown',
        };
    }
}
