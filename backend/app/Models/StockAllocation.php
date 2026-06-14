<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAllocation extends Model
{
    protected $table = 'stock_allocations';

    protected $fillable = [
        'company_id',
        'dispatch_id',
        'allocatable_id',
        'allocatable_type',
        'quantity_allocated',
        'status',
        'allocated_at',
        'used_at',
        'released_at'
    ];

    protected $casts = [
        'quantity_allocated' => 'decimal:2',
        'allocated_at' => 'datetime',
        'used_at' => 'datetime',
        'released_at' => 'datetime'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function dispatch()
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function allocatable()
    {
        return $this->morphTo();
    }

    public function markAsUsed()
    {
        $this->update([
            'status' => 'used',
            'used_at' => now()
        ]);

        return $this;
    }

    public function release()
    {
        $this->update([
            'status' => 'released',
            'released_at' => now()
        ]);

        return $this;
    }
}
