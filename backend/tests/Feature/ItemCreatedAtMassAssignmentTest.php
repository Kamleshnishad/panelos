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
 * DispatchItem, InvoiceItem and TaxCalculation all share the same shape:
 * $timestamps = false, a created_at column with no DB default, and nothing
 * reliably setting it (mass-assignment silently dropped an explicit value
 * for DispatchItem; InvoiceItem/TaxCalculation never set it at all). Harmless
 * on a lenient MySQL sql_mode; production's strict mode rejects the INSERT
 * outright, which is how DispatchItem's bug was found, and then the same
 * failure immediately reappeared one step later for InvoiceItem/TaxCalculation
 * when the dispatch-to-invoice chain was run end to end.
 */
class ItemCreatedAtMassAssignmentTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_dispatch_creation_sets_created_at_on_its_items(): void
    {
        $admin = $this->createAdminUser();
        \App\Models\TaxConfiguration::create([
            'company_id' => $admin->company_id, 'tax_type' => 'exclusive',
            'default_tax_rate' => 18, 'is_active' => true,
        ]);
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

        $dispatchId = $response->json('data.id');
        $invoiceResponse = $this->actingAs($admin, 'sanctum')->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $dispatchId,
            'invoice_date' => now()->toDateString(),
        ]);

        $invoiceResponse->assertStatus(201);
        $invoiceItem = \App\Models\InvoiceItem::first();
        $this->assertNotNull($invoiceItem->created_at);
        $taxCalculation = \App\Models\TaxCalculation::first();
        $this->assertNotNull($taxCalculation->created_at);
    }
}
