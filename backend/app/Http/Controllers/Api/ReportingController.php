<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportingService;
use App\Services\TaxService;
use Illuminate\Http\Request;

class ReportingController extends Controller
{
    protected $reportingService;
    protected $taxService;

    public function __construct(ReportingService $reportingService, TaxService $taxService)
    {
        $this->reportingService = $reportingService;
        $this->taxService = $taxService;
    }

    public function profitLossStatement(Request $request)
    {
        try {
            $from_date = $request->get('from_date');
            $to_date = $request->get('to_date');

            $statement = $this->reportingService->getProfitLossStatement(
                auth()->user()->company_id,
                $from_date,
                $to_date
            );

            return response()->json([
                'success' => true,
                'data' => $statement
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function balanceSheet(Request $request)
    {
        try {
            $asOf = $request->get('as_of');

            $sheet = $this->reportingService->getBalanceSheet(
                auth()->user()->company_id,
                $asOf
            );

            return response()->json([
                'success' => true,
                'data' => $sheet
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function cashFlowStatement(Request $request)
    {
        try {
            $from_date = $request->get('from_date');
            $to_date = $request->get('to_date');

            $statement = $this->reportingService->getCashFlowStatement(
                auth()->user()->company_id,
                $from_date,
                $to_date
            );

            return response()->json([
                'success' => true,
                'data' => $statement
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function accountsReceivableAging(Request $request)
    {
        try {
            $asOf = $request->get('as_of');

            $ar = $this->reportingService->getAccountsReceivable(
                auth()->user()->company_id,
                $asOf,
                (int) $request->query('page', 1),
                (int) $request->query('per_page', 100),
            );

            return response()->json([
                'success' => true,
                'data' => $ar
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function salesReport(Request $request)
    {
        try {
            $from_date = $request->get('from_date');
            $to_date = $request->get('to_date');

            $report = $this->reportingService->getSalesReport(
                auth()->user()->company_id,
                $from_date,
                $to_date
            );

            return response()->json([
                'success' => true,
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function taxReport(Request $request)
    {
        try {
            $from_date = $request->get('from_date');
            $to_date = $request->get('to_date');

            $report = $this->taxService->getTaxReport(
                auth()->user()->company_id,
                $from_date,
                $to_date
            );

            return response()->json([
                'success' => true,
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function accountingDashboard()
    {
        try {
            $dashboard = $this->reportingService->getAccountingDashboard(auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'data' => $dashboard
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function reconcileInvoices()
    {
        try {
            $reconciliation = $this->reportingService->reconcileInvoices(auth()->user()->company_id);

            return response()->json([
                'success' => true,
                'data' => $reconciliation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function monthlyRevenueTrend(Request $request)
    {
        try {
            $months = (int) $request->get('months', 12);
            $data = $this->reportingService->getMonthlyRevenueTrend(auth()->user()->company_id, $months);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function topCustomers(Request $request)
    {
        try {
            $data = $this->reportingService->getTopCustomers(
                auth()->user()->company_id,
                $request->get('from_date'),
                $request->get('to_date'),
                (int) $request->get('limit', 10)
            );
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function panelTypeMix(Request $request)
    {
        try {
            $data = $this->reportingService->getPanelTypeMix(
                auth()->user()->company_id,
                $request->get('from_date'),
                $request->get('to_date')
            );
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /** Order-to-invoice reconciliation (revenue leakage). */
    public function reconciliation(Request $request)
    {
        try {
            $data = $this->reportingService->getReconciliation(
                auth()->user()->company_id,
                $request->get('from'),
                $request->get('to'),
            );
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /** MIS Report — owner monthly summary (revenue, GST, production, aging). */
    public function misReport(Request $request)
    {
        try {
            $cid  = auth()->user()->company_id;
            $from = $request->get('from', now()->startOfMonth()->toDateString());
            $to   = $request->get('to',   now()->toDateString());
            return response()->json(['success' => true, 'data' =>
                $this->reportingService->getMisReport($cid, $from, $to)]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /** Tally XML export — sales vouchers for the period. */
    public function tallyXml(Request $request)
    {
        try {
            $cid  = auth()->user()->company_id;
            $from = $request->get('from', now()->startOfMonth()->toDateString());
            $to   = $request->get('to',   now()->toDateString());
            $data = $this->reportingService->getTallyExportData($cid, $from, $to);

            $xml  = $this->buildTallyXml($data);
            $filename = 'tally_sales_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to) . '.xml';

            return response($xml, 200, [
                'Content-Type'        => 'application/xml',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /** Tally CSV export — flat rows, easy fallback import. */
    public function tallyCsv(Request $request)
    {
        try {
            $cid  = auth()->user()->company_id;
            $from = $request->get('from', now()->startOfMonth()->toDateString());
            $to   = $request->get('to',   now()->toDateString());
            $data = $this->reportingService->getTallyExportData($cid, $from, $to);

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
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
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
