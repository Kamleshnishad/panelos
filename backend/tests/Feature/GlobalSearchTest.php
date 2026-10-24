<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * UX-04. The header search finds records by number, customer or project across modules, returns only what the user's
 * role may open, never another company's records, and survives hostile input.
 */
class GlobalSearchTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;
    use \Tests\Traits\BuildsUmaQuotation;

    private function groupsFor(User $user, string $q): array
    {
        $res = $this->actingAs($user, 'sanctum')->getJson('/api/search?q=' . urlencode($q))->assertOk();

        return collect($res->json('data.groups'))->keyBy('type')->all();
    }

    public function test_it_finds_a_quotation_by_number_customer_and_project_and_says_where_to_open_it(): void
    {
        [$admin, $q] = $this->umaQuotation();

        foreach ([$q->quotation_no, substr($q->quotation_no, -3), 'ideal agri', 'Nandasan'] as $term) {
            $groups = $this->groupsFor($admin, $term);
            $this->assertArrayHasKey('quotations', $groups, "searching {$term}");
            $item = $groups['quotations']['items'][0];
            $this->assertSame($q->id, $item['id']);
            $this->assertSame($q->quotation_no, $item['title']);
            $this->assertStringContainsString('IDEAL AGRI CARE', $item['subtitle']);
            $this->assertStringContainsString('9,11,476.00', $item['meta'], 'Amounts read the Indian way.');
            $this->assertSame('quotations', $groups['quotations']['section']);
        }
    }

    public function test_it_searches_every_module_a_user_may_open(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'name' => 'Zephyr Cold Storage', 'code' => 'ZEPH123', 'city' => 'Rajkot']);
        $dispatch = Dispatch::factory()->for($admin->company)->create(['vehicle_no' => 'GJ01ZZ9999']);
        // The factory chain builds each parent in its own company; put the whole chain in the admin's.
        foreach ([$dispatch, $dispatch->batch, $dispatch->batch->order] as $m) {
            $m->forceFill(['company_id' => $admin->company_id])->save();
        }
        $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id])->assertStatus(201);
        $invoice = $admin->company->invoices()->first();

        $this->assertSame($customer->id, $this->groupsFor($admin, 'zephyr')['customers']['items'][0]['id']);
        $this->assertSame($customer->id, $this->groupsFor($admin, 'ZEPH123')['customers']['items'][0]['id']);
        $this->assertSame($dispatch->id, $this->groupsFor($admin, 'GJ01ZZ9999')['dispatches']['items'][0]['id']);
        $this->assertSame($dispatch->id, $this->groupsFor($admin, $dispatch->dispatch_no)['dispatches']['items'][0]['id']);
        $this->assertSame($invoice->id, $this->groupsFor($admin, $invoice->invoice_no)['invoices']['items'][0]['id']);

        $batch = $dispatch->batch;
        $this->assertSame($batch->id, $this->groupsFor($admin, $batch->batch_no)['batches']['items'][0]['id']);
        $order = $batch->order;
        $this->assertSame($order->id, $this->groupsFor($admin, $order->order_no)['orders']['items'][0]['id']);
    }

    public function test_a_role_only_sees_the_kinds_it_may_open(): void
    {
        [$admin] = $this->umaQuotation();
        Customer::factory()->create(['company_id' => $admin->company_id, 'name' => 'Ideal Traders']);

        $role = Role::factory()->for($admin->company)->create(['permissions' => ['quotations.view']]);
        $clerk = User::factory()->create(['company_id' => $admin->company_id, 'role_id' => $role->id, 'is_company_admin' => false]);

        $groups = $this->groupsFor($clerk, 'ideal');
        $this->assertSame(['quotations'], array_keys($groups), 'A quotations-only role must not see customers.');

        $none = Role::factory()->for($admin->company)->create(['permissions' => []]);
        $nobody = User::factory()->create(['company_id' => $admin->company_id, 'role_id' => $none->id, 'is_company_admin' => false]);
        $this->assertSame([], $this->groupsFor($nobody, 'ideal'));
    }

    public function test_it_never_returns_another_companys_records(): void
    {
        [$admin] = $this->umaQuotation();
        Customer::factory()->create(['company_id' => $admin->company_id, 'name' => 'Ideal Mine']);

        $stranger = $this->createAdminUser();
        $this->assertSame([], $this->groupsFor($stranger, 'ideal'));
        $this->assertSame([], $this->groupsFor($stranger, 'SCP-'));
    }

    public function test_short_blank_and_hostile_queries_are_harmless(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        Customer::factory()->create(['company_id' => $admin->company_id, 'name' => '100% Panels']);

        $this->assertSame([], $this->groupsFor($admin, ''));
        $this->assertSame([], $this->groupsFor($admin, 'a'), 'One character is too broad to search.');
        $this->assertSame([], $this->groupsFor($admin, '   '));

        // % and _ are literal characters, not wildcards.
        $this->assertSame([], $this->groupsFor($admin, '%%'));
        $this->assertSame([], $this->groupsFor($admin, '__'));
        $this->assertArrayHasKey('customers', $this->groupsFor($admin, '100%'));

        foreach (["' OR 1=1 --", '"; DROP TABLE customers; --', '<script>alert(1)</script>', str_repeat('x', 500)] as $evil) {
            $this->actingAs($admin, 'sanctum')->getJson('/api/search?q=' . urlencode($evil))->assertOk()->assertJsonPath('data.groups', []);
        }
        $this->assertGreaterThan(0, Customer::count(), 'The table must still exist.');
    }

    public function test_the_results_are_capped_per_kind(): void
    {
        $admin = $this->createAdminUser();
        Customer::factory()->count(12)->create(['company_id' => $admin->company_id, 'name' => 'Panelco Branch']);

        $this->assertCount(5, $this->groupsFor($admin, 'panelco')['customers']['items']);
    }

    public function test_it_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/search?q=anything')->assertStatus(401);
    }
}
