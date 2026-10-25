<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One request that reached an inbound token (LEADS_PLAN 2.3). Append-only; pruned after 90 days. */
class LeadImportLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['company_id', 'token_id', 'source', 'outcome', 'lead_id', 'reason', 'external_id', 'ip', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function lead() { return $this->belongsTo(Lead::class)->withTrashed(); }
}
