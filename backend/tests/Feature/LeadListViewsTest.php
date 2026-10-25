<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadView;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 5.1: filters, sorting, saved views, "own leads only", leads in the header search. */
class LeadListViewsTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Asia/Kolkata'));
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'name' => 'Admin']);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(array $o = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => 'LD-' . $n, 'contact_name' => "Buyer {$n}", 'source' => 'Phone', 'status' => 'new']);
    }

    private function userWith(array $perms, string $name = 'Staff'): User
    {
        $role = Role::factory()->create(['company_id' => $this->company->id, 'permissions' => $perms]);

        return User::factory()->create(['company_id' => $this->company->id, 'role_id' => $role->id, 'is_company_admin' => false, 'name' => $name]);
    }

    private function nos(string $query = ''): array
    {
        return collect($this->getJson('/api/leads' . $query)->assertOk()->json('data'))->pluck('lead_no')->all();
    }

    // ── filters ──────────────────────────────────────────────────────────────

    public function test_source_and_status_filters(): void
    {
        $this->mk(['lead_no' => 'A', 'source' => 'IndiaMART', 'status' => 'new']);
        $this->mk(['lead_no' => 'B', 'source' => 'IndiaMART', 'status' => 'won']);
        $this->mk(['lead_no' => 'C', 'source' => 'Phone', 'status' => 'new']);

        $this->assertEqualsCanonicalizing(['A', 'B'], $this->nos('?source=IndiaMART'));
        $this->assertSame(['A'], $this->nos('?source=IndiaMART&status=new'));
    }

    public function test_assigned_to_me_none_or_a_person(): void
    {
        $ben = $this->userWith(['leads.view'], 'Ben');
        $this->mk(['lead_no' => 'MINE', 'assigned_to_user_id' => $this->admin->id]);
        $this->mk(['lead_no' => 'BENS', 'assigned_to_user_id' => $ben->id]);
        $this->mk(['lead_no' => 'NOBODY']);

        $this->assertSame(['MINE'], $this->nos('?assigned_to=me'));
        $this->assertSame(['NOBODY'], $this->nos('?assigned_to=none'));
        $this->assertSame(['BENS'], $this->nos("?assigned_to={$ben->id}"));
        $this->assertEqualsCanonicalizing(['MINE', 'BENS', 'NOBODY'], $this->nos());
    }

    public function test_follow_up_filters_only_look_at_open_leads(): void
    {
        $this->mk(['lead_no' => 'LATE', 'next_follow_up_date' => '2026-11-08']);
        $this->mk(['lead_no' => 'TODAY', 'next_follow_up_date' => '2026-11-10']);
        $this->mk(['lead_no' => 'SOON', 'next_follow_up_date' => '2026-11-15']);
        $this->mk(['lead_no' => 'NODATE']);
        $this->mk(['lead_no' => 'WONLATE', 'next_follow_up_date' => '2026-11-01', 'status' => 'won']);

        $this->assertSame(['LATE'], $this->nos('?follow_up=overdue'));
        $this->assertSame(['TODAY'], $this->nos('?follow_up=today'));
        $this->assertEqualsCanonicalizing(['LATE', 'TODAY'], $this->nos('?follow_up=due'));
        $this->assertSame(['NODATE'], $this->nos('?follow_up=none'));
    }

    public function test_filters_can_be_combined(): void
    {
        $this->mk(['lead_no' => 'HIT', 'source' => 'IndiaMART', 'assigned_to_user_id' => $this->admin->id, 'next_follow_up_date' => '2026-11-01']);
        $this->mk(['lead_no' => 'WRONG-SRC', 'source' => 'Phone', 'assigned_to_user_id' => $this->admin->id, 'next_follow_up_date' => '2026-11-01']);
        $this->mk(['lead_no' => 'NOT-MINE', 'source' => 'IndiaMART', 'next_follow_up_date' => '2026-11-01']);

        $this->assertSame(['HIT'], $this->nos('?source=IndiaMART&assigned_to=me&follow_up=overdue'));
    }

    public function test_search_finds_company_email_lead_number_and_a_phone_typed_any_way(): void
    {
        $this->mk(['lead_no' => 'LEAD-2026-0042', 'contact_name' => 'Ravi', 'company_name' => 'Zephyr Cold Storage', 'email' => 'ravi@zephyr.test', 'phone' => '+91 98765-43210']);
        $this->mk(['lead_no' => 'OTHER', 'contact_name' => 'Someone Else', 'phone' => '9111111111']);

        foreach (['zephyr cold', 'ravi@zephyr', '0042', '98765 43210', '9876543210', '43210'] as $q) {
            $this->assertSame(['LEAD-2026-0042'], $this->nos('?search=' . urlencode($q)), $q);
        }
        $this->assertSame([], $this->nos('?search=' . urlencode('%')), 'a % is a character, not a wildcard');
    }

    // ── sorting ──────────────────────────────────────────────────────────────

    public function test_sorting_by_follow_up_puts_leads_without_a_date_last_either_way(): void
    {
        $this->mk(['lead_no' => 'B', 'next_follow_up_date' => '2026-11-12']);
        $this->mk(['lead_no' => 'NONE']);
        $this->mk(['lead_no' => 'A', 'next_follow_up_date' => '2026-11-11']);

        $this->assertSame(['A', 'B', 'NONE'], $this->nos('?sort=follow_up'));
        $this->assertSame(['B', 'A', 'NONE'], $this->nos('?sort=follow_up&dir=desc'));
    }

    public function test_sorting_by_name_created_and_updated_and_ignoring_nonsense(): void
    {
        $this->mk(['lead_no' => '1', 'contact_name' => 'Zed']);
        Carbon::setTestNow(now()->addMinute());
        $this->mk(['lead_no' => '2', 'contact_name' => 'Amy']);
        Carbon::setTestNow(now()->addMinute());
        $this->mk(['lead_no' => '3', 'contact_name' => 'Max']);

        $this->assertSame(['2', '3', '1'], $this->nos('?sort=name'));
        $this->assertSame(['1', '2', '3'], $this->nos('?sort=created&dir=asc'));
        $this->assertSame(['3', '2', '1'], $this->nos(''), 'newest first by default');
        $this->assertSame(['3', '2', '1'], $this->nos('?sort=drop_table&dir=sideways'));

        Carbon::setTestNow(now()->addMinute());
        Lead::where('lead_no', '1')->first()->update(['city' => 'Pune']);
        $this->assertSame('1', $this->nos('?sort=updated')[0]);
    }

    // ── saved views ──────────────────────────────────────────────────────────

    private function saveView(string $name, array $filters, ?User $as = null)
    {
        return ($as ? $this->actingAs($as, 'sanctum') : $this)->postJson('/api/leads/views', ['name' => $name, 'filters' => $filters]);
    }

    public function test_a_person_saves_lists_and_deletes_their_own_views(): void
    {
        $id = $this->saveView('Hot Rajkot', ['source' => 'IndiaMART', 'assigned_to' => 'me', 'follow_up' => 'overdue', 'sort' => 'follow_up'])->assertStatus(201)->json('data.id');

        $this->getJson('/api/leads/views')->assertOk()->assertJsonPath('data.0.name', 'Hot Rajkot')->assertJsonPath('data.0.filters.follow_up', 'overdue');
        $this->deleteJson("/api/leads/views/{$id}")->assertNoContent();
        $this->assertSame([], $this->getJson('/api/leads/views')->json('data'));
    }

    public function test_saving_under_the_same_name_replaces_the_view(): void
    {
        $this->saveView('Mine', ['assigned_to' => 'me'])->assertStatus(201);
        $this->saveView('Mine', ['source' => 'Phone'])->assertStatus(201);

        $views = $this->getJson('/api/leads/views')->json('data');
        $this->assertCount(1, $views);
        $this->assertEquals(['source' => 'Phone'], $views[0]['filters']);
    }

    public function test_views_are_private_to_the_person(): void
    {
        $id = $this->saveView('Secret', ['source' => 'Phone'])->json('data.id');
        $other = $this->userWith(['leads.view'], 'Other');
        $this->actingAs($other, 'sanctum');

        $this->assertSame([], $this->getJson('/api/leads/views')->json('data'));
        $this->deleteJson("/api/leads/views/{$id}")->assertStatus(404);
        $this->assertNotNull(LeadView::find($id));
    }

    public function test_only_filters_the_list_understands_are_kept(): void
    {
        $this->saveView('Junk', ['status' => 'bogus', 'source' => 'Phone', 'assigned_to' => '1; drop table leads', 'follow_up' => 'someday', 'sort' => 'x', 'dir' => 'up', 'evil' => 'y', 'search' => str_repeat('a', 300)])->assertStatus(201);

        $f = $this->getJson('/api/leads/views')->json('data.0.filters');

        $this->assertSame(['source', 'search'], array_keys($f));
        $this->assertSame(100, mb_strlen($f['search']));
    }

    public function test_a_blank_name_and_a_twenty_first_view_are_refused(): void
    {
        $this->saveView('   ', [])->assertStatus(422);
        for ($i = 1; $i <= 20; $i++) {
            $this->saveView("View {$i}", ['source' => 'Phone'])->assertStatus(201);
        }

        $this->saveView('One too many', [])->assertStatus(422);
        $this->saveView('View 3', ['source' => 'Web'])->assertStatus(201);        // replacing is still fine
    }

    public function test_someone_who_can_only_view_leads_can_still_keep_views(): void
    {
        $this->saveView('Mine', ['assigned_to' => 'me'], $this->userWith(['leads.view']))->assertStatus(201);
        $this->actingAs($this->userWith([]), 'sanctum');
        $this->getJson('/api/leads/views')->assertStatus(403);
    }

    // ── own leads only ───────────────────────────────────────────────────────

    private function restricted(): User
    {
        return $this->userWith(['leads.view', 'leads.manage', 'leads.own_only'], 'Sales Exec');
    }

    public function test_a_restricted_person_sees_only_their_leads_in_the_list_and_its_counts(): void
    {
        $me = $this->restricted();
        $this->mk(['lead_no' => 'MINE', 'assigned_to_user_id' => $me->id]);
        $this->mk(['lead_no' => 'MINE-WON', 'assigned_to_user_id' => $me->id, 'status' => 'won']);
        $this->mk(['lead_no' => 'THEIRS', 'assigned_to_user_id' => $this->admin->id]);
        $this->mk(['lead_no' => 'NOBODY']);
        $this->actingAs($me, 'sanctum');

        $res = $this->getJson('/api/leads')->assertOk();

        $this->assertEqualsCanonicalizing(['MINE', 'MINE-WON'], collect($res->json('data'))->pluck('lead_no')->all());
        $this->assertSame(2, $res->json('meta.pagination.total'));
        $this->assertSame(['' => 2, 'new' => 1, 'won' => 1], $res->json('counts'));
        $this->assertSame([], $this->nos('?assigned_to=none'), 'asking for unassigned or someone else\'s finds nothing');
        $this->assertSame([], $this->nos('?assigned_to=' . $this->admin->id));
    }

    public function test_a_restricted_person_cannot_open_or_change_anyone_elses_lead(): void
    {
        $me = $this->restricted();
        $theirs = $this->mk(['assigned_to_user_id' => $this->admin->id, 'phone' => '9800000001']);
        $nobody = $this->mk(['phone' => '9800000002']);
        $mine = $this->mk(['assigned_to_user_id' => $me->id, 'phone' => '9800000003']);
        $this->actingAs($me, 'sanctum');

        foreach ([$theirs, $nobody] as $l) {
            $this->getJson("/api/leads/{$l->id}")->assertStatus(404);
            $this->getJson("/api/leads/{$l->id}/payloads")->assertStatus(404);
            $this->putJson("/api/leads/{$l->id}", ['city' => 'X'])->assertStatus(404);
            $this->postJson("/api/leads/{$l->id}/status", ['status' => 'contacted'])->assertStatus(404);
            $this->postJson("/api/leads/{$l->id}/activities", ['type' => 'note', 'description' => 'hi'])->assertStatus(404);
            $this->postJson("/api/leads/{$l->id}/convert", [])->assertStatus(404);
            $this->deleteJson("/api/leads/{$l->id}")->assertStatus(404);
        }
        $this->getJson("/api/leads/{$mine->id}")->assertOk();
        $this->assertNull($theirs->fresh()->city);
        $this->assertSame('new', $nobody->fresh()->status);
    }

    public function test_a_lead_a_restricted_person_creates_is_theirs_unless_they_pick_someone(): void
    {
        $me = $this->restricted();
        $this->actingAs($me, 'sanctum');

        $id = $this->postJson('/api/leads', ['contact_name' => 'New Buyer', 'phone' => '9801010101', 'source' => 'Phone'])->assertStatus(201)->json('data.id');
        $this->assertSame($me->id, Lead::find($id)->assigned_to_user_id);

        $other = $this->postJson('/api/leads', ['contact_name' => 'Hand Off', 'phone' => '9801010102', 'source' => 'Phone', 'assigned_to_user_id' => $this->admin->id])->assertStatus(201)->json('data.id');
        $this->assertSame($this->admin->id, Lead::find($other)->assigned_to_user_id);
    }

    public function test_the_insights_of_a_restricted_person_count_only_their_leads(): void
    {
        $me = $this->restricted();
        $this->mk(['assigned_to_user_id' => $me->id, 'status' => 'won', 'source' => 'Phone']);
        $this->mk(['assigned_to_user_id' => $me->id, 'status' => 'new', 'source' => 'Phone', 'next_follow_up_date' => '2026-11-01']);
        foreach (range(1, 5) as $i) {
            $this->mk(['assigned_to_user_id' => $this->admin->id, 'status' => 'lost', 'lost_reason' => 'Price', 'next_follow_up_date' => '2026-11-01']);
        }
        $this->actingAs($me, 'sanctum');

        $d = $this->getJson('/api/leads/dashboard')->assertOk()->json('data');

        $this->assertSame([2, 1, 0], [$d['total'], $d['won'], $d['lost']]);
        $this->assertEquals([['source' => 'Phone', 'total' => 2, 'won' => 1, 'conv_pct' => 50]], $d['by_source']);
        $this->assertSame([], $d['lost_reasons']);
        $this->assertSame(1, $d['follow_ups']['overdue']);
    }

    public function test_admins_and_star_roles_are_never_restricted_even_if_the_key_is_ticked(): void
    {
        $this->mk(['assigned_to_user_id' => null, 'lead_no' => 'NOBODY']);
        $admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.own_only']])->id]);
        $star = $this->userWith(['*', 'leads.own_only']);

        $this->assertFalse($admin->ownLeadsOnly());
        $this->assertFalse($star->ownLeadsOnly());
        $this->actingAs($star, 'sanctum');
        $this->assertSame(['NOBODY'], $this->nos());
    }

    public function test_the_restriction_is_part_of_the_role_screen(): void
    {
        $this->getJson('/api/permissions')->assertOk();
        $registry = config('permissions');
        $this->assertArrayHasKey('leads.own_only', $registry['CRM']);

        $role = Role::factory()->create(['company_id' => $this->company->id, 'permissions' => []]);
        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => ['leads.view', 'leads.own_only']])->assertOk();

        $this->assertSame(['leads.view', 'leads.own_only'], $role->fresh()->permissions);
    }

    public function test_a_restricted_export_has_only_their_leads(): void
    {
        $me = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'reports.view', 'leads.own_only']])->id]);
        $this->mk(['contact_name' => 'My Buyer', 'assigned_to_user_id' => $me->id]);
        $this->mk(['contact_name' => 'Other Buyer', 'assigned_to_user_id' => $this->admin->id]);
        $this->actingAs($me, 'sanctum');

        $csv = $this->get('/api/leads/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('My Buyer', $csv);
        $this->assertStringNotContainsString('Other Buyer', $csv);
    }

    // ── the header search ────────────────────────────────────────────────────

    private function searchLeads(string $q): array
    {
        $groups = collect($this->getJson('/api/search?q=' . urlencode($q))->assertOk()->json('data.groups'));

        return $groups->firstWhere('type', 'leads')['items'] ?? [];
    }

    public function test_the_header_search_finds_leads_by_number_name_company_and_phone(): void
    {
        $l = $this->mk(['lead_no' => 'LEAD-2026-0077', 'contact_name' => 'Ravi Patel', 'company_name' => 'Zephyr Cold Storage', 'phone' => '+91 98765 43210', 'city' => 'Rajkot', 'source' => 'IndiaMART']);
        $this->mk(['contact_name' => 'Junk Person', 'phone' => '9876543210', 'status' => 'invalid']);

        foreach (['0077', 'Ravi', 'zephyr', '98765 43210'] as $q) {
            $items = $this->searchLeads($q);
            $this->assertSame([$l->id], array_column($items, 'id'), $q);
        }
        $item = $this->searchLeads('Ravi')[0];
        $this->assertSame('LEAD-2026-0077 Ravi Patel', $item['title']);
        $this->assertStringContainsString('Zephyr Cold Storage', $item['subtitle']);
        $this->assertStringContainsString('IndiaMART', $item['meta']);
    }

    public function test_the_header_search_shows_leads_only_to_people_who_may_see_leads_and_only_theirs_if_restricted(): void
    {
        $me = $this->restricted();
        $this->mk(['contact_name' => 'Pooja Mine', 'assigned_to_user_id' => $me->id]);
        $this->mk(['contact_name' => 'Pooja Theirs', 'assigned_to_user_id' => $this->admin->id]);

        $this->actingAs($me, 'sanctum');
        $this->assertCount(1, $this->searchLeads('Pooja'));
        $this->assertStringEndsWith('Pooja Mine', $this->searchLeads('Pooja')[0]['title']);

        $this->actingAs($this->userWith(['quotations.view']), 'sanctum');
        $this->assertSame([], $this->searchLeads('Pooja'));
    }

    public function test_another_companys_leads_never_appear(): void
    {
        $other = Company::factory()->create();
        Lead::create(['company_id' => $other->id, 'lead_no' => 'X-1', 'contact_name' => 'Pooja Elsewhere', 'source' => 'Phone', 'status' => 'new']);

        $this->assertSame([], $this->searchLeads('Pooja'));
        $this->assertSame([], $this->nos('?search=Pooja'));
    }
}
