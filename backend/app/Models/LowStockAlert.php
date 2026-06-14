<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class LowStockAlert extends BaseModel
{
    use SoftDeletes;

    protected $table = 'low_stock_alerts';

    protected $fillable = [
        'company_id',
        'item_type',
        'item_id',
        'current_quantity',
        'reorder_level',
        'alert_type',
        'status',
        'alert_sent_at',
        'resolved_at'
    ];

    protected $casts = [
        'current_quantity' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'alert_sent_at' => 'datetime',
        'resolved_at' => 'datetime'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function resolve()
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now()
        ]);

        return $this;
    }

    public static function createIfNeeded($itemType, $itemId, $currentQuantity, $reorderLevel)
    {
        if ($currentQuantity > $reorderLevel) {
            return null;
        }

        $existingAlert = static::where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->where('status', 'active')
            ->first();

        if ($existingAlert) {
            return $existingAlert;
        }

        return static::create([
            'company_id' => auth()?->user()?->company_id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'current_quantity' => $currentQuantity,
            'reorder_level' => $reorderLevel,
            'alert_type' => 'low_stock',
            'status' => 'active',
            'alert_sent_at' => now()
        ]);
    }
}
