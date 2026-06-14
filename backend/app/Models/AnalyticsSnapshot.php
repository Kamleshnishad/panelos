<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsSnapshot extends Model
{
    protected $fillable = [
        'company_id',
        'snapshot_date',
        'total_invoices',
        'total_revenue',
        'average_invoice_value',
        'total_quantity_sold',
        'total_inventory_value',
        'total_stock_units',
        'accounts_receivable',
        'invoices_overdue',
        'tax_collected',
        'active_customers',
        'top_panel_type_id',
        'performance_status'
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_invoices' => 'integer',
        'total_revenue' => 'decimal:2',
        'average_invoice_value' => 'decimal:2',
        'total_quantity_sold' => 'integer',
        'total_inventory_value' => 'decimal:2',
        'total_stock_units' => 'integer',
        'accounts_receivable' => 'decimal:2',
        'invoices_overdue' => 'integer',
        'tax_collected' => 'decimal:2',
        'active_customers' => 'integer',
        'top_panel_type_id' => 'integer'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function topPanelType()
    {
        return $this->belongsTo(PanelType::class, 'top_panel_type_id');
    }
}
