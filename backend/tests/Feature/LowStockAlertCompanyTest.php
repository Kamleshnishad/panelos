<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LowStockAlert;
use App\Services\StockService;
use Tests\TestCase;
use Tests\Traits\MakesStockRows;

/**
 * PB-097: a low-stock alert took its company from the signed-in user, so stock moving in a
 * scheduled job or console command (no user) failed with "company_id cannot be null".
 */
class LowStockAlertCompanyTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    use MakesStockRows;

    protected $company;

    public function test_low_stock_alert_is_raised_for_the_stock_rows_company_without_a_signed_in_user(): void
    {
        $this->company = Company::factory()->create();
        $other = Company::factory()->create();
        $coil = $this->coil(['quantity_in_stock' => 40, 'reorder_level' => 100]);
        $chem = $this->chemical(['quantity_in_stock' => 5, 'reorder_level' => 50]);
        $this->coil(['quantity_in_stock' => 10, 'reorder_level' => 100], $other->id);

        $this->assertNull(auth()->user());
        app(StockService::class)->checkLowStock($this->company->id);

        $alerts = LowStockAlert::where('company_id', $this->company->id)->get();
        $this->assertCount(2, $alerts);
        $this->assertEqualsCanonicalizing([$coil->id, $chem->id], $alerts->pluck('item_id')->all());
        $this->assertSame(0, LowStockAlert::where('company_id', $other->id)->count());
        $this->assertSame(0, LowStockAlert::whereNull('company_id')->count());
    }
}
