<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IndianPincode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) return;

        $digits = preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) < 6) return; // allow partial input

        if (!preg_match('/^\d{6}$/', $digits)) {
            $fail('The :attribute must be a valid 6-digit pincode.');
        }
    }
}
