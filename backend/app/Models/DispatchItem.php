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
        'amount'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
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
