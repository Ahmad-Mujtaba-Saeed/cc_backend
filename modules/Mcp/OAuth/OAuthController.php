<?php

namespace Modules\Mcp\OAuth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Mcp\Models\McpOAuthGrant;

/**
 * The OAuth endpoints MCP clients talk to (no session, no CSRF — tokens and
 * PKCE are the protection), plus the consent API the dashboard page uses
 * (Sanctum: the signed-in user is who approves).
 *
 *   GET  /.well-known/oauth-protected-resource[/mcp]   RFC 9728
 *   GET  /.well-known/oauth-authorization-server       RFC 8414
 *   POST /oauth/register                               RFC 7591
 *   GET  /oauth/authorize          → dashboard /oauth/consent?req=…
 *   POST /oauth/token
 *   POST /oauth/revoke                                 RFC 7009
 *   GET  /api/mcp/oauth/requests/{id}          (auth)  what is being asked
 *   POST /api/mcp/oauth/requests/{id}/approve  (auth)  → {redirect_to}
 *   POST /api/mcp/oauth/requests/{id}/deny     (auth)  → {redirect_to}
 *   DELETE /api/mcp/oauth/grants/{id}          (auth)  revoke a connection
 */
class OAuthController extends Controller
{
    public function __construct(private McpOAuthService $oauth)
    {
    }

    public function protectedResource(): JsonResponse
    {
        return $this->json(McpOAuthService::protectedResourceMetadata());
    }

    public function authorizationServer(): JsonResponse
    {
        return $this->json(McpOAuthService::authorizationServerMetadata());
    }

    public function register(Request $request): JsonResponse
    {
        $body = $request->json()->all() ?: $request->all();
        try {
            return $this->json($this->oauth->register(is_array($body) ? $body : []), 201);
        } catch (OAuthError $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    /** Validate, then hand the user to the dashboard's consent page. */
    public function authorize(Request $request): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/') . '/oauth/consent';
        try {
            $id = $this->oauth->beginAuthorization($request->query());
        } catch (OAuthError $e) {
            if ($e->redirectUri !== null) {
                return redirect()->away(McpOAuthService::withQuery($e->redirectUri, $e->toArray() + ['state' => $e->state, 'iss' => McpOAuthService::issuer()]));
            }

            // An unknown client or redirect URI is never redirected to.
            return redirect()->away($frontend . '?' . http_build_query($e->toArray()));
        }

        return redirect()->away($frontend . '?req=' . $id);
    }

    public function token(Request $request): JsonResponse
    {
        try {
            return $this->json($this->oauth->token($request->all(), $this->basic($request)));
        } catch (OAuthError $e) {
            $headers = $e->status === 401 ? ['WWW-Authenticate' => 'Basic realm="oauth"'] : [];

            return $this->json($e->toArray(), $e->status, $headers);
        }
    }

    public function revoke(Request $request): Response|JsonResponse
    {
        try {
            $this->oauth->revoke($request->all(), $this->basic($request));
        } catch (OAuthError $e) {
            return $this->json($e->toArray(), $e->status);
        }

        return response('', 200, ['Cache-Control' => 'no-store']);
    }

    // ---- consent (signed-in dashboard user) ----------------------------

    public function showRequest(string $id): JsonResponse
    {
        $info = $this->oauth->describeRequest($id);
        if ($info === null) {
            return response()->json(['success' => false, 'message' => 'This sign-in request has expired. Start connecting again from the app.'], 410);
        }

        return response()->json(['success' => true, 'data' => $info]);
    }

    public function approve(string $id): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => ['redirect_to' => $this->oauth->approve(auth()->user(), $id)]]);
        } catch (OAuthError $e) {
            return response()->json(['success' => false, 'message' => $e->description], $e->status);
        }
    }

    public function deny(string $id): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => ['redirect_to' => $this->oauth->deny($id)]]);
        } catch (OAuthError $e) {
            return response()->json(['success' => false, 'message' => $e->description], $e->status);
        }
    }

    public function revokeGrant(int $id): JsonResponse
    {
        $grant = McpOAuthGrant::where('user_id', auth()->id())->where('id', $id)->whereNull('revoked_at')->first();
        if (!$grant) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        $grant->revoke();

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------------

    /** @return array{0: ?string, 1: ?string} client id/secret from HTTP Basic */
    private function basic(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');
        if (!str_starts_with($header, 'Basic ')) {
            return [null, null];
        }
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return [null, null];
        }
        [$id, $secret] = explode(':', $decoded, 2);

        return [urldecode($id), urldecode($secret)];
    }

    private function json(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, $headers + ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'], JSON_UNESCAPED_SLASHES);
    }
}
