<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GstTaxBreakdown extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'invoice_id',
        'company_id',
        'transaction_type',
        'gst_rate',
        'sgst_amount',
        'cgst_amount',
        'igst_amount',
        'cess_amount',
        'total_tax_amount',
        'supplier_state',
        'customer_state',
        'is_reverse_charge',
    ];

    protected $casts = [
        'gst_rate' => 'float',
        'sgst_amount' => 'float',
        'cgst_amount' => 'float',
        'igst_amount' => 'float',
        'cess_amount' => 'float',
        'total_tax_amount' => 'float',
        'is_reverse_charge' => 'boolean',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeIntraState($query)
    {
        return $query->whereRaw('supplier_state = customer_state');
    }

    public function scopeInterState($query)
    {
        return $query->whereRaw('supplier_state != customer_state');
    }
}
