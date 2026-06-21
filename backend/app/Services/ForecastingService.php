<?php

namespace App\Services;

use App\Models\SalesMetric;
use App\Models\InventoryForecast;
use App\Models\DemandForecast;
use App\Models\PanelType;
use Illuminate\Support\Facades\DB;

class ForecastingService
{
    protected $salesService;

    public function __construct(StockService $salesService)
    {
        $this->salesService = $salesService;
    }

    public function generateInventoryForecast($companyId, $panelTypeId = null, $daysAhead = 30)
    {
        return DB::transaction(function () use ($companyId, $panelTypeId, $daysAhead) {
            $panelTypes = $panelTypeId
                ? PanelType::where('id', $panelTypeId)->get()
                : PanelType::where('company_id', $companyId)->get();

            $today = now();
            $nowTs = $today->toDateTimeString();
            $rows  = [];

            foreach ($panelTypes as $panelType) {
                $historicalData = $this->getHistoricalSalesData($companyId, $panelType->id, 90);

                if (empty($historicalData)) {
                    continue;
                }

                for ($i = 1; $i <= $daysAhead; $i++) {
                    $prediction = $this->predictUsingMovingAverage($historicalData, $i);

                    $rows[] = [
                        'company_id' => $companyId,
                        'panel_type_id' => $panelType->id,
                        'forecast_date' => $today->toDateString(),
                        'forecast_for_date' => $today->copy()->addDays($i)->toDateString(),
                        'predicted_quantity' => $prediction['quantity'],
                        'forecast_type' => 'moderate',
                        'confidence_score' => $prediction['confidence'],
                        'method' => 'moving_average',
                        'notes' => 'Auto-generated forecast',
                        'created_at' => $nowTs,
                        'updated_at' => $nowTs,
                    ];
                }
            }

            // One bulk insert (chunked) instead of up to daysAhead×panelTypes
            // individual INSERTs in the transaction (SCALE-H4b).
            foreach (array_chunk($rows, 500) as $chunk) {
                InventoryForecast::insert($chunk);
            }

            return $rows;
        });
    }

    public function generateDemandForecast($companyId, $panelTypeId = null, $forecastPeriod = 30)
    {
        return DB::transaction(function () use ($companyId, $panelTypeId, $forecastPeriod) {
            $panelTypes = $panelTypeId
                ? PanelType::where('id', $panelTypeId)->get()
                : PanelType::where('company_id', $companyId)->get();

            $forecasts = [];
            $today = now();

            foreach ($panelTypes as $panelType) {
                $historicalData = $this->getHistoricalSalesData($companyId, $panelType->id, 180);
                $currentStock = $this->getCurrentStock($companyId, $panelType->id);

                if (empty($historicalData)) {
                    continue;
                }

                $avgDailySales = array_sum(array_column($historicalData, 'quantity')) / count($historicalData);
                $predictedDemand = (int)($avgDailySales * $forecastPeriod);

                $seasonalFactor = $this->calculateSeasonalFactor($historicalData);
                $trendStrength = $this->calculateTrendStrength($historicalData);

                $reorderQuantity = $this->calculateReorderQuantity($avgDailySales, $forecastPeriod);
                $riskLevel = $this->assessRiskLevel($currentStock, $predictedDemand);

                $recommendedOrderDate = $today->addDays(
                    $this->daysUntilReorder($currentStock, $avgDailySales)
                );

                $forecast = DemandForecast::create([
                    'company_id' => $companyId,
                    'panel_type_id' => $panelType->id,
                    'forecast_date' => $today,
                    'forecast_period_days' => $forecastPeriod,
                    'predicted_demand' => $predictedDemand,
                    'current_stock' => $currentStock,
                    'reorder_quantity' => $reorderQuantity,
                    'recommended_order_date' => $recommendedOrderDate,
                    'seasonal_factor' => $seasonalFactor,
                    'trend_strength' => $trendStrength,
                    'risk_level' => $riskLevel
                ]);

                $forecasts[] = $forecast;
            }

            return $forecasts;
        });
    }

