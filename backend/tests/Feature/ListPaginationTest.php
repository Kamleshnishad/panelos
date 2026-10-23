<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\PaymentTransaction;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Four list endpoints returned every row a company had ever created:
 * purchase orders, suppliers, users and unpaid invoices. Nothing failed — the
 * pages just got slower every month until someone complained.
 *
 * Accounts receivable was the worst of them: it also called getPaymentStatus()
 * per row, which re-fetched the invoice and summed its payments, so the cost
 * grew with exactly the thing that grows when business is good.
 *
 * These assert the lists stay bounded, and that AR does not go quadratic again.
 */
class ListPaginationTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function unpaidInvoices(int $companyId, int $count): void
    {
        $customer  = Customer::factory()->create(['company_id' => $companyId]);
        $panelType = PanelType::factory()->create(['company_id' => $companyId]);

        for ($i = 0; $i < $count; $i++) {
            $quotation = Quotation::create([
                'company_id' => $companyId, 'customer_id' => $customer->id,
                'quotation_no' => 'Q-PAG-' . $i . '-' . uniqid(), 'status' => 'accepted',
            ]);
            $order = Order::create([
                'company_id' => $companyId, 'quotation_id' => $quotation->id,
                'customer_id' => $customer->id, 'order_no' => 'ORD-PAG-' . $i . '-' . uniqid(),
                'order_date' => now(), 'status' => 'pending',
            ]);
            $order->items()->create([
                'panel_type_id' => $panelType->id, 'total_sqm' => 10, 'rate_per_sqm' => 1000,
                'amount' => 10000, 'quantity' => 10, 'unit_price' => 1000, 'sort_order' => 0,
            ]);
            $invoice = Invoice::create([
                'company_id' => $companyId, 'order_id' => $order->id, 'status' => 'sent',
                'invoice_date' => now(), 'due_date' => now()->subDays(5),
                'subtotal' => 10000, 'tax_amount' => 1800, 'total_amount' => 11800,
            ]);
            // A part payment on every other one, so the paid-per-invoice map matters.
            if ($i % 2 === 0) {
                PaymentTransaction::create([
                    'company_id' => $companyId, 'invoice_id' => $invoice->id,
                    'amount' => 1000, 'payment_method' => 'bank_transfer',
                    'transaction_date' => now(),
                ]);
            }
        }
    }

    public function test_purchase_orders_are_paginated(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $supplier = Supplier::create(['company_id' => $admin->company_id, 'name' => 'Vendor A']);

        foreach (range(1, 25) as $i) {
            PurchaseOrder::create([
                'company_id' => $admin->company_id, 'supplier_id' => $supplier->id,
                'po_no' => 'PO-PAG-' . $i, 'status' => 'ordered', 'order_date' => now(),
            ]);
        }

        $res = $this->getJson('/api/purchase-orders')->assertStatus(200);

        $this->assertCount(20, $res->json('data'), 'Purchase orders came back unbounded.');
        $this->assertSame(25, $res->json('meta.pagination.total'));
        $this->assertSame(2, $res->json('meta.pagination.last_page'));

        $this->assertCount(5, $this->getJson('/api/purchase-orders?page=2')->json('data'));
    }

    public function test_suppliers_are_paginated_but_the_dropdown_can_still_get_them_all(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        foreach (range(1, 60) as $i) {
            Supplier::create(['company_id' => $admin->company_id, 'name' => 'Vendor ' . str_pad($i, 3, '0', STR_PAD_LEFT)]);
        }

        $this->assertCount(50, $this->getJson('/api/suppliers')->json('data'),
            'Supplier list should default to one page.');

        // The purchase-order form needs every vendor in its select.
        $this->assertCount(60, $this->getJson('/api/suppliers?per_page=500')->json('data'));
    }

    public function test_users_are_paginated(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $role = Role::factory()->for($admin->company)->create();

        foreach (range(1, 5) as $i) {
            User::factory()->create([
                'company_id' => $admin->company_id, 'role_id' => $role->id,
                'is_super_admin' => false, 'is_company_admin' => false,
            ]);
        }

        $res = $this->getJson('/api/users?per_page=3')->assertStatus(200);
        $this->assertCount(3, $res->json('data'));
        $this->assertSame(6, $res->json('meta.pagination.total'));   // 5 + the admin
    }

    public function test_receivables_are_paginated_and_carry_their_payment_status(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->unpaidInvoices($admin->company_id, 12);

        $res = $this->getJson('/api/payments/unpaid?per_page=5')->assertStatus(200);

        $this->assertCount(5, $res->json('data'));
        $this->assertSame(12, $res->json('meta.pagination.total'));
        $this->assertSame(3, $res->json('meta.pagination.last_page'));

        $first = $res->json('data.0.payment_status');
        $this->assertNotNull($first, 'Each row must still carry its payment status.');
        $this->assertArrayHasKey('remaining_due', $first);
        $this->assertTrue($first['is_overdue'], 'These invoices are past due.');
    }

    /**
     * The guard that matters: doubling the rows must not double the queries.
     * Before, each row cost two more (re-fetch the invoice, sum its payments).
     */
    public function test_receivables_do_not_issue_a_query_per_invoice(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->unpaidInvoices($admin->company_id, 20);

        $count = 0;
        DB::listen(function () use (&$count) { $count++; });

        $this->getJson('/api/payments/unpaid?per_page=20')->assertStatus(200);

        $this->assertLessThan(
            20,
            $count,
            "Receivables issued {$count} queries for 20 invoices — the per-row lookup is back."
        );
    }
}
