<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadImport;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Leads from a spreadsheet (LEADS_PLAN 4.2). A CSV file (Excel: Save As, CSV) goes through three steps:
 *
 *  1. preview(): the file is read and every row is run through LeadIntakeService::preview(), which says what would happen (new lead, added to
 *     the buyer's open lead, already had, rejected and why) without creating anything. The checked rows are kept in `lead_imports`.
 *  2. commit(): runs those rows through LeadIntakeService::ingest(), the same door every channel uses, so matching, duplicates, assignment rules and the
 *     raw message are exactly as for IndiaMART or the website form. The bell is quiet during the import and each person gets one summary line.
 *  3. undo(): takes back the leads that import created, but only the ones nobody has touched since. Only the most recent import can be undone.
 *
 * Columns are found by their heading, in any order, spelled several ways (Name / Contact name, Phone / Mobile ...); headings we do not know are ignored and listed.
 */
class LeadImportService
{
    public const MAX_ROWS = 1000;
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** field => the headings that mean it, compared with everything but letters and digits removed and in lower case. */
    private const COLUMNS = [
        'contact_name' => ['name', 'contactname', 'fullname', 'customername', 'buyername', 'contactperson', 'leadname', 'person'],
        'company_name' => ['company', 'companyname', 'organisation', 'organization', 'firm', 'business'],
        'phone'        => ['phone', 'mobile', 'mobileno', 'mobilenumber', 'phoneno', 'phonenumber', 'contactno', 'contactnumber', 'whatsapp', 'telephone', 'tel', 'cell'],
        'email'        => ['email', 'emailid', 'emailaddress', 'mail'],
        'city'         => ['city', 'town', 'location'],
        'source'       => ['source', 'leadsource', 'channel'],
        'requirement'  => ['requirement', 'requirements', 'message', 'enquiry', 'inquiry', 'details', 'notes', 'remarks', 'query', 'description'],
        'application'  => ['application', 'use', 'usage'],
        'est_qty_sqm'  => ['areasqm', 'sqm', 'area', 'quantity', 'qty', 'quantitysqm', 'estsqm'],
        'est_value'    => ['valuers', 'valueinr', 'value', 'estvalue', 'estimatedvalue', 'budget', 'amount'],
        'external_id'  => ['reference', 'ref', 'refno', 'id', 'leadid', 'queryid', 'uniqueid', 'externalid'],
        'first_seen_at' => ['date', 'enquirydate', 'inquirydate', 'leaddate', 'createdon', 'receivedon', 'received', 'datetime'],
        'campaign'     => ['campaign'],
    ];

    public const TEMPLATE_HEADERS = ['Name', 'Company', 'Phone', 'Email', 'City', 'Source', 'Requirement', 'Application', 'Area (sqm)', 'Value (Rs)', 'Reference', 'Date'];
    private const TEMPLATE_EXAMPLE = ['Example Buyer (delete this row)', 'Example Cold Storage', '9999900000', 'buyer@example.com', 'Rajkot', 'Exhibition', 'Need 300 sqm PUF wall panel for a cold room', 'Cold Room', '300', '450000', 'TS-001', '2026-10-20'];

    public function __construct(
        private LeadIntakeService $intake,
        private LeadListService $lists,
        private LeadService $leads,
        private AppNotificationService $notify,
    ) {
    }

    // ── the template and CSV helpers ─────────────────────────────────────────

    public static function templateCsv(): string
    {
        return self::csv([self::TEMPLATE_HEADERS, self::TEMPLATE_EXAMPLE]);
    }

    /** Rows of cells to CSV text that Excel opens correctly (UTF-8 with a byte-order mark; formulas neutralised so a name cannot run as one). */
    public static function csv(array $rows): string
    {
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $cells) {
            $out .= self::line($cells);
        }

        return $out;
    }

    public static function line(array $cells): string
    {
        $h = fopen('php://temp', 'w+');
        fputcsv($h, array_map([\App\Http\Controllers\Api\ExportController::class, 'csvSafe'], $cells), ',', '"', '');
        rewind($h);
        $s = stream_get_contents($h);
        fclose($h);

        return $s;
    }

    // ── 1. check the file ────────────────────────────────────────────────────

    /** @throws ValidationException when the file cannot be used at all (empty, too big, no recognisable columns) */
    public function preview(int $companyId, ?int $userId, string $fileName, string $content, ?string $source): LeadImport
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'This file is larger than ' . (self::MAX_BYTES / 1024 / 1024) . ' MB. Split it and import the parts.']);
        }
        [$headers, $table] = $this->readCsv($content);
        [$map, $ignored] = $this->mapColumns($headers);
        if (!array_intersect(['contact_name', 'company_name', 'phone', 'email'], array_keys($map))) {
            throw ValidationException::withMessages(['file' => 'The first row of the file must have headings such as Name, Phone and Email. Download the template to see them.']);
        }
        if (count($table) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'This file has ' . count($table) . ' rows. The limit is ' . self::MAX_ROWS . ' at a time: split it and import the parts.']);
        }
        if (!$table) {
            throw ValidationException::withMessages(['file' => 'The file has headings but no rows.']);
        }

        $default = ($source !== null && trim($source) !== '' ? $this->lists->resolveSource($companyId, $source) : null) ?? $this->lists->resolveSource($companyId, 'Other') ?? 'Other';
        $rows = [];
        $seenKeys = [];
        $seenRefs = [];
        $counts = ['created' => 0, 'merged' => 0, 'already_received' => 0, 'rejected' => 0];

        foreach ($table as $line => $cells) {
            [$data, $raw, $notes, $rowSource] = $this->rowData($companyId, $map, $headers, $cells, $default);
            $p = $this->intake->preview($companyId, $rowSource, $data);
            $outcome = $p['outcome'];
            $reason = $p['reason'];

            if (in_array($outcome, [LeadIntakeResult::CREATED, LeadIntakeResult::MERGED], true)) {
                $keys = array_filter([$p['phone_key'] ? "p:{$p['phone_key']}" : null, $p['email_key'] ? "e:{$p['email_key']}" : null]);
                $earlier = null;
                foreach ($keys as $k) {
                    $earlier ??= $seenKeys[$k] ?? null;
                }
                if ($p['external_id'] !== null && isset($seenRefs[$rowSource . '|' . $p['external_id']])) {
                    $outcome = LeadIntakeResult::ALREADY_RECEIVED;
                    $reason = 'Same reference as row ' . $seenRefs[$rowSource . '|' . $p['external_id']] . ' of this file.';
                } elseif ($earlier !== null) {
                    $outcome = LeadIntakeResult::MERGED;
                    $reason = "Same buyer as row {$earlier} of this file; it is added to that lead.";
                } else {
                    foreach ($keys as $k) {
                        $seenKeys[$k] = $line;
                    }
                    if ($p['external_id'] !== null) {
                        $seenRefs[$rowSource . '|' . $p['external_id']] = $line;
                    }
                }
            }
            $counts[$outcome === LeadIntakeResult::REJECTED ? 'rejected' : $outcome]++;
            $rows[] = ['n' => $line, 'source' => $rowSource, 'data' => $data, 'raw' => $raw, 'outcome' => $outcome, 'reason' => $reason, 'notes' => $notes];
        }

        return LeadImport::create([
            'company_id' => $companyId, 'user_id' => $userId, 'file_name' => mb_substr($fileName ?: 'leads.csv', 0, 150), 'source' => $default, 'status' => LeadImport::PREVIEWED,
            'total_rows' => count($rows), 'counts' => $counts + ['ignored_columns' => $ignored, 'columns' => array_keys($map)], 'rows' => $rows,
        ]);
    }

    // ── 2. import it ─────────────────────────────────────────────────────────

    public function commit(LeadImport $imp): LeadImport
    {
        // Claim it in one statement so a double click or two tabs cannot run the same file twice.
        $claimed = LeadImport::withoutGlobalScope('tenant')->where('id', $imp->id)->where('status', LeadImport::PREVIEWED)->update(['status' => LeadImport::RUNNING]);
        if (!$claimed) {
            throw ValidationException::withMessages(['import' => 'This file has already been imported, or is being imported now.']);
        }
        $imp->refresh();

        $counts = ['created' => 0, 'merged' => 0, 'already_received' => 0, 'rejected' => 0, 'errors' => 0];
        $created = [];
        $companyId = (int) $imp->company_id;
        try {
            $held = LeadAssignmentService::holdAlerts(function () use ($imp, $companyId, &$counts, &$created) {
                foreach ($imp->rows ?? [] as $row) {
                    try {
                        $r = $this->intake->ingest($companyId, $row['source'], $row['data'], $row['raw']);
                    } catch (\Throwable $e) {
                        report($e);                                   // one bad row must not stop the others
                        $counts['errors']++;
                        continue;
                    }
                    match ($r->outcome) {
                        LeadIntakeResult::CREATED          => $counts['created']++,
                        LeadIntakeResult::MERGED           => $counts['merged']++,
                        LeadIntakeResult::ALREADY_RECEIVED => $counts['already_received']++,
                        default                            => $counts['rejected']++,
                    };
                    if ($r->outcome === LeadIntakeResult::CREATED && $r->leadId) {
                        $created[] = $r->leadId;
                    }
                }
            });
        } catch (\Throwable $e) {
            $imp->forceFill(['status' => LeadImport::FAILED, 'created_lead_ids' => $created, 'counts' => $counts + ['ignored_columns' => $imp->counts['ignored_columns'] ?? []]])->save();
            throw $e;
        }

        $imp->forceFill([
            'status' => LeadImport::DONE, 'imported_at' => now(), 'created_lead_ids' => $created,
            'counts' => $counts + ['ignored_columns' => $imp->counts['ignored_columns'] ?? [], 'columns' => $imp->counts['columns'] ?? []],
        ])->save();

        // One line for each person who was given leads, instead of one bell for every lead.
        foreach ($held as $userId => $n) {
            $this->notify->notify($companyId, [(int) $userId], 'lead_assigned', $n . ' new lead' . ($n === 1 ? '' : 's') . ' assigned to you from an import',
                "From the file {$imp->file_name}. Open Leads to see them.", 'leads', null, 'info');
        }

        return $imp;
    }

    // ── 3. take it back ──────────────────────────────────────────────────────

    /** The import that Undo applies to: the latest one that actually ran. */
    public function last(int $companyId): ?LeadImport
    {
        return LeadImport::where('company_id', $companyId)->whereIn('status', [LeadImport::DONE, LeadImport::FAILED, LeadImport::UNDONE])->orderByDesc('id')->first();
    }

    public function undo(LeadImport $imp): LeadImport
    {
        if (!in_array($imp->status, [LeadImport::DONE, LeadImport::FAILED], true)) {
            throw ValidationException::withMessages(['import' => $imp->status === LeadImport::UNDONE ? 'This import has already been undone.' : 'Only an import that has run can be undone.']);
        }
        if ($this->last((int) $imp->company_id)?->id !== $imp->id) {
            throw ValidationException::withMessages(['import' => 'Only the most recent import can be undone.']);
        }

        $removed = 0;
        $kept = [];
        $at = $imp->imported_at ?? $imp->updated_at;
        foreach (Lead::where('company_id', $imp->company_id)->whereIn('id', $imp->created_lead_ids ?? [])->get() as $lead) {
            $why = $this->touched($lead, $at);
            if ($why) {
                $kept[] = ['lead_no' => $lead->lead_no, 'reason' => $why];
                continue;
            }
            $this->leads->delete($lead);
            $removed++;
        }

        $imp->forceFill([
            'status' => LeadImport::UNDONE, 'undone_at' => now(),
            'undo_result' => ['removed' => $removed, 'kept' => count($kept), 'kept_leads' => array_slice($kept, 0, 50), 'added_to_existing_leads' => (int) ($imp->counts['merged'] ?? 0)],
        ])->save();

        return $imp;
    }

    /** Why this lead must stay (somebody has worked on it since the import), or null when it is exactly as the import left it. */
    private function touched(Lead $lead, Carbon $importedAt): ?string
    {
        if ($lead->status !== 'new') {
            return 'its status was changed';
        }
        if ($lead->quotation_id || $lead->customer_id) {
            return 'it has a quotation or a customer';
        }
        if (LeadActivity::where('lead_id', $lead->id)->where(fn ($q) => $q->whereNotNull('user_id')->orWhere('created_at', '>', $importedAt))->exists()) {
            return 'someone has added to it or it has been enquired about again';
        }
        if ($lead->updated_at && $lead->updated_at->gt($importedAt)) {
            return 'it was edited';
        }

        return null;
    }

    /** Checked-but-never-imported files are of no use after two days. */
    public function prune(): int
    {
        return LeadImport::withoutGlobalScope('tenant')->where('status', LeadImport::PREVIEWED)->where('created_at', '<', now()->subDays(2))->forceDelete();
    }

    // ── reading the file ─────────────────────────────────────────────────────

    /** @return array{0:list<string>, 1:array<int, list<string>>} the headings and the rows keyed by their line number in the file (the headings are line 1) */
    private function readCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if ($content === '' || trim($content) === '') {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');          // what Excel's plain "CSV" save produces
        }
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();

        $h = fopen('php://temp', 'w+');
        fwrite($h, $content);
        rewind($h);
        $headers = null;
        $table = [];
        $line = 0;
        while (($cells = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($headers === null) {
                $headers = array_map(fn ($c) => trim((string) $c), $cells);
                continue;
            }
            if (!array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;                                                                 // blank line
            }
            $table[$line] = array_map(fn ($c) => trim((string) $c), $cells);
        }
        fclose($h);

        return [$headers ?? [], $table];
    }

    /** @return array{0:array<string,int>, 1:list<string>} field => column index, and the headings that were not understood */
    private function mapColumns(array $headers): array
    {
        $map = [];
        $ignored = [];
        foreach ($headers as $i => $h) {
            $key = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($h));
            $field = null;
            foreach (self::COLUMNS as $f => $names) {
                if (in_array($key, $names, true)) {
                    $field = $f;
                    break;
                }
            }
            if ($field !== null && !isset($map[$field])) {
                $map[$field] = $i;
            } elseif ($h !== '') {
                $ignored[] = $h;
            }
        }

        return [$map, $ignored];
    }

    /** @return array{0:array, 1:array, 2:list<string>, 3:string} the intake data, the original cells by heading, notes about what was changed, the source to use */
    private function rowData(int $companyId, array $map, array $headers, array $cells, string $default): array
    {
        $notes = [];
        $cell = fn (string $f) => isset($map[$f]) ? trim((string) ($cells[$map[$f]] ?? '')) : '';
        $data = [];
        foreach (['contact_name', 'company_name', 'email', 'city', 'requirement', 'application', 'est_qty_sqm', 'est_value', 'external_id', 'campaign'] as $f) {
            if (($v = $cell($f)) !== '') {
                $data[$f] = $v;
            }
        }

        $phone = $cell('phone');
        if ($phone !== '') {
            if (preg_match('/^\d(\.\d+)?e\+?\d+$/i', $phone)) {
                $notes[] = "The phone number {$phone} was shortened by Excel. Format the column as Text and enter it again.";
            } else {
                $data['phone'] = $phone;
            }
        }

        if (($d = $cell('first_seen_at')) !== '') {
            if ($when = $this->date($d)) {
                $data['first_seen_at'] = $when->toIso8601String();
            } else {
                $notes[] = "The date \"{$d}\" was not understood and was ignored. Use 2026-10-20 or 20-10-2026.";
            }
        }

        $rowSource = $default;
        if (($s = $cell('source')) !== '') {
            $resolved = $this->lists->resolveSource($companyId, $s);
            if ($resolved) {
                $rowSource = $resolved;
            } else {
                $notes[] = "\"{$s}\" is not one of your lead sources, so \"{$default}\" was used.";
            }
        }

        $raw = [];
        foreach ($headers as $i => $h) {
            if ($h !== '') {
                $raw[$h] = $cells[$i] ?? '';
            }
        }

        return [$data, $raw, $notes, $rowSource];
    }

    /** Day first, as in India: 2026-10-20, 20-10-2026, 20/10/2026, 20.10.2026, 20 Oct 2026, with or without a time. Never month first. */
    private function date(string $s): ?Carbon
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd.m.Y', 'd M Y', 'j M Y', 'd-M-Y', 'j-n-Y', 'j/n/Y'] as $f) {
            try {
                $d = Carbon::createFromFormat('!' . $f, $s, 'Asia/Kolkata');
            } catch (\Throwable) {
                continue;
            }
            if ($d && $d->format($f) === $s && $d->year >= 2000 && $d->year <= (int) now()->year + 1) {
                return $d;
            }
        }

        return null;
    }
}
