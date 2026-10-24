<?php

namespace Tests\Unit;

use App\Support\Days;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Carbon 3 made diffInDays() signed and float. Code written for Carbon 2 (`now()->diffInDays($due)`) read an
 * overdue invoice as a negative number, so reminders were never scheduled and messages said "0 days overdue".
 * Days gives one definition of the direction, in whole calendar days.
 */
class DaysTest extends TestCase
{
    private function today(): Carbon
    {
        return Carbon::create(2026, 10, 24, 15, 30, 0);
    }

    public function test_overdue_is_positive_after_the_due_date(): void
    {
        $this->assertSame(5, Days::overdue(Carbon::create(2026, 10, 19, 8), $this->today()));
    }

    public function test_it_is_zero_on_the_day_whatever_the_time(): void
    {
        $this->assertSame(0, Days::overdue(Carbon::create(2026, 10, 24, 0, 0), $this->today()));
        $this->assertSame(0, Days::overdue(Carbon::create(2026, 10, 24, 23, 59), $this->today()));
    }

    public function test_overdue_is_negative_while_still_to_come(): void
    {
        $this->assertSame(-3, Days::overdue(Carbon::create(2026, 10, 27, 1), $this->today()));
    }

    public function test_until_is_the_mirror(): void
    {
        $this->assertSame(10, Days::until(Carbon::create(2026, 11, 3), $this->today()));
        $this->assertSame(-2, Days::until(Carbon::create(2026, 10, 22), $this->today()));
    }

    public function test_a_missing_date_is_zero_and_the_result_is_an_integer(): void
    {
        $this->assertSame(0, Days::overdue(null));
        $this->assertIsInt(Days::overdue(Carbon::create(2026, 1, 1), $this->today()));
    }

    public function test_it_does_not_mutate_the_dates_it_is_given(): void
    {
        $due = Carbon::create(2026, 10, 19, 8, 15);
        Days::overdue($due, $this->today());

        $this->assertSame('2026-10-19 08:15:00', $due->toDateTimeString());
    }
}
