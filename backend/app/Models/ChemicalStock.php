<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class ChemicalStock extends BaseModel
{
    use SoftDeletes;

    protected $table = 'chemical_stocks';

    protected $fillable = [
        'company_id',
        'chemical_id',
        'name',
        'category',
        'quantity_in_stock',
        'unit',
        'reorder_level',
        'batch_number',
        'expiry_date',
        'last_stock_in',
        'last_stock_out'
    ];

    protected $casts = [
        'quantity_in_stock' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'expiry_date' => 'date',
        'last_stock_in' => 'datetime',
        'last_stock_out' => 'datetime'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // NOTE: there is no Chemical master model/table — a ChemicalStock row is
    // self-describing via its own `name`/`category` columns. (Removed the dead
    // belongsTo(Chemical::class) relation that threw "Class not found".)

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

    public function isExpiring()
    {
        return $this->expiry_date && $this->expiry_date->diffInDays(now()) <= 30;
    }

    public function isExpired()
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function getAvailableQuantity()
    {
        // stock_allocations has no deleted_at — bypass inherited SoftDeletes scope
        $allocated = $this->allocations()
            ->withoutGlobalScopes()
            ->where('status', '!=', 'released')
            ->sum('quantity_allocated');

        return $this->quantity_in_stock - ($allocated ?? 0);
    }
}
