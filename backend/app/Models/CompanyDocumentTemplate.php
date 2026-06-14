<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyDocumentTemplate extends BaseModel
{
    use SoftDeletes;

    protected $fillable = ['company_id', 'doc_type', 'template_key'];

    public function company() { return $this->belongsTo(Company::class); }
}
