<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Standard JSON envelope used by Stock/Dispatch controllers.
     * Shape: { success, data, message }
     */
    protected function apiResponse(bool $success, $data = null, string $message = '', int $statusCode = 200)
    {
        return response()->json([
            'success' => $success,
            'data'    => $data,
            'message' => $message,
        ], $statusCode);
    }
}
