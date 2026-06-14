<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['company_id', 'name', 'email', 'phone', 'password', 'role_id', 'is_super_admin', 'is_company_admin', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'whatsapp_no',
        'password',
        'role_id',
        'is_super_admin',
        'is_company_admin',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'is_company_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get company relationship
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get role relationship
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    // ── RBAC ──────────────────────────────────────────────────────────────
    public function isAdmin(): bool
    {
        return (bool) ($this->is_super_admin || $this->is_company_admin);
    }

    /** Effective permission keys (['*'] for admins). */
    public function effectivePermissions(): array
    {
        if ($this->isAdmin()) return ['*'];
        $perms = $this->role?->permissions;
        return is_array($perms) ? $perms : [];
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isAdmin()) return true;
        $perms = $this->effectivePermissions();
        return in_array('*', $perms, true) || in_array($key, $perms, true);
    }

    /** Gate for cost / margin / valuation fields. */
    public function canViewCost(): bool
    {
        return $this->hasPermission('costing.view');
    }
}
