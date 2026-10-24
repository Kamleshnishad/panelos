<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PanelType;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 1.2: one engine for every status change (allowed moves, reopen reason, hold date, invalid leads left out of statistics). */
class LeadStatusEngineTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function lead(array $over = []): Lead
    {
        return app(LeadService::class)->create($this->company->id, $over + ['contact_name' => 'Ravi Patel', 'phone' => '9876543210', 'source' => 'Phone']);
    }

    private function move(Lead $lead, string $to, array $extra = [])
    {
        return $this->postJson("/api/leads/{$lead->id}/status", ['status' => $to] + $extra);
    }

    private function log(Lead $lead): array
    {
        return LeadActivity::where('lead_id', $lead->id)->where('type', 'status_change')->orderBy('id')->pluck('description')->all();
    }

    // ── the table ────────────────────────────────────────────────────────────

    public function test_the_allowed_moves_table(): void
    {
        foreach (Lead::OPEN as $from) {
            $to = Lead::allowedNext($from);
            $this->assertNotContains($from, $to);
            foreach (['on_hold', 'won', 'lost', 'invalid'] as $s) {
                $this->assertContains($s, $to, "{$from} -> {$s}");
            }
        }
        $this->assertSame(['new', 'contacted', 'qualified', 'quoted', 'won', 'lost', 'invalid'], Lead::allowedNext('on_hold'));
        foreach (Lead::CLOSED as $closed) {
            $this->assertSame(Lead::OPEN, Lead::allowedNext($closed), "{$closed} can only be reopened");
        }
        $this->assertSame(Lead::STATUSES, array_keys(Lead::transitions()));
    }

    public function test_a_move_outside_the_table_is_refused_and_changes_nothing(): void
    {
        $lead = $this->lead();
        $this->move($lead, 'won')->assertOk();
        $before = LeadActivity::where('lead_id', $lead->id)->count();

        $this->move($lead, 'lost', ['lost_reason' => 'Price'])->assertStatus(422)->assertJsonPath('message', 'A lead cannot go from won to lost. Reopen it first.');
        $this->move($lead, 'on_hold', ['hold_until' => now()->addDay()->toDateString()])->assertStatus(422);

        $this->assertSame('won', $lead->fresh()->status);
        $this->assertSame($before, LeadActivity::where('lead_id', $lead->id)->count());
    }

    public function test_moving_to_the_same_status_is_a_quiet_no_op(): void
    {
        $lead = $this->lead();

        $this->move($lead, 'new')->assertOk()->assertJsonPath('data.status', 'new');

        $this->assertSame([], $this->log($lead));
    }

    public function test_every_change_writes_a_timeline_entry_by_the_user(): void
    {
        $lead = $this->lead();

        $this->move($lead, 'qualified')->assertOk();
        $this->move($lead, 'won')->assertOk();

        $this->assertSame(['Status: new → qualified', 'Status: qualified → won'], $this->log($lead));
        $this->assertSame($this->admin->id, LeadActivity::where('lead_id', $lead->id)->where('type', 'status_change')->first()->user_id);
    }

    // ── reopening ────────────────────────────────────────────────────────────

    public function test_reopening_needs_a_reason_and_clears_the_lost_reason(): void
    {
        $lead = $this->lead();
        $this->move($lead, 'lost', ['lost_reason' => 'Price too high', 'lost_note' => 'Wanted 10% off'])->assertOk();

        $this->move($lead, 'contacted')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('lost', $lead->fresh()->status);

        $this->move($lead, 'contacted', ['reason' => 'Customer called back'])->assertOk()->assertJsonPath('data.status', 'contacted');

        $fresh = $lead->fresh();
        $this->assertNull($fresh->lost_reason);
        $this->assertNull($fresh->lost_note);
        $this->assertSame('Status: lost → contacted (reopened: Customer called back)', $this->log($lead)[1]);
    }

    public function test_a_won_or_invalid_lead_is_reopened_the_same_way(): void
    {
        foreach (['won', 'invalid'] as $closed) {
            $lead = $this->lead(['contact_name' => $closed]);
            $this->move($lead, $closed)->assertOk();

            $this->move($lead, 'new')->assertStatus(422);
            $this->move($lead, 'new', ['reason' => 'Closed by mistake'])->assertOk();

            $this->assertSame('new', $lead->fresh()->status);
        }
    }

    // ── on hold ──────────────────────────────────────────────────────────────

    public function test_on_hold_needs_a_return_date_and_sets_the_follow_up_to_it(): void
    {
        $lead = $this->lead(['next_follow_up_date' => now()->addDay()->toDateString()]);

        $this->move($lead, 'on_hold')->assertStatus(422)->assertJsonValidationErrors('hold_until');
        $this->move($lead, 'on_hold', ['hold_until' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('hold_until');
        $this->move($lead, 'on_hold', ['hold_until' => 'not a date'])->assertStatus(422);
        $this->assertSame('new', $lead->fresh()->status);

        $until = now()->addDays(20)->toDateString();
        $this->move($lead, 'on_hold', ['hold_until' => $until, 'reason' => 'Site not ready'])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('on_hold', $fresh->status);
        $this->assertSame($until, $fresh->next_follow_up_date->toDateString());
        $this->assertSame("Status: new → on hold (until {$until}, Site not ready)", $this->log($lead)[0]);
    }

    public function test_a_lead_on_hold_can_resume_be_won_lost_or_junked(): void
    {
        $until = now()->addDays(5)->toDateString();
        foreach ([['qualified', []], ['won', []], ['lost', ['lost_reason' => 'Went elsewhere']], ['invalid', []]] as [$to, $extra]) {
            $lead = $this->lead(['contact_name' => $to]);
            $this->move($lead, 'on_hold', ['hold_until' => $until])->assertOk();

            $this->move($lead, $to, $extra)->assertOk();

            $this->assertSame($to, $lead->fresh()->status);
        }
    }

    public function test_a_lead_on_hold_comes_up_in_follow_ups_on_its_return_date(): void
    {
        $lead = $this->lead();
        $this->move($lead, 'on_hold', ['hold_until' => now()->toDateString()])->assertOk();

        $this->getJson('/api/leads?follow_up=due')->assertOk()->assertJsonCount(1, 'data');
    }

    // ── invalid (junk) ───────────────────────────────────────────────────────

    public function test_invalid_leads_are_kept_but_left_out_of_the_default_list_and_every_statistic(): void
    {
        $this->lead(['contact_name' => 'Real', 'source' => 'Website']);
        $junk = $this->lead(['contact_name' => 'Spam', 'source' => 'Website', 'next_follow_up_date' => now()->subDay()->toDateString()]);
        $this->move($junk, 'invalid', ['reason' => 'Spam enquiry'])->assertOk();

        $list = $this->getJson('/api/leads')->assertOk();
        $this->assertSame(['Real'], collect($list->json('data'))->pluck('contact_name')->all());
        $this->assertSame(1, $list->json('counts.'), 'The All tab leaves junk out.');
        $this->assertSame(1, $list->json('counts.invalid'));
        $this->assertSame(['Spam'], collect($this->getJson('/api/leads?status=invalid')->json('data'))->pluck('contact_name')->all());

        $d = $this->getJson('/api/leads/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $d['total']);
        $this->assertSame(1, $d['open']);
        $this->assertSame(1, $d['invalid']);
        $this->assertSame(1, $d['by_source'][0]['total']);
        $this->assertSame(0, $d['follow_ups']['overdue'], 'A junk lead does not nag.');
        $this->assertSame(0, $this->getJson('/api/leads?follow_up=due')->json('meta.pagination.total'));
        $this->assertSame('Status: new → invalid (Spam enquiry)', $this->log($junk)[0]);
    }

    public function test_on_hold_counts_on_its_own_and_not_as_open_work_in_the_win_rate(): void
    {
        $a = $this->lead(['contact_name' => 'A']);
        $b = $this->lead(['contact_name' => 'B']);
        $c = $this->lead(['contact_name' => 'C']);
        $this->move($a, 'won')->assertOk();
        $this->move($b, 'lost', ['lost_reason' => 'Price'])->assertOk();
        $this->move($c, 'on_hold', ['hold_until' => now()->addDays(3)->toDateString()])->assertOk();

        $d = $this->getJson('/api/leads/dashboard')->json('data');

        $this->assertSame(1, $d['on_hold']);
        $this->assertSame(50.0, (float) $d['win_rate']);
    }

    // ── creating and quoting ─────────────────────────────────────────────────

    public function test_a_new_lead_can_start_new_contacted_or_qualified_only(): void
    {
        $this->postJson('/api/leads', ['contact_name' => 'A', 'status' => 'qualified'])->assertStatus(201)->assertJsonPath('data.status', 'qualified');
        foreach (['won', 'lost', 'invalid', 'on_hold', 'quoted'] as $s) {
            $this->postJson('/api/leads', ['contact_name' => 'B', 'status' => $s])->assertStatus(422);
        }
    }

    private function quote(Lead $lead)
    {
        $customer = $this->postJson("/api/leads/{$lead->id}/convert")->json('data.customer_id');
        $pt = PanelType::factory()->create(['company_id' => $this->company->id]);

        return $this->postJson('/api/quotations', ['customer_id' => $customer, 'lead_id' => $lead->id, 'panel_rows' => $this->panelRows($pt->id, 10, 900)]);
    }

    public function test_a_quotation_moves_an_open_or_on_hold_lead_to_quoted(): void
    {
        $open = $this->lead(['contact_name' => 'Open']);
        $held = $this->lead(['contact_name' => 'Held']);
        $this->move($held, 'on_hold', ['hold_until' => now()->addDays(4)->toDateString()])->assertOk();

        $this->quote($open)->assertStatus(201);
        $this->quote($held)->assertStatus(201);

        $this->assertSame('quoted', $open->fresh()->status);
        $this->assertSame('quoted', $held->fresh()->status);
    }

    public function test_a_quotation_never_reopens_a_lost_or_invalid_lead(): void
    {
        $lost = $this->lead(['contact_name' => 'Lost']);
        $this->move($lost, 'lost', ['lost_reason' => 'Price'])->assertOk();
        $junk = $this->lead(['contact_name' => 'Junk']);
        $this->move($junk, 'invalid')->assertOk();

        $this->quote($lost)->assertStatus(201);
        $this->quote($junk)->assertStatus(201);

        $this->assertSame('lost', $lost->fresh()->status);
        $this->assertSame('Price', $lost->fresh()->lost_reason);
        $this->assertSame('invalid', $junk->fresh()->status);
    }

    public function test_the_follow_up_badge_ignores_every_closed_status(): void
    {
        foreach (['won', 'invalid'] as $s) {
            $l = $this->lead(['contact_name' => $s, 'next_follow_up_date' => Carbon::yesterday()->toDateString()]);
            $this->move($l, $s)->assertOk();
        }
        $l = $this->lead(['contact_name' => 'lost', 'next_follow_up_date' => Carbon::yesterday()->toDateString()]);
        $this->move($l, 'lost', ['lost_reason' => 'Price'])->assertOk();

        $this->assertSame(0, app(LeadService::class)->followUpDueCount($this->company->id));
    }
}
