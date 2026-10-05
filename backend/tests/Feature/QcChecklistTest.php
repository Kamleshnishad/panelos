<?php

namespace Tests\Feature;

use App\Models\ProductionBatch;
use App\Models\QualityControl;
use Tests\TestCase;

class QcChecklistTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function qcPendingBatch(int $companyId): ProductionBatch
    {
        $customer = \App\Models\Customer::factory()->create(['company_id' => $companyId]);
        $quotation = \App\Models\Quotation::create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'quotation_no' => 'Q-TEST-' . uniqid(),
            'status' => 'accepted',
        ]);
        $order = \App\Models\Order::create([
            'company_id' => $companyId,
            'quotation_id' => $quotation->id,
            'customer_id' => $customer->id,
            'order_no' => 'ORD-TEST-' . uniqid(),
            'order_date' => now(),
            'status' => 'in_production',
        ]);

        return ProductionBatch::create([
            'company_id' => $companyId,
            'order_id' => $order->id,
            'batch_no' => 'PB-TEST-' . uniqid(),
            'status' => 'qc_pending',
            'planned_quantity' => 100,
            'completed_quantity' => 100,
        ]);
    }

    public function test_checklist_is_persisted_with_qc_record(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->qcPendingBatch($admin->company_id);

        $checklist = [
            ['key' => 'dimensions', 'label' => 'Panel dimensions within tolerance', 'passed' => true],
            ['key' => 'edges', 'label' => 'Edge straightness', 'passed' => false],
        ];

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/batches/{$batch->id}/qc", ['status' => 'fail', 'notes' => 'edge', 'checklist' => $checklist])
            ->assertStatus(201);

        $stored = QualityControl::where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame($checklist, $stored->checklist);
    }

    public function test_malformed_checklist_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->qcPendingBatch($admin->company_id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/batches/{$batch->id}/qc", ['status' => 'pass', 'checklist' => [['key' => 'x']]])
            ->assertStatus(422);
    }
}
