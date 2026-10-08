<?php

namespace Modules\Mcp\Server;

/** A JSON-RPC level error (unknown method/tool, bad params). */
class ProtocolError extends \RuntimeException
{
    public function __construct(string $message, int $code = -32602)
    {
        parent::__construct($message, $code);
    }
}
