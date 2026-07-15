<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class IndianIfsc implements Rule
{
    public function validate($attribute, $value, $parameters): bool
    {
        if (empty($value)) return true;
        $clean = strtoupper(preg_replace('/\s/', '', (string) $value));
        if (strlen($clean) < 11) return true;
        return (bool) preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $clean);
    }

    public function message(): string
    {
        return 'The :attribute must be a valid 11-character IFSC code (e.g. HDFC0001234).';
    }
}
