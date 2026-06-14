<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransaction extends BaseModel
{
    use SoftDeletes;

    protected $table = 'stock_transactions';

    protected $fillable = [
        'company_id',
        'transactionable_id',
        'transactionable_type',
        'type',
        'quantity',
        'unit',
        'reference_no',
        'notes',
        'transaction_date',
        'created_by_user_id'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'transaction_date' => 'datetime'
    ];

    protected $appends = ['item_name', 'item_kind'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transactionable()
    {
        return $this->morphTo();
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \Exception('Stock transactions are immutable. Create a new transaction instead.');
    }

    public function getItemNameAttribute()
    {
        if ($this->transactionable_type === CoilStock::class) {
            return $this->transactionable?->panelType?->name ?? 'Coil Stock';
        } elseif ($this->transactionable_type === ChemicalStock::class) {
            return $this->transactionable?->name ?? 'Chemical Stock';
        }
        return 'Unknown Item';
    }

    public function getItemKindAttribute()
    {
        if ($this->transactionable_type === CoilStock::class)     return 'coil';
        if ($this->transactionable_type === ChemicalStock::class) return 'chemical';
        return 'other';
    }
}
