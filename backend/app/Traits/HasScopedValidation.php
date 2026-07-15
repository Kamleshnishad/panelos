<?php
/**
 * HasScopedValidation — enforces company_id scoping on store/update requests.
 *
 * Trait provides two helpers:
 *   assertBelongsToCompany(Model $model, int $companyId): void
 *     Throws 403 if the record doesn't belong to the caller's company.
 *
 *   validateScopedRules(Request $request, array $rules): array
     Merges a scoped "company_id must equal caller's" rule into the ruleset
     for any field named company_id (or any key the caller passes).
 */

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait HasScopedValidation
{
    /**
     * Ensure $model belongs to $companyId — abort 403 otherwise.
     */
    protected function assertBelongsToCompany(Model $model, int $companyId): void
    {
        if (($model->company_id ?? null) !== $companyId) {
            throw new HttpException(403, 'Resource does not belong to your company.');
        }
    }

    /**
     * Merge a company-scoping rule into the validator rules.
     *
     * @param  array<string, mixed>  $rules  Laravel validator rules keyed by field.
     * @param  string  $companyIdField   The field that holds company_id.
     * @return array<string, mixed>  Augmented rules.
     */
    protected function validateScopedRules(Request $request, array $rules, string $companyIdField = 'company_id'): array
    {
        $companyId = (int) ($request->user()?->company_id ?? 0);

        $rules[$companyIdField] = array_merge(
            $rules[$companyIdField] ?? [],
            ['in:' . $companyId]
        );

        return $rules;
    }
}
