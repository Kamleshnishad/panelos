<?php

namespace Tests\Feature;

use App\Models\CoilStock;
use App\Models\Company;
use Tests\TestCase;

class ProcurementStockValidationTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_po_creation_rejects_a_stock_id_from_another_company(): void
    {
        $admin = $this->createAdminUser();
        $otherCompany = Company::factory()->create();
        $foreignCoil = CoilStock::create([
            'company_id' => $otherCompany->id,
            'quantity_in_stock' => 100,
            'reorder_level' => 10,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/purchase-orders', [
            'items' => [[
                'material_kind' => 'coil',
                'stock_id' => $foreignCoil->id,
                'quantity' => 5,
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.stock_id']);
    }

    public function test_po_creation_accepts_a_stock_id_belonging_to_the_company(): void
    {
        $admin = $this->createAdminUser();
        $coil = CoilStock::create([
            'company_id' => $admin->company_id,
            'quantity_in_stock' => 100,
            'reorder_level' => 10,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/purchase-orders', [
            'items' => [[
                'material_kind' => 'coil',
                'stock_id' => $coil->id,
                'quantity' => 5,
            ]],
        ]);

        $response->assertStatus(201);
    }
}
