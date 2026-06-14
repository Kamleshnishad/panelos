<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\HasAuthTestHelpers;
use Tests\Traits\HasDatabaseTestHelpers;

class BatchTest extends TestCase
{
    use RefreshDatabase;
    use HasAuthTestHelpers;
    use HasDatabaseTestHelpers;

    /**
     * Test create batch from order
     */
    public function test_create_batch_from_order()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        // Create quotation, accept, and create order
        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 100,
                    'unit_price' => 1000,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        // Create batch from order
        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches", [
            'planned_quantity' => 100,
            'notes' => 'Test batch',
        ]);

        $batchResponse->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'batch_no',
                    'order_id',
                    'status',
                    'planned_quantity',
                    'completed_quantity',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'order_id' => $orderId,
                    'status' => 'draft',
                    'planned_quantity' => 100,
                    'completed_quantity' => 0,
                ],
            ]);
    }

    /**
     * Test batch number generation
     */
    public function test_batch_number_generation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => 50,
            ]],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchNo = $batchResponse->json('data.batch_no');

        // Format should be: PREFIX-YYYY-000001
        $this->assertMatchesRegularExpression('/^[A-Z]+-\d{4}-\d{6}$/', $batchNo);
    }

    /**
     * Test get batch details
     */
    public function test_get_batch_details()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => 75,
            ]],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        $response = $this->getJson("/api/batches/{$batchId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'batch_no',
                    'order_id',
                    'status',
                    'planned_quantity',
                    'completed_quantity',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $batchId,
                    'order_id' => $orderId,
                    'status' => 'draft',
                ],
            ]);
    }

    /**
     * Test update batch
     */
    public function test_update_batch()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => 100,
            ]],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        $updateResponse = $this->putJson("/api/batches/{$batchId}", [
            'planned_quantity' => 120,
            'notes' => 'Updated batch notes',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'planned_quantity' => 120,
                    'notes' => 'Updated batch notes',
                ],
            ]);
    }

    /**
     * Test start production
     */
    public function test_start_production()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => 50,
            ]],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        $startResponse = $this->postJson("/api/batches/{$batchId}/start");

        $startResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'in_progress',
                ],
            ]);

        $this->assertNotNull($startResponse->json('data.started_at'));
    }

    /**
     * Test complete production
     */
    public function test_complete_production()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => 60,
            ]],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        $this->postJson("/api/batches/{$batchId}/start");

        $completeResponse = $this->postJson("/api/batches/{$batchId}/complete", [
            'completed_quantity' => 60,
        ]);

        $completeResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'qc_pending',
                    'completed_quantity' => 60,
                ],
            ]);
    }

    /**
     * Test list batches
     */
    public function test_list_batches()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        // Create 3 orders with batches
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/quotations', [
                'customer_id' => $customer->id,
                'items' => [[
                    'panel_type_id' => $panelType->id,
                    'quantity' => 50,
                ]],
            ]);

            $quotationId = $response->json('data.id');
            $this->postJson("/api/quotations/{$quotationId}/send");
            $this->postJson("/api/quotations/{$quotationId}/accept");
            $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
            $orderId = $orderResponse->json('data.id');
            $this->postJson("/api/orders/{$orderId}/batches");
        }

        $response = $this->getJson('/api/batches');

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
     * Test cannot delete batch not in draft
     */
    public function test_cannot_delete_batch_in_production()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [[
                'panel_type_id' => $panelType->id,
                'quantity' => 40,
            ]],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        $this->postJson("/api/batches/{$batchId}/start");

        $deleteResponse = $this->deleteJson("/api/batches/{$batchId}");

        $deleteResponse->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error_code' => 'BATCH_DELETE_ERROR',
            ]);
    }

    /**
     * Test multi-tenant isolation for batches
     */
    public function test_cannot_access_other_company_batch()
    {
        $user1 = $this->createAdminUser();
        $user2 = $this->createAdminUser();

        $customer1 = Customer::factory()->for($user1->company)->create();
        $panelType1 = PanelType::factory()->for($user1->company)->create();

        $response = $this->actingAs($user1, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer1->id,
            'items' => [[
                'panel_type_id' => $panelType1->id,
                'quantity' => 50,
            ]],
        ]);

        $quotationId = $response->json('data.id');

        $this->actingAs($user1, 'sanctum')->postJson("/api/quotations/{$quotationId}/send");
        $this->actingAs($user1, 'sanctum')->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->actingAs($user1, 'sanctum')->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $batchResponse = $this->actingAs($user1, 'sanctum')->postJson("/api/orders/{$orderId}/batches");
        $batchId = $batchResponse->json('data.id');

        // Try to access batch as user2
        $this->actingAs($user2, 'sanctum');
        $response = $this->getJson("/api/batches/{$batchId}");

        $response->assertStatus(404);
    }
}
