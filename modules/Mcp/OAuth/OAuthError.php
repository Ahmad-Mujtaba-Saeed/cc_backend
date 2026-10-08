<?php

namespace Modules\Mcp\OAuth;

/**
 * An OAuth error response (RFC 6749 §5.2 / §4.1.2.1). When `redirectUri` is
 * set the error goes back to the client's redirect URI; otherwise it is shown
 * to the user (an invalid client or redirect URI must never be redirected to).
 */
class OAuthError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description = '',
        public readonly int $status = 400,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($description !== '' ? $description : $error);
    }

    public function toArray(): array
    {
        return array_filter(['error' => $this->error, 'error_description' => $this->description], fn ($v) => $v !== '');
    }
}
