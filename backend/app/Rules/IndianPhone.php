<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IndianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) return;

        $digits = preg_replace('/\D/', '', (string) $value);
        if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
            $fail('The :attribute must be a valid 10-digit Indian mobile number (starts with 6-9).');
        }
    }
}
