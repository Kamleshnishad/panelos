<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class IndianPhone implements Rule
{
    public function validate($attribute, $value, $parameters): bool
    {
        if (empty($value)) return true;
        $digits = preg_replace('/\D/', '', (string) $value);
        return (bool) preg_match('/^[6-9]\d{9}$/', $digits);
    }

    public function message(): string
    {
        return 'The :attribute must be a valid 10-digit Indian mobile number (starts with 6-9).';
    }
}
