<?php

namespace App\Models;

/** One person's saved view of the lead list (LEADS_PLAN 5.1): a name and the filters and sort to apply. */
class LeadView extends BaseModel
{
    protected $fillable = ['company_id', 'user_id', 'name', 'filters'];

    protected $casts = ['filters' => 'array'];
}
