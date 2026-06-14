<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Success response
     */
    protected function successResponse($data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'version' => '1.0',
            ],
        ], $code);
    }

    /**
     * Error response
     */
    protected function errorResponse(array $errors = [], string $message = 'Error occurred', string $errorCode = 'ERROR', int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'errors' => $errors,
            'message' => $message,
            'error_code' => $errorCode,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'version' => '1.0',
            ],
        ], $code);
    }

    /**
     * Paginated response
     */
    protected function paginatedResponse($items, $paginated, string $message = 'Success', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $items,
            'message' => $message,
            'meta' => [
                'pagination' => [
                    'total' => $paginated->total(),
                    'count' => count($items),
                    'per_page' => $paginated->perPage(),
                    'current_page' => $paginated->currentPage(),
                ],
                'timestamp' => now()->toIso8601String(),
                'version' => '1.0',
            ],
        ], $code);
    }

    /**
     * Created response (201)
     */
    protected function createdResponse($data, string $message = 'Resource created successfully'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * No content response (204)
     */
    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
