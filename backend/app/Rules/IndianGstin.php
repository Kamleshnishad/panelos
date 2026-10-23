<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IndianGstin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) return;

        $clean = strtoupper(preg_replace('/\s/', '', (string) $value));
        if (strlen($clean) < 15) return; // allow partial input on update

        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $clean)) {
            $fail('The :attribute must be a valid 15-character GSTIN (e.g. 27AAAAA0000A1Z5).');
        }
    }
}
