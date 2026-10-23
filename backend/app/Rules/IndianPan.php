<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IndianPan implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) return;

        $clean = strtoupper(preg_replace('/\s/', '', (string) $value));
        if (strlen($clean) < 10) return; // allow partial input

        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $clean)) {
            $fail('The :attribute must be a valid 10-character PAN (e.g. ABCDE1234F).');
        }
    }
}
