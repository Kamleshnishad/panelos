<?php

namespace App\Services;

use App\Models\SalesMetric;
use App\Models\DemandForecast;
use App\Models\InventoryForecast;
use App\Models\ForecastAccuracy;
use App\Models\PanelType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MlForecastingService
{
    // Weights for ensemble model
    protected $modelWeights = [
        'linear_regression' => 0.35,
        'exponential_smoothing' => 0.35,
        'moving_average' => 0.20,
        'seasonal_decomposition' => 0.10,
    ];

    public function generateMlForecast($companyId, $panelTypeId, $horizonDays = 30)
    {
        $history = $this->getHistory($companyId, $panelTypeId, 180);

        if (count($history) < 7) {
            return ['success' => false, 'message' => 'Insufficient historical data (need at least 7 days)'];
        }

        $quantities = array_column($history, 'quantity');

        // Run all models
        $lr      = $this->linearRegression($quantities, $horizonDays);
        $es      = $this->exponentialSmoothing($quantities, $horizonDays);
        $ma      = $this->movingAverage($quantities, $horizonDays);
        $sd      = $this->seasonalDecomposition($quantities, $horizonDays);

        // Ensemble predictions (weighted average)
        $ensemble = [];
        for ($i = 0; $i < $horizonDays; $i++) {
            $ensemble[] = max(0, round(
                ($lr[$i]  ?? 0) * $this->modelWeights['linear_regression']     +
                ($es[$i]  ?? 0) * $this->modelWeights['exponential_smoothing']  +
                ($ma[$i]  ?? 0) * $this->modelWeights['moving_average']         +
                ($sd[$i]  ?? 0) * $this->modelWeights['seasonal_decomposition']
            ));
        }

        $totalDemand   = array_sum($ensemble);
        $avgDaily      = count($ensemble) > 0 ? array_sum($ensemble) / count($ensemble) : 0;
        $confidence    = $this->calculateConfidence($quantities, $horizonDays);
        $trendDir      = $this->trendDirection($quantities);
        $seasonality   = $this->detectSeasonality($quantities);
        $anomalies     = $this->detectAnomalies($quantities);
        $currentStock  = $this->getCurrentStock($companyId, $panelTypeId);
        $riskLevel     = $this->assessRisk($currentStock, $totalDemand);
        $reorderQty    = $this->reorderQuantity($avgDaily, $horizonDays);
        $reorderDate   = $this->reorderDate($currentStock, $avgDaily);

        $forecast = DemandForecast::create([
            'company_id'           => $companyId,
            'panel_type_id'        => $panelTypeId,
            'forecast_date'        => now(),
            'forecast_period_days' => $horizonDays,
            'predicted_demand'     => $totalDemand,
            'current_stock'        => $currentStock,
            'reorder_quantity'     => $reorderQty,
            'recommended_order_date' => $reorderDate,
            'seasonal_factor'      => $seasonality['strength'],
            'trend_strength'       => $trendDir['slope'],
            'risk_level'           => $riskLevel,
        ]);

        return [
            'success'             => true,
            'forecast_id'         => $forecast->id,
            'panel_type_id'       => $panelTypeId,
            'horizon_days'        => $horizonDays,
            'total_predicted_demand' => $totalDemand,
            'average_daily_demand' => round($avgDaily, 2),
            'current_stock'       => $currentStock,
            'reorder_quantity'    => $reorderQty,
            'recommended_order_date' => $reorderDate?->toDateString(),
            'confidence_score'    => $confidence,
            'risk_level'          => $riskLevel,
            'trend'               => $trendDir,
            'seasonality'         => $seasonality,
            'anomalies_detected'  => count($anomalies),
            'anomaly_dates'       => $anomalies,
            'daily_predictions'   => $ensemble,
            'model_breakdown'     => [
                'linear_regression'      => array_sum($lr),
                'exponential_smoothing'  => array_sum($es),
                'moving_average'         => array_sum($ma),
                'seasonal_decomposition' => array_sum($sd),
                'ensemble'               => $totalDemand,
            ],
        ];
    }

    // ── Models ─────────────────────────────────────────────────────────────

    public function linearRegression(array $quantities, int $horizonDays): array
    {
        $n     = count($quantities);
        $xMean = ($n - 1) / 2;
        $yMean = array_sum($quantities) / $n;

        $num = $den = 0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($i - $xMean) * ($quantities[$i] - $yMean);
            $den += ($i - $xMean) ** 2;
        }

        $slope     = $den != 0 ? $num / $den : 0;
        $intercept = $yMean - $slope * $xMean;

        $predictions = [];
        for ($i = 1; $i <= $horizonDays; $i++) {
            $predictions[] = max(0, round($intercept + $slope * ($n - 1 + $i)));
        }

        return $predictions;
    }

    public function exponentialSmoothing(array $quantities, int $horizonDays, float $alpha = 0.3): array
    {
        $smoothed = $quantities[0];
        foreach ($quantities as $q) {
            $smoothed = $alpha * $q + (1 - $alpha) * $smoothed;
        }

        $predictions = [];
        for ($i = 0; $i < $horizonDays; $i++) {
            $predictions[] = max(0, round($smoothed));
        }

        return $predictions;
    }

    public function movingAverage(array $quantities, int $horizonDays, int $window = 7): array
    {
        $recent = array_slice($quantities, -min($window, count($quantities)));
        $avg    = array_sum($recent) / count($recent);

        $predictions = [];
        for ($i = 0; $i < $horizonDays; $i++) {
            $predictions[] = max(0, round($avg));
        }

        return $predictions;
    }

    public function seasonalDecomposition(array $quantities, int $horizonDays, int $period = 7): array
    {
        $n = count($quantities);
        if ($n < $period * 2) {
            return $this->movingAverage($quantities, $horizonDays);
        }

        // Compute seasonal indices
        $seasonalIndices = [];
        for ($p = 0; $p < $period; $p++) {
            $vals = [];
            for ($i = $p; $i < $n; $i += $period) {
                $vals[] = $quantities[$i];
            }
            $seasonalIndices[$p] = count($vals) > 0 ? array_sum($vals) / count($vals) : 1;
        }

        $baselineAvg = array_sum($quantities) / $n;

        $predictions = [];
        for ($i = 0; $i < $horizonDays; $i++) {
            $idx    = ($n + $i) % $period;
            $factor = $baselineAvg > 0 ? ($seasonalIndices[$idx] / $baselineAvg) : 1;
            $predictions[] = max(0, round($baselineAvg * $factor));
        }

        return $predictions;
    }

    // ── Analytics helpers ───────────────────────────────────────────────────

    public function calculateConfidence(array $quantities, int $horizonDays): float
    {
        $n = count($quantities);
        // Coefficient of variation (stability)
        $mean = array_sum($quantities) / $n;
        if ($mean == 0) return 50.0;

        $variance = array_sum(array_map(fn($q) => ($q - $mean) ** 2, $quantities)) / $n;
        $cv       = sqrt($variance) / $mean;

        // More data → higher base confidence; further horizon → lower
        $dataBonus    = min(20, $n / 5);
        $horizonPenalty = min(20, $horizonDays / 3);

        return round(max(30, min(95, 80 - ($cv * 30) + $dataBonus - $horizonPenalty)), 1);
    }

    public function trendDirection(array $quantities): array
    {
        $n     = count($quantities);
        $xMean = ($n - 1) / 2;
        $yMean = array_sum($quantities) / $n;

        $num = $den = 0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($i - $xMean) * ($quantities[$i] - $yMean);
            $den += ($i - $xMean) ** 2;
        }

        $slope = $den != 0 ? $num / $den : 0;

        $direction = match(true) {
            $slope >  0.5 => 'strong_up',
            $slope >  0.1 => 'up',
            $slope < -0.5 => 'strong_down',
            $slope < -0.1 => 'down',
            default       => 'stable',
        };

        $pctChange = $yMean != 0 ? round(($slope / $yMean) * 100, 2) : 0;

        return [
            'slope'      => round($slope, 4),
            'direction'  => $direction,
            'pct_change' => $pctChange,
        ];
    }

    public function detectSeasonality(array $quantities, int $period = 7): array
    {
        $n = count($quantities);
        if ($n < $period * 2) {
            return ['detected' => false, 'period' => null, 'strength' => 0.0];
        }

        $indices = [];
        for ($p = 0; $p < $period; $p++) {
            $vals = [];
            for ($i = $p; $i < $n; $i += $period) {
                $vals[] = $quantities[$i];
            }
            $indices[$p] = count($vals) > 0 ? array_sum($vals) / count($vals) : 0;
        }

        $mean = array_sum($indices) / count($indices);
        if ($mean == 0) {
            return ['detected' => false, 'period' => null, 'strength' => 0.0];
        }

        $variance  = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $indices)) / count($indices);
        $strength  = round(sqrt($variance) / $mean, 3);
        $detected  = $strength > 0.1;

        return [
            'detected'        => $detected,
            'period'          => $detected ? $period : null,
            'strength'        => $strength,
            'seasonal_indices' => array_values($indices),
        ];
    }

    public function detectAnomalies(array $quantities, float $zThreshold = 2.0): array
    {
        $n    = count($quantities);
        $mean = array_sum($quantities) / $n;
        $std  = sqrt(array_sum(array_map(fn($q) => ($q - $mean) ** 2, $quantities)) / $n);

        if ($std == 0) return [];

        $anomalies = [];
        foreach ($quantities as $i => $q) {
            $z = abs(($q - $mean) / $std);
            if ($z > $zThreshold) {
                $anomalies[] = [
                    'day_index' => $i,
                    'quantity'  => $q,
                    'z_score'   => round($z, 2),
                    'type'      => $q > $mean ? 'spike' : 'dip',
                ];
            }
        }

        return $anomalies;
    }

    public function compareModels(array $quantities): array
    {
        $n          = count($quantities);
        $trainSize  = (int)($n * 0.8);
        $train      = array_slice($quantities, 0, $trainSize);
        $actual     = array_slice($quantities, $trainSize);
        $horizon    = count($actual);

        $models = [
            'linear_regression'     => $this->linearRegression($train, $horizon),
            'exponential_smoothing' => $this->exponentialSmoothing($train, $horizon),
            'moving_average'        => $this->movingAverage($train, $horizon),
            'seasonal_decomposition'=> $this->seasonalDecomposition($train, $horizon),
        ];

        $results = [];
        foreach ($models as $name => $predicted) {
            $results[$name] = [
                'mae'      => $this->mae($actual, $predicted),
                'rmse'     => $this->rmse($actual, $predicted),
                'mape'     => $this->mape($actual, $predicted),
                'accuracy' => round(max(0, 100 - $this->mape($actual, $predicted)), 1),
            ];
        }

        // Best model by lowest MAPE
        $best = array_keys($results)[0];
        foreach ($results as $name => $metrics) {
            if ($metrics['mape'] < $results[$best]['mape']) {
                $best = $name;
            }
        }

        return ['models' => $results, 'recommended' => $best];
    }

    public function recordAccuracy($companyId, $panelTypeId, $forecastId, $actualQuantity): array
    {
        $forecast = DemandForecast::find($forecastId);
        if (!$forecast) {
            return ['success' => false, 'message' => 'Forecast not found'];
        }

        $predictedPerDay = $forecast->forecast_period_days > 0
            ? $forecast->predicted_demand / $forecast->forecast_period_days
            : 0;

        $mape = $predictedPerDay > 0
            ? abs(($actualQuantity - $predictedPerDay) / $predictedPerDay) * 100
            : 100;

        ForecastAccuracy::create([
            'company_id'        => $companyId,
            'panel_type_id'     => $panelTypeId,
            'demand_forecast_id'=> $forecastId,
            'predicted_quantity'=> $predictedPerDay,
            'actual_quantity'   => $actualQuantity,
            'mape'              => round($mape, 2),
            'accuracy_score'    => round(max(0, 100 - $mape), 2),
        ]);

        return ['success' => true, 'mape' => $mape, 'accuracy' => max(0, 100 - $mape)];
    }

    public function getModelPerformance($companyId): array
    {
        $records = ForecastAccuracy::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        if ($records->isEmpty()) {
            return ['success' => false, 'message' => 'No accuracy records yet'];
        }

        return [
            'success'        => true,
            'total_records'  => $records->count(),
            'avg_accuracy'   => round($records->avg('accuracy_score'), 1),
            'avg_mape'       => round($records->avg('mape'), 2),
            'best_accuracy'  => round($records->max('accuracy_score'), 1),
            'worst_accuracy' => round($records->min('accuracy_score'), 1),
        ];
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    protected function getHistory($companyId, $panelTypeId, $days): array
    {
        return SalesMetric::where('company_id', $companyId)
            ->where('panel_type_id', $panelTypeId)
            ->where('metric_date', '>=', now()->subDays($days))
            ->orderBy('metric_date')
            ->pluck('quantity_sold', 'metric_date')
            ->map(fn($q) => ['quantity' => (int)$q])
            ->values()
            ->toArray();
    }

    protected function getCurrentStock($companyId, $panelTypeId): int
    {
        return (int)DB::table('coil_stocks')
            ->where('company_id', $companyId)
            ->where('panel_type_id', $panelTypeId)
            ->sum('quantity');
    }

    protected function assessRisk($stock, $demand): string
    {
        $ratio = $demand > 0 ? $stock / $demand : 2;
        return match(true) {
            $ratio > 1.5 => 'low',
            $ratio > 0.5 => 'medium',
            default      => 'high',
        };
    }

    protected function reorderQuantity($avgDaily, $horizonDays): int
    {
        return (int)($avgDaily * $horizonDays * 1.5);
    }

    protected function reorderDate($stock, $avgDaily)
    {
        if ($avgDaily <= 0) return now()->addDays(30);
        $daysLeft = $stock / $avgDaily;
        return now()->addDays(max(1, (int)($daysLeft - 14)));
    }

    protected function mae(array $actual, array $predicted): float
    {
        $n = min(count($actual), count($predicted));
        if ($n == 0) return 0;
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum += abs($actual[$i] - ($predicted[$i] ?? 0));
        }
        return round($sum / $n, 2);
    }

    protected function rmse(array $actual, array $predicted): float
    {
        $n = min(count($actual), count($predicted));
        if ($n == 0) return 0;
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum += ($actual[$i] - ($predicted[$i] ?? 0)) ** 2;
        }
        return round(sqrt($sum / $n), 2);
    }

    protected function mape(array $actual, array $predicted): float
    {
        $n = min(count($actual), count($predicted));
        if ($n == 0) return 100;
        $sum = 0;
        $counted = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($actual[$i] != 0) {
                $sum += abs(($actual[$i] - ($predicted[$i] ?? 0)) / $actual[$i]) * 100;
                $counted++;
            }
        }
        return $counted > 0 ? round($sum / $counted, 2) : 100;
    }
}
