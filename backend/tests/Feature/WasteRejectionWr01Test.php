<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSize;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use App\Models\WasteReasonCode;
use Tests\TestCase;

/** WR-0 (QC re-inspection, rework, reason codes) and WR-1 (persisted cut list + NOS). */
class WasteRejectionWr01Test extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public static function makeBatch(int $companyId, array $sizesByThickness, string $status = 'draft'): ProductionBatch
    {
        $customer  = Customer::factory()->create(['company_id' => $companyId]);
        $quotation = Quotation::create(['company_id' => $companyId, 'customer_id' => $customer->id, 'quotation_no' => uniqid('Q'), 'status' => 'accepted']);
        $order     = Order::create(['company_id' => $companyId, 'quotation_id' => $quotation->id, 'customer_id' => $customer->id,
                                    'order_no' => uniqid('SO'), 'order_date' => now(), 'status' => 'pending']);
        $panel     = PanelType::factory()->create(['company_id' => $companyId, 'name' => 'Roof']);

        foreach ($sizesByThickness as $thickness => $sizes) {
            $item = OrderItem::create([
                'order_id' => $order->id, 'panel_type_id' => $panel->id, 'thickness' => $thickness,
                'density_type' => 'PUF', 'density_kgm3' => 40, 'top_skin_material' => 'PPGI', 'top_skin_thickness' => 0.4,
                'top_color' => 'Off White', 'top_surface' => 'RIBBED', 'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => 0.4,
                'total_sqm' => 0, 'rate_per_sqm' => 0, 'quantity' => 0, 'unit_price' => 0, 'amount' => 0,
            ]);
            foreach ($sizes as [$length, $nos]) {
                OrderItemSize::create(['order_item_id' => $item->id, 'length_mm' => $length, 'width_mm' => 1000,
                                       'nos' => $nos, 'sqm' => $length / 1000 * $nos, 'rate_per_sqm' => 0, 'amount' => 0]);
            }
        }

        return ProductionBatch::create(['company_id' => $companyId, 'order_id' => $order->id, 'batch_no' => uniqid('PB'),
                                        'status' => $status, 'planned_quantity' => 10, 'completed_quantity' => 0]);
    }

    // ── WR-1: cut list ──────────────────────────────────────────

    public function test_starting_a_batch_builds_a_sequenced_cut_list_with_dl_doubling(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id, [
            50 => [[3000, 10]],
            30 => [[3660, 60], [1500, 3], [4270, 3]],
        ]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/batches/{$batch->id}/start?force=1")->assertStatus(200);
        $lines = $this->getJson("/api/batches/{$batch->id}/cut-lines")->assertStatus(200)->json('data');

        // 30mm first (longest first), then 50mm; 1500 × 3 → 1 doubled strip of 2 + 1 single
        $this->assertSame([4270, 3660, 1500, 1500, 3000], array_column($lines, 'panel_length_mm'));
        $this->assertSame(['single', 'single', 'double', 'single', 'single'], array_column($lines, 'method'));
        $this->assertSame(3000, $lines[2]['production_length_mm']);
        $this->assertSame(76, array_sum(array_column($lines, 'planned_nos')));
        $this->assertStringContainsString('30mm', $lines[0]['spec_label']);
    }

    public function test_recording_cuts_derives_good_nos_and_completed_sqm(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id, [30 => [[3000, 10], [2000, 5]]]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/batches/{$batch->id}/start?force=1")->assertStatus(200);
        [$long, $short] = $batch->fresh()->cutLines;

        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$long->id}", ['cut_nos' => 10])
            ->assertStatus(200)->assertJsonPath('data.good_nos', 10)->assertJsonPath('data.status', 'done');
        // 2 rejected at the saw (a rejection record, WR-2) → 8 good
        WasteRejectionWr2Test::reject($this, $batch, $long, 2);
        $this->assertSame(8, $long->fresh()->good_nos);
        // remakes: cut 12 with 2 rejected → 10 good = done
        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$long->id}", ['cut_nos' => 12])
            ->assertJsonPath('data.status', 'done');
        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$short->id}", ['cut_nos' => 4])->assertStatus(200);

        // invalid: cut below the panels already rejected; more good than planned
        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$long->id}", ['cut_nos' => 1])->assertStatus(400);
        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$short->id}", ['cut_nos' => 6])->assertStatus(400);

        $this->postJson("/api/batches/{$batch->id}/complete", ['completed_quantity' => 999])->assertStatus(200);
        // 10 × 3.0 + 4 × 2.0 = 38 SQM, typed-in 999 ignored
        $this->assertEquals(38, (float) $batch->fresh()->completed_quantity);
        $this->postJson("/api/batches/{$batch->id}/cut-lines/{$short->id}", ['cut_nos' => 5])->assertStatus(400);
    }

    public function test_cut_lines_are_tenant_isolated(): void
    {
        $admin = $this->createAdminUser();
        $other = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id, [30 => [[3000, 2]]], 'in_progress');

        $this->actingAs($other, 'sanctum')->getJson("/api/batches/{$batch->id}/cut-lines")->assertStatus(404);
    }

    // ── WR-0: QC ────────────────────────────────────────────────

    public function test_failed_batch_can_be_reinspected_and_history_is_kept(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id, [30 => [[3000, 2]]], 'qc_pending');

        $this->actingAs($admin, 'sanctum')->postJson("/api/batches/{$batch->id}/qc", ['status' => 'fail'])->assertStatus(201);
        $this->assertSame('qc_failed', $batch->fresh()->status);

        $this->postJson("/api/batches/{$batch->id}/qc", ['status' => 'pass'])->assertStatus(201);
        $this->assertSame('qc_passed', $batch->fresh()->status);

        $qc = $this->getJson("/api/batches/{$batch->id}/qc")->assertStatus(200);
        $this->assertSame('pass', $qc->json('data.status'));
        $this->assertSame(['pass', 'fail'], array_column($qc->json('data.history'), 'status'));
    }

    public function test_reinspected_and_approved_batch_can_be_dispatched(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id, [30 => [[3000, 2]]], 'qc_pending');

        $this->actingAs($admin, 'sanctum')->postJson("/api/batches/{$batch->id}/qc", ['status' => 'fail'])->assertStatus(201);
        $passId = $this->postJson("/api/batches/{$batch->id}/qc", ['status' => 'pass'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/quality-control/{$passId}/approve")->assertStatus(200);

        // The older failed inspection must not block dispatch — the latest one decides.
        $this->assertSame($passId, $batch->fresh()->qualityControl->id);
        $dispatch = app(\App\Services\DispatchService::class)->createDispatch($batch->id, ['customer_address' => 'Site'], $admin->company_id);
        $this->assertSame('pending', $dispatch->status);
    }

    public function test_rework_required_sends_batch_back_to_the_floor(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->makeBatch($admin->company_id, [30 => [[3000, 2]]], 'qc_pending');

        $this->actingAs($admin, 'sanctum')->postJson("/api/batches/{$batch->id}/qc", ['status' => 'rework_required'])->assertStatus(201);
        $this->assertSame('in_progress', $batch->fresh()->status);

        $this->postJson("/api/batches/{$batch->id}/complete")->assertStatus(200);
        $this->assertSame('qc_pending', $batch->fresh()->status);
    }

    // ── WR-0: reason codes ──────────────────────────────────────

    public function test_reason_codes_are_seeded_per_tenant_and_editable(): void
    {
        $admin = $this->createAdminUser();
        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/waste/reason-codes')->assertStatus(200)->json('data');
        $this->assertCount(count(WasteReasonCode::DEFAULTS), $list);

        $this->postJson('/api/waste/reason-codes', ['code' => 'edge_dmg', 'label' => 'Edge damage', 'category' => 'defect'])
            ->assertStatus(201)->assertJsonPath('data.code', 'EDGE_DMG');
        $this->postJson('/api/waste/reason-codes', ['code' => 'EDGE_DMG', 'label' => 'Dup', 'category' => 'defect'])->assertStatus(422);
        $this->postJson('/api/waste/reason-codes', ['code' => 'X', 'label' => 'Bad', 'category' => 'nonsense'])->assertStatus(422);

        $id = $list[0]['id'];
        $this->putJson("/api/waste/reason-codes/{$id}", ['is_active' => false])->assertStatus(200)->assertJsonPath('data.is_active', false);

        // another tenant gets its own defaults and cannot touch ours
        $other = $this->createAdminUser();
        $this->actingAs($other, 'sanctum')->getJson('/api/waste/reason-codes')->assertJsonCount(count(WasteReasonCode::DEFAULTS), 'data');
        $this->putJson("/api/waste/reason-codes/{$id}", ['label' => 'hijack'])->assertStatus(404);
    }
}
