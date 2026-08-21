<?php

namespace Modules\Billing\Services\Safepay;

use RuntimeException;

class SafepayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $payload = []
    ) {
        parent::__construct($message, $status ?? 0);
    }
}
