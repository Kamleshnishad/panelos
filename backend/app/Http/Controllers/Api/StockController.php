<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoilStock;
use App\Models\ChemicalStock;
use App\Models\StockTransaction;
use App\Models\LowStockAlert;
use App\Services\StockService;
use App\Services\StockDashboardService;
use Illuminate\Http\Request;

class StockController extends Controller
{
    protected $stockService;
    protected $dashboardService;

    public function __construct(StockService $stockService, StockDashboardService $dashboardService)
    {
        $this->stockService = $stockService;
        $this->dashboardService = $dashboardService;
    }

    public function getCoilInventory(Request $request)
    {
        try {
            $companyId = auth()->user()->company_id;

            $query = CoilStock::where('company_id', $companyId)
                ->with('panelType')
                ->orderBy('panel_type_id', 'asc');

            if ($request->has('low_stock')) {
                $query->whereColumn('quantity_in_stock', '<=', 'reorder_level');
            }

            if ($request->has('search')) {
                $search = $request->input('search');
                $query->whereHas('panelType', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
            }

            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);

            $coils = $query->paginate($perPage, ['*'], 'page', $page);

            if (!auth()->user()->canViewCost()) $coils->getCollection()->each->makeHidden('unit_cost');
            return $this->apiResponse(true, $coils, 'Coil inventory retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }

    public function getCoilDetail($id)
    {
        try {
            $companyId = auth()->user()->company_id;

            $stock = CoilStock::where('company_id', $companyId)
                ->with('panelType', 'transactions')
                ->findOrFail($id);

            if (!auth()->user()->canViewCost()) $stock->makeHidden('unit_cost');
            return $this->apiResponse(true, $stock, 'Coil detail retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 404);
        }
    }

    public function addCoilStock(Request $request, $id)
    {
        try {
            $request->validate([
                'quantity' => 'required|numeric|min:0.01',
                'notes' => 'nullable|string'
            ]);

            $companyId = auth()->user()->company_id;

            $stock = $this->stockService->addCoilStock(
                $id,
                $request->input('quantity'),
                $request->input('notes'),
                $companyId
            );

            return $this->apiResponse(true, $stock, 'Coil stock added successfully', 201);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function removeCoilStock(Request $request, $id)
    {
        try {
            $request->validate([
                'quantity' => 'required|numeric|min:0.01',
                'notes' => 'nullable|string'
            ]);

            $companyId = auth()->user()->company_id;

            $stock = $this->stockService->removeCoilStock(
                $id,
                $request->input('quantity'),
                $request->input('notes'),
                $companyId
            );

            return $this->apiResponse(true, $stock, 'Coil stock removed successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function adjustCoilStock(Request $request, $id)
    {
        try {
            $request->validate([
                'new_quantity' => 'required|numeric|min:0',
                'reason' => 'required|string'
            ]);

            $companyId = auth()->user()->company_id;

            $stock = $this->stockService->adjustCoilStock(
                $id,
                $request->input('new_quantity'),
                $request->input('reason'),
                $companyId
            );

            return $this->apiResponse(true, $stock, 'Coil stock adjusted successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function updateCoilReorder(Request $request, $id)
    {
        try {
            $request->validate(['reorder_level' => 'required|numeric|min:0']);
            $stock = CoilStock::where('company_id', auth()->user()->company_id)->findOrFail($id);
            $stock->update(['reorder_level' => $request->input('reorder_level')]);
            return $this->apiResponse(true, $stock, 'Reorder level updated');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function updateChemicalReorder(Request $request, $id)
    {
        try {
            $request->validate(['reorder_level' => 'required|numeric|min:0']);
            $stock = ChemicalStock::where('company_id', auth()->user()->company_id)->findOrFail($id);
            $stock->update(['reorder_level' => $request->input('reorder_level')]);
            return $this->apiResponse(true, $stock, 'Reorder level updated');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function getChemicalInventory(Request $request)
    {
        try {
            $companyId = auth()->user()->company_id;

            $query = ChemicalStock::where('company_id', $companyId)
                ->orderBy('name', 'asc');

            if ($request->has('low_stock')) {
                $query->whereColumn('quantity_in_stock', '<=', 'reorder_level');
            }

            if ($request->has('expiring')) {
                $query->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<=', now()->addDays(30));
            }

            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where('name', 'like', "%{$search}%");
            }

            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);

            $chemicals = $query->paginate($perPage, ['*'], 'page', $page);

            if (!auth()->user()->canViewCost()) $chemicals->getCollection()->each->makeHidden('unit_cost');
            return $this->apiResponse(true, $chemicals, 'Chemical inventory retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }

    public function getChemicalDetail($id)
    {
        try {
            $companyId = auth()->user()->company_id;

            $stock = ChemicalStock::where('company_id', $companyId)
                ->with('transactions')
                ->findOrFail($id);

            if (!auth()->user()->canViewCost()) $stock->makeHidden('unit_cost');
            return $this->apiResponse(true, $stock, 'Chemical detail retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 404);
        }
    }

    public function createChemical(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'          => 'required|string|max:100',
                'category'      => 'nullable|string|max:50',
                'unit'          => 'required|string|max:20',
                'reorder_level' => 'nullable|numeric|min:0',
                'batch_number'  => 'nullable|string|max:100',
                'expiry_date'   => 'nullable|date',
            ]);

            $companyId = auth()->user()->company_id;

            $stock = ChemicalStock::create([
                'company_id'        => $companyId,
                'chemical_id'       => null,
                'name'              => $validated['name'],
                'category'          => $validated['category'] ?? null,
                'unit'              => $validated['unit'],
                'quantity_in_stock' => 0,
                'reorder_level'     => $validated['reorder_level'] ?? 0,
                'batch_number'      => $validated['batch_number'] ?? null,
                'expiry_date'       => $validated['expiry_date'] ?? null,
            ]);

            return $this->apiResponse(true, $stock, 'Chemical created successfully', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->apiResponse(false, $e->errors(), 'Validation failed', 422);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function addChemicalStock(Request $request, $id)
    {
        try {
            $request->validate([
                'quantity' => 'required|numeric|min:0.01',
                'unit' => 'nullable|string',
                'batch_number' => 'nullable|string',
                'expiry_date' => 'nullable|date',
                'notes' => 'nullable|string'
            ]);

            $companyId = auth()->user()->company_id;

            $stock = $this->stockService->addChemicalStock(
                $id,
                $request->input('quantity'),
                $request->input('unit'),
                $request->input('batch_number'),
                $request->input('expiry_date'),
                $request->input('notes'),
                $companyId
            );

            return $this->apiResponse(true, $stock, 'Chemical stock added successfully', 201);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function removeChemicalStock(Request $request, $id)
    {
        try {
            $request->validate([
                'quantity' => 'required|numeric|min:0.01',
                'notes' => 'nullable|string'
            ]);

            $companyId = auth()->user()->company_id;

            $stock = $this->stockService->removeChemicalStock(
                $id,
                $request->input('quantity'),
                $request->input('notes'),
                $companyId
            );

            return $this->apiResponse(true, $stock, 'Chemical stock removed successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function adjustChemicalStock(Request $request, $id)
    {
        try {
            $request->validate([
                'new_quantity' => 'required|numeric|min:0',
                'reason' => 'required|string'
            ]);

            $companyId = auth()->user()->company_id;

            $stock = $this->stockService->adjustChemicalStock(
                $id,
                $request->input('new_quantity'),
                $request->input('reason'),
                $companyId
            );

            return $this->apiResponse(true, $stock, 'Chemical stock adjusted successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function createCoil(Request $request)
    {
        try {
            $companyId = auth()->user()->company_id;

            $validated = $request->validate([
                'panel_type_id' => 'required|integer|exists:panel_types,id',
                'reorder_level' => 'nullable|numeric|min:0',
                'unit_cost'     => 'nullable|numeric|min:0',
            ]);

            // One coil-stock row per panel type
            $exists = CoilStock::where('company_id', $companyId)
                ->where('panel_type_id', $validated['panel_type_id'])->first();
            if ($exists) {
                return $this->apiResponse(false, null, 'Coil stock for this panel type already exists.', 422);
            }

            $stock = CoilStock::create([
                'company_id'        => $companyId,
                'panel_type_id'     => $validated['panel_type_id'],
                'quantity_in_stock' => 0,
                'reorder_level'     => $validated['reorder_level'] ?? 0,
                'unit_cost'         => $validated['unit_cost'] ?? 0,
            ]);

            return $this->apiResponse(true, $stock->load('panelType'), 'Coil stock created successfully', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->apiResponse(false, $e->errors(), 'Validation failed', 422);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    // ── Consumables (oil/film/tape/packaging) ────────────────────────────

    public function getConsumableInventory(Request $request)
    {
        try {
            $companyId = auth()->user()->company_id;

            $query = \App\Models\ConsumableStock::where('company_id', $companyId)->orderBy('name', 'asc');

            if ($request->has('low_stock')) {
                $query->whereColumn('quantity_in_stock', '<=', 'reorder_level');
            }
            if ($request->filled('category')) {
                $query->where('category', $request->input('category'));
            }
            if ($request->has('search')) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            }

            $items = $query->paginate($request->input('per_page', 20), ['*'], 'page', $request->input('page', 1));
            if (!auth()->user()->canViewCost()) $items->getCollection()->each->makeHidden('unit_cost');
            return $this->apiResponse(true, $items, 'Consumable inventory retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }

    public function getConsumableDetail($id)
    {
        try {
            $stock = \App\Models\ConsumableStock::where('company_id', auth()->user()->company_id)
                ->with('transactions')->findOrFail($id);
            if (!auth()->user()->canViewCost()) $stock->makeHidden('unit_cost');
            return $this->apiResponse(true, $stock, 'Consumable detail retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 404);
        }
    }

    public function createConsumable(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'          => 'required|string|max:120',
                'category'      => 'nullable|string|max:50',
                'unit'          => 'required|string|max:20',
                'reorder_level' => 'nullable|numeric|min:0',
                'unit_cost'     => 'nullable|numeric|min:0',
            ]);

            $stock = \App\Models\ConsumableStock::create([
                'company_id'        => auth()->user()->company_id,
                'name'              => $validated['name'],
                'category'          => $validated['category'] ?? null,
                'unit'              => $validated['unit'],
                'quantity_in_stock' => 0,
                'reorder_level'     => $validated['reorder_level'] ?? 0,
                'unit_cost'         => $validated['unit_cost'] ?? 0,
            ]);

            return $this->apiResponse(true, $stock, 'Consumable created successfully', 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->apiResponse(false, $e->errors(), 'Validation failed', 422);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function addConsumableStock(Request $request, $id)
    {
        try {
            $request->validate(['quantity' => 'required|numeric|min:0.01', 'notes' => 'nullable|string']);
            $stock = $this->stockService->addConsumableStock($id, $request->input('quantity'), $request->input('notes'), auth()->user()->company_id);
            return $this->apiResponse(true, $stock, 'Consumable stock added successfully', 201);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function removeConsumableStock(Request $request, $id)
    {
        try {
            $request->validate(['quantity' => 'required|numeric|min:0.01', 'notes' => 'nullable|string']);
            $stock = $this->stockService->removeConsumableStock($id, $request->input('quantity'), $request->input('notes'), auth()->user()->company_id);
            return $this->apiResponse(true, $stock, 'Consumable stock removed successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function adjustConsumableStock(Request $request, $id)
    {
        try {
            $request->validate(['new_quantity' => 'required|numeric|min:0', 'reason' => 'required|string']);
            $stock = $this->stockService->adjustConsumableStock($id, $request->input('new_quantity'), $request->input('reason'), auth()->user()->company_id);
            return $this->apiResponse(true, $stock, 'Consumable stock adjusted successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function updateConsumableReorder(Request $request, $id)
    {
        try {
            $request->validate(['reorder_level' => 'required|numeric|min:0']);
            $stock = \App\Models\ConsumableStock::where('company_id', auth()->user()->company_id)->findOrFail($id);
            $stock->update(['reorder_level' => $request->input('reorder_level')]);
            return $this->apiResponse(true, $stock, 'Reorder level updated');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function getTransactions(Request $request)
    {
        try {
            $companyId = auth()->user()->company_id;

            $query = StockTransaction::where('company_id', $companyId)
                ->with([
                    'createdByUser',
                    'transactionable' => function ($morphTo) {
                        $morphTo->morphWith([
                            CoilStock::class => ['panelType'],
                        ]);
                    },
                ]);

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }

            // Filter by item kind (coil / chemical)
            if ($request->filled('kind')) {
                $modelClass = $request->input('kind') === 'coil' ? CoilStock::class : ChemicalStock::class;
                $query->where('transactionable_type', $modelClass);
            }

            // Date range filters
            if ($request->filled('from_date')) {
                $query->whereDate('transaction_date', '>=', $request->input('from_date'));
            }
            if ($request->filled('to_date')) {
                $query->whereDate('transaction_date', '<=', $request->input('to_date'));
            }
            if ($request->filled('days')) {
                $query->where('transaction_date', '>=', now()->subDays((int) $request->input('days')));
            }

            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 25);

            $transactions = $query->orderBy('transaction_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->apiResponse(true, $transactions, 'Transactions retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }

    public function getTransaction($id)
    {
        try {
            $companyId = auth()->user()->company_id;

            $transaction = StockTransaction::where('company_id', $companyId)
                ->with('transactionable', 'createdByUser')
                ->findOrFail($id);

            return $this->apiResponse(true, $transaction, 'Transaction retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 404);
        }
    }

    public function getAlerts(Request $request)
    {
        try {
            $companyId = auth()->user()->company_id;

            $query = LowStockAlert::where('company_id', $companyId);

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('type')) {
                $query->where('alert_type', $request->input('type'));
            }

            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);

            $alerts = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            return $this->apiResponse(true, $alerts, 'Alerts retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }

    public function resolveAlert($id)
    {
        try {
            $companyId = auth()->user()->company_id;

            $alert = LowStockAlert::where('company_id', $companyId)
                ->findOrFail($id);

            $alert->resolve();

            return $this->apiResponse(true, $alert, 'Alert resolved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function getMaterialSettings()
    {
        try {
            $s = \App\Models\MaterialSetting::firstOrCreate(['company_id' => auth()->user()->company_id]);
            return $this->apiResponse(true, $s, 'Material settings retrieved');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }

    public function updateMaterialSettings(Request $request)
    {
        try {
            $validated = $request->validate([
                'steel_density'          => 'nullable|numeric|min:0.1|max:20',
                'iso_polyol_ratio'       => 'nullable|numeric|min:0.1|max:5',
                'foam_overpack_pct'      => 'nullable|numeric|min:0|max:50',
                'wastage_coil_pct'       => 'nullable|numeric|min:0|max:50',
                'wastage_chemical_pct'   => 'nullable|numeric|min:0|max:50',
                'wastage_consumable_pct' => 'nullable|numeric|min:0|max:50',
                'film_per_sqm'           => 'nullable|numeric|min:0|max:10',
                'tape_per_panel_m'       => 'nullable|numeric|min:0|max:100',
            ]);
            $s = \App\Models\MaterialSetting::firstOrCreate(['company_id' => auth()->user()->company_id]);
            $s->update($validated);
            return $this->apiResponse(true, $s->fresh(), 'Material settings updated');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->apiResponse(false, $e->errors(), 'Validation failed', 422);
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 400);
        }
    }

    public function getDashboard()
    {
        try {
            $companyId = auth()->user()->company_id;

            $dashboardData = $this->dashboardService->getDashboardData($companyId);

            return $this->apiResponse(true, $dashboardData, 'Dashboard data retrieved successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }

    public function getInventoryReport()
    {
        try {
            $companyId = auth()->user()->company_id;

            $report = $this->dashboardService->getInventoryReport($companyId);

            return $this->apiResponse(true, $report, 'Inventory report generated successfully');
        } catch (\Exception $e) {
            return $this->apiResponse(false, null, $e->getMessage(), 500);
        }
    }
}
