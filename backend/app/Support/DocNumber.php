<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Retry a transactional closure on a recoverable concurrency error:
 *   - SQLSTATE 23000 — duplicate key (e.g. two requests grabbed the same
 *     sequential document number); re-running reads a fresh max (CONC-M1).
 *   - SQLSTATE 40001 — deadlock (e.g. two lockForUpdate transactions); the
 *     loser is rolled back by the engine and simply re-runs cleanly (CONC-H1).
 *
 * Wrap the WHOLE DB::transaction(...) so each retry runs a clean transaction.
 * Every other error propagates immediately unchanged.
 */
class DocNumber
{
    /** SQLSTATEs that are safe to retry by re-running the transaction. */
    private const RETRYABLE = ['23000', '40001'];

    public static function retry(callable $fn, int $tries = 3)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                if ($attempt >= $tries || !in_array($e->getCode(), self::RETRYABLE, true)) {
                    throw $e;
                }
                usleep(random_int(5000, 25000)); // brief jitter before retrying
            }
        }
    }
}
