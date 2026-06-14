<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use App\Models\QualityControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\HasAuthTestHelpers;
use Tests\Traits\HasDatabaseTestHelpers;

class QualityControlTest extends TestCase
{
    use RefreshDatabase;
    use HasAuthTestHelpers;
    use HasDatabaseTestHelpers;

    /**
     * Helper to create a batch in qc_pending status
     */
    private function createQcPendingBatch()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $quotResponse = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => 50,
            ]],
        ]);

        $quotationId = $quotResponse->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        $this->postJson("/api/batches/{$batchId}/start");
        $this->postJson("/api/batches/{$batchId}/complete");

        return ProductionBatch::find($batchId);
    }

    /**
     * Test create QC entry (pass)
     */
    public function test_create_qc_entry_pass()
    {
        $batch = $this->createQcPendingBatch();

        $response = $this->postJson("/api/batches/{$batch->id}/qc", [
            'status' => 'pass',
            'notes' => 'All items passed inspection',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'batch_id',
                    'status',
                    'inspected_by_user_id',
                    'inspected_at',
                    'notes',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'batch_id' => $batch->id,
                    'status' => 'pass',
                    'notes' => 'All items passed inspection',
                ],
            ]);

        // Verify batch status was updated
        $batch->refresh();
        $this->assertEquals('qc_passed', $batch->status);
    }

    /**
     * Test create QC entry (fail)
     */
    public function test_create_qc_entry_fail()
    {
        $batch = $this->createQcPendingBatch();

        $response = $this->postJson("/api/batches/{$batch->id}/qc", [
            'status' => 'fail',
            'notes' => 'Multiple defects found',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'batch_id' => $batch->id,
                    'status' => 'fail',
                ],
            ]);

        // Verify batch status was updated
        $batch->refresh();
        $this->assertEquals('qc_failed', $batch->status);
    }

    /**
     * Test cannot create QC for non-qc_pending batch
     */
    public function test_cannot_create_qc_for_non_qc_pending_batch()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $quotResponse = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => 50,
            ]],
        ]);

        $quotationId = $quotResponse->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        // Try to create QC without completing production
        $response = $this->postJson("/api/batches/{$batchId}/qc", [
            'status' => 'pass',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error_code' => 'QC_CREATE_ERROR',
            ]);
    }

    /**
     * Test get QC for batch
     */
    public function test_get_qc_for_batch()
    {
        $batch = $this->createQcPendingBatch();

        $createResponse = $this->postJson("/api/batches/{$batch->id}/qc", [
            'status' => 'pass',
            'notes' => 'Passed inspection',
        ]);

        $qcId = $createResponse->json('data.id');

        $response = $this->getJson("/api/batches/{$batch->id}/qc");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $qcId,
                    'batch_id' => $batch->id,
                    'status' => 'pass',
                ],
            ]);
    }

    /**
     * Test list QC entries
     */
    public function test_list_qc_entries()
    {
        // Create 3 QC entries
        for ($i = 0; $i < 3; $i++) {
            $batch = $this->createQcPendingBatch();
            $this->postJson("/api/batches/{$batch->id}/qc", [
                'status' => $i % 2 === 0 ? 'pass' : 'fail',
            ]);
        }

        $response = $this->getJson('/api/quality-control');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => [
                    'pagination' => [
                        'total',
                        'count',
                        'per_page',
                        'current_page',
                    ],
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);

        $this->assertGreaterThanOrEqual(3, $response->json('meta.pagination.total'));
    }

    /**
     * Test approve QC entry
     */
    public function test_approve_qc_entry()
    {
        $batch = $this->createQcPendingBatch();

        $createResponse = $this->postJson("/api/batches/{$batch->id}/qc", [
            'status' => 'pass',
        ]);

        $qcId = $createResponse->json('data.id');

        $approveResponse = $this->postJson("/api/quality-control/{$qcId}/approve", [
            'notes' => 'Approved for dispatch',
        ]);

        $approveResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $qcId,
                    'status' => 'pass',
                ],
            ]);

        $this->assertNotNull($approveResponse->json('data.approved_at'));
    }

    /**
     * Test get QC statistics
     */
    public function test_get_qc_statistics()
    {
        $this->loginSuperAdmin();

        // Create pass and fail entries
        for ($i = 0; $i < 4; $i++) {
            $batch = $this->createQcPendingBatch();
            $this->postJson("/api/batches/{$batch->id}/qc", [
                'status' => $i < 3 ? 'pass' : 'fail',
            ]);
        }

        $response = $this->getJson('/api/quality-control/statistics');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $stats = $response->json('data');
        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(3, $stats['passed']);
        $this->assertEquals(1, $stats['failed']);
        $this->assertEquals(75, $stats['pass_rate']);
    }

    /**
     * Test multi-tenant isolation for QC
     */
    public function test_cannot_access_other_company_qc()
    {
        $user1 = $this->createAdminUser();
        $user2 = $this->createAdminUser();

        // Create QC as user1
        $this->actingAs($user1, 'sanctum');
        $batch = $this->createQcPendingBatch();
        $createResponse = $this->postJson("/api/batches/{$batch->id}/qc", [
            'status' => 'pass',
        ]);

        $qcId = $createResponse->json('data.id');

        // Try to access as user2
        $this->actingAs($user2, 'sanctum');
        $response = $this->getJson("/api/quality-control/{$qcId}");

        $response->assertStatus(404);
    }

    /**
     * Test validation of QC status
     */
    public function test_validation_qc_status()
    {
        $batch = $this->createQcPendingBatch();

        $response = $this->postJson("/api/batches/{$batch->id}/qc", [
            'status' => 'invalid_status',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
            ]);
    }
}
