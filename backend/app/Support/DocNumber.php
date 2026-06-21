<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * CONC-M1 — retry a document-creation closure when a concurrent request grabs
 * the same sequential number first. The unique index rejects the duplicate
 * (SQLSTATE 23000); we roll back and re-run, which reads a fresh max and picks
 * the next number — instead of bubbling a 500 to the user.
 *
 * Wrap the WHOLE DB::transaction(...) so each retry runs a clean transaction.
 * Only 23000 is retried; every other error propagates immediately unchanged.
 */
class DocNumber
{
    public static function retry(callable $fn, int $tries = 3)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                if ($attempt >= $tries || $e->getCode() !== '23000') {
                    throw $e;
                }
                usleep(random_int(5000, 25000)); // brief jitter before retrying
            }
        }
    }
}
