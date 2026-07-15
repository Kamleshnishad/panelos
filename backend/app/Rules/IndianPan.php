<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class IndianPan implements Rule
{
    public function validate($attribute, $value, $parameters): bool
    {
        if (empty($value)) return true;
        $clean = strtoupper(preg_replace('/\s/', '', (string) $value));
        if (strlen($clean) < 10) return true; // allow partial input
        return (bool) preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $clean);
    }

    public function message(): string
    {
        return 'The :attribute must be a valid 10-character PAN (e.g. ABCDE1234F).';
    }
}
