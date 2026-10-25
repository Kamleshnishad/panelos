<?php

namespace App\Support;

use App\Models\Company;

/**
 * The ledger names and the fallback HSN code the Tally export writes (FR-010). Every Tally company names its ledgers its own way ("Sales - 18%", "Output CGST
 * 9%"), and an import fails when a ledger in the file does not exist in Tally, so these are the company's own settings instead of fixed text.
 * They live in `companies.settings['tally']`; anything not set falls back to DEFAULTS, which are exactly what the export always wrote.
 * The sales ledger may contain {rate} (the GST rate of the invoice, 18 or 12.5), so one pattern can name one ledger per rate.
 */
final class TallyLedgers
{
    public const DEFAULTS = ['sales' => 'Sales @ {rate}%', 'cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST', 'hsn' => '39259010'];

    /** @return array{sales:string, cgst:string, sgst:string, igst:string, hsn:string} */
    public static function for(int $companyId): array
    {
        $saved = Company::withoutGlobalScope('tenant')->whereKey($companyId)->first()?->settings['tally'] ?? [];

        return self::merge(is_array($saved) ? $saved : []);
    }

    /** DEFAULTS with any non-empty saved value on top. */
    public static function merge(array $saved): array
    {
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $k => $default) {
            $v = isset($saved[$k]) && is_string($saved[$k]) ? trim($saved[$k]) : '';
            if ($v !== '') {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** The sales ledger for an invoice taxed at $rate percent. */
    public static function salesLedger(array $names, float $rate): string
    {
        $r = rtrim(rtrim(sprintf('%.2f', $rate), '0'), '.');

        return str_replace('{rate}', $r === '' ? '0' : $r, $names['sales']);
    }

    public static function save(int $companyId, array $names): array
    {
        $company = Company::withoutGlobalScope('tenant')->findOrFail($companyId);
        $settings = $company->settings ?? [];
        $settings['tally'] = array_intersect_key($names, self::DEFAULTS);
        $company->settings = $settings;
        $company->save();

        return self::merge($settings['tally']);
    }
}
