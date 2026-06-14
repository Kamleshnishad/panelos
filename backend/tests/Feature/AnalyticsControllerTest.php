<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PanelType;
use App\Models\SalesMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
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
    }

    public function test_record_sales_metric()
    {
        $response = $this->postJson('/api/analytics/metrics/sales', [
            'panel_type_id' => $this->panelType->id
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_generate_trend_analysis()
    {
        $this->createHistoricalData();

        $response = $this->postJson('/api/analytics/trends', [
            'panel_type_id' => $this->panelType->id,
            'period_days' => 30
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_get_trend_analysis()
    {
        $this->createHistoricalData();

        $response = $this->getJson('/api/analytics/trends', [
            'panel_type_id' => $this->panelType->id,
            'period_days' => 30
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_create_snapshot()
    {
        $response = $this->postJson('/api/analytics/snapshot');

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_id', $this->company->id);
    }

    public function test_get_snapshot()
    {
        // Create a snapshot first
        $this->postJson('/api/analytics/snapshot');

        $response = $this->getJson('/api/analytics/snapshot');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_snapshot_includes_performance_status()
    {
        $this->postJson('/api/analytics/snapshot');

        $response = $this->getJson('/api/analytics/snapshot');

        $response->assertJsonPath('data.performance_status', function ($status) {
            return in_array($status, ['excellent', 'good', 'average', 'poor']);
        });
    }
}
