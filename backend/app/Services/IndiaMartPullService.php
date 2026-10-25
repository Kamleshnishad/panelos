<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LeadConnector;
use App\Models\LeadImportLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Brings a company's IndiaMART enquiries in (LEADS_PLAN 3.2). Run every 10 minutes by `leads:pull-indiamart`, or by hand for a date range.
 *
 * Each run asks for "since the last success, minus a 5-minute overlap" (never more than 7 days at once), so an enquiry that IndiaMART shows a little
 * late is still picked up; the overlap makes IndiaMART repeat the last few, and LeadIntakeService answers `already_received` for those, so nothing doubles.
 * IndiaMART wants 5 minutes between requests, so a run that comes sooner is skipped. A failed run leaves the cursor where it was, so the next run covers the gap.
 * Only enquiries that did something (new lead, added to a lead, rejected, error) go in the import log; repeats from the overlap would drown it.
 */
class IndiaMartPullService
{
    public const SOURCE = 'IndiaMART';
    public const OVERLAP_MINUTES = 5;
    public const FIRST_PULL_HOURS = 24;

    public function __construct(private IndiaMartClient $client, private LeadIntakeService $intake)
    {
    }

    /**
     * The scheduled run for one company: from the cursor (or the last 24 hours the first time) to now.
     * @return array{status:string, message:string, fetched:int, created:int, merged:int, already_received:int, rejected:int, errors:int}
     */
    public function pullSinceLast(LeadConnector $c): array
    {
        $to = now();
        $from = ($c->cursor_at ? $c->cursor_at->copy()->subMinutes(self::OVERLAP_MINUTES) : $to->copy()->subHours(self::FIRST_PULL_HOURS));
        $longest = $to->copy()->subDays(IndiaMartClient::MAX_WINDOW_DAYS)->addMinute();
        if ($from->lt($longest)) {
            $from = $longest;                                   // IndiaMART will not answer for more than 7 days at once
        }

        return $this->run($c, $from, $to, moveCursor: true);
    }

    /** A date range chosen by a person (a back-fill). Does not move the cursor of the automatic pull. */
    public function pullRange(LeadConnector $c, Carbon $from, Carbon $to): array
    {
        return $this->run($c, $from, $to, moveCursor: false);
    }

    private function run(LeadConnector $c, Carbon $from, Carbon $to, bool $moveCursor): array
    {
        $key = $c->key();
        if (!$key) {
            return $this->result('skipped', 'No IndiaMART key is saved.');
        }
        $lock = Cache::lock("lead-pull:indiamart:{$c->company_id}", 300);
        if (!$lock->get()) {
            return $this->result('skipped', 'A pull is already running.');
        }

        try {
            $c->refresh();
            if ($c->last_request_at && $c->last_request_at->gt(now()->subMinutes(IndiaMartClient::MIN_GAP_MINUTES))) {
                return $this->result('skipped', 'IndiaMART was asked a moment ago; it allows one request every 5 minutes.');
            }
            $c->forceFill(['last_request_at' => now()])->save();   // counts even if it fails: IndiaMART counts it too

            try {
                $rows = $this->client->fetch($key, $from, $to);
            } catch (IndiaMartException $e) {
                $c->forceFill(['last_error_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
                $this->log($c, 'pull_error', null, $e->getMessage(), null);

                return $this->result('failed', $e->getMessage());
            }

            $n = ['fetched' => count($rows), 'created' => 0, 'merged' => 0, 'already_received' => 0, 'rejected' => 0, 'errors' => 0];
            foreach ($rows as $row) {
                $this->one($c, $row, $n);
            }

            $c->forceFill(['last_success_at' => now(), 'last_error_at' => null, 'last_error' => null] + ($moveCursor ? ['cursor_at' => $to] : []))->save();

            return $this->result('ok', $this->sentence($n), $n);
        } finally {
            $lock->release();
        }
    }

    private function one(LeadConnector $c, array $row, array &$n): void
    {
        $data = IndiaMartMapper::map($row);
        try {
            $r = $this->intake->ingest($c->company_id, self::SOURCE, $data, $row);
        } catch (\Throwable $e) {
            report($e);                                          // one bad enquiry must not stop the rest
            $n['errors']++;
            $this->log($c, 'error', null, 'This enquiry could not be saved.', $data['external_id']);

            return;
        }
        match ($r->outcome) {
            LeadIntakeResult::CREATED          => $n['created']++,
            LeadIntakeResult::MERGED           => $n['merged']++,
            LeadIntakeResult::ALREADY_RECEIVED => $n['already_received']++,
            default                            => $n['rejected']++,
        };
        if ($r->outcome !== LeadIntakeResult::ALREADY_RECEIVED) {
            $this->log($c, $r->outcome, $r->leadId, $r->reason, $data['external_id']);
        }
    }

    private function log(LeadConnector $c, string $outcome, ?int $leadId, ?string $reason, ?string $externalId): void
    {
        LeadImportLog::withoutGlobalScope('tenant')->create([
            'company_id' => $c->company_id, 'token_id' => null, 'source' => self::SOURCE, 'outcome' => $outcome, 'lead_id' => $leadId,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null, 'external_id' => $externalId, 'ip' => null, 'created_at' => now(),
        ]);
    }

    private function sentence(array $n): string
    {
        return "Pulled {$n['fetched']} " . ($n['fetched'] === 1 ? 'enquiry' : 'enquiries') . ": {$n['created']} new, {$n['merged']} added to existing leads, "
            . "{$n['already_received']} already had, {$n['rejected']} rejected" . ($n['errors'] ? ", {$n['errors']} could not be saved" : '') . '.';
    }

    private function result(string $status, string $message, array $n = []): array
    {
        return ['status' => $status, 'message' => $message] + $n + ['fetched' => 0, 'created' => 0, 'merged' => 0, 'already_received' => 0, 'rejected' => 0, 'errors' => 0];
    }

    /** Companies whose automatic pull should run now. */
    public function due(?int $companyId = null)
    {
        return LeadConnector::withoutGlobalScope('tenant')->where('type', LeadConnector::INDIAMART)->where('is_enabled', true)->whereNotNull('credentials')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('company_id', Company::where('is_active', true)->select('id'))->get();
    }
}
