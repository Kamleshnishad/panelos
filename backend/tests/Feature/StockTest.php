<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Coil;
use App\Models\Chemical;
use App\Models\CoilStock;
use App\Models\ChemicalStock;
use App\Models\StockTransaction;
use App\Models\LowStockAlert;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $stockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->stockService = app(StockService::class);
        $this->actingAs($this->user);
    }

    public function test_add_coil_stock()
    {
        $coil = Coil::factory()->create();

        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Initial stock', $this->company->id);

        $this->assertNotNull($stock);
        $this->assertEquals(500, $stock->quantity_in_stock);
        $this->assertEquals($coil->id, $stock->coil_id);
        $this->assertEquals($this->company->id, $stock->company_id);
    }

    public function test_remove_coil_stock()
    {
        $coil = Coil::factory()->create();
        $this->stockService->addCoilStock($coil->id, 500, 'Initial', $this->company->id);

        $stock = $this->stockService->removeCoilStock($coil->id, 100, 'Removal', $this->company->id);

        $this->assertEquals(400, $stock->quantity_in_stock);
    }

    public function test_cannot_remove_more_than_available()
    {
        $coil = Coil::factory()->create();
        $this->stockService->addCoilStock($coil->id, 100, 'Initial', $this->company->id);

        $this->expectException(\Exception::class);
        $this->stockService->removeCoilStock($coil->id, 150, 'Removal', $this->company->id);
    }

    public function test_adjust_stock()
    {
        $coil = Coil::factory()->create();
        $this->stockService->addCoilStock($coil->id, 500, 'Initial', $this->company->id);

        $stock = $this->stockService->adjustCoilStock($coil->id, 600, 'Correction', $this->company->id);

        $this->assertEquals(600, $stock->quantity_in_stock);
    }

    public function test_stock_transaction_immutable()
    {
        $coil = Coil::factory()->create();
        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Initial', $this->company->id);

        $transaction = $stock->transactions()->first();

        $this->expectException(\Exception::class);
        $transaction->update(['quantity' => 1000]);
    }

    public function test_get_stock_level()
    {
        $coil = Coil::factory()->create();
        $this->stockService->addCoilStock($coil->id, 500, 'Initial', $this->company->id);

        $stock = $this->stockService->getStockLevel('coil', $coil->id, $this->company->id);

        $this->assertEquals(500, $stock->quantity_in_stock);
    }

    public function test_chemical_stock_with_expiry()
    {
        $chemical = Chemical::factory()->create();
        $expiryDate = now()->addDays(45)->format('Y-m-d');

        $stock = $this->stockService->addChemicalStock(
            $chemical->id,
            100,
            'liter',
            'BATCH-001',
            $expiryDate,
            'Chemical stock',
            $this->company->id
        );

        $this->assertNotNull($stock);
        $this->assertEquals('BATCH-001', $stock->batch_number);
        $this->assertTrue($stock->isExpiring());
        $this->assertFalse($stock->isExpired());
    }

    public function test_multi_tenant_isolation_stock()
    {
        $otherCompany = Company::factory()->create();
        $coil = Coil::factory()->create();

        $this->stockService->addCoilStock($coil->id, 500, 'Stock 1', $this->company->id);
        $this->stockService->addCoilStock($coil->id, 300, 'Stock 2', $otherCompany->id);

        $myStock = $this->stockService->getStockLevel('coil', $coil->id, $this->company->id);
        $theirStock = $this->stockService->getStockLevel('coil', $coil->id, $otherCompany->id);

        $this->assertEquals(500, $myStock->quantity_in_stock);
        $this->assertEquals(300, $theirStock->quantity_in_stock);
    }

    public function test_reorder_level_validation()
    {
        $coil = Coil::factory()->create();
        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Initial', $this->company->id);
        $stock->update(['reorder_level' => 100]);

        $this->assertTrue($stock->isLowStock() === false);

        $this->stockService->removeCoilStock($coil->id, 420, 'Removal', $this->company->id);
        $stock->refresh();

        $this->assertTrue($stock->isLowStock());
    }

    public function test_low_stock_alert_trigger()
    {
        $coil = Coil::factory()->create();
        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Initial', $this->company->id);
        $stock->update(['reorder_level' => 100]);

        $this->stockService->removeCoilStock($coil->id, 420, 'Removal', $this->company->id);
        $this->stockService->checkLowStock($this->company->id);

        $alert = LowStockAlert::where('company_id', $this->company->id)
            ->where('item_type', 'coil')
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($alert);
        $this->assertEquals('low_stock', $alert->alert_type);
    }

    public function test_stock_history_retrieval()
    {
        $coil = Coil::factory()->create();
        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Initial', $this->company->id);
        $this->stockService->removeCoilStock($coil->id, 100, 'Removal', $this->company->id);
        $this->stockService->adjustCoilStock($coil->id, 450, 'Adjustment', $this->company->id);

        $history = $this->stockService->getStockHistory('coil', $coil->id, 30, $this->company->id);

        $this->assertGreaterThanOrEqual(3, $history->count());
        $this->assertTrue($history->contains('type', 'in'));
        $this->assertTrue($history->contains('type', 'out'));
        $this->assertTrue($history->contains('type', 'adjustment'));
    }

    public function test_stock_audit_trail()
    {
        $coil = Coil::factory()->create();
        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Initial stock', $this->company->id);

        $transaction = StockTransaction::where('transactionable_id', $stock->id)->first();

        $this->assertNotNull($transaction);
        $this->assertEquals('in', $transaction->type);
        $this->assertEquals(500, $transaction->quantity);
        $this->assertEquals('Initial stock', $transaction->notes);
        $this->assertEquals($this->user->id, $transaction->created_by_user_id);
    }
}
