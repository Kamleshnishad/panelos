<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'quotation_id', 'order_no', 'customer_id', 'status',
        'project_name', 'project_location', 'quality_grade',
        'subtotal', 'discount_amount', 'taxable_amount',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'is_inter_state',
        'tax_amount', 'total_amount', 'total_sqm',
        'transport_fixed', 'transport_amount',
        'advance_pct', 'advance_amount', 'balance_amount',
        'order_date', 'expected_delivery_date', 'notes',
    ];

    protected $casts = [
        'order_date'             => 'date',
        'expected_delivery_date' => 'date',
        'is_inter_state'         => 'boolean',
        'transport_fixed'        => 'boolean',
        'subtotal'               => 'decimal:2',
        'discount_amount'        => 'decimal:2',
        'taxable_amount'         => 'decimal:2',
        'cgst_amount'            => 'decimal:2',
        'sgst_amount'            => 'decimal:2',
        'igst_amount'            => 'decimal:2',
        'tax_amount'             => 'decimal:2',
        'total_amount'           => 'decimal:2',
        'total_sqm'              => 'decimal:4',
        'transport_amount'       => 'decimal:2',
        'advance_pct'            => 'decimal:2',
        'advance_amount'         => 'decimal:2',
        'balance_amount'         => 'decimal:2',
    ];

    public function quotation() { return $this->belongsTo(Quotation::class); }
    public function customer()  { return $this->belongsTo(Customer::class); }
    public function company()   { return $this->belongsTo(Company::class); }
    public function items()     { return $this->hasMany(OrderItem::class)->orderBy('sort_order'); }
    public function batches()   { return $this->hasMany(ProductionBatch::class); }

    public function scopeByStatus($query, string $status)   { return $query->where('status', $status); }
    public function scopeByCustomer($query, int $customerId) { return $query->where('customer_id', $customerId); }
    public function scopeRecent($query)                      { return $query->orderByDesc('created_at'); }
}
