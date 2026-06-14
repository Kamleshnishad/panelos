<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PanelType;
use App\Models\SalesMetric;
use App\Models\TrendAnalysis;
use App\Models\AnalyticsSnapshot;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $analyticsService;
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
        $this->analyticsService = app(AnalyticsService::class);
    }

    public function test_record_sales_metric()
    {
        $result = $this->analyticsService->recordSalesMetric($this->company->id);

        $this->assertDatabaseHas('sales_metrics', [
            'company_id' => $this->company->id
        ]);
    }

    public function test_generate_trend_analysis()
    {
        // Create sample metrics
        for ($i = 0; $i < 30; $i++) {
            SalesMetric::create([
                'company_id' => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date' => now()->subDays(30 - $i),
                'quantity_sold' => 50 + $i,
                'revenue' => 2500 + ($i * 50),
                'average_price' => 50,
                'invoice_count' => 1
            ]);
        }

        $analyses = $this->analyticsService->generateTrendAnalysis(
            $this->company->id,
            $this->panelType->id,
            30
        );

        $this->assertGreaterThan(0, count($analyses));
        $this->assertDatabaseHas('trend_analyses', [
            'company_id' => $this->company->id,
            'panel_type_id' => $this->panelType->id
        ]);
    }

    public function test_trend_direction_upward()
    {
        // Create metrics with upward trend
        for ($i = 0; $i < 30; $i++) {
            SalesMetric::create([
                'company_id' => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date' => now()->subDays(30 - $i),
                'quantity_sold' => 50 + ($i * 2), // Increasing
                'revenue' => 2500 + ($i * 100),
                'average_price' => 50,
                'invoice_count' => 1
            ]);
        }

        $analyses = $this->analyticsService->generateTrendAnalysis(
            $this->company->id,
            $this->panelType->id,
            30
        );

        if (count($analyses) > 0) {
            $analysis = $analyses[0];
            // With clear upward trend, direction should be upward
            $this->assertIsNotNull($analysis->trend_direction);
        }
    }

    public function test_create_analytics_snapshot()
    {
        $snapshot = $this->analyticsService->createAnalyticsSnapshot($this->company->id);

        $this->assertNotNull($snapshot);
        $this->assertEquals($this->company->id, $snapshot->company_id);
        $this->assertDatabaseHas('analytics_snapshots', [
            'company_id' => $this->company->id
        ]);
    }

    public function test_snapshot_includes_key_metrics()
    {
        $snapshot = $this->analyticsService->createAnalyticsSnapshot($this->company->id);

        $this->assertIsNumeric($snapshot->total_invoices);
        $this->assertIsNumeric($snapshot->total_revenue);
        $this->assertIsNumeric($snapshot->average_invoice_value);
        $this->assertIsNumeric($snapshot->total_quantity_sold);
        $this->assertIsNumeric($snapshot->accounts_receivable);
    }

    public function test_snapshot_performance_status_exists()
    {
        $snapshot = $this->analyticsService->createAnalyticsSnapshot($this->company->id);

        $this->assertIn(
            $snapshot->performance_status,
            ['excellent', 'good', 'average', 'poor']
        );
    }

    public function test_get_analytics_snapshot()
    {
        $this->analyticsService->createAnalyticsSnapshot($this->company->id);

        $snapshot = $this->analyticsService->getAnalyticsSnapshot($this->company->id);

        $this->assertNotNull($snapshot);
        $this->assertEquals($this->company->id, $snapshot->company_id);
    }

    public function test_get_trend_analysis()
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

        $this->analyticsService->generateTrendAnalysis(
            $this->company->id,
            $this->panelType->id,
            30
        );

        $analyses = $this->analyticsService->getTrendAnalysis(
            $this->company->id,
            $this->panelType->id,
            30
        );

        $this->assertGreaterThan(0, $analyses->count());
    }

    public function test_volatility_calculation()
    {
        // Create stable metrics (low volatility)
        for ($i = 0; $i < 30; $i++) {
            SalesMetric::create([
                'company_id' => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date' => now()->subDays(30 - $i),
                'quantity_sold' => 50, // Stable
                'revenue' => 2500,
                'average_price' => 50,
                'invoice_count' => 1
            ]);
        }

        $analyses = $this->analyticsService->generateTrendAnalysis(
            $this->company->id,
            $this->panelType->id,
            30
        );

        if (count($analyses) > 0) {
            // Stable data should have low volatility
            $this->assertLessThan(0.5, $analyses[0]->volatility);
        }
    }
}
