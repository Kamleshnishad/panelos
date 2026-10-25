<?php

namespace App\Models;

/** The raw message of one enquiry exactly as the channel sent it (LEADS_PLAN 2.1). For support and re-reading; not shown on the lead screens. */
class LeadPayload extends BaseModel
{
    protected $fillable = ['company_id', 'lead_id', 'source', 'external_id', 'payload', 'received_at'];

    protected $casts = [
        'payload'     => 'array',
        'received_at' => 'datetime',
    ];

    public function lead() { return $this->belongsTo(Lead::class); }
}
