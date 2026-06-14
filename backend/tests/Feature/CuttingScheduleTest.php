<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\HasAuthTestHelpers;

class CuttingScheduleTest extends TestCase
{
    use RefreshDatabase;
    use HasAuthTestHelpers;

    /**
     * Helper to create a batch ready for cutting schedule
     */
    private function createBatchForSchedule($length = 1500, $quantity = 5)
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()
            ->for($company)
            ->create(['length' => $length, 'width' => 1000]);

        $quotResponse = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => $quantity,
                'unit_price' => 500,
            ]],
        ]);

        $quotationId = $quotResponse->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        return ProductionBatch::find($batchResponse->json('data.id'));
    }

    /**
     * Test calculate cutting schedule for short panels (doubling applicable)
     */
    public function test_calculate_schedule_with_doubling()
    {
        $batch = $this->createBatchForSchedule($length = 1500, $quantity = 5);

        $response = $this->postJson("/api/batches/{$batch->id}/calculate-cutting-schedule");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'batch_id',
                    'batch_no',
                    'schedule_items',
                    'total_material_length',
                    'total_items',
                    'optimization' => [
                        'double_cut_items',
                        'single_cut_items',
                        'total_items',
                        'double_cut_percentage',
                    ],
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'batch_id' => $batch->id,
                    'total_items' => 5,
                ],
            ]);

        // Verify doubling is applied
        $optimization = $response->json('data.optimization');
        $this->assertGreaterThan(0, $optimization['double_cut_items']);
    }

    /**
     * Test calculate schedule for long panels (no doubling)
     */
    public function test_calculate_schedule_no_doubling()
    {
        $batch = $this->createBatchForSchedule($length = 2500, $quantity = 3);

        $response = $this->postJson("/api/batches/{$batch->id}/calculate-cutting-schedule");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_items' => 3,
                ],
            ]);

        // Long panels should not use doubling
        $optimization = $response->json('data.optimization');
        $this->assertEquals(0, $optimization['double_cut_items']);
        $this->assertEquals(3, $optimization['single_cut_items']);
    }

    /**
     * Test get cutting instructions
     */
    public function test_get_cutting_instructions()
    {
        $batch = $this->createBatchForSchedule($length = 1500, $quantity = 4);

        $response = $this->get("/api/batches/{$batch->id}/cutting-schedule");

        $response->assertStatus(200);
        $content = $response->getContent();

        // Verify instructions contain expected information
        $this->assertStringContainsString('CUTTING SCHEDULE', $content);
        $this->assertStringContainsString($batch->batch_no, $content);
        $this->assertStringContainsString('Total Items: 4', $content);
    }

    /**
     * Test get schedule as JSON
     */
    public function test_get_schedule_json()
    {
        $batch = $this->createBatchForSchedule($length = 1500, $quantity = 4);

        $response = $this->getJson("/api/batches/{$batch->id}/cutting-schedule/json");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'batch_id',
                    'schedule_items',
                    'waste_percentage',
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * Test optimization efficiency calculation
     */
    public function test_schedule_optimization_efficiency()
    {
        $batch = $this->createBatchForSchedule($length = 1500, $quantity = 6);

        $response = $this->postJson("/api/batches/{$batch->id}/calculate-cutting-schedule");

        $response->assertStatus(200);

        $optimization = $response->json('data.optimization');
        $this->assertNotNull($optimization['optimization_efficiency']);
        $this->assertStringContainsString('%', $optimization['optimization_efficiency']);
    }

    /**
     * Test doubling percentage for mixed quantities
     */
    public function test_doubling_percentage()
    {
        // Test with 4 items (2 double cuts) and 1 single cut
        $batch = $this->createBatchForSchedule($length = 1500, $quantity = 5);

        $response = $this->postJson("/api/batches/{$batch->id}/calculate-cutting-schedule");

        $response->assertStatus(200);

        $schedule = $response->json('data');
        $this->assertEquals(5, $schedule['optimization']['total_items']);
        $this->assertEquals(4, $schedule['optimization']['double_cut_items']);
        $this->assertEquals(1, $schedule['optimization']['single_cut_items']);
        $this->assertEquals(80.0, $schedule['optimization']['double_cut_percentage']);
    }

    /**
     * Test schedule calculation for edge case (exactly 2000mm)
     */
    public function test_schedule_edge_case_2000mm()
    {
        // 2000mm should not be doubled
        $batch = $this->createBatchForSchedule($length = 2000, $quantity = 3);

        $response = $this->postJson("/api/batches/{$batch->id}/calculate-cutting-schedule");

        $response->assertStatus(200);

        $optimization = $response->json('data.optimization');
        $this->assertEquals(0, $optimization['double_cut_items']);
    }
}
