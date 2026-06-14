<?php

namespace App\Services;

use App\Models\ChemicalStock;
use App\Models\CoilStock;
use App\Models\ConsumableStock;
use App\Models\MaterialSetting;
use App\Models\Order;
use App\Models\ProductionRun;

/**
 * BOM / Material-requirement engine (Phase 1 — compute only, NO stock change).
 *
 * Given panel rows, computes the standard raw material needed (coil kg, polyol,
 * isocyanate, film, tape) using per-company settings, applies wastage, and
 * matches each line against current stock to flag shortages. Read-only/advisory.
 */
class MaterialBomService
{
    public function settings(int $companyId): MaterialSetting
    {
        return MaterialSetting::firstOrCreate(['company_id' => $companyId]);
    }

    public function requirementForRun(ProductionRun $run): array
    {
        $run->loadMissing('batches.order.items.panelType', 'batches.order.items.sizes');
        $items = collect();
        foreach ($run->batches as $batch) {
            if ($batch->order) {
                $items = $items->merge($batch->order->items);
            }
        }
        return $this->compute($items, $run->company_id);
    }

    public function requirementForOrder(Order $order): array
    {
        $order->loadMissing('items.panelType', 'items.sizes');
        return $this->compute($order->items, $order->company_id);
    }

    /**
     * @param \Illuminate\Support\Collection $items  QuotationItem/OrderItem-like rows
     */
    private function compute($items, int $companyId): array
    {
        $s = $this->settings($companyId);
        $density   = (float) $s->steel_density;
        $ratio     = (float) $s->iso_polyol_ratio;          // iso per 1 polyol
        $overpack  = 1 + ((float) $s->foam_overpack_pct) / 100;
        $wCoil     = 1 + ((float) $s->wastage_coil_pct) / 100;
        $wChem     = 1 + ((float) $s->wastage_chemical_pct) / 100;
        $wCons     = 1 + ((float) $s->wastage_consumable_pct) / 100;
        $filmPer   = (float) $s->film_per_sqm;
        $tapePer   = (float) $s->tape_per_panel_m;

        $coilByType = [];   // panel_type_id => ['kg'=>, 'name'=>]
        $polyol = 0.0; $iso = 0.0; $film = 0.0; $tape = 0.0;
        $totalSqm = 0.0;

        foreach ($items as $it) {
            $area = (float) $it->total_sqm;
            if ($area <= 0) {
                // fallback from size rows
                $area = 0.0;
                foreach (($it->sizes ?? []) as $sz) {
                    $area += ($sz->length_mm / 1000) * (($sz->width_mm ?: 1000) / 1000) * $sz->nos;
                }
            }
            $totalSqm += $area;

            $topThk = (float) $it->top_skin_thickness;
            $botThk = (float) $it->bottom_skin_thickness;
            $coilKg = $area * $topThk * $density + $area * $botThk * $density;

            $ptId = $it->panel_type_id ?: 0;
            if (!isset($coilByType[$ptId])) {
                $coilByType[$ptId] = ['kg' => 0.0, 'name' => $it->panelType->name ?? ('Panel #' . $ptId)];
            }
            $coilByType[$ptId]['kg'] += $coilKg;

            $foam = $area * ((float) $it->thickness / 1000) * (float) $it->density_kgm3 * $overpack;
            $polyol += $foam / (1 + $ratio);
            $iso    += $foam * $ratio / (1 + $ratio);

            if ($it->guard_film) {
                $film += $area * $filmPer;
            }
            $nos = 0;
            foreach (($it->sizes ?? []) as $sz) { $nos += (int) $sz->nos; }
            $tape += $nos * $tapePer;
        }

        // Apply wastage
        foreach ($coilByType as $k => $v) { $coilByType[$k]['kg'] = $v['kg'] * $wCoil; }
        $polyol *= $wChem; $iso *= $wChem;
        $film *= $wCons; $tape *= $wCons;

        // Build lines with stock availability
        $lines = [];

        foreach ($coilByType as $ptId => $v) {
            if ($v['kg'] <= 0) continue;
            $available = (float) CoilStock::where('company_id', $companyId)
                ->where('panel_type_id', $ptId)->value('quantity_in_stock');
            $lines[] = $this->line('coil', 'Coil — ' . $v['name'], $v['kg'], 'kg', $available);
        }

        if ($polyol > 0) {
            $available = (float) ChemicalStock::where('company_id', $companyId)
                ->where('category', 'Polyol')->sum('quantity_in_stock');
            $lines[] = $this->line('chemical', 'Polyol', $polyol, 'kg', $available);
        }
        if ($iso > 0) {
            $available = (float) ChemicalStock::where('company_id', $companyId)
                ->where('category', 'Isocyanate')->sum('quantity_in_stock');
            $lines[] = $this->line('chemical', 'Isocyanate (MDI)', $iso, 'kg', $available);
        }
        if ($film > 0) {
            $available = (float) ConsumableStock::where('company_id', $companyId)
                ->where('category', 'film')->sum('quantity_in_stock');
            $lines[] = $this->line('consumable', 'Protective Film', $film, 'sqm', $available);
        }
        if ($tape > 0) {
            $available = (float) ConsumableStock::where('company_id', $companyId)
                ->where('category', 'tape')->sum('quantity_in_stock');
            $lines[] = $this->line('consumable', 'Sealant Tape', $tape, 'm', $available);
        }

        $allOk = collect($lines)->every(fn ($l) => $l['ok']);

        return [
            'total_sqm' => round($totalSqm, 2),
            'lines'     => $lines,
            'all_ok'    => $allOk,
            'notes'     => [
                'Quantities include wastage (' . rtrim(rtrim((string) $s->wastage_coil_pct, '0'), '.') . '% coil, '
                    . rtrim(rtrim((string) $s->wastage_chemical_pct, '0'), '.') . '% chemical) and '
                    . rtrim(rtrim((string) $s->foam_overpack_pct, '0'), '.') . '% foam overpack.',
                'Mould oil / release agent is consumed per shift, not per panel — track separately.',
            ],
        ];
    }

    private function line(string $kind, string $label, float $required, string $unit, float $available): array
    {
        $required = round($required, 2);
        return [
            'material_kind' => $kind,
            'label'         => $label,
            'required'      => $required,
            'available'     => round($available, 2),
            'unit'          => $unit,
            'short_by'      => $available >= $required ? 0 : round($required - $available, 2),
            'ok'            => $available >= $required,
        ];
    }
}
