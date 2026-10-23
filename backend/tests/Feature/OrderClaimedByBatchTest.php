<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Services\ProductionBatchService;
use App\Services\ProductionPlanningService;
use App\Services\ProductionRunService;
use Tests\TestCase;

/**
 * Regression: only ProductionRunService moved an order to `in_production`.
 * An order produced through the standalone path (POST /orders/{id}/batches)
 * stayed `pending` forever, so the planner kept offering it as work to do and a
 * run could be built from it — producing the job a second time and issuing raw
 * material a second time.
 */
class OrderClaimedByBatchTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function pendingOrder(int $companyId): Order
    {
        $customer  = Customer::factory()->create(['company_id' => $companyId]);
        $panelType = PanelType::factory()->create(['company_id' => $companyId]);

        $quotation = Quotation::create([
            'company_id'   => $companyId,
            'customer_id'  => $customer->id,
            'quotation_no' => 'Q-TEST-' . uniqid(),
            'status'       => 'accepted',
        ]);

        $order = Order::create([
            'company_id'   => $companyId,
            'quotation_id' => $quotation->id,
            'customer_id'  => $customer->id,
            'order_no'     => 'ORD-TEST-' . uniqid(),
            'order_date'   => now(),
            'status'       => 'pending',
            'total_sqm'    => 78,
        ]);

        $order->items()->create([
            'panel_type_id'       => $panelType->id,
            'thickness'           => 50,
            'density_type'        => 'PUF',
            'density_kgm3'        => 40,
            'top_skin_thickness'  => 0.5,
            'bottom_skin_thickness' => 0.5,
            'total_sqm'           => 78,
            'rate_per_sqm'        => 1450,
            'amount'              => 113100,
            'quantity'            => 78,
            'unit_price'          => 1450,
            'sort_order'          => 0,
        ]);

        return $order->fresh('items');
    }

    public function test_standalone_batch_moves_the_order_into_production(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $order = $this->pendingOrder($admin->company_id);

        app(ProductionBatchService::class)->createFromOrder($order, []);

        $this->assertSame('in_production', $order->fresh()->status);
    }

    public function test_planner_stops_offering_an_order_that_has_a_batch(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $order = $this->pendingOrder($admin->company_id);

        $before = app(ProductionPlanningService::class)->getPlan($admin->company_id);
        $this->assertSame(1, $before['summary']['pending_orders']);

        app(ProductionBatchService::class)->createFromOrder($order, []);

        $after = app(ProductionPlanningService::class)->getPlan($admin->company_id);
        $this->assertSame(0, $after['summary']['pending_orders']);
        $this->assertSame([], $after['groups']);
    }

    public function test_a_run_cannot_be_built_from_an_already_batched_order(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $order = $this->pendingOrder($admin->company_id);

        app(ProductionBatchService::class)->createFromOrder($order, []);

        $this->expectExceptionMessage('No eligible pending orders');
        app(ProductionRunService::class)
            ->createFromOrders($admin->company_id, [$order->id], null, null, null);
    }

    public function test_deleting_the_last_draft_batch_releases_the_order(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $order = $this->pendingOrder($admin->company_id);

        $service = app(ProductionBatchService::class);
        $batch = $service->createFromOrder($order, []);
        $this->assertSame('in_production', $order->fresh()->status);

        $service->delete($batch);

        $this->assertSame('pending', $order->fresh()->status);
    }
}
