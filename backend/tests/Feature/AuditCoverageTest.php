<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\AuditLog;
use App\Models\ChemicalStock;
use App\Models\Company;
use App\Models\CoilStock;
use App\Models\ConsumableStock;
use App\Models\Dispatch;
use App\Models\Role;
use App\Models\StockTransaction;
use App\Models\TaxConfiguration;
use App\Models\User;
use App\Traits\Auditable;
use Tests\TestCase;

/**
 * EN-06. Only 10 of 61 models were audited, so "who made this user an admin?" and "who adjusted this coil stock?" had no
 * answer. Roles, users, company settings, stock and its movements, dispatches, accessories and the tax rate are now logged.
 */
class AuditCoverageTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;
    use \Tests\Traits\MakesStockRows;

    protected Company $company;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminUser();
        $this->company = $this->admin->company;
        $this->actingAs($this->admin, 'sanctum');
    }

    private function rows(string $type, ?string $action = null)
    {
        return AuditLog::withoutGlobalScopes()->where('auditable_type', $type)->when($action, fn ($q) => $q->where('action', $action))->orderBy('id')->get();
    }

    public function test_a_role_permission_change_records_who_and_what(): void
    {
        $role = Role::factory()->for($this->company)->create(['name' => 'Sales', 'permissions' => ['quotations.view']]);
        $role->update(['permissions' => ['quotations.view', 'costing.view']]);

        $row = $this->rows('Role', 'updated')->last();
        $this->assertNotNull($row);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame('Sales', $row->label);
        $this->assertSame($this->company->id, $row->company_id);
        $this->assertStringContainsString('costing.view', json_encode($row->after));
        $this->assertStringNotContainsString('costing.view', json_encode($row->before));
    }

    public function test_promoting_a_user_to_admin_is_recorded_but_secrets_and_login_noise_are_not(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'email' => 'clerk@example.test']);
        $this->assertTrue($this->rows('User', 'created')->contains(fn ($r) => ($r->after['email'] ?? null) === 'clerk@example.test'), 'Creating a user must be logged.');

        $before = AuditLog::withoutGlobalScopes()->count();
        $user->update(['last_login_at' => now()]);
        $user->update(['two_factor_code' => '123456', 'two_factor_expires_at' => now()->addMinutes(10)]);
        $this->assertSame($before, AuditLog::withoutGlobalScopes()->count(), 'A login stamp or a 2FA code must not write audit rows.');

        $user->update(['is_company_admin' => true, 'password' => bcrypt('new-secret')]);
        $row = $this->rows('User', 'updated')->last();
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertEquals(1, $row->after['is_company_admin']);
        $this->assertEquals(0, $row->before['is_company_admin']);
        $this->assertStringNotContainsString('password', json_encode($row->after), 'The password hash must never be stored in the audit log.');
        $this->assertStringNotContainsString('two_factor', json_encode(AuditLog::withoutGlobalScopes()->get()->map->after));
    }

    public function test_company_settings_changes_are_logged_and_a_super_admin_edit_lands_on_the_edited_company(): void
    {
        $this->company->update(['bank_account_no' => '111122223333']);
        $row = $this->rows('Company', 'updated')->last();
        $this->assertSame($this->company->id, $row->company_id);
        $this->assertSame('111122223333', $row->after['bank_account_no']);

        $other = Company::factory()->create();
        $super = User::factory()->superAdmin()->create(['company_id' => $this->company->id]);
        $this->actingAs($super, 'sanctum');
        $plan = $other->subscription_plan === 'pro' ? 'starter' : 'pro';   // a real change, whatever the factory default is
        $other->forceFill(['subscription_plan' => $plan])->save();

        $row = $this->rows('Company', 'updated')->last();
        $this->assertSame($other->id, $row->company_id, 'A change to another tenant must be logged against that tenant.');
        $this->assertSame($super->id, $row->user_id);
        $this->assertSame($plan, $row->after['subscription_plan']);
    }

    public function test_a_stock_adjustment_through_the_api_is_logged_with_the_user_and_the_reason_trail(): void
    {
        $coil = $this->coil(['quantity_in_stock' => 100]);

        $this->postJson("/api/stock/coils/{$coil->id}/adjust", ['new_quantity' => 80, 'reason' => 'Physical count'])->assertOk();

        $stock = $this->rows('CoilStock', 'updated')->last();
        $this->assertNotNull($stock, 'The coil quantity change must be logged.');
        $this->assertSame($this->admin->id, $stock->user_id);
        $this->assertEquals(80, $stock->after['quantity_in_stock']);
        $this->assertEquals(100, $stock->before['quantity_in_stock']);

        $tx = $this->rows('StockTransaction', 'created')->last();
        $this->assertNotNull($tx, 'The movement itself must be logged.');
        $this->assertSame($this->admin->id, $tx->user_id);
    }

    public function test_dispatches_accessories_chemicals_consumables_and_the_tax_rate_are_logged(): void
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $dispatch->update(['transport_amount' => 12000]);
        $this->assertEquals(12000, $this->rows('Dispatch', 'updated')->last()->after['transport_amount']);

        $acc = Accessory::factory()->for($this->company)->create();
        $acc->update(['name' => 'Renamed accessory']);
        $this->assertSame('Renamed accessory', $this->rows('Accessory', 'updated')->last()->after['name']);

        $chem = $this->chemical(['quantity_in_stock' => 10]);
        $chem->update(['quantity_in_stock' => 4]);
        $this->assertEquals(4, $this->rows('ChemicalStock', 'updated')->last()->after['quantity_in_stock']);

        $cons = ConsumableStock::create(['company_id' => $this->company->id, 'name' => 'Mould oil', 'quantity_in_stock' => 5, 'unit' => 'liter', 'reorder_level' => 1, 'unit_cost' => 10]);
        $cons->update(['quantity_in_stock' => 3]);
        $this->assertEquals(3, $this->rows('ConsumableStock', 'updated')->last()->after['quantity_in_stock']);

        $tax = TaxConfiguration::create(['company_id' => $this->company->id, 'tax_type' => 'exclusive', 'default_tax_rate' => 18, 'is_active' => true]);
        $tax->update(['default_tax_rate' => 12]);
        $this->assertEquals(12, $this->rows('TaxConfiguration', 'updated')->last()->after['default_tax_rate']);
    }

    public function test_the_audit_screen_shows_the_new_entries_to_the_company_admin_only_for_their_own_company(): void
    {
        Role::factory()->for($this->company)->create(['name' => 'Supervisor']);

        $res = $this->getJson('/api/audit-logs?type=Role')->assertOk();
        $this->assertContains('Supervisor', collect($res->json('data.logs.data'))->pluck('label')->all());
        $this->assertContains('Role', $res->json('data.types'));

        $stranger = $this->createAdminUser();
        $this->actingAs($stranger, 'sanctum');
        $labels = collect($this->getJson('/api/audit-logs?type=Role')->assertOk()->json('data.logs.data'))->pluck('label')->all();
        $this->assertNotContains('Supervisor', $labels, "Another company's audit entries must never be visible.");
    }

    public function test_the_models_that_matter_stay_audited(): void
    {
        foreach ([Role::class, User::class, Company::class, StockTransaction::class, CoilStock::class, ChemicalStock::class, ConsumableStock::class,
                  Dispatch::class, Accessory::class, TaxConfiguration::class] as $model) {
            $this->assertContains(Auditable::class, class_uses_recursive($model), "{$model} must use the Auditable trait (EN-06).");
        }
    }
}
