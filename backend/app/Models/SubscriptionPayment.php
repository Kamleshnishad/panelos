<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    use BelongsToTenant;   // tenant-scoped reads; super-admin bypasses

    protected $fillable = [
        'company_id', 'plan', 'months', 'total_amount', 'taxable_amount',
        'gst_amount', 'gst_rate', 'method', 'reference', 'invoice_no',
        'period_start', 'period_end', 'created_by_user_id',
    ];

    protected $casts = [
        'total_amount'   => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'gst_amount'     => 'decimal:2',
        'gst_rate'       => 'decimal:2',
        'months'         => 'integer',
        'period_start'   => 'datetime',
        'period_end'     => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
}
