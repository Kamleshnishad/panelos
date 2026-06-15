<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxConfiguration extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $table = 'tax_configurations';

    protected $fillable = [
        'company_id',
        'gst_number',
        'tax_type',
        'default_tax_rate',
        'is_active'
    ];

    protected $casts = [
        'default_tax_rate' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isGstRegistered()
    {
        return !empty($this->gst_number) && $this->gst_number !== '';
    }

    public function validateGstNumber()
    {
        if (!$this->isGstRegistered()) {
            return true;
        }

        // Indian GST format: 2 digit state + 2 digit PAN + 1 digit entity + 1 digit check
        // Example: 27AAJPT5055K1Z5
        if (preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $this->gst_number)) {
            return true;
        }

        return false;
    }
}
