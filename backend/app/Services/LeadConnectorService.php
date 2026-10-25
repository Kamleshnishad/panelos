<?php

namespace App\Services;

use App\Models\LeadConnector;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Settings and the "test connection" for an outside lead channel (LEADS_PLAN 3.1). The key is stored encrypted, shown as "set" with its last
 * four characters, and never returned. A test looks at the last 6 hours, only counts what is there, and creates nothing.
 */
class LeadConnectorService
{
    public const TEST_WINDOW_HOURS = 6;

    public function __construct(private IndiaMartClient $indiaMart, private IndiaMartPullService $pull)
    {
    }

    /** A new key (blank keeps the saved one), or $clear to remove it. Saving a different key forgets the last test result. */
    public function saveIndiaMart(int $companyId, ?string $key, bool $clear = false): LeadConnector
    {
        $c = LeadConnector::forCompany($companyId);
        $key = $key === null ? null : trim($key);

        if ($clear) {
            $c->setKey(null);
            $c->forceFill(['is_enabled' => false, 'last_test_at' => null, 'last_test_ok' => null, 'last_test_message' => null, 'cursor_at' => null])->save();

            return $c;
        }
        if ($key !== null && $key !== '') {
            if (!preg_match('/^[A-Za-z0-9._\-=+\/]{8,200}$/', $key)) {
                throw ValidationException::withMessages(['crm_key' => 'That does not look like an IndiaMART CRM key. Copy it again from Lead Manager → CRM Integration (no spaces).']);
            }
            if ($key !== $c->key()) {
                $c->setKey($key);
                $c->forceFill(['last_test_at' => null, 'last_test_ok' => null, 'last_test_message' => null, 'last_error' => null, 'last_error_at' => null])->save();
            }
        }

        return $c;
    }

    /** Switch the automatic pull (every 10 minutes) on or off. It needs a saved key. */
    public function setEnabled(int $companyId, bool $on): LeadConnector
    {
        $c = LeadConnector::forCompany($companyId);
        if ($on && !$c->key()) {
            throw ValidationException::withMessages(['is_enabled' => 'Save the CRM key first, then switch the import on.']);
        }
        $c->forceFill(['is_enabled' => $on])->save();

        return $c;
    }

    /**
     * Pull a date range by hand (whole Indian days, at most 7 of them, within the last 365 days). Does not move the automatic pull's place.
     * @throws ValidationException for a bad range, no key, or IndiaMART asked less than 5 minutes ago
     */
    public function pullIndiaMartRange(int $companyId, string $fromDate, string $toDate): array
    {
        $c = LeadConnector::forCompany($companyId);
        if (!$c->key()) {
            throw ValidationException::withMessages(['crm_key' => 'Save the CRM key first.']);
        }
        $from = Carbon::parse($fromDate, 'Asia/Kolkata')->startOfDay();
        $to = Carbon::parse($toDate, 'Asia/Kolkata')->endOfDay();
        if ($to->isFuture()) {
            $to = now();
        }
        if ($from->gte($to)) {
            throw ValidationException::withMessages(['from' => 'Choose a start day that is not in the future and not after the end day.']);
        }
        if ($from->diffInSeconds($to) > IndiaMartClient::MAX_WINDOW_DAYS * 86400) {
            throw ValidationException::withMessages(['to' => 'IndiaMART answers for at most 7 days at a time. Pull a shorter range, then the next.']);
        }
        if ($from->lt(now()->subDays(365))) {
            throw ValidationException::withMessages(['from' => 'IndiaMART keeps only the last 365 days.']);
        }

        $r = $this->pull->pullRange($c, $from, $to);
        if ($r['status'] === 'skipped') {
            throw ValidationException::withMessages(['pull' => $r['message']]);
        }

        return $r + ['ok' => $r['status'] === 'ok'];
    }

    /**
     * @return array{ok:bool, message:string, count:?int, tested_at:string}
     * @throws ValidationException when there is no key to test, or the last request to IndiaMART was under 5 minutes ago
     */
    public function testIndiaMart(int $companyId): array
    {
        $c = LeadConnector::forCompany($companyId);
        $key = $c->key();
        if (!$key) {
            throw ValidationException::withMessages(['crm_key' => 'Save the CRM key first, then test it.']);
        }
        if ($c->last_request_at && $c->last_request_at->gt(now()->subMinutes(IndiaMartClient::MIN_GAP_MINUTES))) {
            $wait = max(1, (int) ceil(now()->diffInSeconds($c->last_request_at->copy()->addMinutes(IndiaMartClient::MIN_GAP_MINUTES)) / 60));
            throw ValidationException::withMessages(['test' => "IndiaMART allows one request every 5 minutes, and one was made a moment ago. Try again in about {$wait} minute" . ($wait === 1 ? '' : 's') . '.']);
        }

        $c->forceFill(['last_request_at' => now()])->save();      // counts even if it fails: IndiaMART counts it too
        try {
            $rows = $this->indiaMart->fetch($key, now()->subHours(self::TEST_WINDOW_HOURS), now());
            $count = count($rows);
            $ok = true;
            $message = "Connected. IndiaMART holds {$count} " . ($count === 1 ? 'enquiry' : 'enquiries') . ' from the last ' . self::TEST_WINDOW_HOURS . ' hours. Nothing was imported.';
        } catch (IndiaMartException $e) {
            $count = null;
            $ok = false;
            $message = $e->getMessage();
        }

        $c->forceFill(['last_test_at' => now(), 'last_test_ok' => $ok, 'last_test_message' => mb_substr($message, 0, 255)])->save();

        return ['ok' => $ok, 'message' => $message, 'count' => $count, 'tested_at' => now()->toIso8601String()];
    }
}
