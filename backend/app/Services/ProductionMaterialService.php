<?php

namespace App\Services;

use App\Models\ChemicalStock;
use App\Models\CoilStock;
use App\Models\ConsumableStock;
use App\Models\ProductionMaterialUsage;
use App\Models\ProductionRun;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — issues (deducts) raw material from stock when a production run
 * starts, and records what was consumed. Reuses StockService for the actual
 * deduction + ledger. Availability is checked via MaterialBomService; if short
 * and not forced, the issue is blocked (caller surfaces the shortage).
 */
class ProductionMaterialService
{
    public function __construct(
        private MaterialBomService $bom,
        private StockService $stock,
    ) {}

    /** Already issued for this run? (avoid double-deduction on re-start) */
    public function alreadyIssued(ProductionRun $run): bool
    {
        return ProductionMaterialUsage::where('run_id', $run->id)->exists();
    }

    /**
     * Deduct material for a run. Returns the usage summary.
     * @throws \Exception when stock is short and $force is false.
     */
    public function issueForRun(ProductionRun $run, bool $force = false): array
    {
        if ($this->alreadyIssued($run)) {
            return ['skipped' => true, 'reason' => 'Material already issued for this run.'];
        }

        $req = $this->bom->requirementForRun($run);

        if (!$req['all_ok'] && !$force) {
            $short = collect($req['lines'])->where('ok', false)
                ->map(fn ($l) => "{$l['label']} (short {$l['short_by']} {$l['unit']})")->implode(', ');
            throw new \Exception('Insufficient stock: ' . $short . '. Add stock or start with override.');
        }

        return DB::transaction(function () use ($run, $req) {
            $companyId = $run->company_id;
            $note = 'Production run ' . $run->run_no;
            $userId = auth()?->user()?->id;
            $issued = [];

            foreach ($req['lines'] as $l) {
                $rows = $this->resolveRows($l['material_kind'], $l['ref'], $companyId);
                $stockId = $this->deductFifo($l['material_kind'], $rows, (float) $l['required'], $note, $companyId);

                ProductionMaterialUsage::create([
                    'company_id'         => $companyId,
                    'run_id'             => $run->id,
                    'batch_id'           => null,
                    'material_kind'      => $l['material_kind'],
                    'stock_id'           => $stockId,
                    'material_name'      => $l['label'],
                    'unit'               => $l['unit'],
                    'standard_qty'       => $l['standard'],
                    'issued_qty'         => $l['required'],
                    'actual_qty'         => null,
                    'wastage_pct'        => $l['wastage_pct'] ?? 0,
                    'notes'              => $note,
                    'created_by_user_id' => $userId,
                ]);

                $issued[] = ['material' => $l['label'], 'qty' => $l['required'], 'unit' => $l['unit']];
            }

            return ['skipped' => false, 'issued' => $issued, 'all_ok' => $req['all_ok']];
        });
    }

    /** Candidate stock rows for a requirement line. */
    private function resolveRows(string $kind, $ref, int $companyId)
    {
        if ($kind === 'coil') {
            return CoilStock::where('company_id', $companyId)->where('panel_type_id', $ref)->orderBy('id')->get();
        }
        if ($kind === 'chemical') {
            return ChemicalStock::where('company_id', $companyId)->where('category', $ref)->orderBy('id')->get();
        }
        return ConsumableStock::where('company_id', $companyId)->where('category', $ref)->orderBy('id')->get();
    }

    /**
     * FIFO-deduct $required across rows, capped at each row's on-hand so the
     * underlying StockService guards never throw (handles the force case where
     * stock is short — deducts what's available). Returns first stock id used.
     */
    private function deductFifo(string $kind, $rows, float $required, string $note, int $companyId): ?int
    {
        $remaining = $required;
        $firstId = null;

        foreach ($rows as $row) {
            if ($remaining <= 0) break;
            $onHand = (float) $row->quantity_in_stock;
            $take = min($remaining, $onHand);
            if ($take <= 0) continue;

            if ($kind === 'coil') {
                $this->stock->removeCoilStock($row->id, $take, $note, $companyId);
            } elseif ($kind === 'chemical') {
                $this->stock->removeChemicalStock($row->id, $take, $note, $companyId);
            } else {
                $this->stock->removeConsumableStock($row->id, $take, $note, $companyId);
            }

            $remaining -= $take;
            $firstId = $firstId ?? $row->id;
        }

        return $firstId;
    }

    /** All material usage rows for a run (for detail/report). */
    public function usageForRun(ProductionRun $run)
    {
        return ProductionMaterialUsage::where('run_id', $run->id)->get();
    }
}
