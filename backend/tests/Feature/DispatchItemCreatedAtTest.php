<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use App\Models\QualityControl;
use Tests\TestCase;

/**
 * DispatchItem has $timestamps = false (no updated_at column) and
 * DispatchService explicitly passes created_at on create() — but it wasn't
 * in $fillable, so mass-assignment silently dropped it and no auto-timestamp
 * mechanism filled the gap either. Harmless on a lenient MySQL sql_mode;
 * production's strict mode rejected the resulting INSERT outright, which is
 * how this was actually found — not previously logged as a finding.
 */
class DispatchItemCreatedAtTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_dispatch_creation_sets_created_at_on_its_items(): void
    {
        $admin = $this->createAdminUser();
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $quotation = Quotation::create([
            'company_id' => $admin->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-DISP-' . uniqid(), 'status' => 'accepted',
        ]);
        $order = Order::create([
            'company_id' => $admin->company_id, 'quotation_id' => $quotation->id,
            'customer_id' => $customer->id, 'order_no' => 'ORD-DISP-' . uniqid(),
            'order_date' => now(), 'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'panel_type_id' => $panelType->id,
            'thickness' => 30, 'density_type' => 'PUF', 'density_kgm3' => 40,
            'top_skin_material' => 'PPGI', 'top_skin_thickness' => 0.4, 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => 0.4,
            'total_sqm' => 10, 'rate_per_sqm' => 500, 'quantity' => 1, 'unit_price' => 500, 'amount' => 5000,
        ]);
        $batch = ProductionBatch::create([
            'company_id' => $admin->company_id, 'order_id' => $order->id,
            'batch_no' => 'PB-DISP-' . uniqid(), 'status' => 'qc_passed',
            'planned_quantity' => 10, 'completed_quantity' => 10,
        ]);
        QualityControl::create([
            'batch_id' => $batch->id, 'company_id' => $admin->company_id, 'status' => 'pass',
            'inspected_by_user_id' => $admin->id, 'inspected_at' => now(),
            'approved_by_user_id' => $admin->id, 'approved_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/batches/{$batch->id}/dispatch", []);

        $response->assertStatus(201);
        $this->assertDatabaseHas('dispatch_items', ['panel_type_id' => $panelType->id]);
        $item = \App\Models\DispatchItem::first();
        $this->assertNotNull($item->created_at);
    }
}
