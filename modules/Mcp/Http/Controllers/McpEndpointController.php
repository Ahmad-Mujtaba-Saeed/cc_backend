<?php

namespace Modules\Mcp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Mcp\Models\McpToken;
use Modules\Mcp\Server\McpContext;
use Modules\Mcp\Server\McpServer;
use Modules\Mcp\Tools\VideoStudioTools;

/**
 * POST /mcp            Authorization: Bearer vmcp_…
 * POST /mcp/{secret}   for clients that can only be given a URL
 *
 * The Streamable HTTP transport, stateless: one JSON-RPC message (or batch)
 * in, one JSON body out. GET (the optional server→client SSE stream) is not
 * offered — this server never initiates messages — so it answers 405, which
 * the spec defines as "no stream here".
 */
class McpEndpointController extends Controller
{
    public function handle(Request $request, ?string $secret = null): Response|JsonResponse
    {
        if (!config('mcp.enabled', true)) {
            return response()->json(McpServer::error(null, -32000, 'The MCP studio is turned off on this server.'), 503);
        }

        $token = $this->authenticate($request, $secret);
        if ($token === null) {
            // The challenge points OAuth-capable clients (Claude.ai, ChatGPT,
            // Cursor…) at our authorization server, which is what turns
            // "add this URL" into a normal sign-in + Connect button.
            $presented = $secret !== null || $request->bearerToken() !== null || $request->header('X-Api-Key') !== null;

            return response()->json(
                McpServer::error(null, -32001, 'Unauthorized: sign in (OAuth) or use a key from the Connect Claude page.'),
                401,
                ['WWW-Authenticate' => \Modules\Mcp\OAuth\McpOAuthService::challenge($presented)]
            );
        }

        // Generous for a model's tool loop, tight enough that a runaway
        // client cannot hammer the render host through preview calls.
        // Per connection: OAuth access tokens rotate hourly, the grant does not.
        $key = 'mcp:' . ($token->grant_id ? 'g' . $token->grant_id : $token->id);
        if (RateLimiter::tooManyAttempts($key, 240)) {
            return response()->json(
                McpServer::error(null, -32029, 'Too many requests — slow down and retry in a minute.'),
                429,
                ['Retry-After' => (string) RateLimiter::availableIn($key)]
            );
        }
        RateLimiter::hit($key, 60);

        $raw = (string) $request->getContent();
        $message = json_decode($raw, true);
        if ($message === null && trim($raw) !== 'null') {
            return response()->json(McpServer::error(null, -32700, 'Parse error: the body must be JSON-RPC 2.0'), 400);
        }

        $user = $token->user;
        if (!$user) {
            return response()->json(McpServer::error(null, -32001, 'Unauthorized'), 401);
        }
        auth()->setUser($user);

        // Touch at most once a minute: every tool call would otherwise write.
        if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
            if ($token->grant_id) {
                \Modules\Mcp\Models\McpOAuthGrant::whereKey($token->grant_id)->update(['last_used_at' => now()]);
            }
        }

        $server = new McpServer(new VideoStudioTools(), new McpContext($user, $token));
        $response = $server->handle($message);

        if ($response === null) {
            return response('', 202);
        }

        return response()->json($response, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** GET/DELETE /mcp — no SSE stream and no sessions on this server. */
    public function notAllowed(): Response
    {
        return response('', 405, ['Allow' => 'POST']);
    }

    private function authenticate(Request $request, ?string $secret): ?McpToken
    {
        $candidates = array_filter([
            $secret,
            $request->bearerToken(),
            $request->header('X-Api-Key'),
        ], fn ($v) => is_string($v) && $v !== '');

        foreach ($candidates as $plain) {
            $token = McpToken::findLive((string) $plain);
            if ($token) {
                return $token;
            }
        }

        return null;
    }
}
