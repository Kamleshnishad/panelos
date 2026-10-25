<?php

namespace App\Models;

/** One line of what a lead wants (LEADS_PLAN 5.4): a panel (type, application, thickness, area) or an accessory (item, quantity). */
class LeadRequirementLine extends BaseModel
{
    public const PANEL = 'panel';
    public const ACCESSORY = 'accessory';

    protected $fillable = ['company_id', 'lead_id', 'kind', 'panel_type_id', 'accessory_id', 'application', 'thickness', 'area_sqm', 'qty', 'description', 'sort_order'];

    protected $casts = ['thickness' => 'integer', 'area_sqm' => 'decimal:2', 'qty' => 'decimal:2'];

    public function panelType() { return $this->belongsTo(PanelType::class); }
    public function accessory() { return $this->belongsTo(Accessory::class); }
}
