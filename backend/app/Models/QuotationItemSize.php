<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationItemSize extends Model
{
    protected $fillable = [
        'quotation_item_id', 'length_mm', 'width_mm',
        'nos', 'rate_per_sqm', 'amount', 'sort_order',
    ];

    protected $casts = [
        'rate_per_sqm' => 'decimal:2',
        'amount'       => 'decimal:2',
    ];

    public function item() { return $this->belongsTo(QuotationItem::class, 'quotation_item_id'); }

    // NOTE: `sqm` is a MySQL generated/stored column
    // ((length_mm/1000)*(width_mm/1000)*nos) — read it straight from the DB.
    // The old PHP accessor + decimal cast shadowed the real value and is removed.
}
