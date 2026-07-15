<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class IndianPincode implements Rule
{
    public function validate($attribute, $value, $parameters): bool
    {
        if (empty($value)) return true;
        $digits = preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) < 6) return true;
        return (bool) preg_match('/^\d{6}$/', $digits);
    }

    public function message(): string
    {
        return 'The :attribute must be a valid 6-digit pincode.';
    }
}
