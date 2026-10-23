<?php

namespace Tests\Feature;

use App\Models\BatchStageLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\ProductionStage;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use App\Services\BatchStageLogService;
use App\Services\ProductionBatchService;
use Tests\TestCase;

/**
 * PB-031 / PB-032 / FR-001 — production stages are a real gate, not decoration.
 *
 * Before: areAllStagesCompleted() existed but was never called and completeBatch()
 * checked only that the batch was in_progress, so a batch could be completed, QC'd
 * and dispatched having logged zero stages. Nothing was ever signed off either.
 */
class ProductionStageApprovalTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function batchInProgress(int $companyId): ProductionBatch
    {
        $customer  = Customer::factory()->create(['company_id' => $companyId]);
        $panelType = PanelType::factory()->create(['company_id' => $companyId]);

        $quotation = Quotation::create([
            'company_id' => $companyId, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-TEST-' . uniqid(), 'status' => 'accepted',
        ]);
        $order = Order::create([
            'company_id' => $companyId, 'quotation_id' => $quotation->id,
            'customer_id' => $customer->id, 'order_no' => 'ORD-TEST-' . uniqid(),
            'order_date' => now(), 'status' => 'in_production',
        ]);
        $order->items()->create([
            'panel_type_id' => $panelType->id, 'total_sqm' => 50, 'rate_per_sqm' => 1400,
            'amount' => 70000, 'quantity' => 50, 'unit_price' => 1400, 'sort_order' => 0,
        ]);

        return ProductionBatch::create([
            'company_id' => $companyId, 'order_id' => $order->id,
            'batch_no' => 'PB-TEST-' . uniqid(), 'status' => 'in_progress',
            'planned_quantity' => 50, 'completed_quantity' => 0,
        ]);
    }

    /** @return array{0:ProductionStage,1:ProductionStage} */
    private function twoStages(int $companyId, bool $secondNeedsApproval = true): array
    {
        return [
            ProductionStage::create(['company_id' => $companyId, 'name' => 'Foaming',
                'sequence' => 1, 'is_active' => true, 'requires_approval' => false]),
            ProductionStage::create(['company_id' => $companyId, 'name' => 'Cutting',
                'sequence' => 2, 'is_active' => true, 'requires_approval' => $secondNeedsApproval]),
        ];
    }

    /** A single approval-gated stage, for tests that don't care about sequencing. */
    private function oneApprovalStage(int $companyId): ProductionStage
    {
        return ProductionStage::create(['company_id' => $companyId, 'name' => 'Cutting',
            'sequence' => 1, 'is_active' => true, 'requires_approval' => true]);
    }

    public function test_enforcement_is_off_by_default_so_nothing_breaks(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->twoStages($admin->company_id);
        $batch = $this->batchInProgress($admin->company_id);

        // No stages logged at all, and the company has not opted in.
        $result = app(ProductionBatchService::class)->completeBatch($batch, []);
        $this->assertSame('qc_pending', $result->status);
    }

    public function test_a_batch_cannot_be_completed_with_outstanding_stages(): void
    {
        $admin = $this->createAdminUser();
        $admin->company->update(['enforce_production_stages' => true]);
        $this->actingAs($admin, 'sanctum');
        $this->twoStages($admin->company_id);
        $batch = $this->batchInProgress($admin->company_id);

        $this->expectExceptionMessage('every production stage is completed and approved');
        app(ProductionBatchService::class)->completeBatch($batch, []);
    }

    public function test_a_stage_needing_approval_parks_until_signed_off(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        [$foaming, $cutting] = $this->twoStages($admin->company_id);
        $batch = $this->batchInProgress($admin->company_id);
        $svc = app(BatchStageLogService::class);

        $log = $svc->startStage($batch, $foaming, $admin->id);
        $this->assertSame('completed', $svc->completeStage($log, $admin->id)->status,
            'A stage not flagged for approval should complete outright.');

        $log2 = $svc->startStage($batch, $cutting, $admin->id);
        $this->assertSame('pending_approval', $svc->completeStage($log2, $admin->id)->status);

        $this->assertSame('completed', $svc->approveStage($log2->fresh(), $admin->id)->status);
        $this->assertNotNull($log2->fresh()->approved_at);
        $this->assertSame($admin->id, $log2->fresh()->approved_by_user_id);
    }

    public function test_an_unapproved_stage_does_not_unlock_the_next_one(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $a = ProductionStage::create(['company_id' => $admin->company_id, 'name' => 'Foaming',
            'sequence' => 1, 'is_active' => true, 'requires_approval' => true]);
        $b = ProductionStage::create(['company_id' => $admin->company_id, 'name' => 'Cutting',
            'sequence' => 2, 'is_active' => true, 'requires_approval' => false]);

        $batch = $this->batchInProgress($admin->company_id);
        $svc = app(BatchStageLogService::class);

        $log = $svc->startStage($batch, $a, $admin->id);
        $svc->completeStage($log, $admin->id);   // -> pending_approval

        $this->expectExceptionMessage("Cannot start stage 'Cutting' until 'Foaming' is completed");
        $svc->startStage($batch, $b, $admin->id);
    }

    public function test_a_rejected_stage_goes_back_to_the_floor_and_can_be_rerun(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $cutting = $this->oneApprovalStage($admin->company_id);
        $batch = $this->batchInProgress($admin->company_id);
        $svc = app(BatchStageLogService::class);

        $log = $svc->startStage($batch, $cutting, $admin->id);
        $svc->completeStage($log, $admin->id);

        $rejected = $svc->rejectStage($log->fresh(), $admin->id, 'Edge not square');
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('Edge not square', $rejected->rejection_reason);
        $this->assertNull($rejected->completed_at);

        // The rework path: the same stage may be started again.
        $again = $svc->startStage($batch, $cutting, $admin->id);
        $this->assertSame('in_progress', $again->status);
    }

    public function test_four_eyes_blocks_self_approval_when_the_company_requires_it(): void
    {
        $admin = $this->createAdminUser();
        $admin->company->update(['stage_approval_four_eyes' => true]);
        $this->actingAs($admin, 'sanctum');
        $cutting = $this->oneApprovalStage($admin->company_id);
        $batch = $this->batchInProgress($admin->company_id);
        $svc = app(BatchStageLogService::class);

        $log = $svc->startStage($batch, $cutting, $admin->id);
        $svc->completeStage($log, $admin->id);

        $this->expectExceptionMessage('requires a second person');
        $svc->approveStage($log->fresh(), $admin->id);
    }

    public function test_approving_needs_the_production_approve_permission(): void
    {
        $admin = $this->createAdminUser();
        $company = $admin->company;
        $cutting = $this->oneApprovalStage($company->id);
        $batch = $this->batchInProgress($company->id);

        $this->actingAs($admin, 'sanctum');
        $svc = app(BatchStageLogService::class);
        $log = $svc->startStage($batch, $cutting, $admin->id);
        $svc->completeStage($log, $admin->id);

        // An operator who can run the line but was not given sign-off rights.
        $operatorRole = Role::factory()->for($company)->create([
            'permissions' => ['production.view', 'production.manage'],
        ]);
        $operator = User::factory()->create([
            'company_id' => $company->id, 'role_id' => $operatorRole->id,
            'is_company_admin' => false, 'is_super_admin' => false,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/batches/{$batch->id}/stages/{$cutting->id}/approve")
            ->assertStatus(403);

        $this->assertSame('pending_approval', $log->fresh()->status);
    }

    public function test_reject_requires_a_reason(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $cutting = $this->oneApprovalStage($admin->company_id);
        $batch = $this->batchInProgress($admin->company_id);
        $svc = app(BatchStageLogService::class);
        $log = $svc->startStage($batch, $cutting, $admin->id);
        $svc->completeStage($log, $admin->id);

        $this->postJson("/api/batches/{$batch->id}/stages/{$cutting->id}/reject", [])
            ->assertStatus(422);
    }

    public function test_full_gated_run_completes_once_every_stage_is_approved(): void
    {
        $admin = $this->createAdminUser();
        $admin->company->update(['enforce_production_stages' => true]);
        $this->actingAs($admin, 'sanctum');
        [$foaming, $cutting] = $this->twoStages($admin->company_id);
        $batch = $this->batchInProgress($admin->company_id);
        $svc = app(BatchStageLogService::class);

        $l1 = $svc->startStage($batch, $foaming, $admin->id);
        $svc->completeStage($l1, $admin->id);

        $l2 = $svc->startStage($batch, $cutting, $admin->id);
        $svc->completeStage($l2, $admin->id);
        $svc->approveStage($l2->fresh(), $admin->id);

        $done = app(ProductionBatchService::class)->completeBatch($batch->fresh(), []);
        $this->assertSame('qc_pending', $done->status);
    }
}
