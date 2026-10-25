<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use App\Services\TaxService;
use Illuminate\Http\Request;

/**
 * The report endpoints. Every date, range, count and bucket is checked before it reaches a query (PB-082): a bad value is a 422 that says what is wrong,
 * not a silent wrong answer. An unexpected failure is left to the app's exception handler, which logs it and answers with a reference number; these methods
 * no longer catch everything and hand the raw message (a database error included) to the browser.
 */
class ReportingController extends Controller
{
    protected $reportingService;
    protected $taxService;

    public function __construct(ReportingService $reportingService, TaxService $taxService)
    {
        $this->reportingService = $reportingService;
        $this->taxService = $taxService;
    }

    /** Check a period (both ends optional, YYYY-MM-DD, the end not before the start). */
    private function period(Request $request, string $from = 'from_date', string $to = 'to_date'): void
    {
        $request->validate([
            $from => 'nullable|date_format:Y-m-d',
            $to   => ['nullable', 'date_format:Y-m-d', "after_or_equal:{$from}"],
        ], [
            "{$from}.date_format"  => 'Use the date format YYYY-MM-DD for the start of the period.',
            "{$to}.date_format"    => 'Use the date format YYYY-MM-DD for the end of the period.',
            "{$to}.after_or_equal" => 'The end of the period is before its start.',
        ]);
    }

    private function ok($data)
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function profitLossStatement(Request $request)
    {
        $this->period($request);

        return $this->ok($this->reportingService->getProfitLossStatement(auth()->user()->company_id, $request->query('from_date'), $request->query('to_date')));
    }

    public function balanceSheet(Request $request)
    {
        $request->validate(['as_of' => 'nullable|date_format:Y-m-d'], ['as_of.date_format' => 'Use the date format YYYY-MM-DD for the date.']);

        return $this->ok($this->reportingService->getBalanceSheet(auth()->user()->company_id, $request->query('as_of')));
    }

    public function cashFlowStatement(Request $request)
    {
        $this->period($request);

        return $this->ok($this->reportingService->getCashFlowStatement(auth()->user()->company_id, $request->query('from_date'), $request->query('to_date')));
    }

    public function accountsReceivableAging(Request $request)
    {
        $request->validate([
            'as_of'    => 'nullable|date_format:Y-m-d',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|between:1,500',
            // current | 30 | 60 | 90 | 90plus: narrows the list (not the summary) to one ageing bucket
            'bucket'   => ['nullable', 'in:' . implode(',', array_keys(ReportingService::AR_BUCKETS))],
        ], [
            'as_of.date_format' => 'Use the date format YYYY-MM-DD for the date.',
            'bucket.in'         => 'That is not an ageing bucket (use current, 30, 60, 90 or 90plus).',
        ]);

        return $this->ok($this->reportingService->getAccountsReceivable(
            auth()->user()->company_id,
            $request->query('as_of'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 100),
            $request->query('bucket') ?: null,
        ));
    }

    public function salesReport(Request $request)
    {
        $this->period($request);

        return $this->ok($this->reportingService->getSalesReport(auth()->user()->company_id, $request->query('from_date'), $request->query('to_date')));
    }

    public function taxReport(Request $request)
    {
        $this->period($request);

        return $this->ok($this->taxService->getTaxReport(auth()->user()->company_id, $request->query('from_date'), $request->query('to_date')));
    }

    public function accountingDashboard()
    {
        return $this->ok($this->reportingService->getAccountingDashboard(auth()->user()->company_id));
    }

    public function reconcileInvoices()
    {
        return $this->ok($this->reportingService->reconcileInvoices(auth()->user()->company_id));
    }

    public function monthlyRevenueTrend(Request $request)
    {
        $request->validate(['months' => 'nullable|integer|between:1,60'], ['months.between' => 'Ask for between 1 and 60 months.', 'months.integer' => 'The number of months must be a whole number.']);

        return $this->ok($this->reportingService->getMonthlyRevenueTrend(auth()->user()->company_id, (int) $request->query('months', 12)));
    }

    public function topCustomers(Request $request)
    {
        $this->period($request);
        $request->validate(['limit' => 'nullable|integer|between:1,100'], ['limit.between' => 'Ask for between 1 and 100 customers.', 'limit.integer' => 'The number of customers must be a whole number.']);

        return $this->ok($this->reportingService->getTopCustomers(auth()->user()->company_id, $request->query('from_date'), $request->query('to_date'), (int) $request->query('limit', 10)));
    }

    public function panelTypeMix(Request $request)
    {
        $this->period($request);

        return $this->ok($this->reportingService->getPanelTypeMix(auth()->user()->company_id, $request->query('from_date'), $request->query('to_date')));
    }

    /** Order-to-invoice reconciliation (revenue leakage). */
    public function reconciliation(Request $request)
    {
        $this->period($request, 'from', 'to');

        return $this->ok($this->reportingService->getReconciliation(auth()->user()->company_id, $request->query('from'), $request->query('to')));
    }

    /** MIS Report — owner monthly summary (revenue, GST, production, aging). */
    public function misReport(Request $request)
    {
        $this->period($request, 'from', 'to');
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to   = $request->query('to',   now()->toDateString());

        return $this->ok($this->reportingService->getMisReport(auth()->user()->company_id, $from, $to));
    }

