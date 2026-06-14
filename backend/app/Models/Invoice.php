<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends BaseModel
{
    use SoftDeletes;

    protected $table = 'invoices';

    protected $fillable = [
        'company_id',
        'dispatch_id',
        'order_id',
        'invoice_no',
        'status',
        'subtotal',
        'tax_amount',
        'total_amount',
        'invoice_date',
        'due_date',
        'paid_date',
        'notes',
        'terms'
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'invoice_date' => 'date',
        'due_date' => 'date',
        'paid_date' => 'date'
    ];

    // Expose computed payment fields in API responses (requires payments loaded for accuracy)
    protected $appends = ['remaining_due', 'is_paid', 'is_overdue'];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->invoice_no) {
                $model->invoice_no = static::generateInvoiceNumber($model->company_id);
            }
            if (!$model->invoice_date) {
                $model->invoice_date = now();
            }
        });
    }

    protected static function generateInvoiceNumber($companyId)
    {
        $year = now()->format('Y');
        $prefix = "INV-{$year}";

        $lastInvoice = static::where('company_id', $companyId)
            ->where('invoice_no', 'like', "{$prefix}%")
            ->latest('id')
            ->first();

        $nextNumber = $lastInvoice
            ? intval(substr($lastInvoice->invoice_no, -6)) + 1
            : 1;

        return $prefix . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function dispatch()
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function taxCalculation()
    {
        return $this->hasOne(TaxCalculation::class);
    }

    public function getRemainingDueAttribute()
    {
        $paid = $this->payments->sum('amount') ?? 0;
        return $this->total_amount - $paid;
    }

    public function getIsPaidAttribute()
    {
        return $this->remaining_due <= 0;
    }

    public function getIsOverdueAttribute()
    {
        return $this->due_date && $this->due_date->isPast() && !$this->is_paid;
    }

    public function canSend()
    {
        return $this->status === 'draft';
    }

    public function canAccept()
    {
        return in_array($this->status, ['draft', 'sent']);
    }

    public function canMarkPaid()
    {
        return in_array($this->status, ['accepted', 'sent']);
    }

    public function canCancel()
    {
        return in_array($this->status, ['draft', 'sent']);
    }
}
