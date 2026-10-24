<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Decides whether two leads are the same buyer (LEADS_PLAN 1.4). Phone and e-mail are reduced to a key that ignores spaces, dashes,
 * brackets, +91 / 0 prefixes and letter case; the keys are stored on the lead (phone_key, email_key) so matching is an indexed lookup.
 * Every importer (IndiaMART, forms, CSV) uses this same class.
 */
class LeadMatcher
{
    /** The last 10 digits, or null when the number is too short to identify anyone. "+91 98765-43210", "098765 43210" and "9876543210" are one key. */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    public static function emailKey(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Leads of this company that share the phone or the e-mail. Won, lost and invalid leads count too (a returning buyer is worth knowing about);
     * deleted leads and other companies never match.
     *
     * @return list<array{id:int,lead_no:string,contact_name:string,company_name:?string,status:string,assigned_to:?string,created_at:?string,matched_on:list<string>}>
     */
    public function matches(int $companyId, ?string $phone, ?string $email, ?int $exceptLeadId = null): array
    {
        $phoneKey = self::phoneKey($phone);
        $emailKey = self::emailKey($email);
        if (!$phoneKey && !$emailKey) {
            return [];
        }

        $rows = Lead::withoutGlobalScope('tenant')->where('company_id', $companyId)
            ->when($exceptLeadId, fn ($q) => $q->where('id', '!=', $exceptLeadId))
            ->where(function ($q) use ($phoneKey, $emailKey) {
                if ($phoneKey) $q->orWhere('phone_key', $phoneKey);
                if ($emailKey) $q->orWhere('email_key', $emailKey);
            })
            ->with('assignedUser:id,name')->orderByDesc('id')->limit(10)->get();

        return $rows->map(fn (Lead $l) => [
            'id'           => $l->id,
            'lead_no'      => $l->lead_no,
            'contact_name' => $l->contact_name,
            'company_name' => $l->company_name,
            'status'       => $l->status,
            'assigned_to'  => $l->assignedUser?->name,
            'created_at'   => $l->created_at?->toDateString(),
            'matched_on'   => array_values(array_filter([
                $phoneKey && $l->phone_key === $phoneKey ? 'phone' : null,
                $emailKey && $l->email_key === $emailKey ? 'email' : null,
            ])),
        ])->all();
    }
}
