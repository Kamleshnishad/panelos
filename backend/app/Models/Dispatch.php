<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Dispatch extends BaseModel
{
    use SoftDeletes;

    protected $table = 'dispatches';

    protected $fillable = [
        'company_id',
        'batch_id',
        'dispatch_no',
        'status',
        'dispatch_date',
        'expected_delivery_date',
        'actual_delivery_date',
        'customer_address',
        'tracking_number',
        'notes'
    ];

    protected $casts = [
        'dispatch_date' => 'date',
        'expected_delivery_date' => 'date',
        'actual_delivery_date' => 'date'
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->dispatch_no) {
                $model->dispatch_no = static::generateDispatchNumber($model->company_id);
            }
            if (!$model->dispatch_date) {
                $model->dispatch_date = now();
            }
        });
    }

    protected static function generateDispatchNumber($companyId)
    {
        // Match Invoice + Quotation FY rollover (April–March) so a challan and
        // its invoice always carry the same year prefix within the same FY.
        $now    = now();
        $fyYear = $now->month >= 4 ? $now->year : $now->year - 1;
        $prefix = "DISP-{$fyYear}";

        $lastDispatch = static::where('company_id', $companyId)
            ->where('dispatch_no', 'like', "{$prefix}%")
            ->latest('id')
            ->first();

        $nextNumber = $lastDispatch
            ? intval(substr($lastDispatch->dispatch_no, -6)) + 1
            : 1;

        return $prefix . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function items()
    {
        return $this->hasMany(DispatchItem::class);
    }

    public function allocations()
    {
        return $this->hasMany(StockAllocation::class);
    }

    public function getTotalAmountAttribute()
    {
        return $this->items->sum('amount') ?? 0;
    }

    public function getTotalItemsAttribute()
    {
        return $this->items->sum('quantity') ?? 0;
    }

    public function isFullyAllocated()
    {
        return $this->allocations()
            ->where('status', '!=', 'released')
            ->count() === $this->items->count();
    }

    public function markAsCompleted($actualDeliveryDate = null)
    {
        $this->update([
            'status' => 'delivered',
            'actual_delivery_date' => $actualDeliveryDate ?? now()
        ]);

        if ($this->batch) {
            $this->batch->update(['status' => 'dispatched']);
        }

        return $this;
    }

    public function cancel()
    {
        $this->allocations()
            ->where('status', '!=', 'released')
            ->each(fn($allocation) => $allocation->release());

        $this->update(['status' => 'cancelled']);

        return $this;
    }
}
