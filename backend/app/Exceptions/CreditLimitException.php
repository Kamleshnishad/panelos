<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown from inside the order-creation transaction when the authoritative,
 * row-locked credit re-check fails (CONC-M2). Carries the credit breakdown so the
 * controller can return the same 422 payload as the fast pre-check.
 */
class CreditLimitException extends Exception
{
    public function __construct(public array $credit, public string $customerName)
    {
        parent::__construct('Credit limit exceeded for ' . $customerName . '.');
    }
}
