<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    use ApiResponse;

    /** Shared password policy for setting/changing a password (min 8, letters + numbers). */
    public static function passwordPolicy(): Password
    {
        return Password::min(8)->letters()->numbers();
    }

    /**
     * Login - POST /auth/login
     */
    /**
     * Self-signup — POST /auth/register (public). Provisions a new tenant
     * (company on a 14-day trial) + first admin user, returns an auth token.
     */
    public function register(Request $request, \App\Services\TenantProvisioningService $provisioner)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:150',
            'name'         => 'required|string|max:100',
            // Email must be globally unique (one email = one tenant) so login stays unambiguous
            'email'        => 'required|email|max:150|unique:users,email',
            'password'     => ['required', 'confirmed', self::passwordPolicy()],
            'phone'        => 'nullable|string|max:20',
            'utm_source'   => 'nullable|string|max:60',
            'utm_medium'   => 'nullable|string|max:60',
            'utm_campaign' => 'nullable|string|max:80',
            'signup_referrer' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->toArray(), 'Validation failed', 'VALIDATION_ERROR', 422);
        }

        try {
            $result = $provisioner->provision($validator->validated());
            $user   = $result['user'];
            $token  = $user->createToken('auth_token')->plainTextToken;
            $user->update(['last_login_at' => now()]);

            return $this->successResponse([
                'user' => [
                    'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'company_id' => $user->company_id,
                    'is_super_admin' => $user->is_super_admin, 'is_company_admin' => $user->is_company_admin,
                ],
                'company' => [
                    'id' => $result['company']->id, 'name' => $result['company']->name,
                    'subscription_status' => $result['company']->subscription_status,
                    'trial_ends_at' => $result['company']->trial_ends_at,
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ], 'Account created — welcome to your 14-day trial!', 201);
        } catch (\Throwable $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Could not create account: ' . $e->getMessage(), 'SIGNUP_ERROR', 400);
        }
    }

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

        // Two-factor (email OTP): if enabled, don't issue a token yet — send a code.
        if ($user->two_factor_enabled) {
            $this->sendOtp($user);
            return $this->successResponse([
                'needs_2fa' => true,
                'email'     => $user->email,
            ], 'A verification code has been sent to your email.', 200);
        }

        return $this->issueLogin($user);
    }

    /** Issue the auth token + standard login payload. */
    private function issueLogin(User $user)
    {
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

    /** Generate, store (hashed) and email a 6-digit OTP. Also logged for dev. */
    private function sendOtp(User $user): void
    {
        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'two_factor_code'       => Hash::make($code),
            'two_factor_expires_at' => now()->addMinutes(10),
            'two_factor_attempts'   => 0,   // fresh code → reset the failure counter (SEC-H2)
        ])->save();

        $body = "Your PanelOS verification code is: {$code}\nIt expires in 10 minutes.";
        try {
            \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($user) {
                $m->to($user->email)->subject('PanelOS — Your login code');
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('2FA email send failed', ['error' => $e->getMessage()]);
        }
        // Dev-only fallback: surface the code in the log so local login works without SMTP.
        // NEVER log the code in production — anyone with log access could bypass 2FA.
        if (app()->environment('local')) {
            \Illuminate\Support\Facades\Log::info("2FA code for {$user->email}: {$code}");
        }
    }

    /** Step 2 of login — verify the OTP and issue the token. */
    public function verifyOtp(Request $request)
    {
        $request->validate(['email' => 'required|email', 'code' => 'required|string']);

        $user = User::where('email', $request->email)->where('is_active', true)->first();

        // No active/pending code (or expired) → generic failure (don't leak which).
        if (!$user || !$user->two_factor_code || !$user->two_factor_expires_at || $user->two_factor_expires_at->isPast()) {
            return $this->errorResponse(['code' => ['Invalid or expired code']], 'Invalid or expired code', 'OTP_INVALID', 401);
        }

        // Wrong code → count the attempt; after 5 fails, invalidate the code so a
        // brute-forcer must sign in again to get a fresh one (SEC-H2).
        if (!Hash::check($request->code, $user->two_factor_code)) {
            $attempts = (int) ($user->two_factor_attempts ?? 0) + 1;
            if ($attempts >= 5) {
                $user->forceFill(['two_factor_code' => null, 'two_factor_expires_at' => null, 'two_factor_attempts' => 0])->save();
                return $this->errorResponse(['code' => ['Too many incorrect attempts. Please sign in again to get a new code.']], 'Too many incorrect attempts. Please sign in again to get a new code.', 'OTP_LOCKED', 429);
            }
            $user->forceFill(['two_factor_attempts' => $attempts])->save();
            return $this->errorResponse(['code' => ['Invalid or expired code']], 'Invalid or expired code', 'OTP_INVALID', 401);
        }

        $user->forceFill(['two_factor_code' => null, 'two_factor_expires_at' => null, 'two_factor_attempts' => 0])->save();
        return $this->issueLogin($user);
    }

    /** Enable/disable 2FA for the current user. */
    public function toggleTwoFactor(Request $request)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $user = $request->user();
        $user->update(['two_factor_enabled' => $data['enabled']]);
        return $this->successResponse(['two_factor_enabled' => $user->two_factor_enabled],
            $data['enabled'] ? 'Two-factor enabled — you will get an email code at next login.' : 'Two-factor disabled.');
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
            'is_admin'         => $user->isAdmin(),
            'role'             => $user->role?->name,
            'permissions'      => $user->effectivePermissions(),
            'is_active'        => $user->is_active,
            'two_factor_enabled' => $user->two_factor_enabled,
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
            'new_password' => ['required', 'confirmed', self::passwordPolicy()],
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

        // Revoke all other sessions so a stolen token can't survive a password change.
        $currentTokenId = optional($user->currentAccessToken())->id;
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return $this->successResponse(null, 'Password changed successfully', 200);
    }
}
