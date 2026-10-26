<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionRejection extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    /** Where on the floor the bad panels were found. */
    public const STAGES = ['saw', 'qc', 'stacking', 'store'];

    /** Reason categories that describe a rejected panel (not process scrap or stock adjustments). */
    public const REASON_CATEGORIES = ['defect', 'handling', 'supplier', 'transit'];

    protected $fillable = [
        'company_id', 'batch_id', 'cut_line_id', 'reason_code_id', 'quality_control_id', 'stage_found',
        'nos', 'panel_length_mm', 'width_mm', 'sqm', 'notes', 'status',
        'raised_by_user_id', 'voided_by_user_id', 'voided_at', 'void_reason',
    ];

    protected $casts = ['sqm' => 'decimal:4', 'voided_at' => 'datetime'];

    public function batch()      { return $this->belongsTo(ProductionBatch::class, 'batch_id'); }
    public function cutLine()    { return $this->belongsTo(ProductionCutLine::class, 'cut_line_id'); }
    public function reason()     { return $this->belongsTo(WasteReasonCode::class, 'reason_code_id'); }
    public function raisedBy()   { return $this->belongsTo(User::class, 'raised_by_user_id'); }
    public function voidedBy()   { return $this->belongsTo(User::class, 'voided_by_user_id'); }
    public function attachments() { return $this->morphMany(Attachment::class, 'attachable'); }
}
