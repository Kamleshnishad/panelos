<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private DashboardService $dashboardService) {}

    public function index(Request $request)
    {
        try {
            $data = $this->dashboardService->getDashboard($request->user()->company_id);
            return $this->successResponse($data, 'Dashboard retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to load dashboard', 'DASHBOARD_ERROR', 500);
        }
    }
}
