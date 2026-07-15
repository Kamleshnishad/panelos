<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class IndianGstin implements Rule
{
    public function validate($attribute, $value, $parameters): bool
    {
        if (empty($value)) return true;
        $clean = strtoupper(preg_replace('/\s/', '', (string) $value));
        if (strlen($clean) < 15) return true; // allow partial input on update
        return (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $clean);
    }

    public function message(): string
    {
        return 'The :attribute must be a valid 15-character GSTIN (e.g. 27AAAAA0000A1Z5).';
    }
}
