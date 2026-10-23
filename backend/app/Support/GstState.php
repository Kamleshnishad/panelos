<?php

namespace App\Support;

/**
 * One canonical reading of an Indian state code.
 *
 * The system held three formats at once: the 2-digit GST code ("24"), a 2-letter
 * alpha code ("GJ") and the full state name ("Gujarat"). Quotations derived the
 * supplier's state from the GSTIN prefix and mapped it to *alpha* before
 * comparing it with customers.state_code, while invoices compared
 * companies.state_code to customers.state_code *as stored*. So the same customer
 * could be intra-state on the quotation and inter-state on the invoice — CGST+SGST
 * on one document, IGST on the other, for the same supply.
 *
 * Canonical form here is the **2-digit GST state code**, because that is what
 * GSTIN, e-invoice and the e-way bill use. Everything else is normalised into it.
 */
class GstState
{
    /** GST code => [alpha code, state name] */
    private const STATES = [
        '01' => ['JK', 'Jammu and Kashmir'],
        '02' => ['HP', 'Himachal Pradesh'],
        '03' => ['PB', 'Punjab'],
        '04' => ['CH', 'Chandigarh'],
        '05' => ['UT', 'Uttarakhand'],
        '06' => ['HR', 'Haryana'],
        '07' => ['DL', 'Delhi'],
        '08' => ['RJ', 'Rajasthan'],
        '09' => ['UP', 'Uttar Pradesh'],
        '10' => ['BR', 'Bihar'],
        '11' => ['SK', 'Sikkim'],
        '12' => ['AR', 'Arunachal Pradesh'],
        '13' => ['NL', 'Nagaland'],
        '14' => ['MN', 'Manipur'],
        '15' => ['MZ', 'Mizoram'],
        '16' => ['TR', 'Tripura'],
        '17' => ['ML', 'Meghalaya'],
        '18' => ['AS', 'Assam'],
        '19' => ['WB', 'West Bengal'],
        '20' => ['JH', 'Jharkhand'],
        '21' => ['OD', 'Odisha'],
        '22' => ['CG', 'Chhattisgarh'],
        '23' => ['MP', 'Madhya Pradesh'],
        '24' => ['GJ', 'Gujarat'],
        '25' => ['DD', 'Daman and Diu'],
        '26' => ['DN', 'Dadra and Nagar Haveli'],
        '27' => ['MH', 'Maharashtra'],
        '28' => ['AP', 'Andhra Pradesh'],
        '29' => ['KA', 'Karnataka'],
        '30' => ['GA', 'Goa'],
        '31' => ['LD', 'Lakshadweep'],
        '32' => ['KL', 'Kerala'],
        '33' => ['TN', 'Tamil Nadu'],
        '34' => ['PY', 'Puducherry'],
        '35' => ['AN', 'Andaman and Nicobar Islands'],
        '36' => ['TG', 'Telangana'],
        '37' => ['AP', 'Andhra Pradesh'],
        '38' => ['LA', 'Ladakh'],
    ];

    /** Alpha synonyms that are not the primary alpha code above. */
    private const ALPHA_ALIASES = [
        'OR' => '21', 'CT' => '22', 'UK' => '05', 'UA' => '05', 'TS' => '36',
    ];

    /**
     * Normalise "24", "7", "GJ", "gujarat" (or a GSTIN) to a 2-digit GST code.
     * Returns null when the value names no known state.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        // A full GSTIN carries its state in the first two digits.
        if (preg_match('/^[0-9]{2}[A-Za-z]{5}[0-9]{4}/', $value)) {
            return self::normalize(substr($value, 0, 2));
        }

        if (ctype_digit($value)) {
            $code = str_pad($value, 2, '0', STR_PAD_LEFT);
            return isset(self::STATES[$code]) ? $code : null;
        }

        $upper = strtoupper($value);

        if (strlen($upper) === 2) {
            foreach (self::STATES as $code => [$alpha, $name]) {
                if ($alpha === $upper) return $code;
            }
            return self::ALPHA_ALIASES[$upper] ?? null;
        }

        foreach (self::STATES as $code => [$alpha, $name]) {
            if (strtoupper($name) === $upper) return $code;
        }

        return null;
    }

    /** The GST state code for a company or customer: state_code → GSTIN → state name. */
    public static function forParty(?object $party): ?string
    {
        if (!$party) return null;

        return self::normalize($party->state_code ?? null)
            ?? self::normalize($party->gstin ?? null)
            ?? self::normalize($party->state ?? null);
    }

    /**
     * True when supply crosses a state border (→ IGST).
     *
     * When either side's state is unknown we return false, i.e. treat it as
     * intra-state. That is the conservative domestic default and matches what
     * the quotation engine has always done, so both documents now agree.
     */
    public static function isInterState(?object $supplier, ?object $recipient): bool
    {
        $a = self::forParty($supplier);
        $b = self::forParty($recipient);

        if ($a === null || $b === null) return false;

        return $a !== $b;
    }

    public static function name(?string $value): ?string
    {
        $code = self::normalize($value);
        return $code === null ? null : self::STATES[$code][1];
    }

    public static function alpha(?string $value): ?string
    {
        $code = self::normalize($value);
        return $code === null ? null : self::STATES[$code][0];
    }

    /** @return array<string,string> GST code => state name, for dropdowns. */
    public static function all(): array
    {
        return array_map(fn ($s) => $s[1], self::STATES);
    }
}
