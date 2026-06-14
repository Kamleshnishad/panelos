<?php

namespace App\Services;

use App\Models\ProductionStage;
use Illuminate\Support\Facades\DB;

class ProductionStageService
{
    /**
     * Create a production stage
     */
    public function create(array $data): ProductionStage
    {
        $stage = ProductionStage::create([
            'company_id' => $data['company_id'],
            'name' => $data['name'],
            'sequence' => $data['sequence'] ?? $this->getNextSequence($data['company_id']),
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $stage;
    }

    /**
     * List production stages
     */
    public function list(int $companyId, array $filters = [])
    {
        $query = ProductionStage::where('company_id', $companyId);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $query->orderBy('sequence', 'asc');

        return $query;
    }

    /**
     * Get stage by ID
     */
    public function getById(int $companyId, int $stageId): ProductionStage
    {
        return ProductionStage::where('company_id', $companyId)
            ->findOrFail($stageId);
    }

    /**
     * Update stage
     */
    public function update(ProductionStage $stage, array $data): ProductionStage
    {
        $stage->update([
            'name' => $data['name'] ?? $stage->name,
            'description' => $data['description'] ?? $stage->description,
            'is_active' => $data['is_active'] ?? $stage->is_active,
            'sequence' => $data['sequence'] ?? $stage->sequence,
        ]);

        return $stage->fresh();
    }

    /**
     * Delete stage (only if not used in any logs)
     */
    public function delete(ProductionStage $stage): void
    {
        if ($stage->stageLogs()->exists()) {
            throw new \Exception('Cannot delete stage that has been used in batch logs');
        }

        $stage->delete();
    }

    /**
     * Get all active stages ordered by sequence
     */
    public function getActiveStages(int $companyId): array
    {
        return ProductionStage::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sequence', 'asc')
            ->get()
            ->toArray();
    }

    /**
     * Get next sequence number for company
     */
    private function getNextSequence(int $companyId): int
    {
        $lastStage = ProductionStage::where('company_id', $companyId)
            ->orderByDesc('sequence')
            ->first();

        return $lastStage ? $lastStage->sequence + 1 : 1;
    }

    /**
     * Initialize default stages for company
     */
    public function initializeDefaultStages(int $companyId): array
    {
        $defaultStages = [
            ['name' => 'Cutting', 'description' => 'Raw material cutting'],
            ['name' => 'Lamination', 'description' => 'Layer bonding process'],
            ['name' => 'Finishing', 'description' => 'Surface finishing'],
            ['name' => 'Quality Check', 'description' => 'QC inspection'],
        ];

        $stages = [];
        foreach ($defaultStages as $index => $stage) {
            $stages[] = $this->create([
                'company_id' => $companyId,
                'name' => $stage['name'],
                'description' => $stage['description'],
                'sequence' => $index + 1,
                'is_active' => true,
            ]);
        }

        return $stages;
    }
}
