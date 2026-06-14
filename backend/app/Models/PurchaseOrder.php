<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'supplier_id', 'po_no', 'status', 'order_date', 'expected_date',
        'subtotal', 'tax_pct', 'tax_amount', 'total', 'notes',
    ];

    protected $casts = [
        'order_date'    => 'date',
        'expected_date' => 'date',
        'subtotal'      => 'decimal:2',
        'tax_pct'       => 'decimal:2',
        'tax_amount'    => 'decimal:2',
        'total'         => 'decimal:2',
    ];

    public function company()  { return $this->belongsTo(Company::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items()    { return $this->hasMany(PurchaseOrderItem::class); }
}
