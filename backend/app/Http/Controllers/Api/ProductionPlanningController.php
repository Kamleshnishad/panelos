<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductionPlanningService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ProductionPlanningController extends Controller
{
    use ApiResponse;

    public function __construct(private ProductionPlanningService $planning) {}

    /** Advisory production plan: grouped runs + "take this first" alerts. */
    public function index(Request $request)
    {
        try {
            $plan = $this->planning->getPlan($request->user()->company_id);
            return $this->successResponse($plan, 'Production plan generated');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to build production plan', 'PLAN_ERROR', 500);
        }
    }
}
