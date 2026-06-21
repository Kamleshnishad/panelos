<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Defense-in-depth gate for the platform-owner (super-admin) area.
 * The controller methods already call requireSuperAdmin(), but enforcing it
 * at the route group means a newly-added admin method can never be exposed
 * to a normal tenant by a forgotten in-method check.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->is_super_admin) {
            return response()->json([
                'success'    => false,
                'message'    => 'Super admin only.',
                'error_code' => 'FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}
