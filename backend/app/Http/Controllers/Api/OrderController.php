<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderService $orderService) {}

    public function index(Request $request)
    {
        try {
            $filters   = $request->only(['status', 'customer_id', 'search', 'sort_by', 'sort_order']);
            $perPage   = $request->query('per_page', 20);
            $page      = $request->query('page', 1);
            $paginated = $this->orderService->list($request->user()->company_id, $filters)
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->paginatedResponse($paginated->items(), $paginated, 'Orders retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to list orders', 'ORDER_LIST_ERROR', 500);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $order = Order::where('company_id', $request->user()->company_id)->findOrFail($id);
            return $this->successResponse($this->orderService->getDetails($order), 'Order retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Order not found', 'NOT_FOUND', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to retrieve order', 'ORDER_SHOW_ERROR', 500);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $order     = Order::where('company_id', $request->user()->company_id)->findOrFail($id);
            $validated = $request->validate([
                'expected_delivery_date' => 'nullable|date',
                'notes'                  => 'nullable|string|max:1000',
                'status'                 => 'nullable|in:pending,in_production,completed,cancelled',
            ]);

            $order = $this->orderService->update($order, $validated);
            return $this->successResponse($order, 'Order updated');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse([], 'Order not found', 'NOT_FOUND', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'ORDER_UPDATE_ERROR', 400);
        }
    }
}
