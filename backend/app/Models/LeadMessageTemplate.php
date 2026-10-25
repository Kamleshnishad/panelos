<?php

namespace App\Models;

/** A ready-made WhatsApp text or e-mail for contacting a lead (LEADS_PLAN 5.3). Placeholders such as {name} are filled in when it is used. */
class LeadMessageTemplate extends BaseModel
{
    public const CHANNELS = ['whatsapp', 'email'];

    protected $fillable = ['company_id', 'channel', 'name', 'subject', 'body', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
