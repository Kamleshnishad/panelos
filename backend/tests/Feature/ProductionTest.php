<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\HasAuthTestHelpers;
use Tests\Traits\HasDatabaseTestHelpers;

class ProductionTest extends TestCase
{
    use RefreshDatabase;
    use HasAuthTestHelpers;
    use HasDatabaseTestHelpers;

    /**
     * Test creating order from accepted quotation
     */
    public function test_create_order_from_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        // Create and accept quotation
        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 10,
                    'unit_price' => 1000,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');

        // Send and accept quotation
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");

        // Create order from quotation
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");

        $orderResponse->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'order_no',
                    'quotation_id',
                    'customer_id',
                    'status',
                    'total_amount',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'pending',
                    'quotation_id' => $quotationId,
                ],
                'message' => 'Order created from quotation',
            ]);
    }

    /**
     * Test order number generation
     */
    public function test_order_number_generation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 5,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");

        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderNo = $orderResponse->json('data.order_no');

        // Format should be: PREFIX-YYYY-000001
        $this->assertMatchesRegularExpression('/^[A-Z]+-\d{4}-\d{6}$/', $orderNo);
    }

    /**
     * Test order items snapshot from quotation
     */
    public function test_order_items_snapshot_from_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 10,
                    'unit_price' => 1000,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");

        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        // Verify order items match quotation items
        $order = Order::findOrFail($orderId);
        $this->assertCount(1, $order->items);
        $this->assertEquals(10, $order->items[0]->quantity);
        $this->assertEquals(1000, $order->items[0]->unit_price);
        $this->assertEquals(10000, $order->items[0]->amount);
    }

    /**
     * Test order totals match quotation
     */
    public function test_order_totals_match_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 10,
                    'unit_price' => 1000,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');
        $quotation = Quotation::findOrFail($quotationId);

        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");

        $order = Order::findOrFail($orderResponse->json('data.id'));

        // Order totals should match quotation
        $this->assertEquals($quotation->subtotal, $order->subtotal);
        $this->assertEquals($quotation->tax_amount, $order->tax_amount);
        $this->assertEquals($quotation->total_amount, $order->total_amount);
    }

    /**
     * Test cannot create order for non-accepted quotation
     */
    public function test_cannot_create_order_for_non_accepted_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 5,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');

        // Try to create order without accepting quotation
        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");

        $orderResponse->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error_code' => 'INVALID_STATUS',
            ]);
    }

    /**
     * Test list orders
     */
    public function test_list_orders()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        // Create 3 orders
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/quotations', [
                'customer_id' => $customer->id,
                'items' => [
                    [
                        'panel_type_id' => $panelType->id,
                        'quantity' => 5,
                    ]
                ],
            ]);

            $quotationId = $response->json('data.id');
            $this->postJson("/api/quotations/{$quotationId}/send");
            $this->postJson("/api/quotations/{$quotationId}/accept");
            $this->postJson("/api/quotations/{$quotationId}/create-order");
        }

        $response = $this->getJson('/api/orders');

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
     * Test get order details
     */
    public function test_get_order_details()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 5,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');
        $this->postJson("/api/quotations/{$quotationId}/send");
        $this->postJson("/api/quotations/{$quotationId}/accept");

        $orderResponse = $this->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        $response = $this->getJson("/api/orders/{$orderId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'order_no',
                    'customer_id',
                    'status',
                    'total_amount',
                    'items',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $orderId,
                    'status' => 'pending',
                ],
            ]);
    }

    /**
     * Test multi-tenant isolation for orders
     */
    public function test_cannot_access_other_company_order()
    {
        $user1 = $this->createAdminUser();
        $user2 = $this->createAdminUser();

        $customer1 = Customer::factory()->for($user1->company)->create();
        $panelType1 = PanelType::factory()->for($user1->company)->create();

        $response = $this->actingAs($user1, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer1->id,
            'items' => [
                [
                    'panel_type_id' => $panelType1->id,
                    'quantity' => 5,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');

        $this->actingAs($user1, 'sanctum')->postJson("/api/quotations/{$quotationId}/send");
        $this->actingAs($user1, 'sanctum')->postJson("/api/quotations/{$quotationId}/accept");
        $orderResponse = $this->actingAs($user1, 'sanctum')->postJson("/api/quotations/{$quotationId}/create-order");
        $orderId = $orderResponse->json('data.id');

        // Try to access order as user2
        $this->actingAs($user2, 'sanctum');
        $response = $this->getJson("/api/orders/{$orderId}");

        $response->assertStatus(404);
    }
}
