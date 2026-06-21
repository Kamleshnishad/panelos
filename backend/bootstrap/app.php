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
            'super.admin'   => \App\Http\Middleware\EnsureSuperAdmin::class,
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

        // Catch-all: turn any other uncaught throwable into a clean JSON envelope
        // with a reference id, instead of a bare "Server Error" 500 (OPS-H5).
        // Exceptions that already map to a proper HTTP status are passed through.
        $exceptions->render(function (\Throwable $e, $request) {
            if (!$request->expectsJson()) {
                return null;
            }
            $passthrough = [
                \Illuminate\Validation\ValidationException::class,
                \Illuminate\Auth\AuthenticationException::class,
                \Illuminate\Auth\Access\AuthorizationException::class,
                \Illuminate\Database\Eloquent\ModelNotFoundException::class,
                \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface::class,
            ];
            foreach ($passthrough as $type) {
                if ($e instanceof $type) {
                    return null; // let the framework's own handler set the right status
                }
            }
            if (config('app.debug')) {
                return null; // keep Laravel's detailed trace locally
            }

            $ref = substr(md5(uniqid('', true)), 0, 8);
            \Illuminate\Support\Facades\Log::error("[{$ref}] " . $e->getMessage(), ['exception' => $e]);

            return response()->json([
                'success'    => false,
                'message'    => 'Something went wrong on our end. Please retry, or contact support with reference ' . $ref . '.',
                'error_code' => 'SERVER_ERROR',
                'reference'  => $ref,
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'version' => '1.0',
                ],
            ], 500);
        });
    })->create();
