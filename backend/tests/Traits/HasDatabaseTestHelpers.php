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

    protected function assertDatabaseHasModel($model, ?array $attributes = null): void
    {
        $this->assertDatabaseHas($model->getTable(), $attributes ?? $model->getAttributes());
    }

    protected function assertDatabaseMissingModel($model): void
    {
        $this->assertDatabaseMissing($model->getTable(), ['id' => $model->id]);
    }
}
