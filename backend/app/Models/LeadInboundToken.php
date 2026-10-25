<?php

namespace App\Models;

/** A secret inbound address for one integration (LEADS_PLAN 2.3). Only the hash of the token is stored. */
class LeadInboundToken extends BaseModel
{
    protected $fillable = ['company_id', 'name', 'source', 'token_hash', 'token_hint', 'is_active', 'allowed_origins', 'last_used_at', 'created_by_user_id'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'is_active'    => 'boolean',
        'allowed_origins' => 'array',
        'last_used_at' => 'datetime',
    ];
}
