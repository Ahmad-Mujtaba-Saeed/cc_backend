<?php

use Illuminate\Support\Facades\Route;
use Modules\Mcp\Http\Controllers\McpEndpointController;
use Modules\Mcp\OAuth\OAuthController;

// The MCP endpoint itself: /mcp (no /api prefix — it is the URL users paste
// into their LLM client). Stateless JSON-RPC over Streamable HTTP.
Route::post('/mcp', [McpEndpointController::class, 'handle']);
Route::post('/mcp/{secret}', [McpEndpointController::class, 'handle'])->where('secret', 'vmcp_[A-Za-z0-9]{20,70}');
Route::match(['get', 'delete'], '/mcp', [McpEndpointController::class, 'notAllowed']);
Route::match(['get', 'delete'], '/mcp/{secret}', [McpEndpointController::class, 'notAllowed'])->where('secret', 'vmcp_[A-Za-z0-9]{20,70}');

// OAuth 2.1 (MCP authorization spec) — what makes Claude.ai & co show a
// plain "Connect" button. Discovery documents are served at the root and,
// per RFC 9728 / RFC 8414 path insertion, under the resource path too.
Route::get('/.well-known/oauth-protected-resource/{path?}', [OAuthController::class, 'protectedResource'])->where('path', '.*');
Route::get('/.well-known/oauth-authorization-server/{path?}', [OAuthController::class, 'authorizationServer'])->where('path', '.*');
Route::get('/.well-known/openid-configuration/{path?}', [OAuthController::class, 'authorizationServer'])->where('path', '.*');
Route::post('/oauth/register', [OAuthController::class, 'register'])->middleware('throttle:20,1');
Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])->middleware('throttle:60,1');
Route::post('/oauth/token', [OAuthController::class, 'token'])->middleware('throttle:120,1');
Route::post('/oauth/revoke', [OAuthController::class, 'revoke'])->middleware('throttle:60,1');