    /** Tally XML export — sales vouchers for the period. */
    public function tallyXml(Request $request)
    {
        $this->period($request, 'from', 'to');
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to   = $request->query('to',   now()->toDateString());
        $data = $this->reportingService->getTallyExportData(auth()->user()->company_id, $from, $to);

        $xml  = $this->buildTallyXml($data);
        $filename = 'tally_sales_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to) . '.xml';

        return response($xml, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** Tally CSV export — flat rows, easy fallback import. */
    public function tallyCsv(Request $request)
    {
        $this->period($request, 'from', 'to');
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to   = $request->query('to',   now()->toDateString());
        $data = $this->reportingService->getTallyExportData(auth()->user()->company_id, $from, $to);

        $rows   = [];
        $rows[] = implode(',', [
            'Voucher Type', 'Voucher No', 'Date', 'Party Name', 'Party GSTIN',
            'Party State', 'Taxable Value', 'CGST Rate', 'CGST Amt',
            'SGST Rate', 'SGST Amt', 'IGST Rate', 'IGST Amt', 'Total Amount',
            'Sales Ledger', 'Narration',
        ]);
        // Text columns are guarded against spreadsheet formulas (a customer called "=HYPERLINK(...)"), as in the other CSV exports.
        $text = fn ($x) => '"' . str_replace('"', '""', ExportController::csvSafe($x)) . '"';
        $num  = fn ($x) => '"' . $x . '"';
        foreach ($data['vouchers'] as $v) {
            $rows[] = implode(',', [
                $text($v['voucher_type']), $text($v['voucher_no']), $text($v['date']),
                $text($v['party_name']), $text($v['party_gstin']), $text($v['party_state']),
                $num($v['taxable_value']), $num($v['cgst_rate']), $num($v['cgst_amount']),
                $num($v['sgst_rate']), $num($v['sgst_amount']), $num($v['igst_rate']), $num($v['igst_amount']),
                $num($v['total_amount']), $text($v['sales_ledger']), $text($v['narration']),
            ]);
        }

        $filename = 'tally_sales_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to) . '.csv';
        // The byte-order mark makes Excel read the file as UTF-8, so Gujarati / Hindi names and the rupee sign survive.
        return response("\xEF\xBB\xBF" . implode("\n", $rows), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** Text for an XML element (PB-072): escaped, and stripped of the control characters XML cannot hold at all. */
    private function x($value): string
    {
        return htmlspecialchars((string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** An amount for Tally: plain two-decimal number. */
    private function amt($value): string
    {
        return sprintf('%.2f', (float) $value);
    }

    /** Tally writes dates as YYYYMMDD. */
    private function tallyDate(string $date): string
    {
        return \Carbon\Carbon::parse($date)->format('Ymd');
    }

    private function buildTallyXml(array $data): string
    {
        $company  = $this->x($data['company']?->name ?? 'Company');
        $from     = $this->x($this->tallyDate($data['from']));
        $to       = $this->x($this->tallyDate($data['to']));

        $vouchers = '';
        foreach ($data['vouchers'] as $v) {
            $entry = fn (string $ledger, string $positive, $amount) => '<ALLLEDGERENTRIES.LIST><LEDGERNAME>' . $this->x($ledger) . '</LEDGERNAME><ISDEEMEDPOSITIVE>' . $positive . '</ISDEEMEDPOSITIVE><AMOUNT>' . $amount . '</AMOUNT></ALLLEDGERENTRIES.LIST>';
            $ledgers = '';
            // Debit: party (debtor)
            $ledgers .= $entry($v['party_name'], 'Yes', '-' . $this->amt($v['total_amount']));
            // Credit: Sales
            $ledgers .= $entry($v['sales_ledger'], 'No', $this->amt($v['taxable_value']));
            // GST ledgers
            if ($v['cgst_amount'] > 0) $ledgers .= $entry('CGST', 'No', $this->amt($v['cgst_amount']));
            if ($v['sgst_amount'] > 0) $ledgers .= $entry('SGST', 'No', $this->amt($v['sgst_amount']));
            if ($v['igst_amount'] > 0) $ledgers .= $entry('IGST', 'No', $this->amt($v['igst_amount']));

            $vouchers .= "
  <VOUCHER VCHTYPE=\"Sales\" ACTION=\"Create\">
    <DATE>" . $this->x($v['date_tally']) . "</DATE>
    <VOUCHERTYPENAME>Sales</VOUCHERTYPENAME>
    <VOUCHERNUMBER>" . $this->x($v['voucher_no']) . "</VOUCHERNUMBER>
    <PARTYLEDGERNAME>" . $this->x($v['party_name']) . "</PARTYLEDGERNAME>
    <NARRATION>" . $this->x($v['narration']) . "</NARRATION>
    {$ledgers}
  </VOUCHER>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>
<ENVELOPE>
  <HEADER><VERSION>1</VERSION><TALLYREQUEST>Import</TALLYREQUEST><TYPE>Data</TYPE><ID>Vouchers</ID></HEADER>
  <BODY>
    <IMPORTDATA>
      <REQUESTDESC><REPORTNAME>Vouchers</REPORTNAME><STATICVARIABLES><SVCURRENTCOMPANY>' . $company . '</SVCURRENTCOMPANY><SVFROMDATE>' . $from . '</SVFROMDATE><SVTODATE>' . $to . '</SVTODATE></STATICVARIABLES></REQUESTDESC>
      <REQUESTDATA>' . $vouchers . '</REQUESTDATA>
    </IMPORTDATA>
  </BODY>
</ENVELOPE>';
    }
}
