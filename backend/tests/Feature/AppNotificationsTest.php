<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CoilStock;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\ProductionStage;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\BatchStageLogService;
use Tests\TestCase;

/**
 * The header bell. Before this the bell was only a shortcut to Receivables with a
 * dot computed once at page load; nothing was stored, nothing could be read or
 * cleared, and a pending stage approval told nobody.
 */
class AppNotificationsTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function userWith(int $companyId, array $permissions): User
    {
        $role = Role::factory()->create(['company_id' => $companyId, 'permissions' => $permissions]);
        return User::factory()->create(['company_id' => $companyId, 'role_id' => $role->id]);
    }

    private function batchInProgress(int $companyId): ProductionBatch
    {
        $customer  = Customer::factory()->create(['company_id' => $companyId]);
        $panelType = PanelType::factory()->create(['company_id' => $companyId]);
        $quotation = Quotation::create(['company_id' => $companyId, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-TEST-' . uniqid(), 'status' => 'accepted']);
        $order = Order::create(['company_id' => $companyId, 'quotation_id' => $quotation->id,
            'customer_id' => $customer->id, 'order_no' => 'ORD-TEST-' . uniqid(),
            'order_date' => now(), 'status' => 'in_production']);
        $order->items()->create(['panel_type_id' => $panelType->id, 'total_sqm' => 50, 'rate_per_sqm' => 1400,
            'amount' => 70000, 'quantity' => 50, 'unit_price' => 1400, 'sort_order' => 0]);

        return ProductionBatch::create(['company_id' => $companyId, 'order_id' => $order->id,
            'batch_no' => 'PB-TEST-' . uniqid(), 'status' => 'in_progress',
            'planned_quantity' => 50, 'completed_quantity' => 0]);
    }

    private function note(int $userId, string $type): ?AppNotification
    {
        return AppNotification::withoutGlobalScope('tenant')->where('user_id', $userId)->where('type', $type)->first();
    }

    // ── Stage approval events ─────────────────────────────────────────────

    public function test_completing_an_approval_stage_notifies_approvers_and_only_approvers(): void
    {
        $admin    = $this->createAdminUser();
        $cid      = $admin->company_id;
        $approver = $this->userWith($cid, ['production.approve']);
        $operator = $this->userWith($cid, ['production.manage']);
        $viewer   = $this->userWith($cid, ['production.view']);
        $stage    = ProductionStage::create(['company_id' => $cid, 'name' => 'Cutting', 'sequence' => 1,
            'is_active' => true, 'requires_approval' => true]);
        $batch    = $this->batchInProgress($cid);

        $svc = app(BatchStageLogService::class);
        $log = $svc->startStage($batch, $stage, $operator->id);
        $svc->completeStage($log, $operator->id);

        $n = $this->note($approver->id, 'stage_pending_approval');
        $this->assertNotNull($n, 'The approver was not told.');
        $this->assertSame('batches', $n->link_section);
        $this->assertSame($batch->id, (int) $n->link_id);
        $this->assertStringContainsString('Cutting', $n->body);
        $this->assertNotNull($this->note($admin->id, 'stage_pending_approval'), 'Admins hold every permission.');
        $this->assertNull($this->note($operator->id, 'stage_pending_approval'));
        $this->assertNull($this->note($viewer->id, 'stage_pending_approval'));
    }

    public function test_a_stage_that_needs_no_approval_notifies_nobody(): void
    {
        $admin = $this->createAdminUser();
        $stage = ProductionStage::create(['company_id' => $admin->company_id, 'name' => 'Foaming', 'sequence' => 1,
            'is_active' => true, 'requires_approval' => false]);
        $batch = $this->batchInProgress($admin->company_id);

        $svc = app(BatchStageLogService::class);
        $svc->completeStage($svc->startStage($batch, $stage, $admin->id), $admin->id);

        $this->assertSame(0, AppNotification::withoutGlobalScope('tenant')->count());
    }

    public function test_the_operator_is_told_when_their_stage_is_approved_or_rejected(): void
    {
        $admin    = $this->createAdminUser();
        $cid      = $admin->company_id;
        $operator = $this->userWith($cid, ['production.manage']);
        $stage    = ProductionStage::create(['company_id' => $cid, 'name' => 'Cutting', 'sequence' => 1,
            'is_active' => true, 'requires_approval' => true]);
        $batch    = $this->batchInProgress($cid);

        $svc = app(BatchStageLogService::class);
        $log = $svc->startStage($batch, $stage, $operator->id);
        $svc->completeStage($log, $operator->id);

        $svc->rejectStage($log->fresh(), $admin->id, 'Edges are rough');
        $rejected = $this->note($operator->id, 'stage_rejected');
        $this->assertNotNull($rejected);
        $this->assertSame('danger', $rejected->severity);
        $this->assertStringContainsString('Edges are rough', $rejected->body);

        // Redo and approve.
        $log = $svc->startStage($batch, $stage, $operator->id);
        $svc->completeStage($log, $operator->id);
        $svc->approveStage($log->fresh(), $admin->id);
        $this->assertNotNull($this->note($operator->id, 'stage_approved'));
    }

    public function test_approving_your_own_stage_does_not_notify_yourself(): void
    {
        $admin = $this->createAdminUser();
        $stage = ProductionStage::create(['company_id' => $admin->company_id, 'name' => 'Cutting', 'sequence' => 1,
            'is_active' => true, 'requires_approval' => true]);
        $batch = $this->batchInProgress($admin->company_id);

        $svc = app(BatchStageLogService::class);
        $log = $svc->startStage($batch, $stage, $admin->id);
        $svc->completeStage($log, $admin->id);
        $svc->approveStage($log->fresh(), $admin->id);

        $this->assertNull($this->note($admin->id, 'stage_approved'));
    }

    // ── API ───────────────────────────────────────────────────────────────

    public function test_a_user_sees_only_their_own_notifications_and_can_read_them(): void
    {
        $a = $this->createAdminUser();
        $b = $this->userWith($a->company_id, ['production.view']);
        $svc = app(AppNotificationService::class);
        $svc->notify($a->company_id, [$a->id], 'x', 'For A');
        $svc->notify($a->company_id, [$b->id], 'x', 'For B');

        $list = $this->actingAs($a, 'sanctum')->getJson('/api/notifications')->assertOk();
        $this->assertSame(['For A'], array_column($list->json('data'), 'title'));

        $this->actingAs($a, 'sanctum')->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 1);

        $id = $list->json('data.0.id');
        $this->actingAs($a, 'sanctum')->postJson("/api/notifications/{$id}/read")->assertOk();
        $this->actingAs($a, 'sanctum')->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 0);
        $this->actingAs($a, 'sanctum')->getJson('/api/notifications?unread=1')->assertJsonCount(0, 'data');
    }

    public function test_one_user_cannot_mark_anothers_notification_read(): void
    {
        $a = $this->createAdminUser();
        $b = $this->userWith($a->company_id, ['production.view']);
        app(AppNotificationService::class)->notify($a->company_id, [$b->id], 'x', 'For B');
        $id = AppNotification::withoutGlobalScope('tenant')->where('user_id', $b->id)->value('id');

        $this->actingAs($a, 'sanctum')->postJson("/api/notifications/{$id}/read")->assertStatus(404);
        $this->assertNull(AppNotification::withoutGlobalScope('tenant')->find($id)->read_at);
    }

    public function test_read_all_clears_the_count(): void
    {
        $a = $this->createAdminUser();
        $svc = app(AppNotificationService::class);
        foreach (['1', '2', '3'] as $t) $svc->notify($a->company_id, [$a->id], 'x', "N{$t}");

        $this->actingAs($a, 'sanctum')->postJson('/api/notifications/read-all')->assertOk();
        $this->actingAs($a, 'sanctum')->getJson('/api/notifications/unread-count')->assertJsonPath('data.count', 0);
        $this->actingAs($a, 'sanctum')->getJson('/api/notifications')->assertJsonCount(3, 'data');
    }

    public function test_notifications_never_cross_companies(): void
    {
        $a = $this->createAdminUser();
        $other = $this->createAdminUser();
        app(AppNotificationService::class)->notify($other->company_id, 'production.approve', 'x', 'Other company');

        $this->actingAs($a, 'sanctum')->getJson('/api/notifications')->assertJsonCount(0, 'data');
        $this->assertSame(0, AppNotification::withoutGlobalScope('tenant')->where('user_id', $a->id)->count());
    }

    // ── Standing conditions ───────────────────────────────────────────────

    public function test_the_scan_raises_low_stock_once_and_clears_it_when_restocked(): void
    {
        $admin = $this->createAdminUser();
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id, 'name' => 'Wall Pro']);
        $coil = CoilStock::create(['company_id' => $admin->company_id, 'panel_type_id' => $pt->id,
            'quantity_in_stock' => 10, 'reorder_level' => 100, 'unit_cost' => 80]);

        $this->artisan('notifications:scan')->assertExitCode(0);
        $this->artisan('notifications:scan')->assertExitCode(0);   // idempotent
        $this->assertSame(1, AppNotification::withoutGlobalScope('tenant')->where('type', 'low_coil')->count());

        // The user reads it; an unchanged condition must not make it unread again.
        AppNotification::withoutGlobalScope('tenant')->where('type', 'low_coil')->update(['read_at' => now()]);
        $this->artisan('notifications:scan');
        $this->assertNotNull($this->note($admin->id, 'low_coil')->read_at);

        $coil->update(['quantity_in_stock' => 500]);
        $this->artisan('notifications:scan');
        $this->assertNull($this->note($admin->id, 'low_coil'), 'A resolved condition should disappear.');
    }

    public function test_the_scan_summarises_overdue_invoices_and_follows_the_count(): void
    {
        $admin = $this->createAdminUser();
        $accounts = $this->userWith($admin->company_id, ['payments.view']);
        $make = fn () => Invoice::create(['company_id' => $admin->company_id, 'invoice_no' => 'INV-N-' . uniqid(),
            'status' => 'sent', 'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500,
            'invoice_date' => now()->subDays(20), 'due_date' => now()->subDays(5)]);
        $first = $make();

        $this->artisan('notifications:scan');
        $n = $this->note($accounts->id, 'overdue_invoices');
        $this->assertNotNull($n);
        $this->assertStringStartsWith('1 invoice overdue', $n->title);

        $n->update(['read_at' => now()]);
        $make();
        $this->artisan('notifications:scan');
        $n = $this->note($accounts->id, 'overdue_invoices');
        $this->assertStringStartsWith('2 invoices overdue', $n->title);
        $this->assertNull($n->read_at, 'A changed count is news again.');

        $first->update(['status' => 'paid']);
        Invoice::where('company_id', $admin->company_id)->update(['status' => 'paid']);
        $this->artisan('notifications:scan');
        $this->assertNull($this->note($accounts->id, 'overdue_invoices'));
    }

    public function test_read_notifications_are_pruned_after_thirty_days(): void
    {
        $admin = $this->createAdminUser();
        $svc = app(AppNotificationService::class);
        $svc->notify($admin->company_id, [$admin->id], 'old', 'Old');
        $svc->notify($admin->company_id, [$admin->id], 'recent', 'Recent');
        AppNotification::withoutGlobalScope('tenant')->where('type', 'old')->update(['read_at' => now()->subDays(31)]);
        AppNotification::withoutGlobalScope('tenant')->where('type', 'recent')->update(['read_at' => now()->subDays(2)]);

        $this->artisan('notifications:scan');

        $this->assertNull($this->note($admin->id, 'old'));
        $this->assertNotNull($this->note($admin->id, 'recent'));
    }

    // ── Safety ────────────────────────────────────────────────────────────

    public function test_notifying_a_company_with_nobody_to_tell_is_a_quiet_no_op(): void
    {
        $this->expectNotToPerformAssertions();
        app(AppNotificationService::class)->notify(999999, 'production.approve', 'x', 'Nobody home');
    }

    public function test_the_scan_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('notifications:scan')->assertExitCode(0);
    }
}
