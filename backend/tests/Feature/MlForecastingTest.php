<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PanelType;
use App\Models\SalesMetric;
use App\Models\User;
use App\Services\MlForecastingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlForecastingTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $panelType;
    protected MlForecastingService $ml;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company   = Company::create(['name' => 'Test Company']);
        $this->user      = User::create([
            'name'       => 'Test User',
            'email'      => 'test@example.com',
            'password'   => bcrypt('password'),
            'company_id' => $this->company->id
        ]);

        $this->panelType = PanelType::create([
            'type'       => 'Steel Panel',
            'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
        $this->ml = new MlForecastingService();

        // Seed 60 days of historical sales data
        for ($i = 60; $i >= 1; $i--) {
            SalesMetric::create([
                'company_id'    => $this->company->id,
                'panel_type_id' => $this->panelType->id,
                'metric_date'   => now()->subDays($i)->toDateString(),
                'quantity_sold' => rand(10, 50),
                'revenue'       => rand(1000, 5000),
            ]);
        }
    }

    // ── Unit: individual algorithms ──────────────────────────────────────

    public function test_linear_regression_returns_correct_count()
    {
        $predictions = $this->ml->linearRegression([10, 15, 20, 25, 30], 7);
        $this->assertCount(7, $predictions);
    }

    public function test_linear_regression_predicts_upward_trend()
    {
        $ascending = range(10, 50);          // clear upward trend
        $predictions = $this->ml->linearRegression($ascending, 5);
        // The last prediction should exceed the average
        $this->assertGreaterThan(30, end($predictions));
    }

    public function test_exponential_smoothing_returns_correct_count()
    {
        $predictions = $this->ml->exponentialSmoothing([10, 20, 15, 25, 30], 10);
        $this->assertCount(10, $predictions);
    }

    public function test_exponential_smoothing_non_negative()
    {
        $predictions = $this->ml->exponentialSmoothing([0, 0, 1, 0], 5);
        foreach ($predictions as $p) {
            $this->assertGreaterThanOrEqual(0, $p);
        }
    }

    public function test_moving_average_returns_correct_count()
    {
        $predictions = $this->ml->movingAverage([10, 20, 30, 40, 50], 14);
        $this->assertCount(14, $predictions);
    }

    public function test_seasonal_decomposition_returns_correct_count()
    {
        $data = array_merge(
            [10, 15, 12, 18, 20, 16, 14],
            [11, 16, 13, 19, 21, 17, 15]
        );
        $predictions = $this->ml->seasonalDecomposition($data, 7);
        $this->assertCount(7, $predictions);
    }

    // ── Unit: analytics ──────────────────────────────────────────────────

    public function test_confidence_score_is_within_range()
    {
        $score = $this->ml->calculateConfidence(range(10, 50), 30);
        $this->assertGreaterThanOrEqual(30, $score);
        $this->assertLessThanOrEqual(95, $score);
    }

    public function test_trend_direction_up()
    {
        $result = $this->ml->trendDirection(range(10, 50));
        $this->assertStringContainsString('up', $result['direction']);
        $this->assertGreaterThan(0, $result['slope']);
    }

    public function test_trend_direction_stable()
    {
        $result = $this->ml->trendDirection(array_fill(0, 20, 30));
        $this->assertEquals('stable', $result['direction']);
        $this->assertEquals(0, $result['slope']);
    }

    public function test_seasonality_detected_with_enough_data()
    {
        $repeating = [];
        for ($w = 0; $w < 4; $w++) {
            foreach ([10, 50, 10, 10, 10, 50, 10] as $v) {
                $repeating[] = $v;
            }
        }
        $result = $this->ml->detectSeasonality($repeating);
        $this->assertTrue($result['detected']);
        $this->assertEquals(7, $result['period']);
    }

    public function test_seasonality_not_detected_with_insufficient_data()
    {
        $result = $this->ml->detectSeasonality([10, 20, 30]);
        $this->assertFalse($result['detected']);
    }

    public function test_anomaly_detection_finds_spike()
    {
        $data = array_fill(0, 20, 10);
        $data[10] = 1000; // obvious spike
        $anomalies = $this->ml->detectAnomalies($data, 2.0);
        $this->assertNotEmpty($anomalies);
        $spikeFound = collect($anomalies)->where('day_index', 10)->where('type', 'spike')->isNotEmpty();
        $this->assertTrue($spikeFound);
    }

    public function test_anomaly_detection_quiet_data()
    {
        $anomalies = $this->ml->detectAnomalies(array_fill(0, 20, 30));
        $this->assertEmpty($anomalies);
    }

    public function test_compare_models_returns_all_four_models()
    {
        $data       = range(5, 64);   // 60 data points
        $comparison = $this->ml->compareModels($data);

        $this->assertArrayHasKey('models', $comparison);
        $this->assertArrayHasKey('recommended', $comparison);

        foreach (['linear_regression', 'exponential_smoothing', 'moving_average', 'seasonal_decomposition'] as $model) {
            $this->assertArrayHasKey($model, $comparison['models']);
            $this->assertArrayHasKey('mae',      $comparison['models'][$model]);
            $this->assertArrayHasKey('rmse',     $comparison['models'][$model]);
            $this->assertArrayHasKey('mape',     $comparison['models'][$model]);
            $this->assertArrayHasKey('accuracy', $comparison['models'][$model]);
        }
    }

    // ── API endpoint tests ────────────────────────────────────────────────

    public function test_generate_ml_forecast_endpoint()
    {
        $response = $this->postJson('/api/forecasts/ml', [
            'panel_type_id' => $this->panelType->id,
            'horizon_days'  => 14,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'forecast_id',
                'total_predicted_demand',
                'average_daily_demand',
                'current_stock',
                'reorder_quantity',
                'confidence_score',
                'risk_level',
                'trend',
                'seasonality',
                'anomalies_detected',
                'daily_predictions',
                'model_breakdown',
            ]);
    }

    public function test_generate_ml_forecast_returns_correct_prediction_count()
    {
        $response = $this->postJson('/api/forecasts/ml', [
            'panel_type_id' => $this->panelType->id,
            'horizon_days'  => 21,
        ]);

        $response->assertStatus(201);
        $this->assertCount(21, $response['daily_predictions']);
    }

    public function test_compare_models_endpoint()
    {
        $response = $this->postJson('/api/forecasts/ml/compare-models', [
            'panel_type_id' => $this->panelType->id,
            'days'          => 60,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'models',
                    'recommended',
                ]
            ]);
    }

    public function test_anomaly_detection_endpoint()
    {
        $response = $this->getJson('/api/forecasts/ml/anomalies?panel_type_id=' . $this->panelType->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'anomalies',
                    'seasonality',
                    'trend',
                    'confidence',
                    'data_points',
                ]
            ]);
    }

    public function test_record_actual_endpoint()
    {
        // First generate a forecast to record against
        $forecastResponse = $this->postJson('/api/forecasts/ml', [
            'panel_type_id' => $this->panelType->id,
            'horizon_days'  => 14,
        ]);

        $forecastId = $forecastResponse['forecast_id'];

        $response = $this->postJson('/api/forecasts/ml/record-actual', [
            'panel_type_id'   => $this->panelType->id,
            'forecast_id'     => $forecastId,
            'actual_quantity' => 280,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'mape',
                'accuracy',
            ]);
    }

    public function test_model_performance_endpoint()
    {
        $response = $this->getJson('/api/forecasts/ml/performance');

        $response->assertStatus(400); // No records yet — expected
    }

    public function test_model_performance_after_recording()
    {
        $fr = $this->postJson('/api/forecasts/ml', [
            'panel_type_id' => $this->panelType->id,
            'horizon_days'  => 14,
        ]);

        $this->postJson('/api/forecasts/ml/record-actual', [
            'panel_type_id'   => $this->panelType->id,
            'forecast_id'     => $fr['forecast_id'],
            'actual_quantity' => 300,
        ]);

        $response = $this->getJson('/api/forecasts/ml/performance');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'total_records',
                'avg_accuracy',
                'avg_mape',
                'best_accuracy',
                'worst_accuracy',
            ]);
    }

    public function test_ml_forecast_insufficient_data()
    {
        // Create a new panel type with no historical data
        $emptyPanel = PanelType::create([
            'type'       => 'Empty Panel',
            'company_id' => $this->company->id,
        ]);

        $response = $this->postJson('/api/forecasts/ml', [
            'panel_type_id' => $emptyPanel->id,
            'horizon_days'  => 30,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }
}
