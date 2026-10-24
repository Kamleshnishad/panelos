<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\CoilStock;
use App\Models\ChemicalStock;
use App\Services\StockService;
use App\Services\StockDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockDashboardTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Traits\MakesStockRows;

    protected $company;
    protected $user;
    protected $stockService;
    protected $dashboardService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->stockService = app(StockService::class);
        $this->dashboardService = app(StockDashboardService::class);
        $this->actingAs($this->user);
    }

    public function test_get_total_stock_value()
    {
        $coil = $this->coil(['unit_cost' => 100]);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);

        $value = $this->dashboardService->getTotalStockValue($this->company->id);

        $this->assertGreaterThan(0, $value);
    }

    public function test_get_low_stock_items()
    {
        $coil = $this->coil();
        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $stock->update(['reorder_level' => 100]);
        $this->stockService->removeCoilStock($coil->id, 420, 'Removal', $this->company->id);

        $lowItems = $this->dashboardService->getLowStockItems($this->company->id);

        $this->assertGreaterThan(0, count($lowItems));
        $this->assertTrue(collect($lowItems)->contains('type', 'coil'));
    }

    public function test_get_expiring_soon_chemicals()
    {
        $chemical = $this->chemical();
        $expiryDate = now()->addDays(15)->format('Y-m-d');

        $this->stockService->addChemicalStock(
            $chemical->id,
            100,
            'liter',
            'BATCH-001',
            $expiryDate,
            'Chemical',
            $this->company->id
        );

        $expiring = $this->dashboardService->getExpiringChemicals(30, $this->company->id);

        $this->assertGreaterThan(0, count($expiring));
    }

    public function test_get_stock_movement()
    {
        $coil = $this->coil();
        $this->stockService->addCoilStock($coil->id, 500, 'Initial', $this->company->id);
        $this->stockService->removeCoilStock($coil->id, 100, 'Removal', $this->company->id);

        $movement = $this->dashboardService->getStockMovement(30, $this->company->id);

        $this->assertIsArray($movement->toArray());
    }

    public function test_get_dispatch_pipeline()
    {
        $batch = \App\Models\ProductionBatch::factory()
            ->for($this->company)
            ->create(['status' => 'completed']);

        $count = $this->dashboardService->getDispatchPipeline($this->company->id);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_alert_summary_calculation()
    {
        $coil = $this->coil();
        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $stock->update(['reorder_level' => 100]);
        $this->stockService->removeCoilStock($coil->id, 420, 'Removal', $this->company->id);
        $this->stockService->checkLowStock($this->company->id);

        $alerts = $this->dashboardService->getAlertSummary($this->company->id);

        $this->assertArrayHasKey('total_active', $alerts);
        $this->assertArrayHasKey('low_stock', $alerts);
        $this->assertGreaterThan(0, $alerts['total_active']);
    }

    public function test_dashboard_multi_tenant_isolation()
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        // Only the other company is running low.
        $this->coil(['quantity_in_stock' => 500, 'reorder_level' => 100]);
        $this->coil(['quantity_in_stock' => 10, 'reorder_level' => 100], $otherCompany->id);

        $myLow = collect($this->dashboardService->getLowStockItems($this->company->id));
        $this->actingAs($otherUser);
        $theirLow = collect($this->dashboardService->getLowStockItems($otherCompany->id));

        $this->assertCount(0, $myLow, 'A company must not see another company low stock.');
        $this->assertGreaterThan(0, $theirLow->count());
    }

    public function test_dashboard_performance_with_large_data()
    {
        $coils = collect(range(1, 50))->map(fn () => $this->coil());

        foreach ($coils as $coil) {
            $this->stockService->addCoilStock($coil->id, rand(100, 1000), 'Stock', $this->company->id);
        }

        $startTime = microtime(true);
        $dashboard = $this->dashboardService->getDashboardData($this->company->id);
        $endTime = microtime(true);

        $executionTime = ($endTime - $startTime) * 1000; // Convert to ms

        $this->assertLessThan(5000, $executionTime); // Should complete in under 5 seconds
        $this->assertArrayHasKey('total_stock_value', $dashboard);
        $this->assertArrayHasKey('low_stock_items', $dashboard);
    }
}
