<?php

namespace App\Models;

use App\Models\Concerns\HasImageDataUri;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Accessory extends BaseModel
{
    use HasFactory, HasImageDataUri;

    protected $table = 'accessories';

    protected $imageField = 'image';

    protected $appends = ['image_url'];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'image',
        'unit',
        'hsn_code',
        'rate',
        'unit_price',
        'is_active',
    ];

    protected $casts = [
        'rate'       => 'decimal:2',
        'unit_price' => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    public function quotations()
    {
        return $this->belongsToMany(Quotation::class, 'quotation_accessories')
            ->withPivot('quantity', 'unit_price', 'amount')
            ->withTimestamps();
    }

    public function scopeActive($query)  { return $query->where('is_active', true); }
    public function scopeByCode($q, $c) { return $q->where('code', $c); }
}
