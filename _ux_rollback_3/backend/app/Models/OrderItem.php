<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'panel_type_id',
        'thickness', 'density_type', 'density_kgm3',
        'top_skin_material', 'top_skin_thickness', 'top_color', 'top_surface',
        'bottom_skin_material', 'bottom_skin_thickness', 'bottom_color',
        'guard_film', 'cello_tap', 'hsn_code',
        'total_sqm', 'rate_per_sqm', 'amount',
        'quantity', 'unit_price', 'sort_order',
    ];

    protected $casts = [
        'guard_film'             => 'boolean',
        'cello_tap'              => 'boolean',
        'density_kgm3'           => 'decimal:1',
        'top_skin_thickness'     => 'decimal:2',
        'bottom_skin_thickness'  => 'decimal:2',
        'total_sqm'              => 'decimal:4',
        'rate_per_sqm'           => 'decimal:2',
        'amount'                 => 'decimal:2',
        'quantity'               => 'decimal:2',
        'unit_price'             => 'decimal:2',
    ];

    public function order()     { return $this->belongsTo(Order::class); }
    public function panelType() { return $this->belongsTo(PanelType::class); }
    public function sizes()     { return $this->hasMany(OrderItemSize::class)->orderBy('sort_order'); }
}
