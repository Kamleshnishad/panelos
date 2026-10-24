<?php

namespace Tests\Traits;

use App\Models\ChemicalStock;
use App\Models\CoilStock;

/**
 * Stock rows for tests. The old tests used separate Coil / Chemical master models, which no longer
 * exist: StockService now works on a stock row id (coil_stocks / chemical_stocks). coil_id and chemical_id
 * are legacy nullable columns that getStockLevel() still looks up by, so each row gets a distinct one.
 */
trait MakesStockRows
{
    private int $nextLegacyId = 7000;

    protected function coil(array $attrs = [], ?int $companyId = null): CoilStock
    {
        return CoilStock::create($attrs + [
            'company_id' => $companyId ?? $this->company->id, 'coil_id' => ++$this->nextLegacyId,
            'quantity_in_stock' => 0, 'reorder_level' => 0, 'unit_cost' => 0,
        ]);
    }

    protected function chemical(array $attrs = [], ?int $companyId = null): ChemicalStock
    {
        return ChemicalStock::create($attrs + [
            'company_id' => $companyId ?? $this->company->id, 'chemical_id' => ++$this->nextLegacyId,
            'name' => 'Test chemical', 'category' => 'polyol', 'quantity_in_stock' => 0, 'unit' => 'liter', 'reorder_level' => 0, 'unit_cost' => 0,
        ]);
    }
}
