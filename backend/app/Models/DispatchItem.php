<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchItem extends Model
{
    protected $table = 'dispatch_items';

    public $timestamps = false;

    protected $fillable = [
        'dispatch_id',
        'panel_type_id',
        'quantity',
        'unit_price',
        'amount',
        // $timestamps = false (this table has no updated_at column) means
        // Eloquent never auto-populates created_at either, so the column
        // relies entirely on being passed through create() explicitly —
        // DispatchService::createDispatch() already does pass it, but it was
        // missing here, so mass-assignment silently dropped it every time.
        // Harmless on a lenient MySQL config (NULL/zero-date tolerated);
        // production's strict mode correctly rejected the resulting
        // all-columns-but-this-one INSERT outright.
        'created_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2'
    ];

    public function dispatch()
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function panelType()
    {
        return $this->belongsTo(PanelType::class);
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception('Dispatch items are immutable. Create new dispatch if changes needed.');
    }
}
