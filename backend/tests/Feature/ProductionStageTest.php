<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\ProductionStage;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\HasAuthTestHelpers;
use Tests\Traits\HasDatabaseTestHelpers;

class ProductionStageTest extends TestCase
{
    use RefreshDatabase;
    use HasAuthTestHelpers;
    use HasDatabaseTestHelpers;

    /**
     * Test create production stage
     */
    public function test_create_production_stage()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $response = $this->postJson('/api/production-stages', [
            'name' => 'Cutting',
            'description' => 'Raw material cutting process',
            'sequence' => 1,
            'is_active' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'description',
                    'sequence',
                    'is_active',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Cutting',
                    'sequence' => 1,
                    'is_active' => true,
                ],
            ]);
    }

    /**
     * Test list production stages
     */
    public function test_list_production_stages()
    {
        $user = $this->loginSuperAdmin();

        // Create 3 stages
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/production-stages', [
                'name' => "Stage {$i}",
                'sequence' => $i + 1,
            ]);
        }

        $response = $this->getJson('/api/production-stages');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message',
            ])
            ->assertJson([
                'success' => true,
            ]);

        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    /**
     * Test get production stage
     */
    public function test_get_production_stage()
    {
        $user = $this->loginSuperAdmin();

        $createResponse = $this->postJson('/api/production-stages', [
            'name' => 'Lamination',
            'description' => 'Laminating layers',
            'sequence' => 2,
        ]);

        $stageId = $createResponse->json('data.id');

        $response = $this->getJson("/api/production-stages/{$stageId}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $stageId,
                    'name' => 'Lamination',
                ],
            ]);
    }

    /**
     * Test update production stage
     */
    public function test_update_production_stage()
    {
        $user = $this->loginSuperAdmin();

        $createResponse = $this->postJson('/api/production-stages', [
            'name' => 'Finishing',
            'sequence' => 3,
        ]);

        $stageId = $createResponse->json('data.id');

        $response = $this->putJson("/api/production-stages/{$stageId}", [
            'name' => 'Advanced Finishing',
            'description' => 'Premium finish work',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Advanced Finishing',
                    'description' => 'Premium finish work',
                ],
            ]);
    }

    /**
     * Test batch stage workflow
     */
    public function test_batch_stage_workflow()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        // Create stages
        $cuttingStage = $this->postJson('/api/production-stages', [
            'name' => 'Cutting',
            'sequence' => 1,
        ])->json('data');

        $laminationStage = $this->postJson('/api/production-stages', [
            'name' => 'Lamination',
            'sequence' => 2,
        ])->json('data');

        // Create order
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

        // Create batch
        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        // Start production on batch
        $this->postJson("/api/batches/{$batchId}/start");

        // Start cutting stage
        $startResponse = $this->postJson("/api/batches/{$batchId}/stages/{$cuttingStage['id']}/start");

        $startResponse->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'stage_id' => $cuttingStage['id'],
                    'status' => 'in_progress',
                ],
            ]);

        // Complete cutting stage
        $completeResponse = $this->postJson("/api/batches/{$batchId}/stages/{$cuttingStage['id']}/complete", [
            'notes' => 'Cutting completed successfully',
        ]);

        $completeResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'completed',
                    'notes' => 'Cutting completed successfully',
                ],
            ]);

        $this->assertNotNull($completeResponse->json('data.duration_minutes'));
    }

    /**
     * Test cannot skip stages
     */
    public function test_cannot_skip_stages()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        // Create stages
        $cuttingStage = $this->postJson('/api/production-stages', [
            'name' => 'Cutting',
            'sequence' => 1,
        ])->json('data');

        $laminationStage = $this->postJson('/api/production-stages', [
            'name' => 'Lamination',
            'sequence' => 2,
        ])->json('data');

        // Create order and batch
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

        // Start production on batch
        $this->postJson("/api/batches/{$batchId}/start");

        // Try to start lamination (skipping cutting)
        $response = $this->postJson("/api/batches/{$batchId}/stages/{$laminationStage['id']}/start");

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error_code' => 'STAGE_START_ERROR',
            ]);
    }

    /**
     * Test get batch progress
     */
    public function test_get_batch_progress()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        // Create stages
        $cuttingStage = $this->postJson('/api/production-stages', [
            'name' => 'Cutting',
            'sequence' => 1,
        ])->json('data');

        $laminationStage = $this->postJson('/api/production-stages', [
            'name' => 'Lamination',
            'sequence' => 2,
        ])->json('data');

        // Create order and batch
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

        // Start and complete first stage
        $this->postJson("/api/batches/{$batchId}/stages/{$cuttingStage['id']}/start");
        $this->postJson("/api/batches/{$batchId}/stages/{$cuttingStage['id']}/complete");

        // Get progress
        $response = $this->getJson("/api/batches/{$batchId}/progress");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $progress = $response->json('data');
        $this->assertCount(2, $progress);
        $this->assertEquals('completed', $progress[0]['status']);
        $this->assertEquals('pending', $progress[1]['status']);
    }

    /**
     * Test multi-tenant isolation for stages
     */
    public function test_cannot_access_other_company_stage()
    {
        $user1 = $this->createAdminUser();
        $user2 = $this->createAdminUser();

        $stageResponse = $this->actingAs($user1, 'sanctum')->postJson('/api/production-stages', [
            'name' => 'Test Stage',
            'sequence' => 1,
        ]);

        $stageId = $stageResponse->json('data.id');

        $this->actingAs($user2, 'sanctum');
        $response = $this->getJson("/api/production-stages/{$stageId}");

        $response->assertStatus(404);
    }
}
