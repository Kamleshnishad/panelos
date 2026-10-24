<?php

namespace App\Exceptions;

use Exception;

/** Converting a lead found customers that look like the same buyer; the caller must choose one or ask for a new customer (LEADS_PLAN 1.5). */
class LeadCustomerMatchException extends Exception
{
    /** @param list<array<string,mixed>> $matches */
    public function __construct(public readonly array $matches)
    {
        parent::__construct('This buyer looks like a customer you already have. Use that customer, or create a new one anyway.');
    }
}
