<?php

namespace App\Services;

use App\Models\ProductionBatch;

/**
 * Builds the shop-floor cutting list for a batch.
 *
 * Works off the order's SIZE ROWS (length_mm / nos per row), which is where the
 * real cut list lives. It previously read `panelType->length` and used
 * `order_items.quantity` as a piece count — but that column holds square metres,
 * so a 30-panel batch of 78 m² reported 78 "items" at the panel type's nominal
 * length, overstating material by roughly 6×. The DL rule below also matches the
 * BOQ sheet (quotations/_boq_sheet_body.blade.php), so the office and the floor
 * now describe the same job.
 */
class CuttingScheduleService
{
    /** Panels shorter than this are produced at doubled length, then cut (the "DL" rule). */
    private const DOUBLE_LENGTH_THRESHOLD = 2000; // mm
    private const STANDARD_WIDTH = 1000;          // mm — panel module

    public function calculateSchedule(ProductionBatch $batch): array
    {
        $batch->load('order.items.panelType', 'order.items.sizes');
        $items = $batch->order?->items ?? collect();

        $schedule = [];
        $materialRequired = 0.0;
        $totalPieces = 0;

        foreach ($items as $item) {
            $panelName = $item->panelType->name ?? 'Panel';

            foreach ($item->sizes as $size) {
                $length = (int) $size->length_mm;
                $width  = (int) ($size->width_mm ?: self::STANDARD_WIDTH);
                $nos    = (int) $size->nos;
                if ($length <= 0 || $nos <= 0) continue;

                $totalPieces += $nos;

                if ($length < self::DOUBLE_LENGTH_THRESHOLD) {
                    // Pair them up: each strip yields two panels after cutting.
                    $pairs     = intdiv($nos, 2);
                    $remainder = $nos % 2;

                    if ($pairs > 0) {
                        $stripLength = $length * 2;
                        $schedule[] = [
                            'method'          => 'double',
                            'item_id'         => $item->id,
                            'panel_type'      => $panelName,
                            'panel_length'    => $length,
                            'quantity'        => $pairs * 2,
                            'per_strip'       => 2,
                            'strips_needed'   => $pairs,
                            'material_length' => $stripLength,
                            'material_width'  => $width,
                            'total_length'    => $pairs * $stripLength,
                            'waste'           => 0,
                        ];
                        $materialRequired += $pairs * $stripLength;
                    }

                    if ($remainder > 0) {
                        $schedule[] = [
                            'method'          => 'single',
                            'item_id'         => $item->id,
                            'panel_type'      => $panelName,
                            'panel_length'    => $length,
                            'quantity'        => $remainder,
                            'per_strip'       => 1,
                            'strips_needed'   => $remainder,
                            'material_length' => $length,
                            'material_width'  => $width,
                            'total_length'    => $remainder * $length,
                            'waste'           => 0,
                            'note'            => 'Odd panel left over from a doubled set — run single.',
                        ];
                        $materialRequired += $remainder * $length;
                    }
                } else {
                    $schedule[] = [
                        'method'          => 'single',
                        'item_id'         => $item->id,
                        'panel_type'      => $panelName,
                        'panel_length'    => $length,
                        'quantity'        => $nos,
                        'per_strip'       => 1,
                        'strips_needed'   => $nos,
                        'material_length' => $length,
                        'material_width'  => $width,
                        'total_length'    => $nos * $length,
                        'waste'           => 0,
                    ];
                    $materialRequired += $nos * $length;
                }
            }
        }

        return [
            'batch_id'              => $batch->id,
            'batch_no'              => $batch->batch_no,
            'status'                => 'calculated',
            'schedule_items'        => $schedule,
            'total_material_length' => (int) round($materialRequired),
            'total_items'           => $totalPieces,
            'optimization'          => $this->getOptimizationSummary($schedule),
            'created_at'            => now(),
        ];
    }

    private function getOptimizationSummary(array $schedule): array
    {
        $doubleCount = 0;
        $singleCount = 0;
        $strips      = 0;

        foreach ($schedule as $row) {
            if ($row['method'] === 'double') $doubleCount += $row['quantity'];
            else                             $singleCount += $row['quantity'];
            $strips += $row['strips_needed'];
        }

        $total = $doubleCount + $singleCount;

        return [
            'double_cut_items'        => $doubleCount,
            'single_cut_items'        => $singleCount,
            'total_items'             => $total,
            'double_cut_percentage'   => $total > 0 ? round(($doubleCount / $total) * 100, 2) : 0,
            'strips_needed'           => $strips,
            // Panels produced per strip run — 100% means every strip carried two panels.
            'optimization_efficiency' => $strips > 0 ? round(($total / ($strips * 2)) * 100, 2) . '%' : 'N/A',
        ];
    }

    public function validateSchedule(array $schedule): array
    {
        $errors = [];

        if (empty($schedule['schedule_items'])) {
            $errors[] = 'No items in schedule';
        }

        return $errors;
    }

    public function getCuttingInstructions(ProductionBatch $batch): string
    {
        $schedule = $this->calculateSchedule($batch);
        $opt = $schedule['optimization'];

        $out  = "CUTTING SCHEDULE FOR BATCH: {$batch->batch_no}\n";
        $out .= 'Generated: ' . now()->format('Y-m-d H:i:s') . "\n";
        $out .= "Total Items: {$schedule['total_items']}\n";
        $out .= "Total Material Length: {$schedule['total_material_length']}mm\n\n";

        $out .= "OPTIMIZATION:\n";
        $out .= "- Double Cut Items: {$opt['double_cut_items']}\n";
        $out .= "- Single Cut Items: {$opt['single_cut_items']}\n";
        $out .= "- Double Cut Percentage: {$opt['double_cut_percentage']}%\n";
        $out .= "- Strips Needed: {$opt['strips_needed']}\n";
        $out .= "- Efficiency: {$opt['optimization_efficiency']}\n\n";

        $out .= "CUTTING OPERATIONS:\n";
        foreach ($schedule['schedule_items'] as $i => $row) {
            $out .= ($i + 1) . ". {$row['panel_type']} — {$row['panel_length']}mm ({$row['method']} cut)\n";
            $out .= "   Panels: {$row['quantity']}\n";
            $out .= "   Strip: {$row['material_length']}mm × {$row['material_width']}mm × {$row['strips_needed']}\n";
            if (!empty($row['note'])) $out .= "   Note: {$row['note']}\n";
            $out .= "\n";
        }

        return $out;
    }

    public function calculateWastePercentage(array $schedule): float
    {
        $totalMaterial = $schedule['total_material_length'];
        $totalWaste = array_sum(array_map(fn ($s) => $s['waste'], $schedule['schedule_items']));

        return $totalMaterial > 0 ? round(($totalWaste / $totalMaterial) * 100, 2) : 0;
    }
}
