<?php

namespace Modules\Mcp\Server;

use Modules\Mcp\Models\McpToken;
use Modules\User\Models\User;

/** Who is calling: the account behind the connection secret. */
final class McpContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?McpToken $token = null,
    ) {
    }
}
