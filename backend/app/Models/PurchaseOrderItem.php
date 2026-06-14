<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Child of PurchaseOrder — extends plain Model (NOT BaseModel) because the
// purchase_order_items table has no company_id; BaseModel would auto-inject it
// on insert and crash. Tenant scoping comes via the parent PO.
class PurchaseOrderItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'purchase_order_id', 'material_kind', 'stock_id', 'item_name', 'unit',
        'quantity', 'rate', 'amount', 'received_qty',
    ];

    protected $casts = [
        'quantity'     => 'decimal:2',
        'rate'         => 'decimal:2',
        'amount'       => 'decimal:2',
        'received_qty' => 'decimal:2',
    ];

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
}