    public function getHistoricalSalesData($companyId, $panelTypeId, $days)
    {
        return SalesMetric::where('company_id', $companyId)
            ->where('panel_type_id', $panelTypeId)
            ->where('metric_date', '>=', now()->subDays($days))
            ->orderBy('metric_date')
            ->get(['metric_date', 'quantity_sold'])
            ->map(function ($metric) {
                return [
                    'date' => $metric->metric_date,
                    'quantity' => $metric->quantity_sold
                ];
            })
            ->toArray();
    }

    protected function predictUsingMovingAverage($data, $daysAhead, $window = 7)
    {
        $avg = array_sum(array_column($data, 'quantity')) / count($data);
        $variance = $this->calculateVariance(array_column($data, 'quantity'));

        // Confidence decreases as we forecast further into the future
        $confidence = max(50, 95 - ($daysAhead * 2));

        return [
            'quantity' => (int)$avg,
            'confidence' => $confidence
        ];
    }

    protected function calculateSeasonalFactor($data)
    {
        if (count($data) < 7) {
            return 1.0;
        }

        $firstWeek = array_slice(array_column($data, 'quantity'), 0, 7);
        $lastWeek = array_slice(array_column($data, 'quantity'), -7);

        $firstAvg = array_sum($firstWeek) / count($firstWeek);
        $lastAvg = array_sum($lastWeek) / count($lastWeek);

        if ($firstAvg == 0) {
            return 1.0;
        }

        return round($lastAvg / $firstAvg, 2);
    }

    protected function calculateTrendStrength($data)
    {
        $quantities = array_column($data, 'quantity');

        if (count($quantities) < 2) {
            return 0;
        }

        $n = count($quantities);
        $xMean = ($n - 1) / 2;
        $yMean = array_sum($quantities) / $n;

        $numerator = 0;
        $denominator = 0;

        for ($i = 0; $i < $n; $i++) {
            $numerator += ($i - $xMean) * ($quantities[$i] - $yMean);
            $denominator += ($i - $xMean) ** 2;
        }

        if ($denominator == 0) {
            return 0;
        }

        $slope = $numerator / $denominator;
        $maxChange = max($quantities) - min($quantities);

        if ($maxChange == 0) {
            return 0;
        }

        return round(max(-1, min(1, $slope / $maxChange)), 2);
    }

    protected function calculateVariance($data)
    {
        $avg = array_sum($data) / count($data);
        $squaredDiffs = array_map(function ($value) use ($avg) {
            return ($value - $avg) ** 2;
        }, $data);

        return array_sum($squaredDiffs) / count($squaredDiffs);
    }

    protected function calculateReorderQuantity($avgDailySales, $forecastPeriod)
    {
        // Order 1.5x the forecasted demand to maintain buffer stock
        return (int)($avgDailySales * $forecastPeriod * 1.5);
    }

    protected function assessRiskLevel($currentStock, $predictedDemand)
    {
        $ratio = $predictedDemand > 0 ? $currentStock / $predictedDemand : 1;

        if ($ratio > 1.5) {
            return 'low';
        } elseif ($ratio > 0.5) {
            return 'medium';
        } else {
            return 'high';
        }
    }

    protected function daysUntilReorder($currentStock, $avgDailySales)
    {
        if ($avgDailySales <= 0) {
            return 30;
        }

        $daysOfStock = $currentStock / $avgDailySales;
        $reorderPoint = 14; // Reorder when 14 days of stock remains

        return max(1, (int)($daysOfStock - $reorderPoint));
    }

    protected function getCurrentStock($companyId, $panelTypeId)
    {
        return DB::table('coil_stocks')
            ->where('company_id', $companyId)
            ->where('panel_type_id', $panelTypeId)
            ->sum('quantity_in_stock') ?? 0;
    }

    public function getDemandForecast($companyId, $panelTypeId = null)
    {
        $query = DemandForecast::where('company_id', $companyId)
            ->where('forecast_date', now()->toDateString());

        if ($panelTypeId) {
            $query->where('panel_type_id', $panelTypeId);
        }

        return $query->get();
    }

    public function getUpcomingReorders($companyId, $daysAhead = 30)
    {
        return DemandForecast::where('company_id', $companyId)
            ->whereBetween('recommended_order_date', [now(), now()->addDays($daysAhead)])
            ->where('risk_level', '!=', 'low')
            ->orderBy('recommended_order_date')
            ->with('panelType')
            ->get();
    }
}
