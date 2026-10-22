<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Tests\TestCase;

/**
 * Exercises the REAL seeded default roles (via TenantProvisioningService::provision,
 * the actual signup path) against real routes. The permission-middleware tests this
 * repo had before only used hand-built ad hoc permission sets or the admin bypass —
 * never the system's own default roles — which is exactly how H1/H6/H7 (a role
 * missing a permission it needs, or a route missing a check it needs) shipped
 * undetected. Intentionally does NOT assert on stock/dispatch/procurement routes:
 * those are tracked separately as Critical findings (C2/C7/C8) and are still broken
 * for every non-admin role — asserting success there would fail for an unrelated,
 * already-known reason.
 */
class DefaultRolePermissionsTest extends TestCase
{
    private function userWithDefaultRole(string $roleName): User
    {
        $result = app(TenantProvisioningService::class)->provision([
            'company_name' => 'Test Co ' . uniqid(),
            'name'         => 'Admin ' . uniqid(),
            'email'        => 'admin' . uniqid() . '@example.com',
            'password'     => 'Password123',
        ]);

        $role = Role::where('company_id', $result['company']->id)->where('name', $roleName)->firstOrFail();

        return User::factory()->create([
            'company_id'       => $result['company']->id,
            'role_id'          => $role->id,
            'is_company_admin' => false,
            'is_super_admin'   => false,
            'is_active'        => true,
        ]);
    }

    public function test_sales_role_can_view_and_edit_orders(): void
    {
        $user = $this->userWithDefaultRole('Sales');

        $this->actingAs($user, 'sanctum')->getJson('/api/orders')->assertStatus(200);

        // 404 (no such order) is fine — 403 (blocked by the permission gate) is the bug under test.
        $response = $this->actingAs($user, 'sanctum')->putJson('/api/orders/999999', ['notes' => 'x']);
        $this->assertNotEquals(403, $response->status());
    }

    public function test_accounts_role_can_send_an_invoice_reminder(): void
    {
        $user = $this->userWithDefaultRole('Accounts');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/invoices/999999/send-payment-reminder');
        $this->assertNotEquals(403, $response->status());
    }

    public function test_role_without_reports_view_cannot_bulk_export(): void
    {
        $user = $this->userWithDefaultRole('Production'); // has no reports.view

        $this->actingAs($user, 'sanctum')->getJson('/api/export/customers')->assertStatus(403);
    }

    public function test_role_with_reports_view_can_bulk_export(): void
    {
        $user = $this->userWithDefaultRole('Accounts'); // has reports.view

        $this->actingAs($user, 'sanctum')->getJson('/api/export/customers')->assertStatus(200);
    }

    public function test_non_admin_can_reach_billing_checkout_and_verify(): void
    {
        $user = $this->userWithDefaultRole('Sales'); // holds no settings.manage

        $checkout = $this->actingAs($user, 'sanctum')->postJson('/api/billing/checkout', ['plan' => 'starter']);
        $this->assertNotEquals(403, $checkout->status());

        $verify = $this->actingAs($user, 'sanctum')->postJson('/api/billing/verify', ['reference' => 'x']);
        $this->assertNotEquals(403, $verify->status());
    }

    public function test_viewer_role_cannot_trigger_forecast_generation(): void
    {
        $user = $this->userWithDefaultRole('Viewer'); // reports.view only, no reports.manage

        $this->actingAs($user, 'sanctum')->postJson('/api/forecasts/demand')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->postJson('/api/analytics/snapshot')->assertStatus(403);
    }

    public function test_accounts_role_can_trigger_forecast_generation(): void
    {
        $user = $this->userWithDefaultRole('Accounts'); // now holds reports.manage

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/forecasts/demand');
        $this->assertNotEquals(403, $response->status());
    }

    public function test_c3_reports_module_is_now_protected_by_reports_view(): void
    {
        // Both users built BEFORE any actingAs() call — Role::where(...) inside
        // userWithDefaultRole() is subject to BelongsToTenant's global tenant
        // scope, which would silently AND an already-authenticated user's own
        // company_id onto the lookup and make it find nothing.
        $withReports = $this->userWithDefaultRole('Accounts'); // holds reports.view
        $withoutReports = $this->userWithDefaultRole('Production');

        $this->actingAs($withReports, 'sanctum')->getJson('/api/reports/accounts-receivable')->assertStatus(200);

        // Previously the 'reports' segment was entirely unmapped, so EVERY
        // authenticated role — including one with no reports.view — could
        // read P&L, balance sheet, cash flow and AR data with no check at
        // all (C3). A role that genuinely lacks reports.view must now 403.
        $this->actingAs($withoutReports, 'sanctum')->getJson('/api/reports/accounts-receivable')->assertStatus(403);
    }

    public function test_c7_production_role_can_reach_procurement(): void
    {
        $user = $this->userWithDefaultRole('Production'); // now holds procurement.view/manage
        $this->actingAs($user, 'sanctum')->getJson('/api/suppliers')->assertStatus(200);
    }

    public function test_c8_production_role_can_adjust_stock(): void
    {
        $user = $this->userWithDefaultRole('Production'); // now holds stock.manage
        // 404 (no such coil) is fine — 403 (blocked by the permission gate) is the bug under test.
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/stock/coils/999999/add', ['quantity' => 10]);
        $this->assertNotEquals(403, $response->status());
    }

    public function test_c2_production_role_can_list_and_create_dispatches(): void
    {
        $user = $this->userWithDefaultRole('Production'); // now holds dispatch.view/manage
        $this->actingAs($user, 'sanctum')->getJson('/api/dispatches')->assertStatus(200);

        // dispatches.create lives under /batches/{batchId}/dispatch — segment
        // "batches" — so before the ROUTE_OVERRIDE fix this was gated on
        // production.manage instead of dispatch.manage (C2's "creation-route
        // mismatch"). 404 (no such batch) is fine — 403 is the bug under test.
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/batches/999999/dispatch', []);
        $this->assertNotEquals(403, $response->status());
    }

    public function test_c1_production_role_can_create_a_standalone_batch(): void
    {
        $user = $this->userWithDefaultRole('Production'); // holds production.manage
        $customer = \App\Models\Customer::factory()->create(['company_id' => $user->company_id]);
        $quotation = \App\Models\Quotation::create([
            'company_id' => $user->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-TEST-' . uniqid(), 'status' => 'accepted',
        ]);
        $order = \App\Models\Order::create([
            'company_id' => $user->company_id,
            'quotation_id' => $quotation->id,
            'customer_id' => $customer->id,
            'order_no' => 'ORD-TEST-' . uniqid(),
            'status' => 'pending',
            'order_date' => now(),
        ]);

        // batches.create lives under /orders/{orderId}/batches — segment
        // "orders" — so before the ROUTE_OVERRIDE fix this was gated on
        // orders.manage, which no default role holds (C1). Production, which
        // actually owns batch creation, must be able to reach it.
        $response = $this->actingAs($user, 'sanctum')->postJson("/api/orders/{$order->id}/batches");
        $response->assertStatus(201);
    }
}
