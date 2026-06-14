<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class CoilStock extends BaseModel
{
    use SoftDeletes;

    protected $table = 'coil_stocks';

    protected $fillable = [
        'company_id',
        'coil_id',
        'panel_type_id',
        'quantity_in_stock',
        'reorder_level',
        'unit_cost',
        'last_stock_in',
        'last_stock_out'
    ];

    protected $casts = [
        'quantity_in_stock' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'last_stock_in' => 'datetime',
        'last_stock_out' => 'datetime'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function panelType()
    {
        return $this->belongsTo(PanelType::class);
    }

    public function transactions()
    {
        return $this->morphMany(StockTransaction::class, 'transactionable');
    }

    public function allocations()
    {
        return $this->morphMany(StockAllocation::class, 'allocatable');
    }

    public function isLowStock()
    {
        return $this->quantity_in_stock <= $this->reorder_level;
    }

    public function getAvailableQuantity()
    {
        // stock_allocations has no deleted_at — use withoutGlobalScopes to bypass SoftDeletes
        $allocated = $this->allocations()
            ->withoutGlobalScopes()
            ->where('status', '!=', 'released')
            ->sum('quantity_allocated');

        return $this->quantity_in_stock - ($allocated ?? 0);
    }
}
