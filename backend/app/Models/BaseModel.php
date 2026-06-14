<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BaseModel extends Model
{
    use SoftDeletes;

    protected $fillable = [];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-set company_id for all queries (multi-tenancy)
        if (auth()->check() && auth()->user()) {
            static::creating(function ($model) {
                if (!$model->company_id && auth()->user()->company_id) {
                    $model->company_id = auth()->user()->company_id;
                }
            });
        }
    }

    /**
     * Scope query to current company
     */
    public function scopeCompany($query)
    {
        if (auth()->check() && auth()->user() && !auth()->user()->is_super_admin) {
            return $query->where('company_id', auth()->user()->company_id);
        }
        return $query;
    }

    /**
     * Get company relationship
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
