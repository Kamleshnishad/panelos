<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PanelType;
use App\Models\SalesMetric;
use App\Models\InventoryForecast;
use App\Models\DemandForecast;
use App\Models\User;
use App\Services\ForecastingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $forecastingService;
    protected $panelType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Company']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id
        ]);

        $this->panelType = PanelType::create([
            'type' => 'Standard',
            'thickness_mm' => 100,
            'thermal_resistance' => 5.0
        ]);

        $this->actingAs($this->user);
        $this->forecastingService = app(ForecastingService::class);
    }

    public function test_generate_inventory_forecast()
    {
        // Create historical sales data
        for ($i = 0; $i < 30; $i++) {
            SalesMetric::create([
                'company_id' => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date' => now()->subDays(30 - $i),
                'quantity_sold' => rand(10, 50),
                'revenue' => rand(1000, 5000),
                'average_price' => 50,
                'invoice_count' => 1
            ]);
        }

        $forecasts = $this->forecastingService->generateInventoryForecast(
            $this->company->id,
            $this->panelType->id,
            10
        );

        $this->assertGreaterThan(0, count($forecasts));
        $this->assertDatabaseHas('inventory_forecasts', [
            'company_id' => $this->company->id,
            'panel_type_id' => $this->panelType->id
        ]);
    }

    public function test_generate_demand_forecast()
    {
        // Create historical sales data
        for ($i = 0; $i < 90; $i++) {
            SalesMetric::create([
                'company_id' => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date' => now()->subDays(90 - $i),
                'quantity_sold' => rand(20, 100),
                'revenue' => rand(2000, 10000),
                'average_price' => 50,
                'invoice_count' => rand(1, 5)
            ]);
        }

        $forecasts = $this->forecastingService->generateDemandForecast(
            $this->company->id,
            $this->panelType->id,
            30
        );

        $this->assertGreaterThan(0, count($forecasts));
        $this->assertDatabaseHas('demand_forecasts', [
            'company_id' => $this->company->id,
            'panel_type_id' => $this->panelType->id
        ]);
    }

    public function test_demand_forecast_includes_risk_level()
    {
        for ($i = 0; $i < 90; $i++) {
            SalesMetric::create([
                'company_id' => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date' => now()->subDays(90 - $i),
                'quantity_sold' => rand(10, 30),
                'revenue' => rand(1000, 3000),
                'average_price' => 50,
                'invoice_count' => 1
            ]);
        }

        $forecasts = $this->forecastingService->generateDemandForecast(
            $this->company->id,
            $this->panelType->id,
            30
        );

        foreach ($forecasts as $forecast) {
            $this->assertIn($forecast->risk_level, ['low', 'medium', 'high']);
        }
    }

    public function test_get_demand_forecast()
    {
        DemandForecast::create([
            'company_id' => $this->company->id,
            'panel_type_id' => $this->panelType->id,
            'forecast_date' => now(),
            'forecast_period_days' => 30,
            'predicted_demand' => 100,
            'current_stock' => 50,
            'reorder_quantity' => 150,
            'recommended_order_date' => now()->addDays(5),
            'seasonal_factor' => 1.2,
            'trend_strength' => 0.5,
            'risk_level' => 'medium'
        ]);

        $forecasts = $this->forecastingService->getDemandForecast($this->company->id);

        $this->assertGreaterThan(0, $forecasts->count());
    }

    public function test_get_upcoming_reorders()
    {
        DemandForecast::create([
            'company_id' => $this->company->id,
            'panel_type_id' => $this->panelType->id,
            'forecast_date' => now(),
            'forecast_period_days' => 30,
            'predicted_demand' => 100,
            'current_stock' => 10,
            'reorder_quantity' => 200,
            'recommended_order_date' => now()->addDays(5),
            'seasonal_factor' => 1.0,
            'trend_strength' => 0.0,
            'risk_level' => 'high'
        ]);

        $reorders = $this->forecastingService->getUpcomingReorders($this->company->id, 30);

        $this->assertGreaterThan(0, $reorders->count());
    }

    public function test_forecast_confidence_decreases_with_distance()
    {
        for ($i = 0; $i < 30; $i++) {
            SalesMetric::create([
                'company_id' => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date' => now()->subDays(30 - $i),
                'quantity_sold' => 50,
                'revenue' => 2500,
                'average_price' => 50,
                'invoice_count' => 1
            ]);
        }

        $forecasts = $this->forecastingService->generateInventoryForecast(
            $this->company->id,
            $this->panelType->id,
            30
        );

        // Confidence should decrease as forecast date goes further in future
        $firstForecast = $forecasts[0];
        $lastForecast = $forecasts[count($forecasts) - 1];

        $this->assertGreaterThan(
            $lastForecast->confidence_score,
            $firstForecast->confidence_score
        );
    }
}
