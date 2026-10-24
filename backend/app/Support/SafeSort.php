<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * `sort_by` / `sort_order` come straight from the query string. An unknown column or a direction that is not asc/desc
 * made orderBy() throw a 500 (and, before PB-043, show the SQL). Anything that is not a real column of the model's
 * table now falls back to the screen's default ordering.
 */
class SafeSort
{
    public static function apply(Builder $query, array $filters, string $defaultColumn, string $defaultDirection = 'desc'): Builder
    {
        $column = $filters['sort_by'] ?? null;
        if (!is_string($column) || $column === '' || !Schema::hasColumn($query->getModel()->getTable(), $column)) {
            $column = $defaultColumn;
        }
        $direction = strtolower((string) ($filters['sort_order'] ?? $defaultDirection));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : $defaultDirection;

        return $query->orderBy($column, $direction);
    }
}
