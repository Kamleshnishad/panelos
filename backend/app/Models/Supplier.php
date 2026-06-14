<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends BaseModel
{
    use \App\Traits\Auditable;
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'phone', 'gstin', 'email', 'address'];

    public function company() { return $this->belongsTo(Company::class); }
    public function purchaseOrders() { return $this->hasMany(PurchaseOrder::class); }
}
