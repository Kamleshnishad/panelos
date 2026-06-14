<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionMaterialUsage extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'run_id', 'batch_id', 'material_kind', 'stock_id',
        'material_name', 'unit', 'standard_qty', 'issued_qty', 'actual_qty',
        'wastage_pct', 'notes', 'created_by_user_id',
    ];

    protected $casts = [
        'standard_qty' => 'decimal:2',
        'issued_qty'   => 'decimal:2',
        'actual_qty'   => 'decimal:2',
        'wastage_pct'  => 'decimal:2',
    ];

    public function run()   { return $this->belongsTo(ProductionRun::class, 'run_id'); }
    public function batch() { return $this->belongsTo(ProductionBatch::class, 'batch_id'); }
}
