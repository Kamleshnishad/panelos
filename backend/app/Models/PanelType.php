<?php

namespace App\Models;

use App\Models\Concerns\HasImageDataUri;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PanelType extends BaseModel
{
    use \App\Traits\Auditable;
    use HasFactory, HasImageDataUri;

    protected $imageField = 'image';

    protected $appends = ['image_url'];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'category',
        'hsn_code',
        'available_thicknesses',
        'description',
        'image',
        'thickness',
        'width',
        'length',
        'thermal_resistance',
        'base_price',
        'is_active',
    ];

    protected $casts = [
        'available_thicknesses' => 'array',
        'thickness'             => 'decimal:2',
        'width'                 => 'decimal:2',
        'length'                => 'integer',
        'thermal_resistance'    => 'decimal:2',
        'base_price'            => 'decimal:2',
        'is_active'             => 'boolean',
    ];

    // Surface implied by category
    public function getDefaultTopSurfaceAttribute(): string
    {
        return $this->category === 'roof' ? 'RIBBED' : 'PLAIN';
    }

    // Thicknesses for this panel type (falls back to global list)
    public function getThicknessListAttribute(): array
    {
        return $this->available_thicknesses ?? [30, 40, 50, 60, 75, 80, 100, 120, 150, 200];
    }

    public function quotationItems() { return $this->hasMany(QuotationItem::class); }
    public function orderItems()     { return $this->hasMany(OrderItem::class); }

    public function scopeActive($query)   { return $query->where('is_active', true); }
    public function scopeByCode($q, $c)  { return $q->where('code', $c); }
}
