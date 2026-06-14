<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'invoice_items';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'panel_type_id',
        'quantity',
        'unit_price',
        'amount',
        'tax_rate',
        'tax_amount',
        'total_with_tax'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_with_tax' => 'decimal:2'
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function panelType()
    {
        return $this->belongsTo(PanelType::class);
    }
}
