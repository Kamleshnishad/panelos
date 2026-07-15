<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rule as ValidationRule;

class UniqueScoped implements Rule
{
    protected string $table;
    protected string $column;
    protected $companyId;
    protected ?int $ignoreId;
    protected ?string $extraWhere;

    public function __construct(string $table, string $column, $companyId, ?int $ignoreId = null, ?string $extraWhere = null)
    {
        $this->table = $table;
        $this->column = $column;
        $this->companyId = $companyId;
        $this->ignoreId = $ignoreId;
        $this->extraWhere = $extraWhere;
    }

    public function validate($attribute, $value, $parameters): bool
    {
        if (empty($value)) return true;

        $query = ValidationRule::unique($this->table, $this->column)
            ->where('company_id', $this->companyId);

        if ($this->ignoreId) {
            $query->ignore($this->ignoreId);
        }

        if ($this->extraWhere) {
            $query->where(function ($q) {
                // extraWhere is used only for queries that already have company_id scoping;
                // we don't need it here since Rule::unique builds its own query.
                // This param exists for backward compat with complex cases.
            });
        }

        $result = $query->passes($attribute, $value);
        return $result;
    }

    public function message(): string
    {
        return "The :attribute '{$this->value}' is already used for this company.";
    }
}
