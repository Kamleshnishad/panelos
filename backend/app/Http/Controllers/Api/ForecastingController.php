<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ForecastingService;
use App\Services\MlForecastingService;
use Illuminate\Http\Request;

class ForecastingController extends Controller
{
    protected $forecastingService;

    public function __construct(ForecastingService $forecastingService)
    {
        $this->forecastingService = $forecastingService;
    }

    public function generateInventoryForecast(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'nullable|integer',
            'days_ahead' => 'nullable|integer|min:1|max:365'
        ]);

        try {
            $forecasts = $this->forecastingService->generateInventoryForecast(
                auth()->user()->company_id,
                $validated['panel_type_id'] ?? null,
                $validated['days_ahead'] ?? 30
            );

            return response()->json([
                'success' => true,
                'message' => 'Inventory forecasts generated',
                'data' => $forecasts,
                'count' => count($forecasts)
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function generateDemandForecast(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'nullable|integer',
            'forecast_period' => 'nullable|integer|min:1|max:365'
        ]);

        try {
            $forecasts = $this->forecastingService->generateDemandForecast(
                auth()->user()->company_id,
                $validated['panel_type_id'] ?? null,
                $validated['forecast_period'] ?? 30
            );

            return response()->json([
                'success' => true,
                'message' => 'Demand forecasts generated',
                'data' => $forecasts,
                'count' => count($forecasts)
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getDemandForecast(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'nullable|integer'
        ]);

        try {
            $forecasts = $this->forecastingService->getDemandForecast(
                auth()->user()->company_id,
                $validated['panel_type_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $forecasts,
                'count' => $forecasts->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getUpcomingReorders(Request $request)
    {
        $validated = $request->validate([
            'days_ahead' => 'nullable|integer|min:1|max:365'
        ]);

        try {
            $reorders = $this->forecastingService->getUpcomingReorders(
                auth()->user()->company_id,
                $validated['days_ahead'] ?? 30
            );

            return response()->json([
                'success' => true,
                'data' => $reorders->load('panelType'),
                'count' => $reorders->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function generateMlForecast(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'required|integer',
            'horizon_days'  => 'nullable|integer|min:7|max:365',
        ]);

        $companyId = auth()->user()->company_id;

        try {
            $ml     = new MlForecastingService();
            $result = $ml->generateMlForecast(
                $companyId,
                $validated['panel_type_id'],
                $validated['horizon_days'] ?? 30
            );

            return response()->json($result, $result['success'] ? 201 : 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function compareModels(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'required|integer',
            'days'          => 'nullable|integer|min:30|max:365',
        ]);

        $companyId = auth()->user()->company_id;

        try {
            $ml      = new MlForecastingService();
            $history = $ml->getHistoricalSalesData(
                $companyId,
                $validated['panel_type_id'],
                $validated['days'] ?? 90
            );

            if (count($history) < 14) {
                return response()->json(['success' => false, 'message' => 'Need at least 14 days of data'], 400);
            }

            $quantities = array_column($history, 'quantity');
            $comparison = $ml->compareModels($quantities);

            return response()->json(['success' => true, 'data' => $comparison]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getAnomalyDetection(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'required|integer',
            'days'          => 'nullable|integer|min:7|max:365',
            'z_threshold'   => 'nullable|numeric|min:1|max:4',
        ]);

        $companyId = auth()->user()->company_id;

        try {
            $ml      = new MlForecastingService();
            $history = $ml->getHistoricalSalesData(
                $companyId,
                $validated['panel_type_id'],
                $validated['days'] ?? 90
            );

            if (count($history) < 7) {
                return response()->json(['success' => false, 'message' => 'Insufficient data'], 400);
            }

            $quantities = array_column($history, 'quantity');
            $ml2 = new MlForecastingService();

            return response()->json([
                'success'    => true,
                'data'       => [
                    'anomalies'    => $ml2->detectAnomalies($quantities, $validated['z_threshold'] ?? 2.0),
                    'seasonality'  => $ml2->detectSeasonality($quantities),
                    'trend'        => $ml2->trendDirection($quantities),
                    'confidence'   => $ml2->calculateConfidence($quantities, 30),
                    'data_points'  => count($quantities),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function recordActual(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id'   => 'required|integer',
            'forecast_id'     => 'required|integer',
            'actual_quantity' => 'required|numeric|min:0',
        ]);

        $companyId = auth()->user()->company_id;

        try {
            $ml     = new MlForecastingService();
            $result = $ml->recordAccuracy(
                $companyId,
                $validated['panel_type_id'],
                $validated['forecast_id'],
                $validated['actual_quantity']
            );

            return response()->json($result, $result['success'] ? 201 : 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getModelPerformance()
    {
        $companyId = auth()->user()->company_id;

        try {
            $ml     = new MlForecastingService();
            $result = $ml->getModelPerformance($companyId);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
