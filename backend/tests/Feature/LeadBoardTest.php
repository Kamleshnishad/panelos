<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 5.8 (board): one column per open stage and one for leads on hold, with counts, totals and the first leads, under the list's own filters. */
class LeadBoardTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(array $o = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "LD-{$n}", 'contact_name' => "Buyer {$n}", 'source' => 'Phone', 'status' => 'new']);
    }

    private function cols(string $query = ''): array
    {
        return collect($this->getJson('/api/leads/board' . $query)->assertOk()->json('data.columns'))->keyBy('status')->all();
    }

    public function test_the_columns_are_the_open_stages_and_on_hold_in_that_order_and_closed_leads_are_not_on_the_board(): void
    {
        foreach (['new', 'contacted', 'qualified', 'quoted', 'on_hold', 'won', 'lost', 'invalid'] as $s) {
            $this->lead(['status' => $s]);
        }

        $res = $this->getJson('/api/leads/board')->assertOk();

        $this->assertSame(['new', 'contacted', 'qualified', 'quoted', 'on_hold'], array_column($res->json('data.columns'), 'status'));
        $this->assertSame([1, 1, 1, 1, 1], array_column($res->json('data.columns'), 'count'));
        $this->assertSame(25, $res->json('data.per_column'));
    }

    public function test_each_column_has_its_count_area_and_value_and_only_the_first_leads_with_how_many_more(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->lead(['status' => 'contacted', 'est_qty_sqm' => 10, 'est_value' => 1000.5]);
        }
        $this->lead(['status' => 'new', 'est_qty_sqm' => 5.25, 'est_value' => null]);

        $c = $this->cols();

        $this->assertEquals([27, 270.0, 27013.5, 2, 25], [$c['contacted']['count'], $c['contacted']['est_qty_sqm'], $c['contacted']['est_value'], $c['contacted']['more'], count($c['contacted']['leads'])]);
        $this->assertEquals([1, 5.25, 0.0, 0], [$c['new']['count'], $c['new']['est_qty_sqm'], $c['new']['est_value'], $c['new']['more']]);
        $this->assertSame([0, 0, []], [$c['qualified']['count'], $c['qualified']['more'], $c['qualified']['leads']]);
    }

    public function test_within_a_column_the_nearest_follow_up_comes_first_and_leads_without_a_date_last(): void
    {
        $none = $this->lead();
        $late = $this->lead(['next_follow_up_date' => '2026-11-12']);
        $soon = $this->lead(['next_follow_up_date' => '2026-11-10']);

        $this->assertSame([$soon->id, $late->id, $none->id], array_column($this->cols()['new']['leads'], 'id'));
    }

    public function test_the_cards_carry_what_they_show(): void
    {
        $ben = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Ben']);
        $this->lead(['company_name' => 'Zephyr', 'assigned_to_user_id' => $ben->id, 'est_qty_sqm' => 12, 'next_follow_up_date' => '2026-11-11']);

        $card = $this->cols()['new']['leads'][0];

        $this->assertSame(['Zephyr', 'Ben', '2026-11-11'], [$card['company_name'], $card['assigned_user']['name'], substr($card['next_follow_up_date'], 0, 10)]);
        foreach (['id', 'lead_no', 'contact_name', 'source', 'est_qty_sqm', 'quiet_days', 'response_waiting_minutes'] as $k) {
            $this->assertArrayHasKey($k, $card);
        }
    }

    public function test_the_lists_filters_apply_and_a_status_filter_is_ignored(): void
    {
        $ben = User::factory()->create(['company_id' => $this->company->id]);
        $this->lead(['source' => 'IndiaMART', 'assigned_to_user_id' => $ben->id]);
        $this->lead(['source' => 'Phone', 'status' => 'contacted']);
        $this->lead(['source' => 'IndiaMART', 'status' => 'contacted', 'next_follow_up_date' => '2026-11-01']);

        $this->assertSame([1, 1], [$this->cols('?source=IndiaMART')['new']['count'], $this->cols('?source=IndiaMART')['contacted']['count']]);
        $this->assertSame([1, 0], [$this->cols("?assigned_to={$ben->id}")['new']['count'], $this->cols("?assigned_to={$ben->id}")['contacted']['count']]);
        $this->assertSame([0, 1], [$this->cols('?follow_up=overdue')['new']['count'], $this->cols('?follow_up=overdue')['contacted']['count']]);
        $this->assertSame([1, 2], [$this->cols('?status=won')['new']['count'], $this->cols('?status=won')['contacted']['count']], 'the columns are the statuses, so a status filter means nothing here');
    }

    public function test_quiet_and_unanswered_work_on_the_board_too(): void
    {
        $quiet = $this->lead();
        \DB::table('leads')->where('id', $quiet->id)->update(['created_at' => now()->subDays(20)]);
        $this->lead();

        $this->assertSame(1, $this->cols('?quiet=1')['new']['count']);
        $this->assertSame(2, $this->cols('?unanswered=1')['new']['count']);
    }

    public function test_search_narrows_the_board(): void
    {
        $this->lead(['contact_name' => 'Ravi Patel']);
        $this->lead(['contact_name' => 'Someone Else']);

        $this->assertSame(1, $this->cols('?search=Ravi')['new']['count']);
    }

    public function test_a_restricted_person_sees_only_their_own_leads_and_other_companies_never_appear(): void
    {
        $me = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.own_only']])->id]);
        $this->lead(['assigned_to_user_id' => $me->id]);
        $this->lead(['assigned_to_user_id' => $this->admin->id]);
        $this->lead();
        Lead::create(['company_id' => Company::factory()->create()->id, 'lead_no' => 'X-1', 'contact_name' => 'Elsewhere', 'source' => 'Phone', 'status' => 'new']);

        $this->assertSame(3, $this->cols()['new']['count']);
        $this->actingAs($me, 'sanctum');
        $this->assertSame(1, $this->cols()['new']['count']);
    }

    public function test_reading_the_board_needs_leads_view(): void
    {
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['quotations.view']])->id]), 'sanctum');

        $this->getJson('/api/leads/board')->assertStatus(403);
    }

    public function test_the_board_route_is_not_taken_for_a_lead_id(): void
    {
        $l = $this->lead();

        $this->getJson("/api/leads/{$l->id}")->assertOk()->assertJsonPath('data.id', $l->id);
        $this->assertSame(LeadService::BOARD_COLUMNS, array_column($this->getJson('/api/leads/board')->json('data.columns'), 'status'));
    }
}
