<?php

namespace Modules\Mcp\OAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Mcp\Models\McpOAuthClient;
use Modules\Mcp\Models\McpOAuthGrant;
use Modules\Mcp\Models\McpToken;
use Modules\User\Models\User;

/**
 * The studio's OAuth 2.1 authorization server, shaped by the MCP
 * authorization spec — what Claude.ai, Claude Desktop, ChatGPT, Cursor &co
 * do when a user adds the server URL and clicks "Connect":
 *
 *  1. POST /mcp without a token → 401 + WWW-Authenticate pointing at the
 *     protected-resource metadata (RFC 9728) → authorization-server metadata
 *     (RFC 8414).
 *  2. The client registers (RFC 7591 dynamic registration) — or identifies
 *     itself by a client-ID metadata document URL (CIMD).
 *  3. GET /oauth/authorize with PKCE (S256 only) → the user signs in to the
 *     dashboard and clicks Allow on /oauth/consent → a one-time code back to
 *     the client's redirect URI.
 *  4. POST /oauth/token: code + verifier → a one-hour access token and a
 *     rotating 60-day refresh token.
 *
 * Codes and pending requests live in the (redis) cache; grants and tokens in
 * the database, hashed.
 */
final class McpOAuthService
{
    public const SCOPE = 'studio';
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL_DAYS = 60;
    private const REQUEST_TTL = 900;
    private const CODE_TTL = 600;
    private const AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];

    // ------------------------------------------------------------------
    // discovery
    // ------------------------------------------------------------------

    public static function issuer(): string
    {
        return rtrim((string) config('mcp.public_url', config('app.url')), '/');
    }

    public static function resource(): string
    {
        return self::issuer() . '/mcp';
    }

    /** RFC 9728 — what the MCP endpoint's 401 points at. */
    public static function protectedResourceMetadata(): array
    {
        return [
            'resource' => self::resource(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => (string) config('mcp.server_title', 'Vreato Video Studio'),
            'resource_documentation' => rtrim((string) config('app.frontend_url'), '/') . '/dashboard/claude',
        ];
    }

    /** RFC 8414. */
    public static function authorizationServerMetadata(): array
    {
        $issuer = self::issuer();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => "{$issuer}/oauth/authorize",
            'token_endpoint' => "{$issuer}/oauth/token",
            'registration_endpoint' => "{$issuer}/oauth/register",
            'revocation_endpoint' => "{$issuer}/oauth/revoke",
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => self::AUTH_METHODS,
            'revocation_endpoint_auth_methods_supported' => self::AUTH_METHODS,
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [self::SCOPE],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => rtrim((string) config('app.frontend_url'), '/') . '/dashboard/claude',
        ];
    }

    /** The WWW-Authenticate challenge on a 401 from the MCP endpoint. */
    public static function challenge(bool $invalidToken): string
    {
        $value = 'Bearer resource_metadata="' . self::issuer() . '/.well-known/oauth-protected-resource/mcp", scope="' . self::SCOPE . '"';

        return $invalidToken ? $value . ', error="invalid_token"' : $value;
    }

    // ------------------------------------------------------------------
    // clients
    // ------------------------------------------------------------------

    /** RFC 7591 dynamic client registration. */
    public function register(array $body): array
    {
        $uris = $body['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > 10) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must list 1-10 redirect URIs.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::validRedirectUri($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'Redirect URIs must be https, http on loopback, or a private-use app scheme, without a fragment.');
            }
        }
        $method = (string) ($body['token_endpoint_auth_method'] ?? 'client_secret_basic');
        if (!in_array($method, self::AUTH_METHODS, true)) {
            throw new OAuthError('invalid_client_metadata', 'token_endpoint_auth_method must be one of: ' . implode(', ', self::AUTH_METHODS));
        }
        $grantTypes = array_values(array_filter((array) ($body['grant_types'] ?? ['authorization_code', 'refresh_token']), 'is_string'));
        if (array_diff($grantTypes, ['authorization_code', 'refresh_token']) !== []) {
            throw new OAuthError('invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported.');
        }
        $responseTypes = array_values(array_filter((array) ($body['response_types'] ?? ['code']), 'is_string'));
        if (array_diff($responseTypes, ['code']) !== []) {
            throw new OAuthError('invalid_client_metadata', 'Only the code response type is supported.');
        }

        $name = trim(mb_substr((string) ($body['client_name'] ?? ''), 0, 120)) ?: 'MCP client';
        $clientId = 'vmcl_' . Str::random(32);
        $secret = $method === 'none' ? null : 'vmcs_' . Str::random(48);

        $client = McpOAuthClient::create([
            'client_id' => $clientId,
            'client_secret_hash' => $secret !== null ? hash('sha256', $secret) : null,
            'name' => $name,
            'redirect_uris' => array_values($uris),
            'token_endpoint_auth_method' => $method,
            'source' => 'dcr',
            'metadata' => array_intersect_key($body, array_flip(['client_uri', 'logo_uri', 'software_id', 'software_version', 'scope'])),
        ]);

        return array_filter([
            'client_id' => $client->client_id,
            'client_secret' => $secret,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_secret_expires_at' => $secret !== null ? 0 : null,
            'client_name' => $name,
            'redirect_uris' => $client->redirect_uris,
            'token_endpoint_auth_method' => $method,
            'grant_types' => $grantTypes ?: ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'scope' => self::SCOPE,
        ], fn ($v) => $v !== null);
    }

    /**
     * The client behind a client_id: a registered one, or — when the id is an
     * https URL — the client-ID metadata document it points at (fetched with
     * SSRF guards and cached for a day).
     */
    public function resolveClient(string $clientId): ?McpOAuthClient
    {
        if ($clientId === '' || strlen($clientId) > 255) {
            return null;
        }
        $client = McpOAuthClient::where('client_id', $clientId)->first();
        if (!str_starts_with($clientId, 'https://')) {
            return $client;
        }
        if ($client && $client->metadata_fetched_at && $client->metadata_fetched_at->gt(now()->subDay())) {
            return $client;
        }
        $doc = self::fetchMetadataDocument($clientId);
        if ($doc === null) {
            return $client; // keep using the last good copy if the fetch failed
        }

        return McpOAuthClient::updateOrCreate(['client_id' => $clientId], [
            'name' => $doc['name'],
            'redirect_uris' => $doc['redirect_uris'],
            'token_endpoint_auth_method' => 'none',
            'source' => 'cimd',
            'metadata' => $doc['metadata'],
            'metadata_fetched_at' => now(),
        ]);
    }

    /**
     * Fetch a client-ID metadata document. The URL is attacker-chosen, so:
     * https only, every resolved address public, the connection pinned to
     * that address (no DNS rebinding), no redirects, 5 s, 64 KB.
     *
     * @return array{name: string, redirect_uris: string[], metadata: array}|null
     */
    public static function fetchMetadataDocument(string $url): ?array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['fragment'])) {
            return null;
        }
        $port = (int) ($parts['port'] ?? 443);
        $ips = self::publicAddresses($host);
        if ($ips === []) {
            Log::info('MCP OAuth: client metadata host has no public address', ['url' => $url]);

            return null;
        }
        try {
            $response = Http::timeout(5)
                ->withHeaders(['Accept' => 'application/json'])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:" . (str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0])]],
                ])
                ->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        $body = $response->body();
        if (!$response->successful() || strlen($body) > 65536) {
            return null;
        }
        $doc = json_decode($body, true);
        if (!is_array($doc) || ($doc['client_id'] ?? null) !== $url) {
            return null;
        }
        $uris = array_values(array_filter((array) ($doc['redirect_uris'] ?? []), fn ($u) => is_string($u) && self::validRedirectUri($u)));
        if ($uris === []) {
            return null;
        }

        return [
            'name' => trim(mb_substr((string) ($doc['client_name'] ?? $host), 0, 120)) ?: $host,
            'redirect_uris' => array_slice($uris, 0, 10),
            'metadata' => array_intersect_key($doc, array_flip(['client_uri', 'logo_uri', 'software_id', 'software_version'])),
        ];
    }

    /** @return string[] the host's addresses, only if EVERY one of them is public */
    private static function publicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $rec) {
                if (!empty($rec['ip'])) {
                    $ips[] = $rec['ip'];
                } elseif (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        if ($ips === []) {
            return [];
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return [];
            }
        }

        return array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?: $ips);
    }

    /** https anywhere; http only on loopback; private-use app schemes (RFC 8252); never a fragment. */
    public static function validRedirectUri(string $uri): bool
    {
        if (strlen($uri) > 500 || str_contains($uri, '#')) {
            return false;
        }
        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'https') {
            return !empty($parts['host']);
        }
        if ($scheme === 'http') {
            return in_array(strtolower((string) ($parts['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        }

        return $scheme !== '' && preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme)
            && !in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss'], true);
    }

    /** Exact match — except a loopback http URI may use any port (RFC 8252 §7.3). */
    public static function redirectAllowed(McpOAuthClient $client, string $uri): bool
    {
        foreach ((array) $client->redirect_uris as $registered) {
            if (hash_equals((string) $registered, $uri)) {
                return true;
            }
            $a = parse_url((string) $registered);
            $b = parse_url($uri);
            $loop = ['localhost', '127.0.0.1', '[::1]', '::1'];
            if (($a['scheme'] ?? '') === 'http' && ($b['scheme'] ?? '') === 'http'
                && in_array(strtolower((string) ($a['host'] ?? '')), $loop, true)
                && strtolower((string) ($a['host'] ?? '')) === strtolower((string) ($b['host'] ?? ''))
                && ($a['path'] ?? '/') === ($b['path'] ?? '/')
                && ($a['query'] ?? '') === ($b['query'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // authorization
    // ------------------------------------------------------------------

    /**
     * Validate an authorization request and park it for the consent page.
     *
     * @return string the pending request id (the consent page's ?req=)
     */
    public function beginAuthorization(array $q): string
    {
        $clientId = (string) ($q['client_id'] ?? '');
        $client = $this->resolveClient($clientId);
        if (!$client) {
            throw new OAuthError('invalid_client', 'This app is not registered with the studio. Remove the connector and add it again.');
        }
        $redirect = (string) ($q['redirect_uri'] ?? '');
        if ($redirect === '' && count((array) $client->redirect_uris) === 1) {
            $redirect = (string) $client->redirect_uris[0];
        }
        if ($redirect === '' || !self::redirectAllowed($client, $redirect)) {
            throw new OAuthError('invalid_request', 'The redirect URI does not match what this app registered.');
        }
        $state = isset($q['state']) ? (string) $q['state'] : null;
        $fail = fn (string $error, string $desc) => new OAuthError($error, $desc, 400, $redirect, $state);

        if (($q['response_type'] ?? '') !== 'code') {
            throw $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = (string) ($q['code_challenge'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            throw $fail('invalid_request', 'PKCE is required: send code_challenge (S256).');
        }
        if (($q['code_challenge_method'] ?? '') !== 'S256') {
            throw $fail('invalid_request', 'code_challenge_method must be S256.');
        }
        $resource = isset($q['resource']) ? (string) $q['resource'] : null;
        if ($resource !== null && !self::resourceMatches($resource)) {
            throw $fail('invalid_target', 'This server only issues tokens for ' . self::resource());
        }

        $id = Str::random(40);
        Cache::put('mcp:oauth:req:' . $id, [
            'client_pk' => $client->id,
            'client_id' => $client->client_id,
            'redirect_uri' => $redirect,
            'state' => $state,
            'code_challenge' => $challenge,
            'scope' => self::SCOPE,
            'resource' => $resource ?? self::resource(),
        ], self::REQUEST_TTL);

        return $id;
    }

    /** What the consent page shows. */
    public function describeRequest(string $id): ?array
    {
        $req = Cache::get('mcp:oauth:req:' . $id);
        if (!is_array($req)) {
            return null;
        }
        $client = McpOAuthClient::find($req['client_pk']);
        if (!$client) {
            return null;
        }
        $host = strtolower((string) (parse_url($req['redirect_uri'], PHP_URL_HOST) ?: parse_url($req['redirect_uri'], PHP_URL_SCHEME)));
        $known = ['claude.ai' => 'Claude', 'claude.com' => 'Claude', 'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT'];
        $recognised = null;
        foreach ($known as $domain => $label) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $recognised = $label;
            }
        }

        return [
            'client_name' => $client->name,
            'client_uri' => $client->metadata['client_uri'] ?? null,
            'redirect_host' => $host,
            'recognised_as' => $recognised,
            'local_app' => in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true),
            'scopes' => [[
                'name' => self::SCOPE,
                'description' => 'Create, preview and render videos in your Vreato studio, read the videos it made, and use your free voices and media.',
            ]],
        ];
    }

    /** The user said Allow: a one-time code goes back to the app. */
    public function approve(User $user, string $id): string
    {
        $req = Cache::pull('mcp:oauth:req:' . $id);
        if (!is_array($req)) {
            throw new OAuthError('invalid_request', 'This sign-in request has expired. Start connecting again from the app.', 410);
        }
        $code = Str::random(64);
        Cache::put('mcp:oauth:code:' . hash('sha256', $code), $req + ['user_id' => $user->id], self::CODE_TTL);

        return self::withQuery($req['redirect_uri'], ['code' => $code, 'state' => $req['state'], 'iss' => self::issuer()]);
    }

    public function deny(string $id): string
    {
        $req = Cache::pull('mcp:oauth:req:' . $id);
        if (!is_array($req)) {
            throw new OAuthError('invalid_request', 'This sign-in request has expired.', 410);
        }

        return self::withQuery($req['redirect_uri'], [
            'error' => 'access_denied',
            'error_description' => 'The user declined.',
            'state' => $req['state'],
            'iss' => self::issuer(),
        ]);
    }

    public static function withQuery(string $uri, array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');

        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function resourceMatches(string $resource): bool
    {
        $norm = fn (string $u) => rtrim(strtolower($u), '/');

        return in_array($norm($resource), [$norm(self::resource()), $norm(self::issuer())], true)
            || str_starts_with($norm($resource), $norm(self::resource()) . '/');
    }

    // ------------------------------------------------------------------
    // tokens
    // ------------------------------------------------------------------

    /**
     * The token endpoint.
     *
     * @param  array  $body  form params
     * @param  array{0: ?string, 1: ?string}  $basic  client_id/secret from HTTP Basic
     */
    public function token(array $body, array $basic): array
    {
        $client = $this->authenticateClient($body, $basic);
        $grantType = (string) ($body['grant_type'] ?? '');

        if ($grantType === 'authorization_code') {
            $code = (string) ($body['code'] ?? '');
            $data = $code !== '' ? Cache::pull('mcp:oauth:code:' . hash('sha256', $code)) : null;
            if (!is_array($data) || $data['client_id'] !== $client->client_id) {
                throw new OAuthError('invalid_grant', 'The authorization code is invalid, expired or already used.');
            }
            if (isset($body['redirect_uri']) && (string) $body['redirect_uri'] !== $data['redirect_uri']) {
                throw new OAuthError('invalid_grant', 'redirect_uri does not match the authorization request.');
            }
            $verifier = (string) ($body['code_verifier'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)
                || !hash_equals($data['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
                throw new OAuthError('invalid_grant', 'PKCE verification failed.');
            }
            if (isset($body['resource']) && !self::resourceMatches((string) $body['resource'])) {
                throw new OAuthError('invalid_target', 'This server only issues tokens for ' . self::resource());
            }
            $user = User::find($data['user_id']);
            if (!$user) {
                throw new OAuthError('invalid_grant', 'The account no longer exists.');
            }
            $grant = McpOAuthGrant::create([
                'user_id' => $user->id,
                'oauth_client_id' => $client->id,
                'scope' => $data['scope'],
                'resource' => $data['resource'],
            ]);
            $client->forceFill(['last_used_at' => now()])->saveQuietly();

            return $this->issue($grant, $client);
        }

        if ($grantType === 'refresh_token') {
            $plain = (string) ($body['refresh_token'] ?? '');
            $grant = $plain !== '' ? McpOAuthGrant::where('refresh_token_hash', hash('sha256', $plain))->first() : null;
            if (!$grant || $grant->revoked_at !== null || ($grant->refresh_expires_at && $grant->refresh_expires_at->isPast())
                || (int) $grant->oauth_client_id !== (int) $client->id) {
                throw new OAuthError('invalid_grant', 'The refresh token is invalid, expired or revoked. Connect again.');
            }

            return $this->issue($grant, $client);
        }

        throw new OAuthError('unsupported_grant_type', 'Use authorization_code or refresh_token.');
    }

    /** A fresh access token + a rotated refresh token for a grant. */
    private function issue(McpOAuthGrant $grant, McpOAuthClient $client): array
    {
        [, $access] = McpToken::mintAccess($grant, $client->name, self::ACCESS_TTL);
        $refresh = 'vmor_' . Str::random(48);
        $grant->update([
            'refresh_token_hash' => hash('sha256', $refresh),
            'refresh_expires_at' => now()->addDays(self::REFRESH_TTL_DAYS),
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => $grant->scope,
        ];
    }

    /** RFC 7009: revoke an access or refresh token. Unknown tokens are not an error. */
    public function revoke(array $body, array $basic): void
    {
        $client = $this->authenticateClient($body, $basic);
        $plain = (string) ($body['token'] ?? '');
        if (str_starts_with($plain, 'vmor_')) {
            $grant = McpOAuthGrant::where('refresh_token_hash', hash('sha256', $plain))->first();
            if ($grant && (int) $grant->oauth_client_id === (int) $client->id) {
                $grant->revoke();
            }

            return;
        }
        if (str_starts_with($plain, McpToken::ACCESS_PREFIX)) {
            $token = McpToken::where('token_hash', McpToken::hash($plain))->first();
            if ($token && $token->grant && (int) $token->grant->oauth_client_id === (int) $client->id) {
                $token->update(['revoked_at' => now()]);
            }
        }
    }

    /**
     * Who is calling the token endpoint: HTTP Basic, client_secret_post, or a
     * public client by id alone. A confidential client must prove its secret.
     */
    private function authenticateClient(array $body, array $basic): McpOAuthClient
    {
        [$basicId, $basicSecret] = $basic;
        $clientId = $basicId ?? (string) ($body['client_id'] ?? '');
        $secret = $basicSecret ?? (isset($body['client_secret']) ? (string) $body['client_secret'] : null);
        $client = $this->resolveClient($clientId);
        if (!$client) {
            throw new OAuthError('invalid_client', 'Unknown client.', 401);
        }
        if ($client->isConfidential()) {
            if ($secret === null || !hash_equals((string) $client->client_secret_hash, hash('sha256', $secret))) {
                throw new OAuthError('invalid_client', 'Client authentication failed.', 401);
            }
        }

        return $client;
    }
}
