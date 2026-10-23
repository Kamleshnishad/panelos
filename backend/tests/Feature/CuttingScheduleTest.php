<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\ProductionBatch;
use App\Models\Quotation;
use App\Services\CuttingScheduleService;
use Tests\TestCase;

/**
 * The cutting schedule used to read `panelType->length` and treat
 * `order_items.quantity` as a piece count — but that column holds SQUARE METRES.
 * A 30-panel, 78 m² batch therefore reported 78 "items" at the panel type's
 * nominal length and overstated material by roughly 6×.
 *
 * It now walks the order's size rows, which is where the real cut list lives,
 * and applies the same DL rule as the BOQ sheet: panels under 2000 mm are
 * produced two-to-a-strip at doubled length and cut afterwards.
 */
class CuttingScheduleTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** @param array<int,array{0:int,1:int}> $sizes list of [length_mm, nos] */
    private function batchWithSizes(int $companyId, array $sizes): ProductionBatch
    {
        $customer  = Customer::factory()->create(['company_id' => $companyId]);
        $panelType = PanelType::factory()->create(['company_id' => $companyId, 'name' => 'PUF Wall Panel']);

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
            'status'       => 'in_production',
        ]);

        $totalSqm = 0.0;
        foreach ($sizes as [$length, $nos]) {
            $totalSqm += ($length / 1000) * $nos;
        }

        $item = $order->items()->create([
            'panel_type_id' => $panelType->id,
            'total_sqm'     => $totalSqm,
            'rate_per_sqm'  => 1450,
            'amount'        => $totalSqm * 1450,
            'quantity'      => $totalSqm,
            'unit_price'    => 1450,
            'sort_order'    => 0,
        ]);

        foreach ($sizes as $i => [$length, $nos]) {
            $item->sizes()->create([
                'length_mm'    => $length,
                'width_mm'     => 1000,
                'nos'          => $nos,
                'rate_per_sqm' => 1450,
                'amount'       => ($length / 1000) * $nos * 1450,
                'sort_order'   => $i,
            ]);
        }

        return ProductionBatch::create([
            'company_id' => $companyId,
            'order_id'   => $order->id,
            'batch_no'   => 'PB-TEST-' . uniqid(),
            'status'     => 'in_progress',
            'planned_quantity'   => $totalSqm,
            'completed_quantity' => 0,
        ]);
    }

    public function test_schedule_counts_panels_not_square_metres(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        // 20 × 3000mm + 10 × 1800mm = 30 panels, 78 m²
        $batch = $this->batchWithSizes($admin->company_id, [[3000, 20], [1800, 10]]);

        $schedule = app(CuttingScheduleService::class)->calculateSchedule($batch);

        $this->assertSame(30, $schedule['total_items']);

        // 20 × 3000 single + 5 strips × 3600 doubled = 60000 + 18000 = 78000 mm
        $this->assertSame(78000, $schedule['total_material_length']);
    }

    public function test_short_panels_are_paired_as_doubled_length(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $batch = $this->batchWithSizes($admin->company_id, [[1800, 10]]);
        $schedule = app(CuttingScheduleService::class)->calculateSchedule($batch);

        $double = collect($schedule['schedule_items'])->firstWhere('method', 'double');
        $this->assertNotNull($double);
        $this->assertSame(10, $double['quantity']);
        $this->assertSame(5, $double['strips_needed']);
        $this->assertSame(3600, $double['material_length']);
        $this->assertSame(100.0, (float) $schedule['optimization']['double_cut_percentage']);
    }

    public function test_odd_panel_in_a_doubled_set_runs_single(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $batch = $this->batchWithSizes($admin->company_id, [[1500, 5]]);
        $schedule = app(CuttingScheduleService::class)->calculateSchedule($batch);

        $this->assertSame(4, $schedule['optimization']['double_cut_items']);
        $this->assertSame(1, $schedule['optimization']['single_cut_items']);
        $this->assertSame(80.0, (float) $schedule['optimization']['double_cut_percentage']);
    }

    public function test_2000mm_is_not_doubled(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $batch = $this->batchWithSizes($admin->company_id, [[2000, 4]]);
        $schedule = app(CuttingScheduleService::class)->calculateSchedule($batch);

        $this->assertSame(0, $schedule['optimization']['double_cut_items']);
        $this->assertSame(4, $schedule['optimization']['single_cut_items']);
        $this->assertSame(8000, $schedule['total_material_length']);
    }

    public function test_instructions_endpoint_renders_the_schedule(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->batchWithSizes($admin->company_id, [[3000, 20], [1800, 10]]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/batches/{$batch->id}/cutting-schedule");

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('CUTTING SCHEDULE', $content);
        $this->assertStringContainsString($batch->batch_no, $content);
        $this->assertStringContainsString('Total Items: 30', $content);
    }

    public function test_json_endpoint_keeps_its_contract(): void
    {
        $admin = $this->createAdminUser();
        $batch = $this->batchWithSizes($admin->company_id, [[1800, 10]]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/batches/{$batch->id}/cutting-schedule/json")
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['batch_id', 'schedule_items', 'waste_percentage']]);
    }
}
