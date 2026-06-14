<?php

namespace App\Services;

use App\Models\CoilStock;
use App\Models\ChemicalStock;
use App\Models\LowStockAlert;
use App\Models\Dispatch;
use App\Models\ProductionBatch;
use Illuminate\Support\Facades\DB;

class StockDashboardService
{
    public function getDashboardData($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return [
            'total_stock_value' => $this->getTotalStockValue($companyId),
            'low_stock_items' => $this->getLowStockItems($companyId),
            'expiring_soon_chemicals' => $this->getExpiringChemicals(30, $companyId),
            'pending_dispatch_batches' => $this->getDispatchPipeline($companyId),
            'recent_transactions' => $this->getRecentTransactions($companyId, 10),
            'alerts' => $this->getAlertSummary($companyId)
        ];
    }

    public function getTotalStockValue($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        // Value = Σ quantity_in_stock × unit_cost across coil/chemical/consumable
        // (unit_cost added in inventory Phase 0; set on goods receipt).
        $coil = (float) CoilStock::where('company_id', $companyId)->sum(DB::raw('quantity_in_stock * unit_cost'));
        $chem = (float) ChemicalStock::where('company_id', $companyId)->sum(DB::raw('quantity_in_stock * unit_cost'));
        $cons = (float) \App\Models\ConsumableStock::where('company_id', $companyId)->sum(DB::raw('quantity_in_stock * unit_cost'));

        return round($coil + $chem + $cons, 2);
    }

    public function getLowStockItems($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $coils = CoilStock::where('company_id', $companyId)
            ->where('quantity_in_stock', '<=', DB::raw('reorder_level'))
            ->with('panelType')
            ->get()
            ->map(fn($item) => [
                'type' => 'coil',
                'id' => $item->id,
                'name' => $item->panelType?->name,
                'current' => $item->quantity_in_stock,
                'reorder_level' => $item->reorder_level,
                'shortage' => $item->reorder_level - $item->quantity_in_stock
            ]);

        $chemicals = ChemicalStock::where('company_id', $companyId)
            ->where('quantity_in_stock', '<=', DB::raw('reorder_level'))
            ->get()
            ->map(fn($item) => [
                'type' => 'chemical',
                'id' => $item->id,
                'name' => $item->name,
                'current' => $item->quantity_in_stock,
                'reorder_level' => $item->reorder_level,
                'shortage' => $item->reorder_level - $item->quantity_in_stock
            ]);

        return collect($coils)->merge($chemicals)
            ->sortByDesc('shortage')
            ->values();
    }

    public function getExpiringChemicals($days = 30, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return ChemicalStock::where('company_id', $companyId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->where('quantity_in_stock', '>', 0)
            ->get()
            ->map(fn($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'batch_number' => $item->batch_number,
                'expiry_date' => $item->expiry_date,
                'days_remaining' => $item->expiry_date->diffInDays(now()),
                'quantity' => $item->quantity_in_stock,
                'unit' => $item->unit,
                'status' => $item->expiry_date->isPast() ? 'expired' : 'expiring_soon'
            ])
            ->sortBy('days_remaining');
    }

    public function getDispatchPipeline($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return ProductionBatch::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'dispatched'])
            ->doesntHave('dispatches')
            ->with('order', 'order.customer')
            ->count();
    }

    public function getRecentTransactions($companyId = null, $limit = 10)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return \App\Models\StockTransaction::where('company_id', $companyId)
            ->with('transactionable', 'createdByUser')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'item_name' => $t->item_name,
                'quantity' => $t->quantity,
                'unit' => $t->unit,
                'reference' => $t->reference_no,
                'created_by' => $t->createdByUser?->name,
                'created_at' => $t->created_at
            ]);
    }

    public function getAlertSummary($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        return [
            'total_active' => LowStockAlert::where('company_id', $companyId)
                ->where('status', 'active')
                ->count(),
            'low_stock' => LowStockAlert::where('company_id', $companyId)
                ->where('status', 'active')
                ->where('alert_type', 'low_stock')
                ->count(),
            'expiring_soon' => LowStockAlert::where('company_id', $companyId)
                ->where('status', 'active')
                ->where('alert_type', 'expiring_soon')
                ->count(),
            'out_of_stock' => LowStockAlert::where('company_id', $companyId)
                ->where('status', 'active')
                ->where('alert_type', 'out_of_stock')
                ->count()
        ];
    }

    public function getStockMovement($days = 30, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $transactions = \App\Models\StockTransaction::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy(DB::raw('DATE(created_at)'), 'type')
            ->selectRaw('DATE(created_at) as date, type, SUM(quantity) as total')
            ->orderBy('date')
            ->get();

        return $transactions->groupBy('date')
            ->map(function ($dayTransactions) {
                return [
                    'in' => $dayTransactions->where('type', 'in')->sum('total'),
                    'out' => $dayTransactions->where('type', 'out')->sum('total')
                ];
            });
    }

    public function getInventoryReport($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $coils = CoilStock::where('company_id', $companyId)
            ->with('panelType')
            ->get()
            ->map(fn($item) => [
                'type' => 'coil',
                'name' => $item->panelType?->name,
                'quantity' => $item->quantity_in_stock,
                'reorder_level' => $item->reorder_level,
                'status' => $item->isLowStock() ? 'low' : 'ok'
            ]);

        $chemicals = ChemicalStock::where('company_id', $companyId)
            ->get()
            ->map(fn($item) => [
                'type' => 'chemical',
                'name' => $item->name,
                'quantity' => $item->quantity_in_stock,
                'reorder_level' => $item->reorder_level,
                'expiry' => $item->expiry_date?->format('Y-m-d'),
                'status' => $item->isExpired() ? 'expired' : ($item->isLowStock() ? 'low' : 'ok')
            ]);

        return [
            'total_items' => $coils->count() + $chemicals->count(),
            'coils' => $coils,
            'chemicals' => $chemicals,
            'generated_at' => now()
        ];
    }
}
