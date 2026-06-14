<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionBatch extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'order_id',
        'run_id',
        'batch_no',
        'status',
        'planned_quantity',
        'completed_quantity',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Relationships
     */

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function run()
    {
        return $this->belongsTo(ProductionRun::class, 'run_id');
    }

    public function items()
    {
        return $this->order()->with('items');
    }

    /**
     * Convenience accessor for the underlying order's panel items (with sizes + panel type).
     * Use this instead of items() when you need the actual OrderItem collection.
     */
    public function orderItems()
    {
        return $this->hasManyThrough(
            OrderItem::class,
            Order::class,
            'id',        // Order PK referenced by ProductionBatch.order_id
            'order_id',  // FK on order_items
            'order_id',  // local key on production_batches
            'id'         // local key on orders
        );
    }

    public function stageLogs()
    {
        return $this->hasMany(BatchStageLog::class, 'batch_id');
    }

    public function qualityControl()
    {
        return $this->hasOne(QualityControl::class, 'batch_id');
    }

    public function dispatches()
    {
        return $this->hasMany(Dispatch::class, 'batch_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scopes
     */

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeRecent($query)
    {
        return $query->orderByDesc('created_at');
    }
}
