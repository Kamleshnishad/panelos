<?php

namespace App\Models;

use App\Models\Concerns\HasImageDataUri;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends BaseModel
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
        'subscription_plan',
        'subscription_status',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
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
}
