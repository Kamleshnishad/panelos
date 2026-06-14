<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PanelType;
use App\Models\SalesMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
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
    }

    protected function createHistoricalData()
    {
        for ($i = 0; $i < 90; $i++) {
            SalesMetric::create([
                'company_id' => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date' => now()->subDays(90 - $i),
                'quantity_sold' => rand(20, 100),
                'revenue' => rand(1000, 5000),
                'average_price' => 50,
                'invoice_count' => rand(1, 5)
            ]);
        }
    }

    public function test_generate_inventory_forecast()
    {
        $this->createHistoricalData();

        $response = $this->postJson('/api/forecasts/inventory', [
            'panel_type_id' => $this->panelType->id,
            'days_ahead' => 30
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 30);
    }

    public function test_generate_demand_forecast()
    {
        $this->createHistoricalData();

        $response = $this->postJson('/api/forecasts/demand', [
            'panel_type_id' => $this->panelType->id,
            'forecast_period' => 30
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_get_demand_forecast()
    {
        $response = $this->getJson('/api/forecasts/demand', [
            'panel_type_id' => $this->panelType->id
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_get_upcoming_reorders()
    {
        $response = $this->getJson('/api/forecasts/reorders', [
            'days_ahead' => 30
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
