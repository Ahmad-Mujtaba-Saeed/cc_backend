<?php

/*
| Laravel's defaults, plus the MCP endpoint (/mcp): browser-based MCP clients
| (the MCP Inspector, web agents) call it cross-origin. Auth there is a
| bearer secret, never a cookie, so a wildcard origin is safe.
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'mcp', 'mcp/*', 'oauth/*', '.well-known/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Mcp-Session-Id', 'MCP-Protocol-Version', 'WWW-Authenticate'],

    'max_age' => 0,

    'supports_credentials' => false,

];
