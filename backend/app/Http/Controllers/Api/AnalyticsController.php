<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    protected $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    public function recordSalesMetric(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'nullable|integer',
            'metric_date' => 'nullable|date'
        ]);

        try {
            $metrics = $this->analyticsService->recordSalesMetric(
                auth()->user()->company_id,
                $validated['panel_type_id'] ?? null,
                $validated['metric_date'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Sales metrics recorded',
                'data' => $metrics,
                'count' => $metrics->count()
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function generateTrendAnalysis(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'nullable|integer',
            'period_days' => 'nullable|integer|min:7|max:365'
        ]);

        try {
            $analyses = $this->analyticsService->generateTrendAnalysis(
                auth()->user()->company_id,
                $validated['panel_type_id'] ?? null,
                $validated['period_days'] ?? 30
            );

            return response()->json([
                'success' => true,
                'message' => 'Trend analyses generated',
                'data' => $analyses,
                'count' => count($analyses)
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getTrendAnalysis(Request $request)
    {
        $validated = $request->validate([
            'panel_type_id' => 'nullable|integer',
            'period_days' => 'nullable|integer|min:7|max:365'
        ]);

        try {
            $analyses = $this->analyticsService->getTrendAnalysis(
                auth()->user()->company_id,
                $validated['panel_type_id'] ?? null,
                $validated['period_days'] ?? 30
            );

            return response()->json([
                'success' => true,
                'data' => $analyses,
                'count' => $analyses->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function createSnapshot()
    {
        try {
            $snapshot = $this->analyticsService->createAnalyticsSnapshot(auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'message' => 'Analytics snapshot created',
                'data' => $snapshot
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getSnapshot(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date'
        ]);

        try {
            $snapshot = $this->analyticsService->getAnalyticsSnapshot(
                auth()->user()->company_id,
                $validated['date'] ?? null
            );

            if (!$snapshot) {
                return response()->json([
                    'success' => false,
                    'message' => 'No snapshot found for the given date'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $snapshot
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
