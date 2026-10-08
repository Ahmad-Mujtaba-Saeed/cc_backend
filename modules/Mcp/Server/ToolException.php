<?php

namespace Modules\Mcp\Server;

/**
 * A refusal the MODEL should read and act on ("scene_7 does not exist",
 * "render quota reached"). The server turns it into an isError tool result.
 */
class ToolException extends \RuntimeException
{
    public function __construct(string $message, private array $details = [])
    {
        parent::__construct($message);
    }

    public function details(): array
    {
        return $this->details;
    }
}
