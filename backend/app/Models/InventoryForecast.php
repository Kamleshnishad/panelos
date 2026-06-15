<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryForecast extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'panel_type_id',
        'forecast_date',
        'forecast_for_date',
        'predicted_quantity',
        'forecast_type',
        'confidence_score',
        'method',
        'notes'
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'forecast_for_date' => 'date',
        'predicted_quantity' => 'integer',
        'confidence_score' => 'decimal:2'
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
