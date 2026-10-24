<?php

namespace App\Services;

use RuntimeException;

/** IndiaMART did not give us leads. The message is written for the person using the screen and never contains the key. */
class IndiaMartException extends RuntimeException
{
    /** @param string $kind one of: bad_key, rate_limited, unreachable, rejected, unreadable */
    public function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }
}
