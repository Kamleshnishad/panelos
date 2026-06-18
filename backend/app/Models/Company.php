<?php

namespace App\Models;

use App\Models\Concerns\HasImageDataUri;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Company is the tenant root — it has NO company_id column, so it must NOT
// extend BaseModel (whose creating-hook auto-injects company_id and would crash
// inserts when an authenticated user creates a company). Uses SoftDeletes
// directly (companies has deleted_at).
class Company extends Model
{
    use HasFactory, SoftDeletes, HasImageDataUri;

    protected $imageField = 'logo';

    protected $fillable = [
        'name',
        'subdomain',
        'logo',
        'gstin',
        'pan',
        'address_line1',
        'city',
        'state',
        'state_code',
        'pincode',
        'phone',
        'email',
        'bank_name',
        'bank_account_no',
        'bank_ifsc',
        'bank_branch',
        'authorized_signatory',
        'signatory_phone',
        'primary_color',
        'secondary_color',
        'quotation_prefix',
        'invoice_prefix',
        'order_prefix',
        'challan_prefix',
        'financial_year_start',
        'e_invoice_applicable',
        'tcs_applicable',
        // subscription_plan, subscription_status, trial_ends_at, subscription_ends_at,
        // is_active are guarded — only SubscriptionService / SuperAdminController may
        // mutate them via forceFill(). A tenant admin must never be able to flip these.
        'settings',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'signup_referrer',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'e_invoice_applicable' => 'boolean',
            'tcs_applicable' => 'boolean',
            'is_active' => 'boolean',
            'financial_year_start' => 'integer',
        ];
    }

    /**
     * Get users relationship
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get roles relationship
     */
    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    // ── Plan-based feature gating ────────────────────────────────────────
    public function planLimits(): array
    {
        return config('plans.limits.' . $this->subscription_plan)
            ?? config('plans.limits.starter', ['users' => 3, 'einvoice' => false]);
    }

    /** Max users allowed by plan (0 = unlimited). */
    public function userLimit(): int
    {
        return (int) ($this->planLimits()['users'] ?? 0);
    }

    /** True if another user can be added under the current plan. */
    public function withinUserLimit(): bool
    {
        $limit = $this->userLimit();
        if ($limit <= 0) return true;   // unlimited
        return $this->users()->count() < $limit;
    }

    public function canUseEinvoice(): bool
    {
        return (bool) ($this->planLimits()['einvoice'] ?? false);
    }
}
