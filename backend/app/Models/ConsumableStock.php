<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class ConsumableStock extends BaseModel
{
    use SoftDeletes;

    protected $table = 'consumable_stocks';

    protected $fillable = [
        'company_id', 'name', 'category', 'unit',
        'quantity_in_stock', 'reorder_level', 'unit_cost',
        'last_stock_in', 'last_stock_out',
    ];

    protected $casts = [
        'quantity_in_stock' => 'decimal:2',
        'reorder_level'     => 'decimal:2',
        'unit_cost'         => 'decimal:2',
        'last_stock_in'     => 'datetime',
        'last_stock_out'    => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transactions()
    {
        return $this->morphMany(StockTransaction::class, 'transactionable');
    }

    public function isLowStock()
    {
        return $this->quantity_in_stock <= $this->reorder_level;
    }

    /** Consumables are not dispatch-allocated; available == on hand. */
    public function getAvailableQuantity()
    {
        return $this->quantity_in_stock;
    }
}
