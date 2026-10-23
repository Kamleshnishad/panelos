<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IndianIfsc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) return;

        $clean = strtoupper(preg_replace('/\s/', '', (string) $value));
        if (strlen($clean) < 11) return; // allow partial input

        if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $clean)) {
            $fail('The :attribute must be a valid 11-character IFSC code (e.g. HDFC0001234).');
        }
    }
}
