<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use Tests\TestCase;

class QuotationAccessoryValidationTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_standard_accessory_description_and_unit_are_persisted(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $accessory = Accessory::factory()->create(['company_id' => $admin->company_id]);

        // Created directly (not via POST /quotations) because quotation-number
        // generation uses MySQL's REGEXP, unsupported on the SQLite test driver —
        // a pre-existing, unrelated test-environment gap. update() doesn't touch
        // the number generator and exercises the exact same validation rules and
        // saveAccessories() path this fix targets.
        $quotation = Quotation::create([
            'company_id' => $admin->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'SCP-TEST-' . uniqid(), 'status' => 'draft',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$quotation->id}", [
            'customer_id' => $customer->id,
            'panel_rows' => [[
                'panel_type_id' => $panelType->id,
                'thickness' => 50,
                'density_type' => 'PUF',
                'density_kgm3' => 40,
                'top_skin_material' => 'PPGI',
                'top_skin_thickness' => '0.40',
                'top_surface' => 'PLAIN',
                'bottom_skin_material' => 'PPGI',
                'bottom_skin_thickness' => '0.40',
                'sizes' => [['length_mm' => 3000, 'nos' => 2, 'rate_per_sqm' => 500]],
            ]],
            'accessories' => [[
                'type' => 'standard',
                'accessory_id' => $accessory->id,
                'qty' => 2,
                'rate' => 150,
                'description' => 'Corner flashing — custom note',
                'unit' => 'RMT',
            ]],
        ]);

        $response->assertStatus(200);

        $saved = $this->actingAs($admin, 'sanctum')->getJson("/api/quotations/{$quotation->id}")->json('data');
        $this->assertSame('Corner flashing — custom note', $saved['accessories'][0]['pivot']['description']);
        $this->assertSame('RMT', $saved['accessories'][0]['pivot']['unit']);
    }

    /**
     * QB1: the "+ Door/Window" button seeds accessory_id with the literal
     * string 'door' as a row-type marker (not a real master-accessory
     * reference). The backend's Rule::exists('accessories','id') check then
     * rejected it outright — failing the ENTIRE quotation save, including
     * all its panel rows, not just the one accessory row. Fixed by having
     * the frontend send accessory_id: null for door/installation rows
     * (QuotationCreate.vue); the backend's saveAccessories() already
     * anticipated a null accessory_id for door rows (adds the amount to
     * installation_amount) — only the validation layer was blocking it.
     */
    public function test_door_accessory_row_with_no_master_accessory_saves_successfully(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);

        $quotation = Quotation::create([
            'company_id' => $admin->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'SCP-TEST-' . uniqid(), 'status' => 'draft',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$quotation->id}", [
            'customer_id' => $customer->id,
            'panel_rows' => [[
                'panel_type_id' => $panelType->id,
                'thickness' => 50,
                'density_type' => 'PUF',
                'density_kgm3' => 40,
                'top_skin_material' => 'PPGI',
                'top_skin_thickness' => '0.40',
                'top_surface' => 'PLAIN',
                'bottom_skin_material' => 'PPGI',
                'bottom_skin_thickness' => '0.40',
                'sizes' => [['length_mm' => 3000, 'nos' => 2, 'rate_per_sqm' => 500]],
            ]],
            'accessories' => [[
                'type' => 'door',
                'accessory_id' => null,
                'door_type' => 'hinged_door',
                'door_width' => 900,
                'door_height' => 2100,
                'qty' => 1,
                'rate' => 5000,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertSame(5000.0, (float) $quotation->fresh()->installation_amount);
    }

    /**
     * PB-044. door_type is a DB enum, but validation accepted any string, so a bad
     * value ("Single") got as far as the INSERT and came back as a 500 carrying the
     * raw SQL error. It must be a clean 422 instead.
     */
    public function test_an_unknown_door_type_is_rejected_with_a_422_not_a_sql_error(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $quotation = Quotation::create([
            'company_id' => $admin->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'SCP-TEST-' . uniqid(), 'status' => 'draft',
        ]);

        $payload = fn (string $doorType) => [
            'customer_id' => $customer->id,
            'panel_rows' => [[
                'panel_type_id' => $panelType->id, 'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
                'top_skin_material' => 'PPGI', 'top_skin_thickness' => '0.40', 'top_surface' => 'PLAIN',
                'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => '0.40',
                'sizes' => [['length_mm' => 3000, 'nos' => 2, 'rate_per_sqm' => 500]],
            ]],
            'accessories' => [['type' => 'door', 'qty' => 1, 'rate' => 17500, 'door_type' => $doorType, 'door_width' => 1000, 'door_height' => 2100]],
        ];

        $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$quotation->id}", $payload('Single'))
            ->assertStatus(422)->assertJsonValidationErrors(['accessories.0.door_type']);

        $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$quotation->id}", $payload('hinged_door'))
            ->assertStatus(200);
    }
}
