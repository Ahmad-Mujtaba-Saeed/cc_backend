<?php

namespace Modules\Billing\Services\Safepay;

/**
 * Verifies the `X-SFPY-SIGNATURE` header Safepay sends with every webhook.
 *
 * The scheme is HMAC-SHA512 (hex digest) of the event payload keyed with the
 * endpoint's shared secret — found under Developers > Endpoints > "View shared
 * secret" in the merchant dashboard.
 *
 * Safepay's own PHP example signs `json_encode($request->input(), JSON_UNESCAPED_SLASHES)`
 * rather than the raw request body, so the two can differ in slash escaping and
 * whitespace. We therefore accept any candidate encoding that matches, which
 * keeps a valid event from being rejected over a serialisation detail while
 * still refusing anything not signed with the secret.
 */
class SafepayWebhookSignature
{
    /**
     * @param string $rawBody the unmodified request body
     * @param array  $decoded the same payload decoded to an array
     */
    public static function verify(string $rawBody, array $decoded, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        foreach (self::candidates($rawBody, $decoded) as $candidate) {
            if (hash_equals(hash_hmac('sha512', $candidate, $secret), $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private static function candidates(string $rawBody, array $decoded): array
    {
        $candidates = [$rawBody];

        foreach ([JSON_UNESCAPED_SLASHES, 0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
            $encoded = json_encode($decoded, $flags);
            if ($encoded !== false) {
                $candidates[] = $encoded;
            }
        }

        return array_values(array_unique(array_filter($candidates, fn ($c) => $c !== '')));
    }
}
