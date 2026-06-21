<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.active' => \App\Http\Middleware\EnsureActiveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'error_code' => 'UNAUTHENTICATED',
                    'meta' => [
                        'timestamp' => now()->toIso8601String(),
                        'version' => '1.0',
                    ],
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->expectsJson()) {
                // Surface the FIRST specific field error as the message so every
                // form shows the real reason (not a generic "Validation failed").
                $first = collect($e->errors())->flatten()->first();
                return response()->json([
                    'success' => false,
                    'errors' => $e->errors(),
                    'message' => $first ?: 'Validation failed',
                    'error_code' => 'VALIDATION_ERROR',
                    'meta' => [
                        'timestamp' => now()->toIso8601String(),
                        'version' => '1.0',
                    ],
                ], 422);
            }
        });
    })->create();
