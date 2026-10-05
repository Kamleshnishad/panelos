<?php

namespace Tests\Unit;

use App\Models\ChemicalStock;
use Tests\TestCase;

class ChemicalExpiryTest extends TestCase
{
    private function chemical(\DateTimeInterface $expiry): ChemicalStock
    {
        $c = new ChemicalStock();
        $c->expiry_date = $expiry;
        return $c;
    }

    public function test_expiring_within_30_days_is_expiring_not_expired(): void
    {
        $c = $this->chemical(now()->addDays(10));
        $this->assertTrue($c->isExpiring());
        $this->assertFalse($c->isExpired());
    }

    public function test_long_expired_chemical_is_not_flagged_as_expiring(): void
    {
        // Regression: absolute diff made a 45-day-old expiry look like "not expiring"
        // in a way that could mask it; it must be expired, never "expiring".
        $c = $this->chemical(now()->subDays(45));
        $this->assertFalse($c->isExpiring());
        $this->assertTrue($c->isExpired());
    }

    public function test_far_future_expiry_is_neither(): void
    {
        $c = $this->chemical(now()->addDays(90));
        $this->assertFalse($c->isExpiring());
        $this->assertFalse($c->isExpired());
    }
}
