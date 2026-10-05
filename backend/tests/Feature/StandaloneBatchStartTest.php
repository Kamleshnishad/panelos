<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use Tests\TestCase;

class StandaloneBatchStartTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_standalone_batch_cannot_be_started_without_a_run(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $quotation = Quotation::create([
            'company_id' => $admin->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-SB-' . uniqid(), 'status' => 'accepted',
        ]);
        $order = Order::create([
            'company_id' => $admin->company_id, 'quotation_id' => $quotation->id,
            'customer_id' => $customer->id, 'order_no' => 'ORD-SB-' . uniqid(),
            'order_date' => now(), 'status' => 'pending',
        ]);
        $batch = ProductionBatch::create([
            'company_id' => $admin->company_id, 'order_id' => $order->id,
            'batch_no' => 'PB-SB-' . uniqid(), 'status' => 'draft',
            'planned_quantity' => 10, 'completed_quantity' => 0, 'run_id' => null,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/batches/{$batch->id}/start")
            ->assertStatus(400);

        $this->assertSame('draft', $batch->fresh()->status);
    }
}
