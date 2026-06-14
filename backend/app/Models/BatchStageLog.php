<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BatchStageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'stage_id',
        'status',
        'started_at',
        'completed_at',
        'duration_minutes',
        'notes',
        'logged_by_user_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Relationships
     */

    public function batch()
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function stage()
    {
        return $this->belongsTo(ProductionStage::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }
}
