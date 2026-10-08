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

    /**
     * The client app's name from its `initialize` (e.g. "claude-ai",
     * "claude-code"), remembered on the credential — or, for an OAuth access
     * token minted by a refresh after initialize, on its connection.
     */
    public function clientName(): ?string
    {
        if (!$this->token) {
            return null;
        }

        return $this->token->last_client ?: ($this->token->grant_id ? $this->token->grant?->last_client : null);
    }
}
