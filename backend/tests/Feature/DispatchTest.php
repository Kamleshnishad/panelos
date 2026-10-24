<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
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
    use \Tests\Traits\MakesStockRows;

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

    public function test_complete_dispatch()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);

        $completedDispatch = $this->dispatchService->completeDispatch($dispatch->id, [], $this->company->id);

        $this->assertEquals('delivered', $completedDispatch->status);
        $this->assertNotNull($completedDispatch->actual_delivery_date);
    }

    /**
     * Raw coil is consumed when production starts, not at dispatch (SYSTEM.md, design decision 6).
     * The old tests asserted that dispatch allocated and released coil stock; that behaviour was removed
     * on purpose because it double-counted. This pins the current rule instead.
     */
    public function test_dispatch_neither_reserves_nor_deducts_coil_stock()
    {
        $stock = $this->coil(['quantity_in_stock' => 500]);
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 100, 500, $this->company->id);

        $this->assertSame([], $this->dispatchService->allocateStockForDispatch($dispatch->id, $this->company->id));
        $this->dispatchService->completeDispatch($dispatch->id, [], $this->company->id);

        $this->assertEquals(500, $stock->fresh()->quantity_in_stock, 'Dispatch must not deduct coil.');
        $this->assertSame(0, StockAllocation::where('dispatch_id', $dispatch->id)->count(), 'Dispatch must not reserve coil.');
        $this->assertSame(0, $stock->transactions()->count(), 'No stock movement may be recorded at dispatch.');
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
