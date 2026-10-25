<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadTask;
use App\Models\NotificationSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadStaleService;
use App\Services\LeadTaskService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 5.6: how fast a lead was first answered, and which open leads have gone quiet. Both are read from the timeline: nothing is stored. */
class LeadEngagementTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'name' => 'Admin']);
        $this->ben = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'name' => 'Ben']);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A lead that arrived $daysAgo days (and $hoursAgo hours) before now. */
    private function lead(float $daysAgo = 0, array $o = []): Lead
    {
        static $n = 0;
        $n++;
        $at = now()->subMinutes((int) round($daysAgo * 1440));
        $l = Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "LD-{$n}", 'contact_name' => "Buyer {$n}", 'source' => 'Phone', 'status' => 'new', 'assigned_to_user_id' => $this->ben->id]);
        \DB::table('leads')->where('id', $l->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $l->fresh();
    }

    private function act(Lead $l, string $type, $when, ?User $by = null, string $text = 'x'): LeadActivity
    {
        return LeadActivity::create(['company_id' => $this->company->id, 'lead_id' => $l->id, 'user_id' => $by?->id, 'type' => $type, 'description' => $text, 'activity_date' => $when]);
    }

    private function row(Lead $l): array
    {
        return collect($this->getJson('/api/leads?per_page=100')->assertOk()->json('data'))->firstWhere('id', $l->id);
    }

    // ── first response ───────────────────────────────────────────────────────

    public function test_the_first_call_email_whatsapp_or_meeting_by_a_person_is_the_response_and_its_time_is_measured(): void
    {
        foreach (['call', 'email', 'whatsapp', 'meeting'] as $type) {
            $l = $this->lead(1);
            $this->act($l, $type, now()->subDay()->addMinutes(12), $this->ben);
            $this->act($l, $type, now()->subHours(5), $this->ben);                           // a later one does not move it

            $this->assertSame(12, $this->row($l)['response_minutes'], $type);
        }
    }

    public function test_notes_the_systems_own_lines_and_plain_tasks_do_not_count_as_a_response(): void
    {
        $l = $this->lead(1);
        $this->act($l, 'note', now()->subHours(20), $this->ben, 'Just a note');
        $this->act($l, 'call', now()->subHours(19), null, 'a system line that looks like a call');
        $this->act($l, 'status_change', now()->subHours(18), null, 'Assigned to Ben (rule: Website)');
        $this->act($l, 'note', now()->subHours(17), $this->ben, 'Next action planned: Call on 12 Nov');

        $row = $this->row($l);

        $this->assertNull($row['response_minutes']);
        $this->assertSame(1440, $row['response_waiting_minutes']);
    }

    public function test_a_person_moving_a_lead_out_of_new_counts_but_other_status_changes_do_not(): void
    {
        $moved = $this->lead(1);
        $this->act($moved, 'status_change', now()->subDay()->addMinutes(30), $this->ben, 'Status: new → contacted');
        $other = $this->lead(1);
        $this->act($other, 'status_change', now()->subHours(10), $this->ben, 'Status: contacted → qualified');

        $this->assertSame(30, $this->row($moved)['response_minutes']);
        $this->assertNull($this->row($other)['response_minutes']);
    }

    public function test_finishing_a_planned_call_through_the_real_endpoint_is_a_response(): void
    {
        $l = $this->lead(0.5);
        $id = $this->postJson("/api/leads/{$l->id}/tasks", ['type' => 'call', 'due_date' => '2026-11-10'])->assertStatus(201)->json('data.id');
        Carbon::setTestNow(now()->addMinutes(20));

        $this->postJson("/api/leads/tasks/{$id}/complete", ['note' => 'Spoke'])->assertOk();

        $this->assertSame(740, $this->row($l)['response_minutes']);                           // 12 h earlier + 20 min
    }

    public function test_the_wait_is_measured_from_the_buyers_own_time_unless_that_is_old_news(): void
    {
        $fresh = $this->lead(0, ['first_seen_at' => now()->subHours(2)]);                     // IndiaMART said 2 h ago, arrived now
        $this->act($fresh, 'call', now()->addMinutes(10), $this->ben);
        $old = $this->lead(0, ['first_seen_at' => now()->subDays(3)]);                        // an old enquiry imported just now
        $this->act($old, 'call', now()->addMinutes(10), $this->ben);

        $this->assertSame(130, $this->row($fresh)['response_minutes']);
        $this->assertSame(10, $this->row($old)['response_minutes']);
    }

    public function test_waiting_is_only_for_open_leads_nobody_has_answered(): void
    {
        $open = $this->lead(0.25);
        $won = $this->lead(0.25, ['status' => 'won']);
        $hold = $this->lead(0.25, ['status' => 'on_hold']);

        $this->assertSame(360, $this->row($open)['response_waiting_minutes']);
        $this->assertNull($this->row($won)['response_waiting_minutes']);
        $this->assertNull($this->row($hold)['response_waiting_minutes']);
    }

    // ── quiet ────────────────────────────────────────────────────────────────

    public function test_an_open_lead_nobody_has_touched_for_the_quiet_days_is_quiet_and_a_touch_resets_it(): void
    {
        $quiet = $this->lead(10);
        $this->act($quiet, 'note', now()->subDays(8), $this->ben);
        $recent = $this->lead(10);
        $this->act($recent, 'note', now()->subDays(3), $this->ben);
        $never = $this->lead(9);

        $this->assertEqualsCanonicalizing([$quiet->id, $never->id], collect($this->getJson('/api/leads?quiet=1')->json('data'))->pluck('id')->all());
        $this->assertSame(8, $this->row($quiet)['quiet_days']);
        $this->assertSame(3, $this->row($recent)['quiet_days']);
        $this->assertSame(9, $this->row($never)['quiet_days'], 'a lead never touched counts from the day it arrived');
    }

    public function test_the_systems_own_lines_do_not_keep_a_lead_awake(): void
    {
        $l = $this->lead(10);
        $this->act($l, 'note', now()->subHour(), null, 'Enquired again on Website');
        $this->act($l, 'status_change', now()->subMinutes(5), null, 'Assigned to Ben (rule: x)');

        $this->assertSame([$l->id], collect($this->getJson('/api/leads?quiet=1')->json('data'))->pluck('id')->all());
    }

    public function test_closed_and_on_hold_leads_and_leads_with_an_action_planned_are_not_quiet(): void
    {
        $this->lead(20, ['status' => 'won']);
        $this->lead(20, ['status' => 'lost']);
        $this->lead(20, ['status' => 'on_hold']);
        $planned = $this->lead(20);
        LeadTask::create(['company_id' => $this->company->id, 'lead_id' => $planned->id, 'assigned_to_user_id' => $this->ben->id, 'type' => 'call', 'due_date' => LeadTaskService::today()->toDateString(), 'status' => 'open']);
        $overdueOnly = $this->lead(20);
        LeadTask::create(['company_id' => $this->company->id, 'lead_id' => $overdueOnly->id, 'assigned_to_user_id' => $this->ben->id, 'type' => 'call', 'due_date' => '2026-11-01', 'status' => 'open']);

        $ids = collect($this->getJson('/api/leads?quiet=1')->json('data'))->pluck('id')->all();

        $this->assertSame([$overdueOnly->id], $ids, 'a planned action today or later means someone has it in hand; an overdue one does not');
    }

    public function test_the_company_sets_how_many_quiet_days_and_zero_turns_the_alert_off_but_not_the_view(): void
    {
        $l = $this->lead(5);
        $this->assertSame([], $this->getJson('/api/leads?quiet=1')->json('data'), 'default 7 days');

        $this->putJson('/api/settings/notifications', ['lead_stale_days' => 3])->assertOk();
        $this->assertSame([$l->id], collect($this->getJson('/api/leads?quiet=1')->json('data'))->pluck('id')->all());

        $this->putJson('/api/settings/notifications', ['lead_stale_days' => 0])->assertOk();
        $this->getJson('/api/settings/notifications')->assertJsonPath('data.lead_stale_days', 0);
        $this->assertSame([], $this->getJson('/api/leads?quiet=1')->json('data'), 'zero falls back to 7 for the view');
        $this->putJson('/api/settings/notifications', ['lead_stale_days' => 91])->assertStatus(422);
    }

    public function test_a_company_that_never_set_it_gets_seven_days(): void
    {
        $this->assertNull(NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $this->company->id)->first());
        $this->lead(8);

        $this->assertCount(1, $this->getJson('/api/leads?quiet=1')->json('data'));
        $this->assertSame(7, $this->getJson('/api/leads/dashboard')->json('data.response.quiet_days'));
        $this->getJson('/api/settings/notifications')->assertJsonPath('data.lead_stale_days', 7);
    }

    // ── unanswered, saved views, detail, insights ────────────────────────────

    public function test_the_unanswered_filter_lists_open_leads_nobody_has_responded_to(): void
    {
        $waiting = $this->lead(1);
        $answered = $this->lead(1);
        $this->act($answered, 'call', now()->subHours(20), $this->ben);
        $this->lead(1, ['status' => 'lost']);

        $this->assertSame([$waiting->id], collect($this->getJson('/api/leads?unanswered=1')->json('data'))->pluck('id')->all());
    }

    public function test_a_saved_view_can_keep_the_quiet_and_unanswered_filters(): void
    {
        $this->postJson('/api/leads/views', ['name' => 'Neglected', 'filters' => ['quiet' => '1', 'unanswered' => '1', 'junk' => '1']])->assertStatus(201);

        $this->assertEquals(['quiet' => '1', 'unanswered' => '1'], $this->getJson('/api/leads/views')->json('data.0.filters'));
    }

    public function test_the_detail_carries_the_response_and_quiet_figures(): void
    {
        $l = $this->lead(10);
        $this->act($l, 'whatsapp', now()->subDays(10)->addMinutes(45), $this->ben);

        $d = $this->getJson("/api/leads/{$l->id}")->assertOk()->json('data');

        $this->assertSame([45, 9], [$d['response_minutes'], $d['quiet_days']], 'the last touch was 45 minutes into day ten: nine full days of quiet');
        $this->assertNull($d['response_waiting_minutes']);
    }

    public function test_the_insights_say_how_fast_leads_are_answered_and_how_many_wait_or_are_quiet(): void
    {
        $a = $this->lead(3);
        $this->act($a, 'call', now()->subDays(3)->addMinutes(10), $this->ben);
        $b = $this->lead(3);
        $this->act($b, 'call', now()->subDays(3)->addMinutes(110), $this->ben);
        $this->lead(3);                                                                       // nobody answered it: waiting, and quiet (3 days < 7: not quiet)
        $this->lead(12);                                                                      // waiting and quiet
        $this->lead(40);                                                                      // older than 30 days: not in the averages, but waiting and quiet

        $r = $this->getJson('/api/leads/dashboard')->assertOk()->json('data.response');

        $this->assertEquals([2, 60, 50, 3, 2, 7], [$r['responded'], $r['avg_minutes'], $r['within_hour_pct'], $r['waiting'], $r['quiet'], $r['quiet_days']]);
    }

    public function test_a_restricted_person_sees_only_their_own_numbers(): void
    {
        $me = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.own_only']])->id]);
        $this->lead(12, ['assigned_to_user_id' => $me->id]);
        $this->lead(12);
        $this->lead(12);
        $this->actingAs($me, 'sanctum');

        $r = $this->getJson('/api/leads/dashboard')->json('data.response');

        $this->assertSame([1, 1], [$r['waiting'], $r['quiet']]);
        $this->assertCount(1, $this->getJson('/api/leads?quiet=1')->json('data'));
    }

    public function test_the_export_and_the_list_still_work_with_the_new_columns(): void
    {
        $this->lead(12);
        $this->getJson('/api/leads')->assertOk()->assertJsonStructure(['data' => [['id', 'quiet_days', 'response_minutes', 'response_waiting_minutes']]]);
        $this->get('/api/leads/export?quiet=1')->assertOk();
    }

    // ── the bell ─────────────────────────────────────────────────────────────

    private function bell(User $u)
    {
        return AppNotification::withoutGlobalScope('tenant')->where('user_id', $u->id)->where('type', LeadStaleService::TYPE)->get();
    }

    private function remind(): void
    {
        app(LeadStaleService::class)->sync($this->company->id);
    }

    public function test_each_person_gets_one_line_for_their_quiet_leads_naming_the_oldest(): void
    {
        $oldest = $this->lead(20, ['contact_name' => 'Ravi Patel']);
        $this->lead(10);
        $this->lead(2);                                                                       // not quiet
        $this->lead(15, ['assigned_to_user_id' => $this->admin->id]);

        $this->remind();
        $this->remind();

        $b = $this->bell($this->ben);
        $this->assertCount(1, $b);
        $this->assertSame('2 leads have had no activity for 7 days or more', $b[0]->title);
        $this->assertStringContainsString("Oldest: {$oldest->lead_no} (Ravi Patel), last touched 21 Oct", $b[0]->body);
        $this->assertStringNotContainsString('20 days', $b[0]->body, 'a date, not "N days", so it does not turn unread every morning');
        $this->assertSame('1 lead has had no activity for 7 days or more', $this->bell($this->admin)[0]->title);
    }

    public function test_quiet_leads_with_no_owner_are_reported_to_the_admins_only(): void
    {
        $this->lead(12, ['assigned_to_user_id' => null]);
        $admin2 = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);

        $this->remind();

        $this->assertSame('1 unassigned lead has had no activity for 7 days or more', $this->bell($this->admin)[0]->title);
        $this->assertCount(1, $this->bell($admin2));
        $this->assertCount(0, $this->bell($this->ben));
    }

    public function test_the_line_stays_read_when_nothing_changed_and_goes_when_the_leads_are_looked_after(): void
    {
        $l = $this->lead(12);
        $this->remind();
        $this->bell($this->ben)[0]->forceFill(['read_at' => now()])->save();

        Carbon::setTestNow(now()->addDay());
        $this->remind();
        $this->assertNotNull($this->bell($this->ben)[0]->read_at);

        $this->act($l, 'call', now(), $this->ben);
        $this->remind();
        $this->assertCount(0, $this->bell($this->ben));
    }

    public function test_zero_days_switches_the_alert_off_and_clears_what_was_there(): void
    {
        $this->lead(12);
        $this->remind();
        $this->assertCount(1, $this->bell($this->ben));

        $this->putJson('/api/settings/notifications', ['lead_stale_days' => 0])->assertOk();
        $this->remind();

        $this->assertCount(0, $this->bell($this->ben));
    }

    public function test_the_hourly_scan_runs_it(): void
    {
        $this->lead(12);

        $this->artisan('notifications:scan')->assertExitCode(0);

        $this->assertCount(1, $this->bell($this->ben));
    }

    public function test_another_companys_leads_are_never_in_my_alert_or_my_numbers(): void
    {
        $other = Company::factory()->create();
        Lead::create(['company_id' => $other->id, 'lead_no' => 'X-1', 'contact_name' => 'Elsewhere', 'source' => 'Phone', 'status' => 'new', 'created_at' => now()->subDays(30)]);

        $this->remind();

        $r = $this->getJson('/api/leads/dashboard')->json('data.response');
        $this->assertSame([0, 0], [$r['waiting'], $r['quiet']]);
        $this->assertSame(0, AppNotification::withoutGlobalScope('tenant')->where('type', LeadStaleService::TYPE)->count());
    }
}
