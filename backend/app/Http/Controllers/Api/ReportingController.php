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
                $asOf
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
            foreach ($data['vouchers'] as $v) {
                $rows[] = implode(',', array_map(fn ($x) => '"' . str_replace('"', '""', $x) . '"', [
                    $v['voucher_type'], $v['voucher_no'], $v['date'],
                    $v['party_name'], $v['party_gstin'], $v['party_state'],
                    $v['taxable_value'], $v['cgst_rate'], $v['cgst_amount'],
                    $v['sgst_rate'], $v['sgst_amount'], $v['igst_rate'], $v['igst_amount'],
                    $v['total_amount'], $v['sales_ledger'], $v['narration'],
                ]));
            }

            $filename = 'tally_sales_' . str_replace('-', '', $from) . '_' . str_replace('-', '', $to) . '.csv';
            return response(implode("\n", $rows), 200, [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    private function buildTallyXml(array $data): string
    {
        $company  = e($data['company']?->name ?? 'Company');
        $from     = e($data['from']);
        $to       = e($data['to']);

        $vouchers = '';
        foreach ($data['vouchers'] as $v) {
            $ledgers = '';
            // Debit: party (debtor)
            $ledgers .= "<ALLLEDGERENTRIES.LIST><LEDGERNAME>{$v['party_name']}</LEDGERNAME><ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE><AMOUNT>-{$v['total_amount']}</AMOUNT></ALLLEDGERENTRIES.LIST>";
            // Credit: Sales
            $ledgers .= "<ALLLEDGERENTRIES.LIST><LEDGERNAME>{$v['sales_ledger']}</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>{$v['taxable_value']}</AMOUNT></ALLLEDGERENTRIES.LIST>";
            // GST ledgers
            if ($v['cgst_amount'] > 0) $ledgers .= "<ALLLEDGERENTRIES.LIST><LEDGERNAME>CGST</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>{$v['cgst_amount']}</AMOUNT></ALLLEDGERENTRIES.LIST>";
            if ($v['sgst_amount'] > 0) $ledgers .= "<ALLLEDGERENTRIES.LIST><LEDGERNAME>SGST</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>{$v['sgst_amount']}</AMOUNT></ALLLEDGERENTRIES.LIST>";
            if ($v['igst_amount'] > 0) $ledgers .= "<ALLLEDGERENTRIES.LIST><LEDGERNAME>IGST</LEDGERNAME><ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE><AMOUNT>{$v['igst_amount']}</AMOUNT></ALLLEDGERENTRIES.LIST>";

            $vouchers .= "
  <VOUCHER VCHTYPE=\"Sales\" ACTION=\"Create\">
    <DATE>{$v['date']}</DATE>
    <VOUCHERTYPENAME>Sales</VOUCHERTYPENAME>
    <VOUCHERNUMBER>{$v['voucher_no']}</VOUCHERNUMBER>
    <PARTYLEDGERNAME>{$v['party_name']}</PARTYLEDGERNAME>
    <NARRATION>{$v['narration']}</NARRATION>
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
