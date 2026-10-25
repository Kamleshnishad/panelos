<?php

namespace App\Models;

/** One open stage of a company's lead pipeline (LEADS_PLAN 5.8): a name, an order and a chance of winning. `key` is what a lead's status holds and never changes. */
class LeadStage extends BaseModel
{
    protected $fillable = ['company_id', 'key', 'name', 'probability', 'sort_order', 'role'];

    protected $casts = ['probability' => 'integer', 'sort_order' => 'integer'];
}
