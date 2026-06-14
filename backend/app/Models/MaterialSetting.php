<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialSetting extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'steel_density', 'iso_polyol_ratio', 'foam_overpack_pct',
        'wastage_coil_pct', 'wastage_chemical_pct', 'wastage_consumable_pct',
        'film_per_sqm', 'tape_per_panel_m',
    ];

    protected $casts = [
        'steel_density'          => 'decimal:3',
        'iso_polyol_ratio'       => 'decimal:3',
        'foam_overpack_pct'      => 'decimal:2',
        'wastage_coil_pct'       => 'decimal:2',
        'wastage_chemical_pct'   => 'decimal:2',
        'wastage_consumable_pct' => 'decimal:2',
        'film_per_sqm'           => 'decimal:3',
        'tape_per_panel_m'       => 'decimal:2',
    ];

    public function company() { return $this->belongsTo(Company::class); }
}
