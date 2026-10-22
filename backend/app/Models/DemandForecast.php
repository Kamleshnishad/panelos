<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemandForecast extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'panel_type_id',
        'forecast_date',
        'forecast_period_days',
        'predicted_demand',
        'current_stock',
        'reorder_quantity',
        'recommended_order_date',
        'seasonal_factor',
        'trend_strength',
        'risk_level'
    ];

    protected $casts = [
        // C4: an explicit Y-m-d format (not the bare 'date' cast, which still
        // carries a time-of-day internally) so a stored value and a freshly
        // computed match value in updateOrCreate() can never disagree.
        'forecast_date' => 'date:Y-m-d',
        'recommended_order_date' => 'date',
        'predicted_demand' => 'integer',
        'current_stock' => 'integer',
        'reorder_quantity' => 'integer',
        'seasonal_factor' => 'decimal:2',
        'trend_strength' => 'decimal:2'
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
