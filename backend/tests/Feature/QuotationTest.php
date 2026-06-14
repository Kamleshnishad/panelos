<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\HasAuthTestHelpers;
use Tests\Traits\HasDatabaseTestHelpers;

class QuotationTest extends TestCase
{
    use RefreshDatabase;
    use HasAuthTestHelpers;
    use HasDatabaseTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test creating a quotation
     */
    public function test_create_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'valid_until' => now()->addDays(30)->format('Y-m-d'),
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 10,
                    'unit_price' => 1000,
                ]
            ],
            'notes' => 'Test quotation',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'quotation_no',
                    'customer_id',
                    'status',
                    'subtotal',
                    'tax_amount',
                    'total_amount',
                ],
                'message',
                'meta',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'draft',
                ],
                'message' => 'Quotation created successfully',
            ]);

        // Verify totals calculated correctly
        $data = $response->json('data');
        $this->assertEquals(10000, $data['subtotal']); // 10 * 1000
        $this->assertEquals(1800, $data['tax_amount']); // 10000 * 0.18
        $this->assertEquals(11800, $data['total_amount']); // 10000 + 1800
    }

    /**
     * Test validation on quotation creation
     */
    public function test_create_quotation_validation()
    {
        $user = $this->loginSuperAdmin();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => 999, // Non-existent customer
            'items' => [
                [
                    'panel_type_id' => 999, // Non-existent panel type
                    'quantity' => -5, // Invalid quantity
                ]
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
            ]);
    }

    /**
     * Test getting quotations list
     */
    public function test_list_quotations()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        // Create test data
        $customer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        for ($i = 0; $i < 3; $i++) {
            Quotation::factory()
                ->for($company)
                ->for($customer)
                ->create();
        }

        $response = $this->getJson('/api/quotations');

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
     * Test getting single quotation
     */
    public function test_show_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $quotation = Quotation::factory()
            ->for($company)
            ->for($customer)
            ->create();

        $response = $this->getJson("/api/quotations/{$quotation->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $quotation->id,
                    'quotation_no' => $quotation->quotation_no,
                    'status' => $quotation->status,
                ],
            ]);
    }

    /**
     * Test updating a quotation
     */
    public function test_update_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $newCustomer = Customer::factory()->for($company)->create();
        $panelType = PanelType::factory()->for($company)->create();

        $quotation = Quotation::factory()
            ->for($company)
            ->for($customer)
            ->create();

        $response = $this->putJson("/api/quotations/{$quotation->id}", [
            'customer_id' => $newCustomer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 5,
                    'unit_price' => 500,
                ]
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'customer_id' => $newCustomer->id,
                ],
            ]);
    }

    /**
     * Test deleting quotation
     */
    public function test_delete_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $quotation = Quotation::factory()
            ->for($company)
            ->for($customer)
            ->create(['status' => 'draft']);

        $response = $this->deleteJson("/api/quotations/{$quotation->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('quotations', [
            'id' => $quotation->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * Test cannot delete non-draft quotation
     */
    public function test_cannot_delete_sent_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $quotation = Quotation::factory()
            ->for($company)
            ->for($customer)
            ->create(['status' => 'sent']);

        $response = $this->deleteJson("/api/quotations/{$quotation->id}");

        $response->assertStatus(400);
    }

    /**
     * Test sending quotation
     */
    public function test_send_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $quotation = Quotation::factory()
            ->for($company)
            ->for($customer)
            ->create(['status' => 'draft']);

        $response = $this->postJson("/api/quotations/{$quotation->id}/send");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'sent',
                ],
            ]);

        $this->assertNotNull($response->json('data.sent_at'));
    }

    /**
     * Test accepting quotation
     */
    public function test_accept_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $quotation = Quotation::factory()
            ->for($company)
            ->for($customer)
            ->create(['status' => 'sent']);

        $response = $this->postJson("/api/quotations/{$quotation->id}/accept");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'accepted',
                ],
            ]);

        $this->assertNotNull($response->json('data.accepted_at'));
    }

    /**
     * Test rejecting quotation
     */
    public function test_reject_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $quotation = Quotation::factory()
            ->for($company)
            ->for($customer)
            ->create(['status' => 'sent']);

        $response = $this->postJson("/api/quotations/{$quotation->id}/reject");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'rejected',
                ],
            ]);
    }

    /**
     * Test multi-tenant isolation
     */
    public function test_cannot_access_other_company_quotation()
    {
        $user1 = $this->createAdminUser();
        $user2 = $this->createAdminUser();

        $customer1 = Customer::factory()->for($user1->company)->create();
        $quotation1 = Quotation::factory()
            ->for($user1->company)
            ->for($customer1)
            ->create();

        // Try to access user1's quotation as user2
        $this->actingAs($user2, 'sanctum');
        $response = $this->getJson("/api/quotations/{$quotation1->id}");

        $response->assertStatus(404);
    }

    /**
     * Test quotation number generation
     */
    public function test_quotation_number_generation()
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
                    'quantity' => 1,
                ]
            ],
        ]);

        $quotationNo = $response->json('data.quotation_no');

        // Format should be: PREFIX-YYYY-000001
        $this->assertMatchesRegularExpression('/^[A-Z]+-\d{4}-\d{6}$/', $quotationNo);
    }

    /**
     * Test PDF generation
     */
    public function test_download_quotation_pdf()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = Customer::factory()->for($company)->create();
        $quotation = Quotation::factory()
            ->for($company)
            ->for($customer)
            ->create();

        $response = $this->get("/api/quotations/{$quotation->id}/pdf");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * Test PDF generation for non-existent quotation
     */
    public function test_pdf_not_found()
    {
        $user = $this->loginSuperAdmin();

        $response = $this->get("/api/quotations/99999/pdf");

        $response->assertStatus(404);
    }

    /**
     * Test cannot download other company's quotation PDF
     */
    public function test_cannot_download_other_company_quotation_pdf()
    {
        $user1 = $this->createAdminUser();
        $user2 = $this->createAdminUser();

        $customer1 = Customer::factory()->for($user1->company)->create();
        $quotation1 = Quotation::factory()
            ->for($user1->company)
            ->for($customer1)
            ->create();

        $this->actingAs($user2, 'sanctum');
        $response = $this->get("/api/quotations/{$quotation1->id}/pdf");

        $response->assertStatus(404);
    }
}
