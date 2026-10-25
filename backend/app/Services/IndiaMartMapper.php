<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * One IndiaMART enquiry row -> the common shape LeadIntakeService takes (LEADS_PLAN 3.2).
 *
 * IndiaMART's help page names these fields: UNIQUE_QUERY_ID, QUERY_TYPE (W, B, P, BIZ, WA), QUERY_TIME, SENDER_NAME (they fill in
 * "IndiaMART Buyer" when there is none), SENDER_MOBILE and/or SENDER_EMAIL, SENDER_COUNTRY_ISO, QUERY_MESSAGE. The names for company, city and
 * product are not in the readable part of the page, so the likely spellings are all tried, and the whole row is kept as the lead's raw message
 * so nothing is lost if one is spelt differently (confirmed against a real account in 3.4).
 */
class IndiaMartMapper
{
    public const TYPES = ['W' => 'Direct enquiry', 'B' => 'Buy-lead', 'P' => 'Call (PNS)', 'BIZ' => 'Catalog view', 'WA' => 'WhatsApp enquiry'];

    public static function map(array $row): array
    {
        $name = self::first($row, ['SENDER_NAME']);
        if ($name !== null && strcasecmp($name, 'IndiaMART Buyer') === 0) {
            $name = null;                                   // their placeholder: better to fall back to the company or the phone
        }
        $product = self::first($row, ['QUERY_PRODUCT_NAME', 'PRODUCT_NAME', 'QUERY_PRODUCT']);
        $message = self::first($row, ['QUERY_MESSAGE', 'MESSAGE']);
        $type = strtoupper((string) self::first($row, ['QUERY_TYPE']));

        return [
            'external_id'  => self::first($row, ['UNIQUE_QUERY_ID', 'QUERY_ID']),
            'contact_name' => $name,
            'company_name' => self::first($row, ['SENDER_COMPANY', 'SENDER_COMPANY_NAME', 'COMPANY_NAME', 'COMPANY']),
            'phone'        => self::first($row, ['SENDER_MOBILE', 'SENDER_MOBILE_ALT', 'SENDER_PHONE']),
            'email'        => self::first($row, ['SENDER_EMAIL', 'SENDER_EMAIL_ALT']),
            'city'         => self::first($row, ['SENDER_CITY', 'CITY']),
            'requirement'  => trim(implode('. ', array_filter([$product ? "Product: {$product}" : null, $message]))) ?: null,
            'campaign'     => self::TYPES[$type] ?? null,
            'first_seen_at' => self::time(self::first($row, ['QUERY_TIME'])),
        ];
    }

    /** The first of these keys that holds a plain, non-empty value. */
    private static function first(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $row[$k] ?? null;
            if (is_scalar($v) && !is_bool($v) && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }

        return null;
    }

    /** IndiaMART's times are Indian time. Understands ISO-like text, "10-Nov-2026 14:05:00", "10-11-202614:05:00" and epoch seconds or milliseconds. */
    private static function time(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        try {
            if (ctype_digit($v) && strlen($v) >= 10) {
                return Carbon::createFromTimestamp(strlen($v) >= 13 ? (int) substr($v, 0, 10) : (int) $v, 'UTC')->toIso8601String();
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})(\d{2}:\d{2}:\d{2})$/', $v, $m)) {
                return Carbon::parse("{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}", 'Asia/Kolkata')->utc()->toIso8601String();
            }
            if (preg_match('/^(\d{2}-[A-Za-z]{3}-\d{4})(\d{2}:\d{2}:\d{2})$/', $v, $m)) {
                $v = "{$m[1]} {$m[2]}";
            }

            return Carbon::parse($v, 'Asia/Kolkata')->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
