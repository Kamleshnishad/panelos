<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use ApiResponse;

    /** Only company/super admins may manage users. */
    private function authorizeAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (!$request->user()->is_company_admin && !$request->user()->is_super_admin) {
            return $this->errorResponse([], 'Only company admins can manage users', 'FORBIDDEN', 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        try {
            $users = User::where('company_id', $request->user()->company_id)
                ->with('role')
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => $this->present($u));

            return $this->successResponse($users, 'Users retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to load users', 'USER_LIST_ERROR', 500);
        }
    }

    public function roles(Request $request)
    {
        try {
            $roles = Role::where('company_id', $request->user()->company_id)
                ->orderBy('id')
                ->get(['id', 'name', 'description', 'is_system_role', 'permissions']);
            return $this->successResponse($roles, 'Roles retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], 'Failed to load roles', 'ROLE_LIST_ERROR', 500);
        }
    }

    /** Permission registry (module → key → label) for the role matrix UI. */
    public function permissionRegistry(Request $request)
    {
        return $this->successResponse(config('permissions', []), 'Permission registry');
    }

    /** Set a role's permission keys (admin only). */
    public function updateRolePermissions(Request $request, $id)
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;
        try {
            $role = Role::where('company_id', $request->user()->company_id)->findOrFail($id);
            $data = $request->validate(['permissions' => 'present|array', 'permissions.*' => 'string|max:60']);

            // keep only keys that exist in the registry
            $valid = collect(config('permissions', []))->flatMap(fn ($g) => array_keys($g))->all();
            $perms = array_values(array_intersect($data['permissions'], $valid));

            $role->update(['permissions' => $perms]);
            return $this->successResponse($role->fresh(), 'Permissions updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'ROLE_PERM_ERROR', 400);
        }
    }

    public function store(Request $request)
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        try {
            $companyId = $request->user()->company_id;

            // Plan-based user limit
            $company = $request->user()->company;
            if ($company && !$company->withinUserLimit()) {
                return $this->errorResponse(
                    ['plan' => $company->subscription_plan, 'limit' => $company->userLimit()],
                    "Your {$company->subscription_plan} plan allows up to {$company->userLimit()} users. Upgrade your plan to add more.",
                    'PLAN_LIMIT_REACHED',
                    422
                );
            }

            $validated = $request->validate([
                'name'             => 'required|string|max:255',
                'email'            => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'phone'            => 'nullable|string|max:20',
                'password'         => ['required', \App\Http\Controllers\Api\AuthController::passwordPolicy()],
                'role_id'          => ['nullable', Rule::exists('roles', 'id')->where('company_id', $companyId)],
                'is_company_admin' => 'nullable|boolean',
                'is_active'        => 'nullable|boolean',
            ]);

            $user = User::create([
                'company_id'       => $companyId,
                'name'             => $validated['name'],
                'email'            => $validated['email'],
                'phone'            => $validated['phone'] ?? null,
                'password'         => Hash::make($validated['password']),
                'role_id'          => $validated['role_id'] ?? null,
                'is_company_admin' => $validated['is_company_admin'] ?? false,
                'is_super_admin'   => false,
                'is_active'        => $validated['is_active'] ?? true,
            ]);

            return $this->createdResponse($this->present($user->load('role')), 'User created', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'USER_CREATE_ERROR', 400);
        }
    }

    public function update(Request $request, int $id)
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        try {
            $companyId = $request->user()->company_id;
            $user = User::where('company_id', $companyId)->findOrFail($id);

            $validated = $request->validate([
                'name'             => 'sometimes|required|string|max:255',
                'email'            => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
                'phone'            => 'nullable|string|max:20',
                'role_id'          => ['nullable', Rule::exists('roles', 'id')->where('company_id', $companyId)],
                'is_company_admin' => 'nullable|boolean',
                'is_active'        => 'nullable|boolean',
            ]);

            // Guard: don't let an admin lock themselves out / deactivate self
            if ($user->id === $request->user()->id) {
                if (array_key_exists('is_active', $validated) && !$validated['is_active']) {
                    return $this->errorResponse([], 'You cannot deactivate your own account', 'SELF_DEACTIVATE', 400);
                }
                if (array_key_exists('is_company_admin', $validated) && !$validated['is_company_admin']) {
                    return $this->errorResponse([], 'You cannot remove your own admin rights', 'SELF_DEMOTE', 400);
                }
            }

            $user->update($validated);
            return $this->successResponse($this->present($user->fresh('role')), 'User updated');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'USER_UPDATE_ERROR', 400);
        }
    }

    public function resetPassword(Request $request, int $id)
    {
        if ($deny = $this->authorizeAdmin($request)) return $deny;

        try {
            $user = User::where('company_id', $request->user()->company_id)->findOrFail($id);

            $validated = $request->validate([
                'password' => ['required', \App\Http\Controllers\Api\AuthController::passwordPolicy()],
            ]);

            $user->update(['password' => Hash::make($validated['password'])]);
            // Revoke existing tokens so the user must re-login
            $user->tokens()->delete();

            return $this->successResponse([], 'Password reset successfully');
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 'Validation failed', 'VALIDATION_ERROR', 422);
        } catch (\Exception $e) {
            return $this->errorResponse(['error' => $e->getMessage()], $e->getMessage(), 'PASSWORD_RESET_ERROR', 400);
        }
    }

    private function present(User $user): array
    {
        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'phone'            => $user->phone,
            'role_id'          => $user->role_id,
            'role_name'        => $user->role?->name,
            'is_company_admin' => (bool) $user->is_company_admin,
            'is_super_admin'   => (bool) $user->is_super_admin,
            'is_active'        => (bool) $user->is_active,
            'last_login_at'    => $user->last_login_at,
        ];
    }
}
