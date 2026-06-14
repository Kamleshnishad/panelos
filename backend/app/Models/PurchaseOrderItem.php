<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderItem extends BaseModel
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
