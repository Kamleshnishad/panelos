<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadPayload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one function every channel calls (LEADS_PLAN 2.2): IndiaMART, the website form, a spreadsheet, WhatsApp.
 * A connector turns its own message into the common shape below and calls ingest(); everything else (matching, duplicates,
 * the timeline, keeping the raw message) happens here, once.
 *
 * Common shape ($data; every key optional except that a name or contact is needed):
 *   contact_name, company_name, phone, email, city, requirement, application, est_qty_sqm, est_value,
 *   external_id (the channel's id for the enquiry), campaign, utm_source/medium/campaign/term/content, landing_page, first_seen_at.
 *
 * What it does, in order:
 *  1. Same channel and same external_id as before: nothing (ALREADY_RECEIVED). Safe to call twice, or to re-pull a period.
 *  2. No name, or no usable phone or e-mail: REJECTED, the raw message is kept with no lead.
 *  3. The buyer (same phone or e-mail, LeadMatcher) already has an open lead: MERGED, an "enquired again" note goes on it, blanks are
 *     filled (never overwritten) and its follow-up is brought to today.
 *  4. Otherwise CREATED. If only closed leads match, a new lead is still made (a new opportunity) and its timeline names the old ones.
 *
 * It never uses the signed-in user: it runs from jobs and webhooks. Every query is scoped to the company it was given.
 */
class LeadIntakeService
{
    public function __construct(private LeadService $leads, private LeadMatcher $matcher, private LeadListService $lists)
    {
    }

    public function ingest(int $companyId, string $source, array $data, ?array $raw = null): LeadIntakeResult
    {
        $source = $this->source($companyId, $source);
        $lead = $this->clean($data);
        $payload = $raw ?? $data;
        $externalId = $lead['external_id'];

        if ($externalId !== null && $this->alreadyReceived($companyId, $source, $externalId)) {
            return new LeadIntakeResult(LeadIntakeResult::ALREADY_RECEIVED, reason: 'This enquiry was received before.');
        }

        if ($problem = $this->problem($lead)) {
            $this->keep($companyId, null, $source, $externalId, $payload);

            return new LeadIntakeResult(LeadIntakeResult::REJECTED, reason: $problem);
        }

        $matches = $this->matcher->matches($companyId, $lead['phone'], $lead['email']);
        $open = collect($matches)->first(fn ($m) => !in_array($m['status'], Lead::CLOSED, true));

        try {
            return $open
                ? $this->merge($companyId, $source, $lead, $payload, (int) $open['id'])
                : $this->create($companyId, $source, $lead, $payload, array_column($matches, 'lead_no'), $matches);
        } catch (QueryException $e) {
            // Two deliveries of the same enquiry at the same instant: the unique key stopped the second one.
            if ($externalId !== null && $this->alreadyReceived($companyId, $source, $externalId)) {
                return new LeadIntakeResult(LeadIntakeResult::ALREADY_RECEIVED, reason: 'This enquiry was received before.');
            }
            throw $e;
        }
    }

    private function create(int $companyId, string $source, array $d, array $payload, array $similarNos, array $matches): LeadIntakeResult
    {
        return DB::transaction(function () use ($companyId, $source, $d, $payload, $similarNos, $matches) {
            $lead = $this->leads->create($companyId, [
                'contact_name' => $d['contact_name'] ?? $d['company_name'] ?? $d['phone'] ?? $d['email'],
                'company_name' => $d['company_name'], 'phone' => $d['phone'], 'email' => $d['email'], 'city' => $d['city'],
                'source' => $source, 'external_id' => $d['external_id'], 'campaign' => $d['campaign'],
                'utm_source' => $d['utm_source'], 'utm_medium' => $d['utm_medium'], 'utm_campaign' => $d['utm_campaign'],
                'utm_term' => $d['utm_term'], 'utm_content' => $d['utm_content'], 'landing_page' => $d['landing_page'],
                'first_seen_at' => $d['first_seen_at'] ?? now(),
                'requirement' => $d['requirement'], 'application' => $d['application'], 'est_qty_sqm' => $d['est_qty_sqm'], 'est_value' => $d['est_value'],
                'status' => 'new',
            ]);
            $this->keep($companyId, $lead->id, $source, $d['external_id'], $payload);

            $text = "Received from {$source}" . ($d['external_id'] ? " (ref {$d['external_id']})" : '');
            if ($matches) {
                $text .= '. Same buyer as ' . collect($matches)->map(fn ($m) => "{$m['lead_no']} ({$m['status']})")->implode(', ') . '.';
            }
            $this->note($lead, $text);

            return new LeadIntakeResult(LeadIntakeResult::CREATED, $lead->id, $lead->lead_no, similar: $similarNos);
        });
    }

    private function merge(int $companyId, string $source, array $d, array $payload, int $leadId): LeadIntakeResult
    {
        return DB::transaction(function () use ($companyId, $source, $d, $payload, $leadId) {
            $lead = Lead::withoutGlobalScope('tenant')->where('company_id', $companyId)->lockForUpdate()->findOrFail($leadId);

            // Fill what the lead is missing; never overwrite what someone typed.
            $fill = [];
            foreach (['company_name', 'phone', 'email', 'city', 'application', 'est_qty_sqm', 'est_value'] as $f) {
                if ($d[$f] !== null && ($lead->{$f} === null || $lead->{$f} === '')) $fill[$f] = $d[$f];
            }
            // A fresh enquiry is the moment to follow up: bring the follow-up to today (the reminders in 1.7 take it from there).
            $today = Carbon::today();
            if (!$lead->next_follow_up_date || $lead->next_follow_up_date->gt($today)) {
                $fill['next_follow_up_date'] = $today->toDateString();
            }
            if ($fill) {
                $lead->update($fill);
            }
            $this->keep($companyId, $lead->id, $source, $d['external_id'], $payload);

            $about = $d['requirement'] ? ': ' . mb_substr($d['requirement'], 0, 300) : '';
            $this->note($lead, "Enquired again on {$source}" . ($d['external_id'] ? " (ref {$d['external_id']})" : '') . $about);
            // The person who has this lead hears about it now. An unassigned lead has nobody to tell.
            if ($lead->assigned_to_user_id) {
                app(LeadAssignmentService::class)->alert($lead, (int) $lead->assigned_to_user_id, 'Enquired again');
            }

            return new LeadIntakeResult(LeadIntakeResult::MERGED, $lead->id, $lead->lead_no);
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function note(Lead $lead, string $text): void
    {
        LeadActivity::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => null, 'type' => 'note', 'description' => $text, 'activity_date' => now()]);
    }

    private function keep(int $companyId, ?int $leadId, string $source, ?string $externalId, array $payload): void
    {
        LeadPayload::withoutGlobalScope('tenant')->create([
            'company_id' => $companyId, 'lead_id' => $leadId, 'source' => $source, 'external_id' => $externalId, 'payload' => $payload, 'received_at' => now(),
        ]);
    }

    /** Has this channel's enquiry already become a lead, or been added to one? (A rejected message does not count: a corrected resend must get through.) */
    protected function alreadyReceived(int $companyId, string $source, string $externalId): bool
    {
        return Lead::withoutGlobalScope('tenant')->withTrashed()->where('company_id', $companyId)->where('source', $source)->where('external_id', $externalId)->exists()
            || LeadPayload::withoutGlobalScope('tenant')->withTrashed()->where('company_id', $companyId)->where('source', $source)->where('external_id', $externalId)->whereNotNull('lead_id')->exists();
    }

    private function problem(array $d): ?string
    {
        if (!($d['contact_name'] ?? $d['company_name'] ?? $d['phone'] ?? $d['email'])) {
            return 'No name, company, phone or e-mail in the message.';
        }
        if (!LeadMatcher::phoneKey($d['phone']) && !LeadMatcher::emailKey($d['email'])) {
            return 'No usable phone number (10 digits) or e-mail to reach this buyer.';
        }

        return null;
    }

    /** The company's spelling of the channel when it is one of its sources; otherwise the channel's own name, kept as given. */
    private function source(int $companyId, string $source): string
    {
        $source = mb_substr(trim($source), 0, 60);

        return $this->lists->resolveSource($companyId, $source) ?? ($source !== '' ? $source : 'Other');
    }

    /** Trim, cap lengths, drop anything that is not a plain value, parse numbers and dates. Never throws on a malformed message. */
    private function clean(array $in): array
    {
        $s = function (string $k, int $max) use ($in) {
            $v = $in[$k] ?? null;
            if (!is_scalar($v) || is_bool($v)) return null;
            $v = trim((string) $v);

            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $n = function (string $k) use ($in) {
            $v = $in[$k] ?? null;
            if (!is_scalar($v) || is_bool($v)) return null;
            $v = str_replace([',', '₹', ' '], '', (string) $v);

            return is_numeric($v) && (float) $v >= 0 && (float) $v < 1e11 ? round((float) $v, 2) : null;
        };
        $when = null;
        if (is_scalar($in['first_seen_at'] ?? null) && trim((string) $in['first_seen_at']) !== '') {
            try {
                $when = Carbon::parse((string) $in['first_seen_at']);
                if ($when->isFuture()) $when = now();
            } catch (\Throwable) {
                $when = null;
            }
        }
        $email = $s('email', 150);

        return [
            'contact_name' => $s('contact_name', 150), 'company_name' => $s('company_name', 200),
            'phone' => LeadMatcher::phoneKey($s('phone', 30)) ? $s('phone', 30) : null,      // "n/a" or a stub is not a phone; the raw message keeps it
            'email' => $email && LeadMatcher::emailKey($email) ? $email : null,
            'city' => $s('city', 120), 'requirement' => $s('requirement', 2000), 'application' => $s('application', 40),
            'est_qty_sqm' => $n('est_qty_sqm'), 'est_value' => $n('est_value'),
            'external_id' => $s('external_id', 120), 'campaign' => $s('campaign', 150),
            'utm_source' => $s('utm_source', 150), 'utm_medium' => $s('utm_medium', 150), 'utm_campaign' => $s('utm_campaign', 150),
            'utm_term' => $s('utm_term', 150), 'utm_content' => $s('utm_content', 150), 'landing_page' => $s('landing_page', 500),
            'first_seen_at' => $when,
        ];
    }
}
