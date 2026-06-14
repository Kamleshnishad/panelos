<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemSize extends Model
{
    protected $fillable = [
        'order_item_id', 'length_mm', 'width_mm',
        'nos', 'sqm', 'rate_per_sqm', 'amount', 'sort_order',
    ];

    protected $casts = [
        'sqm'          => 'decimal:4',
        'rate_per_sqm' => 'decimal:2',
        'amount'       => 'decimal:2',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
