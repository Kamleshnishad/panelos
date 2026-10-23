<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\Quotation;
use Tests\TestCase;

/**
 * Found on live 2026-10-24: after the newest quotation was deleted, EVERY new quotation failed with a 500
 * (duplicate entry '6-SCP-2027-013'). Quotation is soft-deleted, so the row keeps its number in the unique
 * key, but the number generator ignored trashed rows and handed the same number out again.
 */
class DocumentNumberAfterDeleteTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function create($admin, $customer, $pt)
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id,
            'panel_rows' => [[
                'panel_type_id' => $pt->id, 'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
                'top_skin_material' => 'PPGI', 'top_skin_thickness' => '0.40', 'top_surface' => 'PLAIN',
                'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => '0.40',
                'sizes' => [['length_mm' => 3000, 'nos' => 2, 'rate_per_sqm' => 500]],
            ]],
        ]);
    }

    public function test_a_new_quotation_after_deleting_the_newest_gets_a_fresh_number(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);

        $a = $this->create($admin, $customer, $pt)->assertStatus(201)->json('data');
        $b = $this->create($admin, $customer, $pt)->assertStatus(201)->json('data');
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/quotations/{$b['id']}")->assertStatus(204);

        $c = $this->create($admin, $customer, $pt)->assertStatus(201)->json('data');

        $this->assertNotSame($b['quotation_no'], $c['quotation_no'], 'A deleted number was handed out again.');
        $this->assertNotSame($a['quotation_no'], $c['quotation_no']);
        $this->assertGreaterThan((int) substr($b['quotation_no'], -3), (int) substr($c['quotation_no'], -3));
    }

    public function test_order_and_invoice_numbers_also_skip_deleted_ones(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $quote = fn () => Quotation::create(['company_id' => $admin->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-ND-' . uniqid(), 'status' => 'accepted']);

        $inv = Invoice::create(['company_id' => $admin->company_id, 'status' => 'draft', 'subtotal' => 1, 'tax_amount' => 0, 'total_amount' => 1,
            'invoice_date' => now(), 'due_date' => now()->addDays(5)]);
        $inv->delete();
        $next = Invoice::create(['company_id' => $admin->company_id, 'status' => 'draft', 'subtotal' => 1, 'tax_amount' => 0, 'total_amount' => 1,
            'invoice_date' => now(), 'due_date' => now()->addDays(5)]);
        $this->assertNotSame($inv->invoice_no, $next->invoice_no);

        $svc = app(\App\Services\OrderService::class);
        $make = fn () => Order::create(['company_id' => $admin->company_id, 'quotation_id' => $quote()->id, 'customer_id' => $customer->id,
            'order_no' => (new \ReflectionMethod($svc, 'generateOrderNumber'))->invoke($svc, $admin->company_id),
            'order_date' => now(), 'status' => 'pending']);
        $o1 = $make();
        $o1->delete();
        $o2 = $make();
        $this->assertNotSame($o1->order_no, $o2->order_no);
    }
}
