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
}
