<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'lead_no', 'contact_name', 'company_name', 'phone', 'email', 'city',
        'source', 'requirement', 'application', 'est_qty_sqm', 'est_value', 'status',
        'assigned_to_user_id', 'next_follow_up_date', 'lost_reason', 'customer_id', 'quotation_id', 'notes',
    ];

    protected $casts = [
        'est_qty_sqm'         => 'decimal:2',
        'est_value'           => 'decimal:2',
        'next_follow_up_date' => 'date',
    ];

    public function company()      { return $this->belongsTo(Company::class); }
    public function assignedUser() { return $this->belongsTo(User::class, 'assigned_to_user_id'); }
    public function customer()     { return $this->belongsTo(Customer::class); }
    public function quotation()    { return $this->belongsTo(Quotation::class); }
    public function activities()   { return $this->hasMany(LeadActivity::class)->orderByDesc('activity_date')->orderByDesc('id'); }
}
