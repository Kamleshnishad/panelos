<?php

namespace App\Services;

use App\Models\Accessory;
use Illuminate\Support\Facades\DB;

class AccessoryService
{
    /**
     * Create a new accessory
     */
    public function create(array $data): Accessory
    {
        return Accessory::create($data);
    }

    /**
     * Update an accessory
     */
    public function update(Accessory $accessory, array $data): Accessory
    {
        $accessory->update($data);
        return $accessory->fresh();
    }

    /**
     * Delete an accessory (soft delete)
     */
    public function delete(Accessory $accessory): bool
    {
        return $accessory->delete();
    }

    /**
     * List accessories with filters
     */
    public function list(int $companyId, array $filters = [])
    {
        $query = Accessory::where('company_id', $companyId);

        if (isset($filters['status']) && $filters['status'] === 'active') {
            $query->active();
        }

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('code', 'like', "%{$filters['search']}%");
            });
        }

        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        return $query;
    }

    /**
     * Add accessory to quotation
     */
    public function addToQuotation($quotation, array $accessoryData): void
    {
        DB::transaction(function () use ($quotation, $accessoryData) {
            // Tenant guard: only attach accessories belonging to the quotation's company.
            $accessory = Accessory::where('company_id', $quotation->company_id)
                ->findOrFail($accessoryData['accessory_id']);

            $quantity = $accessoryData['quantity'];
            $unitPrice = $accessoryData['unit_price'] ?? $accessory->unit_price;
            $amount = $quantity * $unitPrice;

            $quotation->accessories()->attach($accessory->id, [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
            ]);

            // Recalculate quotation totals
            app(QuotationService::class)->recalculateTotals($quotation);
        });
    }

    /**
     * Remove accessory from quotation
     */
    public function removeFromQuotation($quotation, int $accessoryId): void
    {
        DB::transaction(function () use ($quotation, $accessoryId) {
            $quotation->accessories()->detach($accessoryId);

            // Recalculate quotation totals
            app(QuotationService::class)->recalculateTotals($quotation);
        });
    }
}
