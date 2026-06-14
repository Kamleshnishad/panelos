<?php

namespace App\Services;

use App\Models\CoilStock;
use App\Models\ChemicalStock;
use App\Models\StockTransaction;
use App\Models\StockAllocation;
use App\Models\LowStockAlert;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function addCoilStock($stockId, $quantity, $notes = null, $companyId = null)
    {
        return DB::transaction(function () use ($stockId, $quantity, $notes, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            // $stockId is the CoilStock primary id (record keyed by panel_type)
            $stock = CoilStock::where('company_id', $companyId)
                ->findOrFail($stockId);

            $newQuantity = $stock->quantity_in_stock + $quantity;
            $stock->update([
                'quantity_in_stock' => $newQuantity,
                'last_stock_in' => now()
            ]);

            $this->createTransaction($stock, 'in', $quantity, 'kg', null, $notes);

            $this->checkAndCreateAlert($stock);

            return $stock;
        });
    }

    public function removeCoilStock($stockId, $quantity, $notes = null, $companyId = null)
    {
        return DB::transaction(function () use ($stockId, $quantity, $notes, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $stock = CoilStock::where('company_id', $companyId)
                ->findOrFail($stockId);

            if ($stock->getAvailableQuantity() < $quantity) {
                throw new \Exception('Insufficient stock available. Available: ' . $stock->getAvailableQuantity());
            }

            $newQuantity = $stock->quantity_in_stock - $quantity;
            $stock->update([
                'quantity_in_stock' => $newQuantity,
                'last_stock_out' => now()
            ]);

            $this->createTransaction($stock, 'out', $quantity, 'kg', null, $notes);

            $this->checkAndCreateAlert($stock);

            return $stock;
        });
    }

    public function adjustCoilStock($stockId, $newQuantity, $reason, $companyId = null)
    {
        return DB::transaction(function () use ($stockId, $newQuantity, $reason, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $stock = CoilStock::where('company_id', $companyId)
                ->findOrFail($stockId);

            $difference = $newQuantity - $stock->quantity_in_stock;
            $type = $difference >= 0 ? 'in' : 'out';
            $quantity = abs($difference);

            $stock->update(['quantity_in_stock' => $newQuantity]);

            $this->createTransaction($stock, 'adjustment', $quantity, 'kg', null, "Adjustment: {$reason}");

            $this->checkAndCreateAlert($stock);

            return $stock;
        });
    }

    public function addChemicalStock($stockId, $quantity, $unit = null, $batchNumber = null, $expiryDate = null, $notes = null, $companyId = null)
    {
        return DB::transaction(function () use ($stockId, $quantity, $unit, $batchNumber, $expiryDate, $notes, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            if ($expiryDate && now()->parse($expiryDate)->isPast()) {
                throw new \Exception('Cannot add expired chemical');
            }

            // $stockId is the ChemicalStock primary id
            $stock = ChemicalStock::where('company_id', $companyId)
                ->findOrFail($stockId);

            $newQuantity = $stock->quantity_in_stock + $quantity;
            $stock->update([
                'quantity_in_stock' => $newQuantity,
                'batch_number' => $batchNumber ?? $stock->batch_number,
                'expiry_date' => $expiryDate ?? $stock->expiry_date,
                'last_stock_in' => now()
            ]);

            $this->createTransaction($stock, 'in', $quantity, $stock->unit, null, $notes);

            $this->checkAndCreateAlert($stock);

            return $stock;
        });
    }

    public function removeChemicalStock($stockId, $quantity, $notes = null, $companyId = null)
    {
        return DB::transaction(function () use ($stockId, $quantity, $notes, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $stock = ChemicalStock::where('company_id', $companyId)
                ->findOrFail($stockId);

            if ($stock->getAvailableQuantity() < $quantity) {
                throw new \Exception('Insufficient chemical stock. Available: ' . $stock->getAvailableQuantity());
            }

            $newQuantity = $stock->quantity_in_stock - $quantity;
            $stock->update([
                'quantity_in_stock' => $newQuantity,
                'last_stock_out' => now()
            ]);

            $this->createTransaction($stock, 'out', $quantity, $stock->unit, null, $notes);

            $this->checkAndCreateAlert($stock);

            return $stock;
        });
    }

    public function adjustChemicalStock($stockId, $newQuantity, $reason, $companyId = null)
    {
        return DB::transaction(function () use ($stockId, $newQuantity, $reason, $companyId) {
            $companyId = $companyId ?? auth()->user()->company_id;

            $stock = ChemicalStock::where('company_id', $companyId)
                ->findOrFail($stockId);

            $difference = $newQuantity - $stock->quantity_in_stock;
            $type = $difference >= 0 ? 'in' : 'out';
            $quantity = abs($difference);

            $stock->update(['quantity_in_stock' => $newQuantity]);

            $this->createTransaction($stock, 'adjustment', $quantity, $stock->unit, null, "Adjustment: {$reason}");

            $this->checkAndCreateAlert($stock);

            return $stock;
        });
    }

    public function getStockLevel($itemType, $itemId, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $modelClass = $itemType === 'coil' ? CoilStock::class : ChemicalStock::class;

        return $modelClass::where('company_id', $companyId)
            ->where($itemType === 'coil' ? 'coil_id' : 'chemical_id', $itemId)
            ->first();
    }

    public function getStockHistory($itemType, $itemId, $days = 30, $companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $stock = $this->getStockLevel($itemType, $itemId, $companyId);

        if (!$stock) {
            return collect();
        }

        return $stock->transactions()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function checkLowStock($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        // Check coils
        $lowCoils = CoilStock::where('company_id', $companyId)
            ->whereColumn('quantity_in_stock', '<=', 'reorder_level')
            ->get();

        foreach ($lowCoils as $coil) {
            LowStockAlert::createIfNeeded('coil', $coil->id, $coil->quantity_in_stock, $coil->reorder_level);
        }

        // Check chemicals
        $lowChemicals = ChemicalStock::where('company_id', $companyId)
            ->whereColumn('quantity_in_stock', '<=', 'reorder_level')
            ->get();

        foreach ($lowChemicals as $chemical) {
            LowStockAlert::createIfNeeded('chemical', $chemical->id, $chemical->quantity_in_stock, $chemical->reorder_level);
        }

        // Check expiring chemicals
        $expiringChemicals = ChemicalStock::where('company_id', $companyId)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->get();

        foreach ($expiringChemicals as $chemical) {
            if ($chemical->isExpiring()) {
                LowStockAlert::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'item_type' => 'chemical',
                        'item_id' => $chemical->id,
                        'alert_type' => 'expiring_soon',
                        'status' => 'active'
                    ],
                    [
                        'current_quantity' => $chemical->quantity_in_stock,
                        'reorder_level' => $chemical->reorder_level,
                        'alert_sent_at' => now()
                    ]
                );
            }
        }
    }

    public function getReorderSuggestions($companyId = null)
    {
        $companyId = $companyId ?? auth()->user()->company_id;

        $coils = CoilStock::where('company_id', $companyId)
            ->where('quantity_in_stock', '<=', DB::raw('reorder_level'))
            ->with('panelType')
            ->get();

        $chemicals = ChemicalStock::where('company_id', $companyId)
            ->where('quantity_in_stock', '<=', DB::raw('reorder_level'))
            ->get();

        return [
            'coils' => $coils,
            'chemicals' => $chemicals
        ];
    }

    private function createTransaction($stock, $type, $quantity, $unit, $referenceNo = null, $notes = null)
    {
        return StockTransaction::create([
            'company_id' => $stock->company_id,
            'transactionable_id' => $stock->id,
            'transactionable_type' => get_class($stock),
            'type' => $type,
            'quantity' => $quantity,
            'unit' => $unit,
            'reference_no' => $referenceNo,
            'notes' => $notes,
            'transaction_date' => now(),
            'created_by_user_id' => auth()?->user()?->id
        ]);
    }

    private function checkAndCreateAlert($stock)
    {
        if ($stock->isLowStock()) {
            LowStockAlert::createIfNeeded(
                $stock instanceof CoilStock ? 'coil' : 'chemical',
                $stock->id,
                $stock->quantity_in_stock,
                $stock->reorder_level
            );
        }

        if ($stock instanceof ChemicalStock && $stock->isExpiring()) {
            LowStockAlert::updateOrCreate(
                [
                    'company_id' => $stock->company_id,
                    'item_type' => 'chemical',
                    'item_id' => $stock->id,
                    'alert_type' => 'expiring_soon',
                    'status' => 'active'
                ],
                [
                    'current_quantity' => $stock->quantity_in_stock,
                    'reorder_level' => $stock->reorder_level,
                    'alert_sent_at' => now()
                ]
            );
        }
    }
}
