<?php

namespace App\Services;

use App\Models\ProductionBatch;
use Illuminate\Support\Facades\DB;

class CuttingScheduleService
{
    private const STANDARD_WIDTH = 1000; // mm
    private const STANDARD_LENGTH = 2000; // mm (minimum)
    private const STANDARD_ROLL_WIDTH = 1000; // mm

    /**
     * Calculate cutting schedule for batch
     */
    public function calculateSchedule(ProductionBatch $batch): array
    {
        $batch->load('order.items.panelType');
        $items = $batch->order->items;

        $schedule = [];
        $totalWaste = 0;
        $materialRequired = 0;

        foreach ($items as $item) {
            $panelType = $item->panelType;
            $quantity = $item->quantity;
            $length = $panelType->length ?? self::STANDARD_LENGTH;
            $width = $panelType->width ?? self::STANDARD_WIDTH;

            // Check if doubling is applicable
            $doubleCount = 0;
            $remainingQuantity = $quantity;

            if ($this->canDouble($length, $width)) {
                $doubleCount = intdiv($quantity, 2);
                $remainingQuantity = $quantity % 2;
            }

            // Calculate material needed
            $doubleSchedule = [];
            if ($doubleCount > 0) {
                $materialLength = $length * 2;
                $materialWidth = $width;
                $totalNeeded = $doubleCount * $materialLength;

                $doubleSchedule = [
                    'method' => 'double',
                    'item_id' => $item->id,
                    'panel_type' => $panelType->name,
                    'quantity' => $doubleCount * 2,
                    'per_roll' => 2,
                    'rolls_needed' => $doubleCount,
                    'material_length' => $materialLength,
                    'material_width' => $materialWidth,
                    'total_length' => $totalNeeded,
                    'waste' => 0,
                ];

                $materialRequired += $totalNeeded;
            }

            // Single cuts for remaining items
            $singleSchedule = [];
            if ($remainingQuantity > 0) {
                $singleSchedule = [
                    'method' => 'single',
                    'item_id' => $item->id,
                    'panel_type' => $panelType->name,
                    'quantity' => $remainingQuantity,
                    'per_roll' => 1,
                    'rolls_needed' => $remainingQuantity,
                    'material_length' => $length,
                    'material_width' => $width,
                    'total_length' => $remainingQuantity * $length,
                    'waste' => 0,
                ];

                $materialRequired += $remainingQuantity * $length;
            }

            if (!empty($doubleSchedule)) {
                $schedule[] = $doubleSchedule;
            }
            if (!empty($singleSchedule)) {
                $schedule[] = $singleSchedule;
            }
        }

        return [
            'batch_id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'status' => 'calculated',
            'schedule_items' => $schedule,
            'total_material_length' => $materialRequired,
            'total_items' => $items->sum('quantity'),
            'optimization' => $this->getOptimizationSummary($schedule),
            'created_at' => now(),
        ];
    }

    /**
     * Check if panel can be doubled
     */
    private function canDouble(int $length, int $width): bool
    {
        // Can double if length < 2000mm and both items fit in standard width
        return $length < self::STANDARD_LENGTH && ($length * 2) <= 3000;
    }

    /**
     * Get optimization summary
     */
    private function getOptimizationSummary(array $schedule): array
    {
        $doubleCount = 0;
        $singleCount = 0;

        foreach ($schedule as $item) {
            if ($item['method'] === 'double') {
                $doubleCount += $item['quantity'];
            } else {
                $singleCount += $item['quantity'];
            }
        }

        $total = $doubleCount + $singleCount;
        $doublePercentage = $total > 0 ? round(($doubleCount / $total) * 100, 2) : 0;

        return [
            'double_cut_items' => $doubleCount,
            'single_cut_items' => $singleCount,
            'total_items' => $total,
            'double_cut_percentage' => $doublePercentage,
            'optimization_efficiency' => $this->calculateEfficiency($schedule),
        ];
    }

    /**
     * Calculate material efficiency
     */
    private function calculateEfficiency(array $schedule): string
    {
        $scheduleCount = count($schedule);
        if ($scheduleCount === 0) {
            return 'N/A';
        }

        $doubleCount = count(array_filter($schedule, fn($s) => $s['method'] === 'double'));
        $totalRolls = array_sum(array_map(fn($s) => $s['rolls_needed'], $schedule));

        if ($totalRolls === 0) {
            return 'N/A';
        }

        // Efficiency = (number of items per roll) / total rolls
        $efficiency = round(($scheduleCount * 2 / $totalRolls) * 100, 2);

        return "{$efficiency}%";
    }

    /**
     * Validate cutting schedule
     */
    public function validateSchedule(array $schedule): array
    {
        $errors = [];

        if (empty($schedule['schedule_items'])) {
            $errors[] = 'No items in schedule';
        }

        // No length validation - allow both single and doubled cuts
        // Validation is done at the panel type creation level

        return $errors;
    }

    /**
     * Get cutting instructions
     */
    public function getCuttingInstructions(ProductionBatch $batch): string
    {
        $schedule = $this->calculateSchedule($batch);

        $instructions = "CUTTING SCHEDULE FOR BATCH: {$batch->batch_no}\n";
        $instructions .= "Generated: " . now()->format('Y-m-d H:i:s') . "\n";
        $instructions .= "Total Items: {$schedule['total_items']}\n";
        $instructions .= "Total Material Length: {$schedule['total_material_length']}mm\n\n";

        $instructions .= "OPTIMIZATION:\n";
        $instructions .= "- Double Cut Items: {$schedule['optimization']['double_cut_items']}\n";
        $instructions .= "- Single Cut Items: {$schedule['optimization']['single_cut_items']}\n";
        $instructions .= "- Double Cut Percentage: {$schedule['optimization']['double_cut_percentage']}%\n";
        $instructions .= "- Efficiency: {$schedule['optimization']['optimization_efficiency']}\n\n";

        $instructions .= "CUTTING OPERATIONS:\n";
        foreach ($schedule['schedule_items'] as $index => $item) {
            $instructions .= ($index + 1) . ". {$item['panel_type']} ({$item['method']} cut)\n";
            $instructions .= "   Quantity: {$item['quantity']} items\n";
            $instructions .= "   Material: {$item['material_length']}mm × {$item['material_width']}mm\n";
            $instructions .= "   Rolls Needed: {$item['rolls_needed']}\n\n";
        }

        return $instructions;
    }

    /**
     * Calculate waste percentage
     */
    public function calculateWastePercentage(array $schedule): float
    {
        $totalMaterial = $schedule['total_material_length'];
        $totalWaste = array_sum(array_map(fn($s) => $s['waste'], $schedule['schedule_items']));

        return $totalMaterial > 0 ? round(($totalWaste / $totalMaterial) * 100, 2) : 0;
    }
}
