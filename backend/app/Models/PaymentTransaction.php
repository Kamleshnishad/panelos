<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions';

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'invoice_id',
        'amount',
        'payment_method',
        'reference_no',
        'transaction_date',
        'created_by_user_id',
        'created_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime'
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception('Payment transactions are immutable. Record reversal as new negative payment.');
    }
}
