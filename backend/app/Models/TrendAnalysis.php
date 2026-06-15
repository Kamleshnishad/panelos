<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrendAnalysis extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'panel_type_id',
        'analysis_date',
        'period_days',
        'growth_rate',
        'volatility',
        'peak_sales',
        'low_sales',
        'average_sales',
        'trend_direction',
        'seasonal_pattern',
        'year_over_year_change'
    ];

    protected $casts = [
        'analysis_date' => 'date',
        'period_days' => 'integer',
        'growth_rate' => 'decimal:4',
        'volatility' => 'decimal:4',
        'peak_sales' => 'integer',
        'low_sales' => 'integer',
        'average_sales' => 'decimal:2',
        'seasonal_pattern' => 'integer',
        'year_over_year_change' => 'decimal:4'
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
