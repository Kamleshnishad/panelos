<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The pieces of one order size row that travel on one dispatch (FT-03). */
class DispatchItemSize extends Model
{
    protected $table = 'dispatch_item_sizes';

    public $timestamps = false;

    protected $fillable = ['dispatch_item_id', 'order_item_size_id', 'length_mm', 'width_mm', 'nos', 'sqm', 'amount', 'created_at'];

    protected $casts = ['sqm' => 'decimal:4', 'amount' => 'decimal:2'];

    public function item()
    {
        return $this->belongsTo(DispatchItem::class, 'dispatch_item_id');
    }
}
