<?php

namespace App\Services;

use App\Models\ChemicalStock;
use App\Models\CoilStock;
use App\Models\ConsumableStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class ProcurementService
{
    public function __construct(private StockService $stock) {}

    // ── Suppliers ────────────────────────────────────────────────────────
    public function listSuppliers(int $companyId)
    {
        return Supplier::where('company_id', $companyId)->orderBy('name')->get();
    }

    public function createSupplier(int $companyId, array $data): Supplier
    {
        return Supplier::create([
            'company_id' => $companyId,
            'name'       => $data['name'],
            'phone'      => $data['phone'] ?? null,
            'gstin'      => $data['gstin'] ?? null,
            'email'      => $data['email'] ?? null,
            'address'    => $data['address'] ?? null,
        ]);
    }

    public function updateSupplier(Supplier $supplier, array $data): Supplier
    {
        $supplier->update(collect($data)->only(['name', 'phone', 'gstin', 'email', 'address'])->toArray());
        return $supplier->fresh();
    }

    /** All stock rows that can be put on a PO line. */
    public function purchasableItems(int $companyId): array
    {
        $coils = CoilStock::where('company_id', $companyId)->with('panelType')->get()
            ->map(fn ($c) => [
                'material_kind' => 'coil', 'stock_id' => $c->id,
                'name' => 'Coil — ' . ($c->panelType->name ?? ('Panel #' . $c->panel_type_id)),
                'unit' => 'kg', 'quantity_in_stock' => (float) $c->quantity_in_stock, 'unit_cost' => (float) $c->unit_cost,
            ]);
        $chem = ChemicalStock::where('company_id', $companyId)->get()
            ->map(fn ($c) => [
                'material_kind' => 'chemical', 'stock_id' => $c->id, 'name' => $c->name,
                'unit' => $c->unit, 'quantity_in_stock' => (float) $c->quantity_in_stock, 'unit_cost' => (float) $c->unit_cost,
            ]);
        $cons = ConsumableStock::where('company_id', $companyId)->get()
            ->map(fn ($c) => [
                'material_kind' => 'consumable', 'stock_id' => $c->id, 'name' => $c->name,
                'unit' => $c->unit, 'quantity_in_stock' => (float) $c->quantity_in_stock, 'unit_cost' => (float) $c->unit_cost,
            ]);
        return $coils->concat($chem)->concat($cons)->values()->all();
    }

    /**
     * Reorder suggestion: every stock item at/below its reorder level (coil,
     * chemical, consumable), shaped as ready-to-create PO item rows. Suggested
     * quantity tops the item back up to ~2× reorder level (min = the shortfall).
     */
    public function reorderSuggestion(int $companyId): array
    {
        $items = [];

        foreach (CoilStock::where('company_id', $companyId)
            ->where('quantity_in_stock', '<=', DB::raw('reorder_level'))->with('panelType')->get() as $c) {
            $items[] = $this->reorderRow('coil', $c->id,
                'Coil — ' . ($c->panelType->name ?? ('Panel #' . $c->panel_type_id)),
                'kg', $c->quantity_in_stock, $c->reorder_level, $c->unit_cost);
        }
        foreach (ChemicalStock::where('company_id', $companyId)
            ->where('quantity_in_stock', '<=', DB::raw('reorder_level'))->get() as $c) {
            $items[] = $this->reorderRow('chemical', $c->id, $c->name,
                $c->unit, $c->quantity_in_stock, $c->reorder_level, $c->unit_cost);
        }
        foreach (ConsumableStock::where('company_id', $companyId)
            ->where('quantity_in_stock', '<=', DB::raw('reorder_level'))->get() as $c) {
            $items[] = $this->reorderRow('consumable', $c->id, $c->name,
                $c->unit, $c->quantity_in_stock, $c->reorder_level, $c->unit_cost);
        }

        return ['items' => $items, 'count' => count($items)];
    }

    private function reorderRow(string $kind, int $id, string $name, string $unit, $onHand, $reorder, $unitCost): array
    {
        $onHand  = (float) $onHand;
        $reorder = (float) $reorder;
        $target  = max($reorder * 2, $reorder + 1);          // top up to ~2× reorder
        $qty     = round(max($target - $onHand, $reorder), 2); // never less than one reorder qty
        return [
            'material_kind' => $kind,
            'stock_id'      => $id,
            'item_name'     => $name,
            'unit'          => $unit,
            'quantity'      => $qty,
            'rate'          => (float) ($unitCost ?? 0),
            'on_hand'       => round($onHand, 2),
            'reorder_level' => round($reorder, 2),
        ];
    }

    // ── Purchase Orders ──────────────────────────────────────────────────
    public function listPurchaseOrders(int $companyId, array $filters = [])
    {
        $q = PurchaseOrder::where('company_id', $companyId)->with('supplier', 'items');
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        return $q->orderByDesc('created_at')->get();
    }

    public function getPurchaseOrder(PurchaseOrder $po): PurchaseOrder
    {
        return $po->load('supplier', 'items');
    }

    public function createPurchaseOrder(int $companyId, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($companyId, $data) {
            $taxPct = (float) ($data['tax_pct'] ?? 0);
            $po = PurchaseOrder::create([
                'company_id'    => $companyId,
                'supplier_id'   => $data['supplier_id'] ?? null,
                'po_no'         => $this->generatePoNumber($companyId),
                'status'        => 'ordered',
                'order_date'    => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'tax_pct'       => $taxPct,
                'notes'         => $data['notes'] ?? null,
            ]);

            $subtotal = 0;
            foreach ($data['items'] ?? [] as $row) {
                $qty  = (float) $row['quantity'];
                $rate = (float) ($row['rate'] ?? 0);
                $amt  = $qty * $rate;
                $subtotal += $amt;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'material_kind'     => $row['material_kind'],
                    'stock_id'          => $row['stock_id'],
                    'item_name'         => $row['item_name'] ?? 'Item',
                    'unit'              => $row['unit'] ?? 'kg',
                    'quantity'          => $qty,
                    'rate'              => $rate,
                    'amount'            => $amt,
                    'received_qty'      => 0,
                ]);
            }

            $taxAmt = $subtotal * $taxPct / 100;
            $po->update(['subtotal' => $subtotal, 'tax_amount' => $taxAmt, 'total' => $subtotal + $taxAmt]);

            return $po->load('supplier', 'items');
        });
    }

    /**
     * Receive goods against a PO. Each receipt adds to its stock row, updates
     * unit_cost, and (chemicals) batch/expiry. PO status recomputed.
     * @param array $receipts [ ['po_item_id'=>, 'received_qty'=>, 'cost'=>, 'batch_no'=>, 'expiry_date'=>], ... ]
     */
    public function receive(PurchaseOrder $po, array $receipts): PurchaseOrder
    {
        if ($po->status === 'cancelled') {
            throw new \Exception('Cannot receive against a cancelled PO.');
        }

        return DB::transaction(function () use ($po, $receipts) {
            $companyId = $po->company_id;
            $note = 'GRN — PO ' . $po->po_no;

            foreach ($receipts as $r) {
                $qty = (float) ($r['received_qty'] ?? 0);
                if ($qty <= 0) continue;

                $item = PurchaseOrderItem::where('id', $r['po_item_id'] ?? 0)
                    ->where('purchase_order_id', $po->id)->first();
                if (!$item) continue;

                $cost = isset($r['cost']) && $r['cost'] !== null ? (float) $r['cost'] : (float) $item->rate;

                if ($item->material_kind === 'coil') {
                    $this->stock->addCoilStock($item->stock_id, $qty, $note, $companyId);
                    CoilStock::where('id', $item->stock_id)->update(['unit_cost' => $cost]);
                } elseif ($item->material_kind === 'chemical') {
                    $this->stock->addChemicalStock($item->stock_id, $qty, null, $r['batch_no'] ?? null, $r['expiry_date'] ?? null, $note, $companyId);
                    ChemicalStock::where('id', $item->stock_id)->update(['unit_cost' => $cost]);
                } else {
                    $this->stock->addConsumableStock($item->stock_id, $qty, $note, $companyId);
                    ConsumableStock::where('id', $item->stock_id)->update(['unit_cost' => $cost]);
                }

                $item->update(['received_qty' => (float) $item->received_qty + $qty]);
            }

            // Recompute PO status
            $po->load('items');
            $allDone = $po->items->every(fn ($i) => (float) $i->received_qty >= (float) $i->quantity);
            $anyDone = $po->items->contains(fn ($i) => (float) $i->received_qty > 0);
            $po->update(['status' => $allDone ? 'received' : ($anyDone ? 'partial' : 'ordered')]);

            return $po->fresh('supplier', 'items');
        });
    }

    public function cancel(PurchaseOrder $po): void
    {
        if ($po->status === 'received') {
            throw new \Exception('Cannot cancel a fully received PO.');
        }
        $po->update(['status' => 'cancelled']);
    }

    // ── Valuation ────────────────────────────────────────────────────────
    public function stockValuation(int $companyId): array
    {
        $coil = (float) CoilStock::where('company_id', $companyId)->sum(DB::raw('quantity_in_stock * unit_cost'));
        $chem = (float) ChemicalStock::where('company_id', $companyId)->sum(DB::raw('quantity_in_stock * unit_cost'));
        $cons = (float) ConsumableStock::where('company_id', $companyId)->sum(DB::raw('quantity_in_stock * unit_cost'));

        return [
            'coil'      => round($coil, 2),
            'chemical'  => round($chem, 2),
            'consumable'=> round($cons, 2),
            'total'     => round($coil + $chem + $cons, 2),
        ];
    }

    private function generatePoNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $last = PurchaseOrder::withTrashed()->where('company_id', $companyId)->orderByDesc('id')->value('po_no');
        $seq = 1;
        if ($last) { $parts = explode('-', $last); $seq = ((int) end($parts)) + 1; }
        return sprintf('PO-%s-%04d', $year, $seq);
    }
}
