<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A quotation line that is not a master accessory: installation, a custom item, or a
 * door/window typed into the form. Reached only through its quotation (which is tenant-scoped).
 */
class QuotationLine extends Model
{
    protected $fillable = [
        'quotation_id', 'type', 'description', 'unit', 'quantity', 'unit_price', 'amount',
        'hsn_code', 'door_type', 'door_width', 'door_height', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2',
    ];

    public function quotation() { return $this->belongsTo(Quotation::class); }
}
