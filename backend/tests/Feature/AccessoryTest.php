<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\HasAuthTestHelpers;
use Tests\Traits\HasDatabaseTestHelpers;

class AccessoryTest extends TestCase
{
    use RefreshDatabase;
    use HasAuthTestHelpers;
    use HasDatabaseTestHelpers;

    /**
     * Test creating an accessory
     */
    public function test_create_accessory()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $response = $this->postJson('/api/accessories', [
            'name' => 'Installation Kit',
            'code' => 'INST001',
            'description' => 'Complete installation kit',
            'unit_price' => 500,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'code',
                    'unit_price',
                ],
                'message',
                'meta',
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Accessory created successfully',
                'data' => [
                    'name' => 'Installation Kit',
                    'code' => 'INST001',
                    'unit_price' => 500,
                ],
            ]);
    }

    /**
     * Test validation on accessory creation
     */
    public function test_create_accessory_validation()
    {
        $user = $this->loginSuperAdmin();

        $response = $this->postJson('/api/accessories', [
            'name' => '',
            'unit_price' => -100,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'VALIDATION_ERROR',
            ]);
    }

    /**
     * Test listing accessories
     */
    public function test_list_accessories()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        Accessory::factory(5)->for($company)->create();

        $response = $this->getJson('/api/accessories');

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

        $this->assertGreaterThanOrEqual(5, $response->json('meta.pagination.total'));
    }

    /**
     * Test getting single accessory
     */
    public function test_show_accessory()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $accessory = Accessory::factory()->for($company)->create();

        $response = $this->getJson("/api/accessories/{$accessory->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $accessory->id,
                    'name' => $accessory->name,
                    'code' => $accessory->code,
                ],
            ]);
    }

    /**
     * Test updating an accessory
     */
    public function test_update_accessory()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $accessory = Accessory::factory()->for($company)->create();

        $response = $this->putJson("/api/accessories/{$accessory->id}", [
            'name' => 'Updated Accessory',
            'unit_price' => 750,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Accessory',
                    'unit_price' => 750,
                ],
            ]);
    }

    /**
     * Test deleting an accessory
     */
    public function test_delete_accessory()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $accessory = Accessory::factory()->for($company)->create();

        $response = $this->deleteJson("/api/accessories/{$accessory->id}");

        $response->assertStatus(204);

        $this->assertDatabaseMissing('accessories', [
            'id' => $accessory->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * Test adding accessory to quotation
     */
    public function test_add_accessory_to_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $quotation = Quotation::factory()->for($company)->create(['status' => 'draft']);
        $accessory = Accessory::factory()->for($company)->create();

        $response = $this->postJson("/api/quotations/{$quotation->id}/accessories", [
            'accessory_id' => $accessory->id,
            'quantity' => 2,
            'unit_price' => 500,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertTrue(
            $quotation->fresh()->accessories->contains($accessory)
        );
    }

    /**
     * Test removing accessory from quotation
     */
    public function test_remove_accessory_from_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $quotation = Quotation::factory()->for($company)->create(['status' => 'draft']);
        $accessory = Accessory::factory()->for($company)->create();

        $quotation->accessories()->attach($accessory->id, [
            'quantity' => 2,
            'unit_price' => 500,
            'amount' => 1000,
        ]);

        $response = $this->deleteJson("/api/quotations/{$quotation->id}/accessories/{$accessory->id}");

        $response->assertStatus(200);

        $this->assertFalse(
            $quotation->fresh()->accessories->contains($accessory)
        );
    }

    /**
     * Test cannot add accessory to non-draft quotation
     */
    public function test_cannot_add_accessory_to_sent_quotation()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $quotation = Quotation::factory()->for($company)->create(['status' => 'sent']);
        $accessory = Accessory::factory()->for($company)->create();

        $response = $this->postJson("/api/quotations/{$quotation->id}/accessories", [
            'accessory_id' => $accessory->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(400);
    }

    /**
     * Test accessory calculation in quotation totals
     */
    public function test_accessory_affects_quotation_totals()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        $customer = \App\Models\Customer::factory()->for($company)->create();
        $panelType = \App\Models\PanelType::factory()->for($company)->create();

        $response = $this->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'panel_type_id' => $panelType->id,
                    'quantity' => 10,
                    'unit_price' => 100,
                ]
            ],
        ]);

        $quotationId = $response->json('data.id');
        $quotation = Quotation::findOrFail($quotationId);

        // After creating quotation with items, subtotal should be 1000
        $this->assertEquals(1000, $quotation->subtotal);

        $accessory = Accessory::factory()->for($company)->create(['unit_price' => 500]);

        $this->postJson("/api/quotations/{$quotation->id}/accessories", [
            'accessory_id' => $accessory->id,
            'quantity' => 1,
            'unit_price' => 500,
        ]);

        $updated = $quotation->fresh();

        // New subtotal should be 1000 + 500 = 1500
        $this->assertEquals(1500, $updated->subtotal);
        // New tax should be 1500 * 0.18 = 270
        $this->assertEquals(270, $updated->tax_amount);
        // New total should be 1500 + 270 = 1770
        $this->assertEquals(1770, $updated->total_amount);
    }

    /**
     * Test multi-tenant isolation for accessories
     */
    public function test_cannot_access_other_company_accessory()
    {
        $user1 = $this->createAdminUser();
        $user2 = $this->createAdminUser();

        $accessory1 = Accessory::factory()->for($user1->company)->create();

        $this->actingAs($user2, 'sanctum');
        $response = $this->getJson("/api/accessories/{$accessory1->id}");

        $response->assertStatus(404);
    }

    /**
     * Test filtering accessories by search
     */
    public function test_filter_accessories_by_search()
    {
        $user = $this->loginSuperAdmin();
        $company = $user->company;

        Accessory::factory()->for($company)->create(['name' => 'Installation Kit', 'code' => 'INST001']);
        Accessory::factory()->for($company)->create(['name' => 'Test Accessory', 'code' => 'TEST001']);

        $response = $this->getJson('/api/accessories?search=Installation');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}
