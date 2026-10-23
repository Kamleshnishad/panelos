<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxCalculation extends Model
{
    protected $table = 'tax_calculations';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'tax_rate',
        'taxable_amount',
        'tax_amount',
        'sgst_amount',
        'cgst_amount',
        'igst_amount',
        // No DB default and $timestamps = false — same sibling bug as
        // InvoiceItem/DispatchItem. TaxService::applyTaxToInvoice() never
        // set this, so every invoice's tax calc fails under strict sql_mode.
        'created_at',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (TaxCalculation $tax) {
            $tax->created_at ??= now();
        });
    }

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2'
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception('Tax calculations are immutable.');
    }
}
