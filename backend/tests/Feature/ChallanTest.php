<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\Coil;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\Dispatch;
use App\Services\DispatchService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChallanTest extends TestCase
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

    public function test_generate_challan_pdf()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [
            'customer_address' => '123 Main St',
            'tracking_number' => 'TRK123'
        ], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 10, 500, $this->company->id);

        $challan = $this->dispatchService->generateChallan($dispatch->id, $this->company->id);

        $this->assertNotNull($challan);
        $this->assertArrayHasKey('dispatch_no', $challan);
        $this->assertArrayHasKey('items', $challan);
    }

    public function test_challan_contains_dispatch_items()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 10, 500, $this->company->id);
        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 5, 500, $this->company->id);

        $challan = $this->dispatchService->generateChallan($dispatch->id, $this->company->id);

        $this->assertCount(2, $challan['items']);
        $this->assertEquals(10, $challan['items'][0]['quantity']);
        $this->assertEquals(5, $challan['items'][1]['quantity']);
    }

    public function test_challan_includes_customer_address()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $address = '456 Oak Ave, Apt 5';
        $dispatch = $this->dispatchService->createDispatch($batch->id, [
            'customer_address' => $address
        ], $this->company->id);

        $challan = $this->dispatchService->generateChallan($dispatch->id, $this->company->id);

        $this->assertEquals($address, $challan['address']);
    }

    public function test_challan_tracking_number()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $trackingNo = 'TRK-2026-12345';
        $dispatch = $this->dispatchService->createDispatch($batch->id, [
            'tracking_number' => $trackingNo
        ], $this->company->id);

        $challan = $this->dispatchService->generateChallan($dispatch->id, $this->company->id);

        $this->assertEquals($trackingNo, $challan['tracking']);
    }

    public function test_challan_generation_creates_file()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);

        $challan = $this->dispatchService->generateChallan($dispatch->id, $this->company->id);

        $this->assertIsArray($challan);
        $this->assertNotEmpty($challan['dispatch_no']);
    }

    public function test_challan_preview_returns_html()
    {
        $batch = ProductionBatch::factory()->for($this->company)->create(['status' => 'qc_passed']);
        $dispatch = $this->dispatchService->createDispatch($batch->id, [], $this->company->id);
        $panelType = PanelType::factory()->create();
        $coil = Coil::factory()->create();
        $panelType->update(['coil_id' => $coil->id]);

        $this->dispatchService->addDispatchItem($dispatch->id, $panelType->id, 10, 500, $this->company->id);

        $challan = $this->dispatchService->generateChallan($dispatch->id, $this->company->id);

        $this->assertArrayHasKey('total', $challan);
        $this->assertEquals(5000, $challan['total']);
    }
}
