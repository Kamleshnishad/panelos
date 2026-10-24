<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Whole calendar days between a date and today, with a fixed direction.
 *
 * Carbon 3 changed diffInDays(): it is now SIGNED (later minus earlier) and returns a float
 * (-5.0000000128819), where Carbon 2 returned a positive integer. Code written as
 * `now()->diffInDays($invoice->due_date)` therefore got a negative number for an overdue invoice, and
 * `max(0, …)` turned that into "not due" — reminders never scheduled, "0 days overdue" in messages,
 * every overdue invoice landing in the first aging bucket. Compare dates, not instants, and name the direction.
 */
class Days
{
    /** Days past the due date: positive once overdue, 0 on the day, negative while still to come. */
    public static function overdue(?CarbonInterface $due, ?CarbonInterface $today = null): int
    {
        return $due ? (int) round($due->copy()->startOfDay()->diffInDays(($today ?? now())->copy()->startOfDay(), false)) : 0;
    }

    /** Days still to go until a date (an expiry, a delivery): positive before it, negative once past. */
    public static function until(?CarbonInterface $date, ?CarbonInterface $today = null): int
    {
        return -self::overdue($date, $today);
    }
}
