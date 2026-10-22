<?php

namespace Tests\Feature;

use App\Models\ChemicalStock;
use App\Models\CoilStock;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\ProductionMaterialUsage;
use App\Models\Quotation;
use Tests\TestCase;

/**
 * C1 fix: a standalone batch (created outside a Production Run, via the
 * "Production" tab) previously could NEVER be started — ProductionBatchService
 * ::startProduction() unconditionally threw unless run_id was set, because only
 * a run issued material. Standalone batches now issue their own material via
 * ProductionMaterialService::issueForBatch(), mirroring what a run already
 * does for its child batches.
 */
class StandaloneBatchStartTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function makeOrder($companyId, ?Customer $customer = null): Order
    {
        $customer = $customer ?? Customer::factory()->create(['company_id' => $companyId]);
        $quotation = Quotation::create([
            'company_id' => $companyId, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-SB-' . uniqid(), 'status' => 'accepted',
        ]);
        return Order::create([
            'company_id' => $companyId, 'quotation_id' => $quotation->id,
            'customer_id' => $customer->id, 'order_no' => 'ORD-SB-' . uniqid(),
            'order_date' => now(), 'status' => 'pending',
        ]);
    }

    public function test_standalone_batch_with_no_material_requirement_can_now_be_started(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->makeOrder($admin->company_id);
        // No order items → MaterialBomService computes an empty requirement
        // (all_ok trivially true) — nothing to issue, nothing to block on.
        $batch = ProductionBatch::create([
            'company_id' => $admin->company_id, 'order_id' => $order->id,
            'batch_no' => 'PB-SB-' . uniqid(), 'status' => 'draft',
            'planned_quantity' => 10, 'completed_quantity' => 0, 'run_id' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/batches/{$batch->id}/start")
            ->assertStatus(200);

        $this->assertSame('in_progress', $batch->fresh()->status);
    }

    public function test_standalone_batch_issues_its_own_material_when_stock_is_sufficient(): void
    {
        $admin = $this->createAdminUser();
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $order = $this->makeOrder($admin->company_id);
        OrderItem::create([
            'order_id' => $order->id, 'panel_type_id' => $panelType->id,
            'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
            'top_skin_material' => 'PPGI', 'top_skin_thickness' => 0.5, 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => 0.5,
            'total_sqm' => 10, 'rate_per_sqm' => 500, 'quantity' => 1, 'unit_price' => 500, 'amount' => 5000,
        ]);
        $batch = ProductionBatch::create([
            'company_id' => $admin->company_id, 'order_id' => $order->id,
            'batch_no' => 'PB-SB-' . uniqid(), 'status' => 'draft',
            'planned_quantity' => 10, 'completed_quantity' => 0, 'run_id' => null,
        ]);

        CoilStock::create([
            'company_id' => $admin->company_id, 'panel_type_id' => $panelType->id,
            'quantity_in_stock' => 100000, 'reorder_level' => 10,
        ]);
        ChemicalStock::create([
            'company_id' => $admin->company_id, 'category' => 'Polyol',
            'quantity_in_stock' => 100000, 'unit' => 'kg', 'reorder_level' => 10,
        ]);
        ChemicalStock::create([
            'company_id' => $admin->company_id, 'category' => 'Isocyanate',
            'quantity_in_stock' => 100000, 'unit' => 'kg', 'reorder_level' => 10,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/batches/{$batch->id}/start")
            ->assertStatus(200);

        $this->assertSame('in_progress', $batch->fresh()->status);
        $this->assertGreaterThan(0, ProductionMaterialUsage::where('batch_id', $batch->id)->whereNull('run_id')->count());

        $coil = CoilStock::where('company_id', $admin->company_id)->where('panel_type_id', $panelType->id)->first();
        $this->assertLessThan(100000, (float) $coil->quantity_in_stock);
    }

    public function test_standalone_batch_start_blocks_on_insufficient_stock_unless_forced(): void
    {
        $admin = $this->createAdminUser();
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $order = $this->makeOrder($admin->company_id);
        OrderItem::create([
            'order_id' => $order->id, 'panel_type_id' => $panelType->id,
            'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
            'top_skin_material' => 'PPGI', 'top_skin_thickness' => 0.5, 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => 0.5,
            'total_sqm' => 10, 'rate_per_sqm' => 500, 'quantity' => 1, 'unit_price' => 500, 'amount' => 5000,
        ]);
        $batch = ProductionBatch::create([
            'company_id' => $admin->company_id, 'order_id' => $order->id,
            'batch_no' => 'PB-SB-' . uniqid(), 'status' => 'draft',
            'planned_quantity' => 10, 'completed_quantity' => 0, 'run_id' => null,
        ]);
        // No stock seeded at all → every line is short.

        $blocked = $this->actingAs($admin, 'sanctum')->postJson("/api/batches/{$batch->id}/start");
        $blocked->assertStatus(400);
        $this->assertStringContainsString('Insufficient stock', $blocked->json('message'));
        $this->assertSame('draft', $batch->fresh()->status);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/batches/{$batch->id}/start", ['force' => true])
            ->assertStatus(200);
        $this->assertSame('in_progress', $batch->fresh()->status);
    }
}
