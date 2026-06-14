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

    /**
     * Build a draft-PO suggestion to cover a run's material shortage.
     * Resolves each short BOM line back to a real stock row (so the PO can
     * target it) and proposes the shortfall quantity at the row's last cost.
     * Lines with no matching stock row yet are returned under 'unresolved'.
     */
    public function poSuggestionForRun(ProductionRun $run): array
    {
        $req = $this->bom->requirementForRun($run);
        $items = [];
        $unresolved = [];

        foreach (collect($req['lines'])->where('ok', false) as $l) {
            $qty = round((float) $l['short_by'], 2);
            if ($qty <= 0) continue;

            $row = $this->resolveRows($l['material_kind'], $l['ref'], $run->company_id)->first();
            if (!$row) {
                $unresolved[] = [
                    'label'  => $l['label'],
                    'qty'    => $qty,
                    'unit'   => $l['unit'],
                    'reason' => 'No matching stock item exists yet — create the stock item first, then re-check.',
                ];
                continue;
            }

            $items[] = [
                'material_kind' => $l['material_kind'],
                'stock_id'      => $row->id,
                'item_name'     => $l['label'],
                'unit'          => $l['unit'],
                'quantity'      => $qty,
                'rate'          => (float) ($row->unit_cost ?? 0),
            ];
        }

        return [
            'run_no'       => $run->run_no,
            'has_shortage' => !$req['all_ok'],
            'items'        => $items,
            'unresolved'   => $unresolved,
        ];
    }

    /**
     * Record actual consumed quantities (entered at completion). Updates each
     * usage row's actual_qty + real wastage%, and reconciles stock by the
     * difference vs what was issued at start (so inventory stays truthful).
     * @param array $actuals  [ ['id'=>, 'actual_qty'=>], ... ]
     */
    public function recordActuals(ProductionRun $run, array $actuals): void
    {
        if (empty($actuals)) return;

        DB::transaction(function () use ($run, $actuals) {
            $companyId = $run->company_id;
            foreach ($actuals as $a) {
                $id = $a['id'] ?? null;
                if ($id === null || !array_key_exists('actual_qty', $a) || $a['actual_qty'] === null) continue;

                $usage = ProductionMaterialUsage::where('id', $id)
                    ->where('run_id', $run->id)->where('company_id', $companyId)->first();
                if (!$usage) continue;

                $actual = (float) $a['actual_qty'];
                $issued = (float) $usage->issued_qty;
                $std    = (float) $usage->standard_qty;
                $delta  = $actual - $issued;   // +ve = consumed more than issued

                // Reconcile stock by the difference
                if ($usage->stock_id && abs($delta) > 0.001) {
                    $note = 'Run ' . $run->run_no . ' actual reconciliation';
                    if ($delta > 0) {
                        $this->removeCapped($usage->material_kind, $usage->stock_id, $delta, $note, $companyId);
                    } else {
                        $this->addBack($usage->material_kind, $usage->stock_id, -$delta, $note, $companyId);
                    }
                }

                $usage->update([
                    'actual_qty'  => $actual,
                    'wastage_pct' => $std > 0 ? round(($actual - $std) / $std * 100, 2) : 0,
                ]);
            }
        });
    }

    private function removeCapped(string $kind, int $stockId, float $qty, string $note, int $companyId): void
    {
        $model = $this->modelFor($kind);
        $row = $model::where('company_id', $companyId)->find($stockId);
        if (!$row) return;
        $take = min($qty, (float) $row->quantity_in_stock);
        if ($take <= 0) return;
        if ($kind === 'coil')          $this->stock->removeCoilStock($stockId, $take, $note, $companyId);
        elseif ($kind === 'chemical')  $this->stock->removeChemicalStock($stockId, $take, $note, $companyId);
        else                           $this->stock->removeConsumableStock($stockId, $take, $note, $companyId);
    }

    private function addBack(string $kind, int $stockId, float $qty, string $note, int $companyId): void
    {
        if ($qty <= 0) return;
        if ($kind === 'coil')          $this->stock->addCoilStock($stockId, $qty, $note, $companyId);
        elseif ($kind === 'chemical')  $this->stock->addChemicalStock($stockId, $qty, null, null, null, $note, $companyId);
        else                           $this->stock->addConsumableStock($stockId, $qty, $note, $companyId);
    }

    private function modelFor(string $kind): string
    {
        return $kind === 'coil' ? CoilStock::class : ($kind === 'chemical' ? ChemicalStock::class : ConsumableStock::class);
    }

    /** Wastage summary (actual vs standard) across runs in a date range. */
    public function wastageReport(int $companyId, ?string $from, ?string $to): array
    {
        $q = ProductionMaterialUsage::where('company_id', $companyId)->whereNotNull('actual_qty');
        if ($from) $q->whereDate('created_at', '>=', $from);
        if ($to)   $q->whereDate('created_at', '<=', $to);
        $rows = $q->get();

        $byMaterial = [];
        foreach ($rows as $r) {
            $key = $r->material_name;
            if (!isset($byMaterial[$key])) {
                $byMaterial[$key] = ['material' => $key, 'unit' => $r->unit, 'kind' => $r->material_kind,
                    'standard' => 0.0, 'actual' => 0.0, 'runs' => 0];
            }
            $byMaterial[$key]['standard'] += (float) $r->standard_qty;
            $byMaterial[$key]['actual']   += (float) $r->actual_qty;
            $byMaterial[$key]['runs']     += 1;
        }

        $lines = array_values(array_map(function ($m) {
            $m['standard'] = round($m['standard'], 2);
            $m['actual']   = round($m['actual'], 2);
            $m['wastage']  = round($m['actual'] - $m['standard'], 2);
            $m['wastage_pct'] = $m['standard'] > 0 ? round(($m['actual'] - $m['standard']) / $m['standard'] * 100, 2) : 0;
            return $m;
        }, $byMaterial));

        return ['lines' => $lines, 'count' => $rows->count()];
    }
}
