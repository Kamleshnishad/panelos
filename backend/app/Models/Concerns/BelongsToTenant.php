<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Structural multi-tenancy: automatically scopes ALL reads to the current
 * tenant (auth user's company_id) and auto-fills company_id on create.
 *
 * This is the SaaS safety net — isolation no longer depends on every query
 * remembering a manual where('company_id'). A forgotten filter can no longer
 * leak another tenant's data.
 *
 * Rules:
 *  - Only applies when an authenticated user with a company_id exists. In
 *    console/tinker/scheduled commands (no auth) the scope is a no-op, so
 *    system jobs and seeders keep working.
 *  - Super admins (is_super_admin) bypass the scope to manage all tenants.
 *  - Apply ONLY to models whose table actually has a company_id column.
 *
 * To read across tenants intentionally, use ::withoutGlobalScope('tenant').
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        // Auto-scope every read to the current tenant
        static::addGlobalScope('tenant', function (Builder $builder) {
            $user = auth()->user();
            if ($user && $user->company_id && !($user->is_super_admin ?? false)) {
                $model = $builder->getModel();
                $builder->where($model->getTable() . '.' . 'company_id', $user->company_id);
            }
        });

        // Auto-fill company_id on create (when authenticated and not already set)
        static::creating(function (Model $model) {
            if (empty($model->company_id)) {
                $user = auth()->user();
                if ($user && $user->company_id) {
                    $model->company_id = $user->company_id;
                }
            }
        });
    }
}
