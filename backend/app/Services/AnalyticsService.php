<?php

namespace App\Services;

use App\Models\SalesMetric;
use App\Models\TrendAnalysis;
use App\Models\AnalyticsSnapshot;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\PanelType;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    public function recordSalesMetric($companyId, $panelTypeId = null, $metricDate = null)
    {
        return DB::transaction(function () use ($companyId, $panelTypeId, $metricDate) {
            $metricDate = $metricDate ?? now()->toDateString();

            $query = Invoice::where('company_id', $companyId)
                ->where('status', '!=', 'draft')
                ->whereDate('invoice_date', $metricDate);

            if ($panelTypeId) {
                $query->whereHas('items', function ($q) use ($panelTypeId) {
                    $q->where('panel_type_id', $panelTypeId);
                });
            }

            $panelTypes = $panelTypeId
                ? PanelType::where('id', $panelTypeId)->get()
                : PanelType::all();

            foreach ($panelTypes as $panelType) {
                $itemsQuery = Invoice::where('company_id', $companyId)
                    ->where('status', '!=', 'draft')
                    ->whereDate('invoice_date', $metricDate)
                    ->with(['items' => function ($q) use ($panelType) {
                        $q->where('panel_type_id', $panelType->id);
                    }])
                    ->get();

                $totalQuantity = 0;
                $totalRevenue = 0;
                $invoiceCount = 0;

                foreach ($itemsQuery as $invoice) {
                    if ($invoice->items->count() > 0) {
                        $totalQuantity += $invoice->items->sum('quantity');
                        $totalRevenue += $invoice->items->sum('amount');
                        $invoiceCount++;
                    }
                }

                $avgPrice = $totalQuantity > 0 ? $totalRevenue / $totalQuantity : 0;

                SalesMetric::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'panel_type_id' => $panelType->id,
                        'metric_date' => $metricDate
                    ],
                    [
                        'quantity_sold' => $totalQuantity,
                        'revenue' => round($totalRevenue, 2),
                        'average_price' => round($avgPrice, 2),
                        'invoice_count' => $invoiceCount
                    ]
                );
            }

            return SalesMetric::where('company_id', $companyId)
                ->whereDate('metric_date', $metricDate)
                ->get();
        });
    }

    public function generateTrendAnalysis($companyId, $panelTypeId = null, $periodDays = 30)
    {
        return DB::transaction(function () use ($companyId, $panelTypeId, $periodDays) {
            $panelTypes = $panelTypeId
                ? PanelType::where('id', $panelTypeId)->get()
                : PanelType::all();

            $analyses = [];

            foreach ($panelTypes as $panelType) {
                $metrics = SalesMetric::where('company_id', $companyId)
                    ->where('panel_type_id', $panelType->id)
                    ->where('metric_date', '>=', now()->subDays($periodDays))
                    ->orderBy('metric_date')
                    ->get();

                if ($metrics->count() < 2) {
                    continue;
                }

                $quantities = $metrics->pluck('quantity_sold')->toArray();

                $analysis = TrendAnalysis::create([
                    'company_id' => $companyId,
                    'panel_type_id' => $panelType->id,
                    'analysis_date' => now(),
                    'period_days' => $periodDays,
                    'growth_rate' => $this->calculateGrowthRate($quantities),
                    'volatility' => $this->calculateVolatility($quantities),
                    'peak_sales' => max($quantities),
                    'low_sales' => min($quantities),
                    'average_sales' => round(array_sum($quantities) / count($quantities), 2),
                    'trend_direction' => $this->determineTrendDirection($quantities),
                    'seasonal_pattern' => $this->detectSeasonalPattern($metrics),
                    'year_over_year_change' => $this->calculateYoYChange($companyId, $panelType->id)
                ]);

                $analyses[] = $analysis;
            }

            return $analyses;
        });
    }

    public function createAnalyticsSnapshot($companyId, $snapshotDate = null)
    {
        return DB::transaction(function () use ($companyId, $snapshotDate) {
            $snapshotDate = $snapshotDate ?? now()->toDateString();

            $invoices = Invoice::where('company_id', $companyId)
                ->where('status', '!=', 'draft')
                ->with('items', 'taxCalculation')
                ->get();

            $totalInvoices = $invoices->count();
            $totalRevenue = $invoices->sum('subtotal');
            $avgInvoiceValue = $totalInvoices > 0 ? $totalRevenue / $totalInvoices : 0;

            $totalQuantity = 0;
            $panelTypesSold = [];

            foreach ($invoices as $invoice) {
                foreach ($invoice->items as $item) {
                    $totalQuantity += $item->quantity;
                    $panelTypesSold[$item->panel_type_id] = ($panelTypesSold[$item->panel_type_id] ?? 0) + $item->quantity;
                }
            }

            $topPanelTypeId = array_key_exists(0, $panelTypesSold)
                ? array_key_first($panelTypesSold)
                : (array_keys($panelTypesSold)[0] ?? null);

            $totalInventoryValue = DB::table('coil_stocks')
                ->where('company_id', $companyId)
                ->sum(DB::raw('quantity * average_cost'));

            $totalStockUnits = DB::table('coil_stocks')
                ->where('company_id', $companyId)
                ->sum('quantity') ?? 0;

            $accountsReceivable = Invoice::where('company_id', $companyId)
                ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
                ->with('taxCalculation')
                ->get()
                ->sum(function ($inv) {
                    return $inv->subtotal + ($inv->taxCalculation->tax_amount ?? 0);
                });

            $overdueInvoices = Invoice::where('company_id', $companyId)
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
                ->count();

            $taxCollected = $invoices->sum(function ($inv) {
                return $inv->taxCalculation->tax_amount ?? 0;
            });

            $activeCustomers = $invoices->pluck('dispatch.batch.order.customer_id')->unique()->count();

            $performanceStatus = $this->assessPerformanceStatus(
                $totalRevenue,
                $accountsReceivable,
                $overdueInvoices
            );

            $snapshot = AnalyticsSnapshot::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'snapshot_date' => $snapshotDate
                ],
                [
                    'total_invoices' => $totalInvoices,
                    'total_revenue' => round($totalRevenue, 2),
                    'average_invoice_value' => round($avgInvoiceValue, 2),
                    'total_quantity_sold' => $totalQuantity,
                    'total_inventory_value' => round($totalInventoryValue, 2),
                    'total_stock_units' => $totalStockUnits,
                    'accounts_receivable' => round($accountsReceivable, 2),
                    'invoices_overdue' => $overdueInvoices,
                    'tax_collected' => round($taxCollected, 2),
                    'active_customers' => $activeCustomers,
                    'top_panel_type_id' => $topPanelTypeId,
                    'performance_status' => $performanceStatus
                ]
            );

            return $snapshot;
        });
    }

    protected function calculateGrowthRate($quantities)
    {
        if (count($quantities) < 2) {
            return 0;
        }

        $firstHalf = array_slice($quantities, 0, (int)(count($quantities) / 2));
        $secondHalf = array_slice($quantities, (int)(count($quantities) / 2));

        $firstAvg = array_sum($firstHalf) / count($firstHalf);
        $secondAvg = array_sum($secondHalf) / count($secondHalf);

        if ($firstAvg == 0) {
            return 0;
        }

        return round((($secondAvg - $firstAvg) / $firstAvg) * 100, 4);
    }

    protected function calculateVolatility($quantities)
    {
        $mean = array_sum($quantities) / count($quantities);
        $squaredDiffs = array_map(function ($x) use ($mean) {
            return ($x - $mean) ** 2;
        }, $quantities);

        $variance = array_sum($squaredDiffs) / count($squaredDiffs);
        $stdDev = sqrt($variance);

        return round($mean > 0 ? ($stdDev / $mean) : 0, 4);
    }

    protected function determineTrendDirection($quantities)
    {
        if (count($quantities) < 2) {
            return 'stable';
        }

        $firstHalf = array_slice($quantities, 0, (int)(count($quantities) / 2));
        $secondHalf = array_slice($quantities, (int)(count($quantities) / 2));

        $firstAvg = array_sum($firstHalf) / count($firstHalf);
        $secondAvg = array_sum($secondHalf) / count($secondHalf);

        $change = (($secondAvg - $firstAvg) / $firstAvg) * 100;

        if ($change > 5) {
            return 'upward';
        } elseif ($change < -5) {
            return 'downward';
        } else {
            return 'stable';
        }
    }

    protected function detectSeasonalPattern($metrics)
    {
        // Detect which month has the highest sales
        $byMonth = $metrics->groupBy(function ($metric) {
            return $metric->metric_date->month;
        });

        $monthAverages = $byMonth->map(function ($group) {
            return $group->avg('quantity_sold');
        });

        if ($monthAverages->count() > 0) {
            return (int)$monthAverages->keys()[0];
        }

        return 1;
    }

    protected function calculateYoYChange($companyId, $panelTypeId)
    {
        $thisYearMetrics = SalesMetric::where('company_id', $companyId)
            ->where('panel_type_id', $panelTypeId)
            ->where('metric_date', '>=', now()->subYear())
            ->sum('quantity_sold');

        $lastYearMetrics = SalesMetric::where('company_id', $companyId)
            ->where('panel_type_id', $panelTypeId)
            ->whereBetween('metric_date', [now()->subYears(2), now()->subYear()])
            ->sum('quantity_sold');

        if ($lastYearMetrics == 0) {
            return null;
        }

        return round((($thisYearMetrics - $lastYearMetrics) / $lastYearMetrics) * 100, 4);
    }

    protected function assessPerformanceStatus($revenue, $ar, $overdueCount)
    {
        if ($revenue == 0) {
            return 'poor';
        }

        $arRatio = $ar / $revenue;

        if ($arRatio > 0.75) {
            return 'poor';
        } elseif ($arRatio > 0.5 || $overdueCount > 10) {
            return 'average';
        } elseif ($arRatio > 0.25 || $overdueCount > 5) {
            return 'good';
        } else {
            return 'excellent';
        }
    }

    public function getAnalyticsSnapshot($companyId, $date = null)
    {
        $date = $date ?? now()->toDateString();

        return AnalyticsSnapshot::where('company_id', $companyId)
            ->where('snapshot_date', $date)
            ->first();
    }

    public function getTrendAnalysis($companyId, $panelTypeId = null, $periodDays = 30)
    {
        $query = TrendAnalysis::where('company_id', $companyId)
            ->where('period_days', $periodDays)
            ->where('analysis_date', '>=', now()->subDays(7));

        if ($panelTypeId) {
            $query->where('panel_type_id', $panelTypeId);
        }

        return $query->get();
    }
}
