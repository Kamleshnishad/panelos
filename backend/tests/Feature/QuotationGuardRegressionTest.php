<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Services\QuotationService;
use Tests\TestCase;

class QuotationGuardRegressionTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function acceptedQuotationWithOrder(int $companyId): Quotation
    {
        $customer = Customer::factory()->create(['company_id' => $companyId]);

        $quotation = Quotation::create([
            'company_id'   => $companyId,
            'customer_id'  => $customer->id,
            'quotation_no' => 'Q-TEST-' . uniqid(),
            'status'       => 'accepted',
        ]);

        Order::create([
            'company_id'   => $companyId,
            'quotation_id' => $quotation->id,
            'customer_id'  => $customer->id,
            'order_no'     => 'ORD-TEST-' . uniqid(),
            'order_date'   => now(),
            'status'       => 'pending',
        ]);

        return $quotation->fresh();
    }

    /**
     * Revising a quotation that already produced an order left an open v2 draft
     * that could be accepted and ordered again — two live orders for one job.
     */
    public function test_a_quotation_with_an_order_cannot_be_revised(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $quotation = $this->acceptedQuotationWithOrder($admin->company_id);

        $this->expectExceptionMessage('already has an order');
        app(QuotationService::class)->revise($quotation);
    }

    /**
     * The duplicate-order guard worked, but the controller caught the domain
     * exception and re-threw it as a generic 500 "Failed to create order".
     */
    public function test_duplicate_create_order_returns_400_with_the_real_message(): void
    {
        $admin = $this->createAdminUser();
        $quotation = $this->acceptedQuotationWithOrder($admin->company_id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/quotations/{$quotation->id}/create-order")
            ->assertStatus(400)
            ->assertJsonPath('message', 'An order already exists for this quotation.');
    }

    /**
     * `base_price ?? 850` only caught NULL, so a panel type sitting on the 0.00
     * column default priced from premiums alone (the live tenant got ₹160/m²).
     */
    public function test_suggested_rate_falls_back_when_base_price_is_zero(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $customer  = Customer::factory()->create(['company_id' => $admin->company_id, 'type' => 'retail']);
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id, 'base_price' => 0]);

        $rate = $this->postJson('/api/quotations/suggested-rate', [
            'panel_type_id'         => $panelType->id,
            'customer_id'           => $customer->id,
            'quality_grade'         => 'High',
            'thickness'             => 50,
            'density_type'          => 'PUF',
            'top_skin_thickness'    => 0.50,
            'bottom_skin_thickness' => 0.50,
            'top_surface'           => 'PLAIN',
        ])->assertStatus(200)->json('data.rate');

        // 850 base + 160 thickness + 60 top skin + 30 bottom skin (half) = 1100
        $this->assertEquals(1100, $rate);
    }

    /** The same spec nested under row_data must price identically, not fall back to defaults. */
    public function test_suggested_rate_accepts_a_nested_row_data_payload(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $customer  = Customer::factory()->create(['company_id' => $admin->company_id, 'type' => 'retail']);
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id, 'base_price' => 0]);

        $rate = $this->postJson('/api/quotations/suggested-rate', [
            'panel_type_id' => $panelType->id,
            'customer_id'   => $customer->id,
            'quality_grade' => 'High',
            'row_data'      => [
                'thickness'             => 50,
                'density_type'          => 'PUF',
                'top_skin_thickness'    => 0.50,
                'bottom_skin_thickness' => 0.50,
                'top_surface'           => 'PLAIN',
            ],
        ])->assertStatus(200)->json('data.rate');

        $this->assertEquals(1100, $rate);
    }

    /**
     * Removing every installation row used to leave the previous amount in the
     * quotation total forever, because it was only written when > 0.
     */
    public function test_removing_installation_rows_clears_the_amount(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $customer  = Customer::factory()->create(['company_id' => $admin->company_id]);
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);

        $payload = [
            'customer_id' => $customer->id,
            'panel_rows'  => [[
                'panel_type_id'         => $panelType->id,
                'thickness'             => 50,
                'density_type'          => 'PUF',
                'density_kgm3'          => 40,
                'top_skin_material'     => 'PPGI',
                'top_skin_thickness'    => '0.50',
                'top_surface'           => 'PLAIN',
                'bottom_skin_material'  => 'PPGI',
                'bottom_skin_thickness' => '0.50',
                'sizes'                 => [['length_mm' => 3000, 'nos' => 10, 'rate_per_sqm' => 1450]],
            ]],
            'accessories' => [
                ['type' => 'installation', 'description' => 'Site work', 'qty' => 1, 'rate' => 5000],
            ],
        ];

        $id = $this->postJson('/api/quotations', $payload)->assertStatus(201)->json('data.id');
        $this->assertEquals(5000, (float) Quotation::findOrFail($id)->installation_amount);

        $payload['accessories'] = [];
        $this->putJson("/api/quotations/{$id}", $payload)->assertStatus(200);

        $this->assertEquals(0, (float) Quotation::findOrFail($id)->installation_amount);
    }
}
