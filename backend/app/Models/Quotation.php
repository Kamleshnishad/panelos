<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'quotation_no', 'quotation_prefix', 'revision_number',
        'parent_quotation_id', 'customer_id', 'status',
        'project_name', 'project_location', 'quality_grade', 'validity_days',
        'quoted_on', 'valid_until', 'sent_at', 'accepted_at', 'rejected_at', 'expired_at',
        'panel_subtotal', 'accessory_subtotal', 'installation_amount', 'total_sqm',
        'subtotal', 'discount_pct', 'discount_amount', 'taxable_amount',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'is_inter_state',
        'tax_amount', 'transport_fixed', 'transport_amount', 'round_off',
        'total_amount', 'advance_pct', 'advance_amount', 'balance_amount',
        'notes', 'terms_and_conditions',
    ];

    protected $casts = [
        'quoted_on'       => 'date',
        'valid_until'     => 'date',
        'sent_at'         => 'datetime',
        'accepted_at'     => 'datetime',
        'rejected_at'     => 'datetime',
        'expired_at'      => 'datetime',
        'is_inter_state'  => 'boolean',
        'transport_fixed' => 'boolean',
        'subtotal'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'discount_pct'    => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount'  => 'decimal:2',
        'cgst_amount'     => 'decimal:2',
        'sgst_amount'     => 'decimal:2',
        'igst_amount'     => 'decimal:2',
        'transport_amount'=> 'decimal:2',
        'round_off'       => 'decimal:2',
        'advance_pct'     => 'decimal:2',
        'advance_amount'  => 'decimal:2',
        'balance_amount'  => 'decimal:2',
        'panel_subtotal'  => 'decimal:2',
        'accessory_subtotal' => 'decimal:2',
        'installation_amount' => 'decimal:2',
        'total_sqm'       => 'decimal:4',
    ];

    // Expose "rates pending" so the UI can flag a draft quotation (typically one
    // just converted from a BOQ) whose panel rows still have a 0 rate awaiting
    // sales pricing. (BOQs themselves are now the separate status='boq' stage.)
    // N+1 safe: only computes when items are already loaded, else null (unknown).
    protected $appends = ['rates_pending'];

    public function getRatesPendingAttribute()
    {
        if (!$this->relationLoaded('items')) return null;
        if ($this->items->isEmpty()) return false;
        foreach ($this->items as $item) {
            if ((float) $item->rate_per_sqm <= 0) return true;
        }
        return false;
    }

    public function customer()      { return $this->belongsTo(Customer::class); }
    public function company()       { return $this->belongsTo(Company::class); }
    public function items()         { return $this->hasMany(QuotationItem::class)->orderBy('sort_order'); }
    public function accessories()   { return $this->belongsToMany(Accessory::class, 'quotation_accessories')->withPivot('quantity', 'unit_price', 'amount', 'type', 'description', 'unit', 'door_type', 'door_width', 'door_height')->withTimestamps(); }
    public function orders()        { return $this->hasMany(Order::class); }
    public function parent()        { return $this->belongsTo(Quotation::class, 'parent_quotation_id'); }
    public function revisions()     { return $this->hasMany(Quotation::class, 'parent_quotation_id'); }

    public function scopeByStatus($query, string $status) { return $query->where('status', $status); }
    public function scopeByCustomer($query, int $id) { return $query->where('customer_id', $id); }
    public function scopeRecent($query) { return $query->orderByDesc('created_at'); }
}
