<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionCutLine extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id', 'batch_id', 'order_item_id', 'order_item_size_id', 'spec_label', 'sequence',
        'method', 'panel_length_mm', 'production_length_mm', 'width_mm', 'per_strip',
        'planned_nos', 'cut_nos', 'rejected_nos', 'is_remake',
    ];

    protected $casts = ['is_remake' => 'boolean'];

    protected $appends = ['good_nos', 'good_sqm', 'planned_sqm', 'status'];

    public function batch()
    {
        return $this->belongsTo(ProductionBatch::class, 'batch_id');
    }

    public function getGoodNosAttribute(): int
    {
        return max(0, $this->cut_nos - $this->rejected_nos);
    }

    public function getGoodSqmAttribute(): float
    {
        return $this->sqm($this->good_nos);
    }

    public function getPlannedSqmAttribute(): float
    {
        return $this->sqm($this->planned_nos);
    }

    /** pending → in_progress → done (good panels cover the plan). */
    public function getStatusAttribute(): string
    {
        if ($this->good_nos >= $this->planned_nos) return 'done';
        return $this->cut_nos > 0 ? 'in_progress' : 'pending';
    }

    private function sqm(int $nos): float
    {
        return round($this->panel_length_mm / 1000 * $this->width_mm / 1000 * $nos, 3);
    }
}
