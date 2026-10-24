<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LEADS_PLAN 0.1. Pins what the lead module does today, end to end, so the phases that follow can change it on purpose.
 * Tests marked CURRENT document a behaviour the audit calls a fault (LD-xx); the sub-phase that fixes it flips that test.
 */
class LeadLifecycleTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        // A real company admin. The helper's admin is a platform super admin, which bypasses the tenant scope.
        $this->admin = $this->tenantAdmin($this->company);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function tenantAdmin(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id, 'is_company_admin' => true]);
    }

    private function lead(array $over = []): Lead
    {
        return app(LeadService::class)->create($this->company->id, $over + ['contact_name' => 'Ravi Patel', 'phone' => '9876543210', 'source' => 'Phone']);
    }

    private function newLead(array $payload = [])
    {
        return $this->postJson('/api/leads', $payload + ['contact_name' => 'Meera Shah']);
    }

    // ── creating ─────────────────────────────────────────────────────────────

    public function test_a_lead_needs_a_contact_name_and_starts_new_with_a_lead_number(): void
    {
        $this->postJson('/api/leads', ['phone' => '9000000000'])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $res = $this->newLead(['company_name' => 'Zephyr Cold Storage', 'phone' => '9811111111', 'city' => 'Rajkot', 'est_qty_sqm' => 250, 'est_value' => 300000])->assertStatus(201);

        $this->assertMatchesRegularExpression('/^LEAD-\d{4}-0001$/', $res->json('data.lead_no'));
        $this->assertSame('new', $res->json('data.status'));
        $this->assertSame('Other', Lead::find($res->json('data.id'))->source, 'No source given: Other.');
        $this->assertSame($this->company->id, Lead::find($res->json('data.id'))->company_id);
    }

    public function test_lead_numbers_count_up_per_company_and_a_deleted_lead_number_is_not_reused(): void
    {
        $a = $this->newLead()->json('data');
        $b = $this->newLead()->json('data');
        $this->assertSame('0001', substr($a['lead_no'], -4));
        $this->assertSame('0002', substr($b['lead_no'], -4));

        $this->deleteJson("/api/leads/{$b['id']}")->assertStatus(204);
        $this->assertSame('0003', substr($this->newLead()->json('data.lead_no'), -4), 'The number of a deleted lead is never handed out again.');

        $other = $this->tenantAdmin(Company::factory()->create());
        $this->actingAs($other, 'sanctum');
        $this->assertSame('0001', substr($this->newLead()->json('data.lead_no'), -4), 'Another company starts at 0001.');
    }

    public function test_CURRENT_the_lead_number_keeps_counting_across_a_new_year(): void
    {
        // LD-07 (decided in 1.6). Today LEAD-2027-0002 follows LEAD-2026-0001: the year changes, the sequence does not.
        Carbon::setTestNow('2026-12-30');
        $first = $this->newLead()->json('data.lead_no');
        Carbon::setTestNow('2027-01-02');
        $second = $this->newLead()->json('data.lead_no');
        Carbon::setTestNow();

        $this->assertSame('LEAD-2026-0001', $first);
        $this->assertSame('LEAD-2027-0002', $second);
    }

    public function test_source_is_the_companys_own_list_matched_without_regard_to_case(): void
    {
        $this->newLead(['source' => 'indiamart'])->assertStatus(201)->assertJsonPath('data.source', 'IndiaMART');
        $this->newLead(['source' => 'walk-in'])->assertStatus(201)->assertJsonPath('data.source', 'Walk-in');
        $this->newLead(['source' => 'TradeIndia'])->assertStatus(201);       // flipped in 1.1: a channel is a list entry, not a schema change
        $this->newLead(['source' => 'Smoke Signals'])->assertStatus(422);
    }

    public function test_CURRENT_the_same_phone_number_can_be_entered_again_as_a_new_lead(): void
    {
        // LD-05: no duplicate detection. Sub-phase 1.4 turns this into a warning.
        $first = $this->newLead(['phone' => '9822222222'])->assertStatus(201)->json('data.id');
        $second = $this->newLead(['phone' => '9822222222'])->assertStatus(201)->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Lead::where('phone', '9822222222')->count());
    }

    // ── assigning, listing, searching ────────────────────────────────────────

    public function test_a_lead_can_only_be_assigned_to_someone_in_the_same_company(): void
    {
        $mine = $this->tenantAdmin($this->company);
        $foreign = $this->tenantAdmin(Company::factory()->create());

        $this->newLead(['assigned_to_user_id' => $mine->id])->assertStatus(201)->assertJsonPath('data.assigned_to_user_id', $mine->id);
        $this->newLead(['assigned_to_user_id' => $foreign->id])->assertStatus(422);
        $id = $this->newLead()->json('data.id');
        $this->putJson("/api/leads/{$id}", ['assigned_to_user_id' => $foreign->id])->assertStatus(422);
    }

    public function test_the_list_filters_by_status_source_assignee_and_search_and_counts_every_status(): void
    {
        $rep = $this->tenantAdmin($this->company);
        $a = $this->lead(['contact_name' => 'Anil Mehta', 'company_name' => 'Mehta Agro', 'phone' => '9811100001', 'source' => 'IndiaMART', 'assigned_to_user_id' => $rep->id]);
        $b = $this->lead(['contact_name' => 'Bina Joshi', 'phone' => '9811100002', 'source' => 'Website']);
        $c = $this->lead(['contact_name' => 'Chirag Doshi', 'phone' => '9811100003', 'source' => 'IndiaMART']);
        app(LeadService::class)->changeStatus($c, 'contacted');

        $names = fn (string $qs) => collect($this->getJson('/api/leads' . $qs)->assertOk()->json('data'))->pluck('contact_name')->sort()->values()->all();

        $this->assertSame(['Anil Mehta', 'Bina Joshi', 'Chirag Doshi'], $names(''));
        $this->assertSame(['Chirag Doshi'], $names('?status=contacted'));
        $this->assertSame(['Anil Mehta', 'Chirag Doshi'], $names('?source=IndiaMART'));
        $this->assertSame(['Anil Mehta'], $names('?assigned_to=' . $rep->id));
        $this->assertSame(['Anil Mehta'], $names('?search=mehta+agro'), 'Searches the company name.');
        $this->assertSame(['Bina Joshi'], $names('?search=9811100002'), 'Searches the phone.');
        $this->assertSame(['Chirag Doshi'], $names('?search=' . $c->lead_no), 'Searches the lead number.');

        $counts = $this->getJson('/api/leads?status=contacted')->json('counts');
        $this->assertSame(3, $counts[''], 'The tab counts ignore the filter.');
        $this->assertSame(2, $counts['new']);
        $this->assertSame(1, $counts['contacted']);
    }

    public function test_the_list_is_paginated(): void
    {
        foreach (range(1, 5) as $i) {
            $this->lead(['contact_name' => "Lead {$i}", 'phone' => "90000000{$i}0"]);
        }

        $res = $this->getJson('/api/leads?per_page=2&page=2')->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(5, $res->json('meta.pagination.total'));
        $this->assertSame(3, $res->json('meta.pagination.last_page'));
        $this->assertSame(2, $res->json('meta.pagination.current_page'));
    }

    // ── status and timeline ──────────────────────────────────────────────────

    public function test_a_status_change_is_written_to_the_timeline_and_lost_keeps_its_reason(): void
    {
        $lead = $this->lead();

        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'contacted'])->assertOk()->assertJsonPath('data.status', 'contacted');
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'lost', 'lost_reason' => 'Price too high'])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('lost', $fresh->status);
        $this->assertSame('Price too high', $fresh->lost_reason);
        $log = LeadActivity::where('lead_id', $lead->id)->where('type', 'status_change')->orderBy('id')->pluck('description')->all();
        $this->assertSame('Status: new → contacted', $log[0]);
        $this->assertSame('Status: contacted → lost (reason: Price too high)', $log[1]);
        $this->assertSame($this->admin->id, LeadActivity::where('lead_id', $lead->id)->orderBy('id')->first()->user_id);
    }

    public function test_only_the_known_statuses_are_accepted(): void
    {
        $lead = $this->lead();

        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'maybe'])->assertStatus(422);
        $this->postJson("/api/leads/{$lead->id}/status", [])->assertStatus(422);
        foreach (['contacted', 'qualified', 'quoted', 'new'] as $status) {
            $this->postJson("/api/leads/{$lead->id}/status", ['status' => $status])->assertOk();
        }
    }

    public function test_editing_a_lead_cannot_change_its_status_but_can_resend_the_current_one(): void
    {
        // LD-03 (1.2): a status change goes through the status action, which checks the move and writes the timeline.
        $lead = $this->lead();

        $this->putJson("/api/leads/{$lead->id}", ['status' => 'lost'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('new', $lead->fresh()->status);

        $this->putJson("/api/leads/{$lead->id}", ['status' => 'new', 'city' => 'Surat'])->assertOk();
        $this->assertSame('Surat', $lead->fresh()->city);
    }

    public function test_activities_are_notes_calls_emails_whatsapp_and_meetings_newest_first(): void
    {
        $lead = $this->lead();

        foreach (['note', 'call', 'email', 'whatsapp', 'meeting'] as $type) {
            $this->postJson("/api/leads/{$lead->id}/activities", ['type' => $type, 'description' => "A {$type}"])->assertOk();
        }
        $this->postJson("/api/leads/{$lead->id}/activities", ['type' => 'status_change', 'description' => 'sneaky'])->assertStatus(422);
        $this->postJson("/api/leads/{$lead->id}/activities", ['type' => 'fax'])->assertStatus(422);

        $timeline = $this->getJson("/api/leads/{$lead->id}")->assertOk()->json('data.activities');
        $this->assertCount(5, $timeline);
        $this->assertSame('A meeting', $timeline[0]['description'], 'Newest first.');
        $this->assertSame($this->admin->name, $timeline[0]['user']['name']);
    }

    // ── converting and quoting ───────────────────────────────────────────────

    public function test_convert_creates_a_customer_from_the_lead_and_links_it(): void
    {
        $lead = $this->lead(['company_name' => 'Zephyr Cold Storage', 'contact_name' => 'Zoya Khan', 'email' => 'zoya@zephyr.test', 'phone' => '9833333333', 'city' => 'Rajkot']);

        $res = $this->postJson("/api/leads/{$lead->id}/convert")->assertOk();

        $customer = Customer::find($res->json('data.customer_id'));
        $this->assertSame($lead->id, $res->json('data.lead_id'));
        $this->assertSame('Zephyr Cold Storage', $customer->name, 'The company name wins over the contact name.');
        $this->assertSame('Zoya Khan', $customer->contact_person);
        $this->assertSame('zoya@zephyr.test', $customer->email);
        $this->assertSame('9833333333', $customer->phone);
        $this->assertSame('Rajkot', $customer->city);
        $this->assertSame(30, (int) $customer->payment_terms_days);
        $this->assertEquals(0, $customer->credit_limit, 'No credit limit until someone sets one.');
        $this->assertSame($this->company->id, $customer->company_id);
        $this->assertSame($customer->id, $lead->fresh()->customer_id);
        $this->assertTrue(LeadActivity::where('lead_id', $lead->id)->where('description', 'like', 'Customer created/linked:%')->exists());
    }

    public function test_converting_twice_reuses_the_same_customer(): void
    {
        $lead = $this->lead();

        $one = $this->postJson("/api/leads/{$lead->id}/convert")->json('data.customer_id');
        $two = $this->postJson("/api/leads/{$lead->id}/convert")->json('data.customer_id');

        $this->assertSame($one, $two);
        $this->assertSame(1, Customer::where('company_id', $this->company->id)->count());
    }

    public function test_CURRENT_convert_leaves_the_state_blank_and_does_not_look_for_an_existing_customer(): void
    {
        // LD-04. Sub-phase 1.5 asks for the state, reuses a matching customer and uses the normal code sequence.
        $existing = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Ravi Patel', 'phone' => '9876543210']);
        $lead = $this->lead(['contact_name' => 'Ravi Patel', 'phone' => '9876543210']);

        $customerId = $this->postJson("/api/leads/{$lead->id}/convert")->json('data.customer_id');

        $this->assertNotSame($existing->id, $customerId, 'A second Ravi Patel is created.');
        $this->assertSame('', Customer::find($customerId)->getRawOriginal('state_code'), 'No state, so the GST split is unknown.');
    }

    private function quotationFor(Lead $lead, ?int $customerId = null)
    {
        $customerId ??= $this->postJson("/api/leads/{$lead->id}/convert")->json('data.customer_id');
        $pt = PanelType::factory()->create(['company_id' => $this->company->id]);

        return $this->postJson('/api/quotations', [
            'customer_id' => $customerId, 'lead_id' => $lead->id, 'panel_rows' => $this->panelRows($pt->id, 10, 900),
        ]);
    }

    public function test_a_quotation_made_for_a_lead_links_back_and_moves_it_to_quoted(): void
    {
        $lead = $this->lead();

        $res = $this->quotationFor($lead)->assertStatus(201);

        $fresh = $lead->fresh();
        $this->assertSame('quoted', $fresh->status);
        $this->assertSame($res->json('data.id'), $fresh->quotation_id);
        $this->assertNotNull($fresh->customer_id);
        $this->assertTrue(LeadActivity::where('lead_id', $lead->id)->where('description', 'Converted to quotation')->exists());
    }

    public function test_a_won_lead_stays_won_when_another_quotation_is_made_and_the_timeline_says_so(): void
    {
        // LD-02 (1.2): a closed lead is never reopened by a quotation.
        $lead = $this->lead();
        $this->quotationFor($lead)->assertStatus(201);
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'won'])->assertOk();
        $first = $lead->fresh()->quotation_id;

        $this->quotationFor($lead->fresh(), $lead->fresh()->customer_id)->assertStatus(201);

        $fresh = $lead->fresh();
        $this->assertSame('won', $fresh->status);
        $this->assertSame($first, $fresh->quotation_id, 'The quotation it was won on stays linked.');
        $this->assertTrue(LeadActivity::where('lead_id', $lead->id)->where('description', 'like', '%stays won%')->exists());
    }

    public function test_accepting_the_quotation_alone_does_not_mark_the_lead_won(): void
    {
        // LD-01 (1.3): "won" means an order was created (see LeadWonFromOrderTest), not that the quotation was accepted.
        $lead = $this->lead();
        $q = $this->quotationFor($lead)->json('data.id');

        Quotation::where('id', $q)->update(['status' => 'accepted']);

        $this->assertSame('quoted', $lead->fresh()->status);
    }

    // ── follow-ups and the dashboard ─────────────────────────────────────────

    public function test_the_follow_up_count_and_filter_include_today_and_overdue_but_not_closed_or_future_leads(): void
    {
        $overdue = $this->lead(['contact_name' => 'Overdue', 'next_follow_up_date' => now()->subDays(3)->toDateString()]);
        $today = $this->lead(['contact_name' => 'Today', 'next_follow_up_date' => now()->toDateString()]);
        $this->lead(['contact_name' => 'Later', 'next_follow_up_date' => now()->addDays(2)->toDateString()]);
        $this->lead(['contact_name' => 'None']);
        $won = $this->lead(['contact_name' => 'Won', 'next_follow_up_date' => now()->subDay()->toDateString()]);
        app(LeadService::class)->changeStatus($won, 'won');

        $this->assertSame(2, app(LeadService::class)->followUpDueCount($this->company->id));
        $due = collect($this->getJson('/api/leads?follow_up=due')->json('data'))->pluck('contact_name')->sort()->values()->all();
        $this->assertSame(['Overdue', 'Today'], $due);
    }

    public function test_the_dashboard_counts_the_funnel_win_rate_sources_lost_reasons_and_follow_up_buckets(): void
    {
        $svc = app(LeadService::class);
        $svc->create($this->company->id, ['contact_name' => 'N1', 'source' => 'IndiaMART']);
        $q = $svc->create($this->company->id, ['contact_name' => 'Q1', 'source' => 'IndiaMART', 'next_follow_up_date' => now()->subDays(2)->toDateString()]);
        $svc->changeStatus($q, 'quoted');
        foreach (['W1' => 'IndiaMART', 'W2' => 'Website', 'W3' => 'Website'] as $name => $src) {
            $svc->changeStatus($svc->create($this->company->id, ['contact_name' => $name, 'source' => $src]), 'won');
        }
        $svc->changeStatus($svc->create($this->company->id, ['contact_name' => 'L1', 'source' => 'Website']), 'lost', 'Price');
        $svc->changeStatus($svc->create($this->company->id, ['contact_name' => 'L2', 'source' => 'Website']), 'lost', 'Price');
        $svc->create($this->company->id, ['contact_name' => 'F1', 'next_follow_up_date' => now()->toDateString()]);
        $svc->create($this->company->id, ['contact_name' => 'F2', 'next_follow_up_date' => now()->addDays(3)->toDateString()]);

        $d = $this->getJson('/api/leads/dashboard')->assertOk()->json('data');

        $this->assertSame(9, $d['total']);
        $this->assertSame(3, $d['won']);
        $this->assertSame(2, $d['lost']);
        $this->assertSame(4, $d['open']);
        $this->assertEquals(60.0, $d['win_rate'], '3 won of 5 closed.');
        $funnel = collect($d['funnel'])->pluck('count', 'status');
        $this->assertSame(['new' => 3, 'contacted' => 0, 'qualified' => 0, 'quoted' => 1, 'won' => 3, 'lost' => 2], $funnel->all());
        $web = collect($d['by_source'])->firstWhere('source', 'Website');
        $this->assertSame(['total' => 4, 'won' => 2], ['total' => $web['total'], 'won' => $web['won']]);
        $this->assertEquals(50.0, $web['conv_pct']);
        $this->assertSame([['reason' => 'Price', 'count' => 2]], $d['lost_reasons']);
        $this->assertSame(['overdue' => 1, 'today' => 1, 'this_week' => 1], $d['follow_ups']);
    }

    // ── deleting, isolation, permissions, audit ──────────────────────────────

    public function test_deleting_a_lead_hides_it_but_keeps_the_row(): void
    {
        $lead = $this->lead();

        $this->deleteJson("/api/leads/{$lead->id}")->assertStatus(204);

        $this->assertCount(0, $this->getJson('/api/leads')->json('data'));
        $this->getJson("/api/leads/{$lead->id}")->assertStatus(404);
        $this->assertNotNull(Lead::withTrashed()->find($lead->id)->deleted_at);
    }

    public function test_another_company_cannot_see_or_touch_a_lead(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->tenantAdmin(Company::factory()->create()), 'sanctum');

        $this->assertCount(0, $this->getJson('/api/leads')->json('data'));
        $this->assertSame(0, $this->getJson('/api/leads/dashboard')->json('data.total'));
        $this->getJson("/api/leads/{$lead->id}")->assertStatus(404);
        $this->putJson("/api/leads/{$lead->id}", ['contact_name' => 'Hijacked'])->assertStatus(400);
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'won'])->assertStatus(400);
        $this->postJson("/api/leads/{$lead->id}/activities", ['type' => 'note', 'description' => 'x'])->assertStatus(400);
        $this->postJson("/api/leads/{$lead->id}/convert")->assertStatus(400);
        $this->deleteJson("/api/leads/{$lead->id}")->assertStatus(400);

        $fresh = $lead->fresh();
        $this->assertSame('Ravi Patel', $fresh->contact_name);
        $this->assertSame('new', $fresh->status);
        $this->assertNull($fresh->customer_id);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->count());
    }

    public function test_viewing_needs_leads_view_and_changing_needs_leads_manage(): void
    {
        $lead = $this->lead();
        $viewer = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false,
            'role_id' => Role::factory()->for($this->company)->create(['permissions' => ['leads.view']])->id]);
        $nobody = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false,
            'role_id' => Role::factory()->for($this->company)->create(['permissions' => []])->id]);

        $this->actingAs($viewer, 'sanctum');
        $this->getJson('/api/leads')->assertOk();
        $this->getJson("/api/leads/{$lead->id}")->assertOk();
        $this->getJson('/api/leads/dashboard')->assertOk();
        $this->postJson('/api/leads', ['contact_name' => 'X'])->assertStatus(403);
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'won'])->assertStatus(403);
        $this->deleteJson("/api/leads/{$lead->id}")->assertStatus(403);

        $this->actingAs($nobody, 'sanctum');
        $this->getJson('/api/leads')->assertStatus(403);
    }

    public function test_creating_and_editing_a_lead_is_written_to_the_audit_log_with_the_user(): void
    {
        $id = $this->newLead(['contact_name' => 'Audited Lead'])->json('data.id');
        $this->putJson("/api/leads/{$id}", ['city' => 'Surat'])->assertOk();

        $rows = AuditLog::where('auditable_type', 'Lead')->where('auditable_id', $id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $rows->pluck('action')->all());
        $this->assertSame($this->admin->id, $rows[1]->user_id);
        $this->assertSame('Surat', $rows[1]->after['city']);
    }

    public function test_the_customer_profile_lists_the_leads_that_became_that_customer(): void
    {
        $lead = $this->lead(['contact_name' => 'Profile Buyer']);
        $customerId = $this->postJson("/api/leads/{$lead->id}/convert")->json('data.customer_id');

        $profile = $this->getJson("/api/customers/{$customerId}/profile")->assertOk()->json('data');

        $this->assertContains($lead->lead_no, collect($profile['leads'])->pluck('no')->all());
    }
}
