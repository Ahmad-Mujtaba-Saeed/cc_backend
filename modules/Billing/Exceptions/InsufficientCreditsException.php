<?php

namespace Modules\Billing\Exceptions;

use Exception;

class InsufficientCreditsException extends Exception
{
    public int $balance;
    public int $cost;

    public function __construct(int $balance, int $cost)
    {
        parent::__construct('Insufficient credits');
        $this->balance = $balance;
        $this->cost = $cost;
    }
}
