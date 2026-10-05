<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class ModulePermissionTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function userWithPermissions(array $permissions): User
    {
        $company = Company::factory()->create();
        $role = Role::factory()->for($company)->create(['permissions' => $permissions]);

        return User::factory()->create([
            'company_id' => $company->id,
            'role_id' => $role->id,
            'is_company_admin' => false,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
    }

    public function test_read_only_role_cannot_record_payment(): void
    {
        $user = $this->userWithPermissions(['payments.view']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/record', ['invoice_id' => 1, 'amount' => 100])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_manage_role_passes_permission_gate(): void
    {
        $user = $this->userWithPermissions(['payments.view', 'payments.manage']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/payments/record', ['invoice_id' => 1, 'amount' => 100]);

        $this->assertNotEquals(403, $response->status());
    }

    public function test_read_only_role_can_view_but_not_write_customers(): void
    {
        $user = $this->userWithPermissions(['customers.view']);

        $this->actingAs($user, 'sanctum')->getJson('/api/customers')->assertStatus(200);
        $this->actingAs($user, 'sanctum')->postJson('/api/customers', ['name' => 'X'])->assertStatus(403);
    }

    public function test_user_without_any_role_is_blocked_from_settings_writes(): void
    {
        $user = $this->userWithPermissions([]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/company', ['name' => 'Hacked'])
            ->assertStatus(403);
    }

    public function test_admin_bypasses_module_gate(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/payments/record', ['invoice_id' => 1, 'amount' => 100]);

        $this->assertNotEquals(403, $response->status());
    }
}
