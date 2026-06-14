<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Login - POST /auth/login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                $validator->errors()->toArray(),
                'Validation failed',
                'VALIDATION_ERROR',
                422
            );
        }

        $user = User::where('email', $request->email)
            ->where('is_active', true)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse(
                ['email' => ['Invalid credentials']],
                'Invalid email or password',
                'INVALID_CREDENTIALS',
                401
            );
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $user->update(['last_login_at' => now()]);

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'company_id' => $user->company_id,
                'is_super_admin' => $user->is_super_admin,
                'is_company_admin' => $user->is_company_admin,
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Login successful', 200);
    }

    /**
     * Logout - POST /auth/logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // Delete current token if available
        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $currentToken->delete();
        } else {
            // Fallback: delete all tokens for this user
            $user->tokens()->delete();
        }

        return $this->successResponse(null, 'Logout successful', 200);
    }

    /**
     * Get current user - GET /auth/me
     */
    public function me(Request $request)
    {
        $user    = $request->user();
        $company = $user->company;

        return $this->successResponse([
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'phone'            => $user->phone,
            'company_id'       => $user->company_id,
            'is_super_admin'   => $user->is_super_admin,
            'is_company_admin' => $user->is_company_admin,
            'is_active'        => $user->is_active,
            'last_login_at'    => $user->last_login_at,
            'company'          => $company ? [
                'id'           => $company->id,
                'name'         => $company->name,
                'gstin'        => $company->gstin,
                'state_code'   => $company->state_code,
                'quotation_prefix' => $company->quotation_prefix ?? 'SCP',
            ] : null,
        ], 'Current user retrieved', 200);
    }

    /**
     * Refresh token - POST /auth/refresh-token
     */
    public function refreshToken(Request $request)
    {
        $user = $request->user();

        // Delete old token if it exists
        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $currentToken->delete();
        }

        // Create new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Token refreshed successfully', 200);
    }

    /**
     * Change password - POST /auth/change-password
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string|min:6',
            'new_password' => 'required|string|min:6|confirmed',
            'new_password_confirmation' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                $validator->errors()->toArray(),
                'Validation failed',
                'VALIDATION_ERROR',
                422
            );
        }

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->errorResponse(
                ['current_password' => ['Current password is incorrect']],
                'Current password verification failed',
                'PASSWORD_MISMATCH',
                401
            );
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return $this->successResponse(null, 'Password changed successfully', 200);
    }
}
