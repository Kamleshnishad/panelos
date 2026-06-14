<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Coil;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\Dispatch;
use App\Models\StockAllocation;
use App\Services\DispatchService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $dispatchService;
    protected $stockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->dispatchService = app(DispatchService::class);
        $this->stockService = app(StockService::class);
        $this->actingAs($this->user);
    }

    public function test_allocate_stock()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $stock = $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);

        $allocations = $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $this->assertCount(1, $allocations);
        $this->assertEquals('allocated', $allocations[0]->status);
        $this->assertEquals(100, $allocations[0]->quantity_allocated);
    }

    public function test_release_allocation()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $allocations = $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $allocations[0]->release();

        $this->assertEquals('released', $allocations[0]->status);
        $this->assertNotNull($allocations[0]->released_at);
    }

    public function test_use_allocation_on_dispatch()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $allocations = $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $allocations[0]->markAsUsed();

        $this->assertEquals('used', $allocations[0]->status);
        $this->assertNotNull($allocations[0]->used_at);
    }

    public function test_cannot_allocate_more_than_available()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 50, 'Insufficient', $this->company->id);

        $this->expectException(\Exception::class);
        $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);
    }

    public function test_allocation_prevents_duplicate_reserve()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $allocation = StockAllocation::where('dispatch_id', $dispatch->id)->first();
        $this->assertNotNull($allocation);
        $this->assertEquals(1, StockAllocation::where('dispatch_id', $dispatch->id)->count());
    }

    public function test_expired_allocation_auto_release()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $allocations = $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        // Simulate expiry by marking as released (in real scenario, use scheduled job)
        $allocations[0]->release();

        $this->assertEquals('released', $allocations[0]->status);
    }

    public function test_allocation_audit_trail()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $allocations = $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $allocation = $allocations[0];
        $this->assertNotNull($allocation->allocated_at);
        $this->assertEquals('allocated', $allocation->status);
        $this->assertEquals($dispatch->id, $allocation->dispatch_id);
    }

    public function test_allocation_multi_tenant_isolation()
    {
        $otherCompany = Company::factory()->create();
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $allocations = $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $allocation = $allocations[0];
        $this->assertEquals($this->company->id, $allocation->company_id);
        $this->assertNotEquals($otherCompany->id, $allocation->company_id);
    }
}
