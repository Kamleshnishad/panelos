<?php

namespace Tests\Traits;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;

trait HasDatabaseTestHelpers
{
    protected function createTestCompany(?array $attributes = null): Company
    {
        return Company::factory()->create($attributes ?? []);
    }

    protected function createTestUser(?array $attributes = null, ?Company $company = null): User
    {
        $company = $company ?? Company::factory()->create();
        $role = Role::factory()->for($company)->create();

        return User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $role->id,
            ...$attributes ?? [],
        ]);
    }

    protected function createTestRole(Company $company, ?array $attributes = null): Role
    {
        return Role::factory()->for($company)->create($attributes ?? []);
    }

    protected function createAdminUser(?array $attributes = null, ?Company $company = null): User
    {
        $company = $company ?? Company::factory()->create();
        $adminRole = Role::factory()->superAdmin()->for($company)->create();

        return User::factory()->superAdmin()->create([
            'company_id' => $company->id,
            'role_id' => $adminRole->id,
            ...$attributes ?? [],
        ]);
    }

    /**
     * A valid `panel_rows` payload for POST /api/quotations. The old tests sent `items` with a bare
     * quantity, a shape the API dropped long ago. quantity is square metres (1000 mm panels).
     */
    protected function panelRows(int $panelTypeId, int $sqm = 10, float $rate = 1000): array
    {
        return [[
            'panel_type_id' => $panelTypeId, 'application' => 'Wall', 'thickness' => 50,
            'density_type' => 'PUF', 'density_kgm3' => 40,
            'top_skin_material' => 'PPGI', 'top_skin_thickness' => '0.40', 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => '0.40',
            'sizes' => [['length_mm' => 1000, 'nos' => $sqm, 'rate_per_sqm' => $rate]],  // 1000 mm panels: nos = sqm
        ]];
    }

    protected function assertDatabaseHasModel($model, ?array $attributes = null): void
    {
        $this->assertDatabaseHas($model->getTable(), $attributes ?? $model->getAttributes());
    }

    protected function assertDatabaseMissingModel($model): void
    {
        $this->assertDatabaseMissing($model->getTable(), ['id' => $model->id]);
    }
}
