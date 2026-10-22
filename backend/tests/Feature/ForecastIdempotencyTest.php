<?php

namespace Tests\Feature;

use App\Models\DemandForecast;
use App\Models\PanelType;
use App\Models\SalesMetric;
use Tests\TestCase;

class ForecastIdempotencyTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function seedHistory($companyId, $panelTypeId, int $days): void
    {
        for ($i = 1; $i <= $days; $i++) {
            SalesMetric::create([
                'company_id' => $companyId,
                'panel_type_id' => $panelTypeId,
                'metric_date' => now()->subDays($i)->toDateString(),
                'quantity_sold' => 10 + ($i % 3),
                'revenue' => 1000,
                'average_price' => 100,
                'invoice_count' => 1,
            ]);
        }
    }

    public function test_generating_demand_forecast_twice_updates_in_place_instead_of_crashing(): void
    {
        $admin = $this->createAdminUser();
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $this->seedHistory($admin->company_id, $panelType->id, 30);

        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/forecasts/demand', ['panel_type_id' => $panelType->id]);
        $first->assertStatus(201);

        // Repeated "Generate" click — must update in place, not throw a
        // duplicate-key error (C4).
        $second = $this->actingAs($admin, 'sanctum')->postJson('/api/forecasts/demand', ['panel_type_id' => $panelType->id]);
        $second->assertStatus(201);

        $this->assertSame(1, DemandForecast::where('company_id', $admin->company_id)
            ->where('panel_type_id', $panelType->id)->count());
    }

    public function test_generating_ml_forecast_twice_updates_in_place_instead_of_crashing(): void
    {
        $admin = $this->createAdminUser();
        $panelType = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $this->seedHistory($admin->company_id, $panelType->id, 30);

        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/forecasts/ml', ['panel_type_id' => $panelType->id]);
        $first->assertStatus(201);
        $this->assertTrue($first->json('success'));

        $second = $this->actingAs($admin, 'sanctum')->postJson('/api/forecasts/ml', ['panel_type_id' => $panelType->id]);
        $second->assertStatus(201);
        $this->assertTrue($second->json('success'));

        $this->assertSame(1, DemandForecast::where('company_id', $admin->company_id)
            ->where('panel_type_id', $panelType->id)->count());
    }
}
