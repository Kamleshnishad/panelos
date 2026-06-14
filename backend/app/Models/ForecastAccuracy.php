<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastAccuracy extends Model
{
    protected $fillable = [
        'company_id',
        'panel_type_id',
        'demand_forecast_id',
        'predicted_quantity',
        'actual_quantity',
        'mape',
        'accuracy_score',
    ];

    protected $casts = [
        'predicted_quantity' => 'float',
        'actual_quantity'    => 'float',
        'mape'               => 'float',
        'accuracy_score'     => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function panelType(): BelongsTo
    {
        return $this->belongsTo(PanelType::class);
    }

    public function demandForecast(): BelongsTo
    {
        return $this->belongsTo(DemandForecast::class);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
