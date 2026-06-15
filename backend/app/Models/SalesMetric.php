<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesMetric extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'panel_type_id',
        'metric_date',
        'quantity_sold',
        'revenue',
        'average_price',
        'invoice_count'
    ];

    protected $casts = [
        'metric_date' => 'date',
        'quantity_sold' => 'integer',
        'revenue' => 'decimal:2',
        'average_price' => 'decimal:2',
        'invoice_count' => 'integer'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function panelType()
    {
        return $this->belongsTo(PanelType::class);
    }
}
