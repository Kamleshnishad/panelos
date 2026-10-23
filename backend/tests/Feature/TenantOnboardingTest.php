<?php

namespace Tests\Feature;

use App\Models\ChemicalStock;
use App\Models\CoilStock;
use App\Models\ConsumableStock;
use App\Models\PanelType;
use App\Models\ProductionStage;
use App\Models\StockTransaction;
use App\Models\TaxConfiguration;
use App\Services\TenantProvisioningService;
use Tests\TestCase;

/**
 * Phase 5 — a new tenant must reach a quotation and a production run without
 * hitting a silent dead end.
 *
 * The BOM engine resolves materials by exact strings (chemicals by category
 * 'Polyol'/'Isocyanate', consumables by 'film'/'tape', coil by panel_type_id).
 * A tenant with no rows, or rows named anything else, got "Insufficient stock"
 * on their first production run with nothing explaining why.
 *
 * PB-033: the stock create endpoints accepted an opening quantity and silently
 * discarded it, returning 201 with a zero balance.
 */
class TenantOnboardingTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function provision(): array
    {
        return app(TenantProvisioningService::class)->provision([
            'company_name' => 'Onboarding Test Co',
            'name'         => 'Owner',
            'email'        => 'owner-' . uniqid() . '@example.invalid',
            'password'     => 'Testing12345',
        ]);
    }

    public function test_signup_seeds_the_material_rows_the_bom_looks_for(): void
    {
        $companyId = $this->provision()['company']->id;

        $this->assertSame(1, ChemicalStock::where('company_id', $companyId)->where('category', 'Polyol')->count());
        $this->assertSame(1, ChemicalStock::where('company_id', $companyId)->where('category', 'Isocyanate')->count());
        $this->assertSame(1, ConsumableStock::where('company_id', $companyId)->where('category', 'film')->count());
        $this->assertSame(1, ConsumableStock::where('company_id', $companyId)->where('category', 'tape')->count());
    }

    public function test_signup_seeds_production_stages_in_sequence(): void
    {
        $companyId = $this->provision()['company']->id;

        $stages = ProductionStage::where('company_id', $companyId)->orderBy('sequence')->get();
        $this->assertCount(6, $stages);
        $this->assertSame('Raw Material Inspection', $stages->first()->name);
        $this->assertSame([1, 2, 3, 4, 5, 6], $stages->pluck('sequence')->all());
        // Sign-off is opt-in: seeded stages must not silently gate the floor.
        $this->assertTrue($stages->every(fn ($s) => !$s->requires_approval));
    }

    public function test_signup_seeds_tax_configuration_at_18_percent(): void
    {
        $companyId = $this->provision()['company']->id;

        $tax = TaxConfiguration::where('company_id', $companyId)->firstOrFail();
        $this->assertEquals(18.0, (float) $tax->default_tax_rate);
        $this->assertSame('exclusive', $tax->tax_type);
        $this->assertTrue((bool) $tax->is_active);
    }

    public function test_seeded_stock_starts_at_zero_not_at_a_fake_balance(): void
    {
        $companyId = $this->provision()['company']->id;

        foreach (ChemicalStock::where('company_id', $companyId)->get() as $row) {
            $this->assertEquals(0, (float) $row->quantity_in_stock);
        }
    }

    /** PB-033 — an opening balance must be honoured, and must hit the ledger. */
    public function test_opening_stock_is_booked_and_ledgered(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);

        $this->postJson('/api/stock/coils', [
            'panel_type_id' => $panelType->id,
            'quantity_in_stock' => 310,
            'reorder_level' => 50,
            'unit_cost' => 62,
        ])->assertStatus(201);

        $coil = CoilStock::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertEquals(310, (float) $coil->quantity_in_stock,
            'Opening balance was discarded — the PB-033 regression.');

        // Booked through StockService, so the movement is auditable.
        $this->assertSame(1, StockTransaction::where('transactionable_id', $coil->id)
            ->where('transactionable_type', CoilStock::class)->where('type', 'in')->count());
    }

    public function test_creating_stock_without_a_quantity_still_starts_at_zero(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);

        $this->postJson('/api/stock/coils', ['panel_type_id' => $panelType->id])->assertStatus(201);

        $coil = CoilStock::where('company_id', $admin->company_id)->firstOrFail();
        $this->assertEquals(0, (float) $coil->quantity_in_stock);
        $this->assertSame(0, StockTransaction::where('transactionable_id', $coil->id)->count());
    }

    public function test_dashboard_lists_what_setup_is_still_outstanding(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $setup = $this->getJson('/api/dashboard')->assertStatus(200)->json('data.setup');

        $this->assertFalse($setup['done']);
        $keys = array_column($setup['outstanding'], 'key');
        $this->assertContains('panel_types', $keys);
        $this->assertContains('customers', $keys);
        // Each item tells the user where to go and why it matters.
        $this->assertNotEmpty($setup['outstanding'][0]['hint']);
        $this->assertNotEmpty($setup['outstanding'][0]['go']);
    }

    public function test_a_panel_type_without_coil_stock_is_flagged(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        PanelType::factory()->create(['company_id' => $admin->company_id, 'base_price' => 900]);

        $setup = $this->getJson('/api/dashboard')->json('data.setup');
        $coil = collect($setup['outstanding'])->firstWhere('key', 'coil_stock');

        $this->assertNotNull($coil, 'A panel type with no coil row must be flagged — production would fail.');
        $this->assertStringContainsString('Insufficient stock', $coil['hint']);
    }
}
