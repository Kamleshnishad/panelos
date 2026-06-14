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

class DispatchTest extends TestCase
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

    public function test_create_dispatch_from_batch()
    {
        $batch = ProductionBatch::factory()
            ->for($this->company)
            ->create(['status' => 'qc_passed']);

        $dispatch = $this->dispatchService->createDispatch($batch->id, [
            'customer_address' => '123 Main St',
            'expected_delivery_date' => now()->addDays(7),
            'tracking_number' => 'TRK123'
        ], $this->company->id);

        $this->assertNotNull($dispatch);
        $this->assertEquals('pending', $dispatch->status);
        $this->assertStringContainsString('DISP-', $dispatch->dispatch_no);
    }

    public function test_cannot_dispatch_incomplete_batch()
    {
        $batch = ProductionBatch::factory()
            ->for($this->company)
            ->create(['status' => 'draft']);

        $this->expectException(\Exception::class);
        $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
    }

    public function test_dispatch_number_generation()
    {
        $batch1 = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $batch2 = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);

        $dispatch1 = $this->dispatchService->createDispatch($batch1->id, [], $this->company->id);
        $dispatch2 = $this->dispatchService->createDispatch($batch2->id, [], $this->company->id);

        $this->assertNotEquals($dispatch1->dispatch_no, $dispatch2->dispatch_no);
        $this->assertTrue(intval(substr($dispatch2->dispatch_no, -6)) > intval(substr($dispatch1->dispatch_no, -6)));
    }

    public function test_add_dispatch_item()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();

        $item = $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 10, 500, $this->company->id);

        $this->assertNotNull($item);
        $this->assertEquals(10, $item->quantity);
        $this->assertEquals(5000, $item->amount);
    }

    public function test_allocate_stock_for_dispatch()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);

        $allocations = $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $this->assertNotEmpty($allocations);
        $this->assertEquals('allocated', $allocations[0]->status);
    }

    public function test_cannot_allocate_insufficient_stock()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 50, 'Insufficient stock', $this->company->id);

        $this->expectException(\Exception::class);
        $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);
    }

    public function test_complete_dispatch()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $completedDispatch = $this->dispatchService->completeDispatch($dispatch->id, [], $this->company->id);

        $this->assertEquals('delivered', $completedDispatch->status);
        $this->assertNotNull($completedDispatch->actual_delivery_date);
    }

    public function test_cancel_dispatch_releases_allocations()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);
        $this->stockService->addCoilStock($coil->id, 500, 'Stock', $this->company->id);
        $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id);

        $this->dispatchService->cancelDispatch($dispatch->id, $this->company->id);

        $allocation = StockAllocation::where('dispatch_id', $dispatch->id)->first();
        $this->assertEquals('released', $allocation->status);
    }

    public function test_cannot_dispatch_twice()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);

        $this->dispatchService->createDispatch($batch->id, [], $this->company->id);

        $this->expectException(\Exception::class);
        $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
    }

    public function test_multi_tenant_dispatch_isolation()
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);

        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);

        $this->actingAs($otherUser);

        $this->expectException(\Exception::class);
        $this->dispatchService->getDispatchDetails($dispatch->id, $otherCompany->id);
    }
}
