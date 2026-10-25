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
use App\Services\LeadReminderService;
use App\Services\LeadService;
use App\Services\LeadTaskReminderService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 5.2: next actions on a lead, "my day", reminders, and the follow-up date that follows them. */
class LeadTaskTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        // 10 Nov 2026, 14:30 in India (09:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'name' => 'Admin']);
        $this->ben = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'name' => 'Ben', 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.manage']])->id]);
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

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "LD-{$n}", 'contact_name' => "Buyer {$n}", 'source' => 'Phone', 'status' => 'new', 'assigned_to_user_id' => $this->admin->id]);
    }

    private function add(Lead $l, array $o = [])
    {
        return $this->postJson("/api/leads/{$l->id}/tasks", $o + ['type' => 'call', 'due_date' => '2026-11-12']);
    }

    private function bell(User $u, string $type)
    {
        return AppNotification::withoutGlobalScope('tenant')->where('user_id', $u->id)->where('type', $type)->get();
    }

    // ── planning an action ───────────────────────────────────────────────────

    public function test_an_action_is_planned_with_a_day_a_time_and_a_person_and_goes_on_the_timeline(): void
    {
        $l = $this->lead();

        $res = $this->add($l, ['type' => 'visit', 'due_date' => '2026-11-12', 'due_time' => '14:30', 'title' => 'Measure the cold room', 'assigned_to_user_id' => $this->ben->id])->assertStatus(201);

        $this->assertSame(['visit', 'Site visit', '2026-11-12', '14:30', 'Ben', 'open'], [$res->json('data.type'), $res->json('data.type_label'), $res->json('data.due_date'), $res->json('data.due_time'), $res->json('data.assignee'), $res->json('data.status')]);
        $line = LeadActivity::where('lead_id', $l->id)->latest('id')->value('description');
        $this->assertStringContainsString('Site visit on 12 Nov, 2:30 pm (Ben): Measure the cold room', $line);
    }

    public function test_with_nobody_chosen_it_goes_to_the_leads_owner_then_to_whoever_made_it(): void
    {
        $owned = $this->lead(['assigned_to_user_id' => $this->ben->id]);
        $orphan = $this->lead(['assigned_to_user_id' => null]);

        $this->assertSame($this->ben->id, $this->add($owned)->json('data.assigned_to_user_id'));
        $this->assertSame($this->admin->id, $this->add($orphan)->json('data.assigned_to_user_id'));
    }

    public function test_bad_input_is_refused_in_words(): void
    {
        $l = $this->lead();
        $inactive = User::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);
        $stranger = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->add($l, ['due_date' => '2026-11-09'])->assertStatus(422)->assertJsonValidationErrors('due_date');
        $this->add($l, ['due_date' => 'tomorrow'])->assertStatus(422);
        $this->add($l, ['due_time' => '25:00'])->assertStatus(422)->assertJsonValidationErrors('due_time');
        $this->add($l, ['type' => 'telepathy'])->assertStatus(422);
        $this->add($l, ['assigned_to_user_id' => $inactive->id])->assertStatus(422)->assertJsonValidationErrors('assigned_to_user_id');
        $this->add($l, ['assigned_to_user_id' => $stranger->id])->assertStatus(422);
        $this->assertSame(0, LeadTask::count());
    }

    public function test_today_is_indian_today_not_the_servers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 20:00:00', 'UTC'));        // already 11 Nov, 1:30 am, in India
        $l = $this->lead();

        $this->add($l, ['due_date' => '2026-11-10'])->assertStatus(422);
        $this->add($l, ['due_date' => '2026-11-11'])->assertStatus(201);
    }

    public function test_a_closed_lead_takes_no_new_action(): void
    {
        $l = $this->lead(['status' => 'won']);

        $this->add($l)->assertStatus(422)->assertJsonValidationErrors('lead');
    }

    public function test_the_person_it_is_handed_to_is_told_but_not_the_person_who_made_it(): void
    {
        $l = $this->lead();

        $this->add($l, ['assigned_to_user_id' => $this->ben->id])->assertStatus(201);
        $this->add($l, ['assigned_to_user_id' => $this->admin->id])->assertStatus(201);

        $this->assertCount(1, $this->bell($this->ben, 'lead_task_assigned'));
        $this->assertStringContainsString('New action for you: Call', $this->bell($this->ben, 'lead_task_assigned')[0]->title);
        $this->assertCount(0, $this->bell($this->admin, 'lead_task_assigned'));
    }

    // ── the lead's follow-up date follows its actions ────────────────────────

    public function test_the_follow_up_date_is_the_earliest_open_action(): void
    {
        $l = $this->lead(['next_follow_up_date' => '2026-12-01']);

        $this->add($l, ['due_date' => '2026-11-20']);
        $this->assertSame('2026-11-20', $l->fresh()->next_follow_up_date->toDateString());
        $a = $this->add($l, ['due_date' => '2026-11-15'])->json('data.id');
        $this->assertSame('2026-11-15', $l->fresh()->next_follow_up_date->toDateString());

        $this->putJson("/api/leads/tasks/{$a}", ['due_date' => '2026-11-25'])->assertOk();
        $this->assertSame('2026-11-20', $l->fresh()->next_follow_up_date->toDateString());
    }

    public function test_finishing_or_removing_the_last_action_clears_the_date_but_a_lead_that_never_had_one_is_left_alone(): void
    {
        $never = $this->lead(['next_follow_up_date' => '2026-11-30']);
        $l = $this->lead();
        $id = $this->add($l, ['due_date' => '2026-11-12'])->json('data.id');
        $id2 = $this->add($l, ['due_date' => '2026-11-14'])->json('data.id');

        $this->postJson("/api/leads/tasks/{$id}/complete")->assertOk();
        $this->assertSame('2026-11-14', $l->fresh()->next_follow_up_date->toDateString());
        $this->deleteJson("/api/leads/tasks/{$id2}")->assertNoContent();
        $this->assertNull($l->fresh()->next_follow_up_date);
        $this->assertSame('2026-11-30', $never->fresh()->next_follow_up_date->toDateString());
    }

    public function test_a_lead_on_hold_keeps_the_date_it_comes_back_on(): void
    {
        $l = $this->lead(['status' => 'on_hold', 'next_follow_up_date' => '2026-12-15']);

        $this->add($l, ['due_date' => '2026-11-12'])->assertStatus(201);

        $this->assertSame('2026-12-15', $l->fresh()->next_follow_up_date->toDateString());
    }

    // ── completing, and what is next ─────────────────────────────────────────

    public function test_completing_logs_the_outcome_and_plans_the_next_step_in_one_go(): void
    {
        $l = $this->lead();
        $id = $this->add($l, ['type' => 'call', 'due_date' => '2026-11-10'])->json('data.id');

        $res = $this->postJson("/api/leads/tasks/{$id}/complete", ['note' => 'Wants a quote by Friday', 'next' => ['type' => 'quote', 'due_date' => '2026-11-13', 'due_time' => '10:00']])->assertOk();

        $this->assertSame(['done', 'quote', '2026-11-13'], [$res->json('data.task.status'), $res->json('data.next.type'), $res->json('data.next.due_date')]);
        $this->assertSame('2026-11-13', $l->fresh()->next_follow_up_date->toDateString());
        $done = LeadActivity::where('lead_id', $l->id)->where('type', 'call')->value('description');
        $this->assertSame('Done: Call. Wants a quote by Friday', $done);
        $this->assertSame(1, LeadTask::where('status', 'open')->count());
    }

    public function test_a_failed_next_step_does_not_leave_the_first_one_half_done(): void
    {
        $l = $this->lead();
        $id = $this->add($l, ['due_date' => '2026-11-10'])->json('data.id');

        $this->postJson("/api/leads/tasks/{$id}/complete", ['next' => ['type' => 'call', 'due_date' => '2026-11-01']])->assertStatus(422);

        $this->assertSame('open', LeadTask::find($id)->status);
    }

    public function test_the_kind_of_action_decides_the_kind_of_timeline_entry(): void
    {
        $l = $this->lead();
        foreach (['whatsapp' => 'whatsapp', 'email' => 'email', 'visit' => 'meeting', 'quote' => 'note', 'other' => 'note'] as $type => $expect) {
            $id = $this->add($l, ['type' => $type])->json('data.id');
            $this->postJson("/api/leads/tasks/{$id}/complete")->assertOk();
            $this->assertSame($expect, LeadActivity::where('lead_id', $l->id)->where('description', 'like', 'Done: ' . LeadTask::TYPES[$type] . '%')->value('type'), $type);
        }
    }

    public function test_an_action_can_be_completed_only_once(): void
    {
        $id = $this->add($this->lead())->json('data.id');

        $this->postJson("/api/leads/tasks/{$id}/complete")->assertOk();
        $this->postJson("/api/leads/tasks/{$id}/complete")->assertStatus(422);
        $this->putJson("/api/leads/tasks/{$id}", ['title' => 'late edit'])->assertStatus(422);
    }

    public function test_an_overdue_action_can_be_retitled_without_being_moved(): void
    {
        $l = $this->lead();
        $id = $this->add($l, ['due_date' => '2026-11-10'])->json('data.id');
        Carbon::setTestNow(now()->addDays(3));

        $this->putJson("/api/leads/tasks/{$id}", ['title' => 'Try again', 'due_date' => '2026-11-10'])->assertOk()->assertJsonPath('data.title', 'Try again');
        $this->putJson("/api/leads/tasks/{$id}", ['due_date' => '2026-11-09'])->assertStatus(422);
    }

    public function test_handing_an_action_over_rings_the_new_person_and_is_logged(): void
    {
        $l = $this->lead();
        $id = $this->add($l)->json('data.id');

        $this->putJson("/api/leads/tasks/{$id}", ['assigned_to_user_id' => $this->ben->id])->assertOk();

        $this->assertCount(1, $this->bell($this->ben, 'lead_task_assigned'));
        $this->assertStringContainsString('Next action changed: Call', LeadActivity::where('lead_id', $l->id)->latest('id')->value('description'));
    }

    // ── closing the lead ─────────────────────────────────────────────────────

    public function test_closing_a_lead_cancels_its_open_actions_and_they_leave_my_day(): void
    {
        $l = $this->lead();
        $this->add($l, ['due_date' => '2026-11-10']);
        $this->add($l, ['due_date' => '2026-11-12']);

        app(LeadService::class)->transition($l->fresh(), 'lost', ['lost_reason' => 'Price too high'], $this->admin->id);

        $this->assertSame(['cancelled', 'cancelled'], LeadTask::pluck('status')->all());
        $this->assertSame([], $this->getJson('/api/leads/tasks')->json('data.today'));
    }

    // ── my day ───────────────────────────────────────────────────────────────

    public function test_my_day_has_overdue_today_and_the_coming_week_and_counts_the_rest(): void
    {
        $l = $this->lead();
        $this->add($l, ['due_date' => '2026-11-10'])->assertStatus(201);                                        // today
        $this->add($l, ['due_date' => '2026-11-10', 'due_time' => '09:00', 'type' => 'email']);                 // today, earlier
        $this->add($l, ['due_date' => '2026-11-14']);                                                           // upcoming
        $this->add($l, ['due_date' => '2026-12-20']);                                                           // later
        LeadTask::create(['company_id' => $this->company->id, 'lead_id' => $l->id, 'assigned_to_user_id' => $this->admin->id, 'type' => 'call', 'due_date' => '2026-11-07', 'status' => 'open']);   // overdue
        $this->add($l, ['assigned_to_user_id' => $this->ben->id, 'due_date' => '2026-11-10']);                  // someone else's

        $d = $this->getJson('/api/leads/tasks')->assertOk()->json('data');

        $this->assertSame(['2026-11-07'], array_column($d['overdue'], 'due_date'));
        $this->assertSame(['email', 'call'], array_column($d['today'], 'type'), 'by time, an action with no time after the timed ones');
        $this->assertSame(['2026-11-14'], array_column($d['upcoming'], 'due_date'));
        $this->assertSame(1, $d['later']);
        $this->assertSame($l->lead_no, $d['today'][0]['lead']['lead_no']);
        $this->assertArrayHasKey('call', $d['types']);
    }

    public function test_my_day_can_show_a_colleague_nobody_or_everyone(): void
    {
        $l = $this->lead();
        $this->add($l, ['assigned_to_user_id' => $this->ben->id, 'due_date' => '2026-11-10']);
        LeadTask::create(['company_id' => $this->company->id, 'lead_id' => $l->id, 'assigned_to_user_id' => null, 'type' => 'call', 'due_date' => '2026-11-10', 'status' => 'open']);
        $this->add($l, ['due_date' => '2026-11-10']);

        $count = fn (string $q) => count($this->getJson('/api/leads/tasks' . $q)->json('data.today'));

        $this->assertSame([1, 1, 1, 3], [$count(''), $count("?assigned_to={$this->ben->id}"), $count('?assigned_to=none'), $count('?assigned_to=all')]);
    }

    public function test_done_and_deleted_actions_and_deleted_leads_are_not_in_my_day(): void
    {
        $l = $this->lead();
        $done = $this->add($l, ['due_date' => '2026-11-10'])->json('data.id');
        $gone = $this->add($l, ['due_date' => '2026-11-10'])->json('data.id');
        $this->postJson("/api/leads/tasks/{$done}/complete");
        $this->deleteJson("/api/leads/tasks/{$gone}");
        $gone = $this->lead();
        $this->add($gone, ['due_date' => '2026-11-10']);
        $gone->delete();

        $this->assertSame([], $this->getJson('/api/leads/tasks')->json('data.today'));
    }

    public function test_a_lead_lists_its_open_actions_then_the_last_done(): void
    {
        $l = $this->lead();
        $a = $this->add($l, ['due_date' => '2026-11-15'])->json('data.id');
        $this->add($l, ['due_date' => '2026-11-12']);
        $this->postJson("/api/leads/tasks/{$a}/complete", ['note' => 'ok']);

        $d = $this->getJson("/api/leads/{$l->id}/tasks")->assertOk()->json('data');

        $this->assertSame(['2026-11-12'], array_column($d['open'], 'due_date'));
        $this->assertSame(['ok'], array_column($d['done'], 'done_note'));
    }

    // ── reminders ────────────────────────────────────────────────────────────

    private function remind(): void
    {
        app(LeadTaskReminderService::class)->sync($this->company->id);
    }

    public function test_the_assignee_gets_one_standing_bell_per_due_action_that_turns_red_when_overdue_and_clears_when_done(): void
    {
        $l = $this->lead(['contact_name' => 'Ravi Patel']);
        $id = $this->add($l, ['due_date' => '2026-11-10', 'due_time' => '16:00', 'assigned_to_user_id' => $this->ben->id])->json('data.id');
        $this->add($l, ['due_date' => '2026-11-20', 'assigned_to_user_id' => $this->ben->id]);                   // not due yet

        $this->remind();
        $this->remind();

        $bells = $this->bell($this->ben, 'lead_task');
        $this->assertCount(1, $bells);
        $this->assertSame(['warning', 'Due today at 4:00 pm: Call ' . $l->lead_no . ' Ravi Patel'], [$bells[0]->severity, $bells[0]->title]);
        $this->assertCount(0, $this->bell($this->admin, 'lead_task'));

        $bells[0]->forceFill(['read_at' => now()])->save();
        $this->remind();
        $this->assertNotNull($this->bell($this->ben, 'lead_task')[0]->read_at, 'unchanged: stays read');

        Carbon::setTestNow(now()->addDay());
        $this->remind();
        $b = $this->bell($this->ben, 'lead_task')[0];
        $this->assertSame(['danger', null], [$b->severity, $b->read_at], 'overdue: red and unread again');
        $this->assertStringStartsWith('Overdue: Call', $b->title);

        $this->postJson("/api/leads/tasks/{$id}/complete")->assertOk();
        $this->remind();
        $this->assertCount(0, $this->bell($this->ben, 'lead_task'));
    }

    public function test_the_follow_up_reminder_of_1_7_skips_a_lead_that_has_open_actions(): void
    {
        $withActions = $this->lead(['assigned_to_user_id' => $this->ben->id, 'next_follow_up_date' => '2026-11-10']);
        $plain = $this->lead(['assigned_to_user_id' => $this->ben->id, 'next_follow_up_date' => '2026-11-10']);
        $this->add($withActions, ['due_date' => '2026-11-10', 'assigned_to_user_id' => $this->ben->id]);

        app(LeadReminderService::class)->sync($this->company->id);
        $this->remind();

        $leadBells = $this->bell($this->ben, 'lead_follow_up');
        $this->assertCount(1, $leadBells);
        $this->assertSame($plain->id, $leadBells[0]->link_id);
        $this->assertSame($withActions->id, $this->bell($this->ben, 'lead_task')[0]->link_id);
    }

    public function test_the_notification_switch_turns_action_reminders_off_and_the_hourly_scan_runs_them(): void
    {
        $this->add($this->lead(), ['due_date' => '2026-11-10', 'assigned_to_user_id' => $this->ben->id]);

        $this->artisan('notifications:scan')->assertExitCode(0);
        $this->assertCount(1, $this->bell($this->ben, 'lead_task'));

        NotificationSetting::withoutGlobalScope('tenant')->updateOrCreate(['company_id' => $this->company->id], ['notify_lead_followups' => false]);
        $this->remind();
        $this->assertCount(0, $this->bell($this->ben, 'lead_task'));
    }

    public function test_an_action_of_a_lead_that_was_closed_without_the_service_is_not_reminded(): void
    {
        $l = $this->lead();
        $this->add($l, ['due_date' => '2026-11-10', 'assigned_to_user_id' => $this->ben->id]);
        $l->update(['status' => 'won']);                       // closed by some route that skipped the cancel

        $this->remind();

        $this->assertCount(0, $this->bell($this->ben, 'lead_task'));
    }

    // ── who may ──────────────────────────────────────────────────────────────

    public function test_another_company_never_sees_or_touches_an_action(): void
    {
        $l = $this->lead();
        $id = $this->add($l)->json('data.id');
        $other = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);
        $this->actingAs($other, 'sanctum');

        $this->getJson("/api/leads/{$l->id}/tasks")->assertStatus(404);
        $this->postJson("/api/leads/{$l->id}/tasks", ['type' => 'call', 'due_date' => '2026-11-12'])->assertStatus(404);
        $this->putJson("/api/leads/tasks/{$id}", ['title' => 'x'])->assertStatus(404);
        $this->postJson("/api/leads/tasks/{$id}/complete")->assertStatus(404);
        $this->deleteJson("/api/leads/tasks/{$id}")->assertStatus(404);
        $this->assertSame([], $this->getJson('/api/leads/tasks?assigned_to=all')->json('data.today'));
    }

    public function test_changing_needs_leads_manage_and_a_restricted_person_meets_only_their_leads_actions(): void
    {
        $mine = $this->lead(['assigned_to_user_id' => $this->ben->id]);
        $theirs = $this->lead(['assigned_to_user_id' => $this->admin->id]);
        $mineId = $this->add($mine, ['due_date' => '2026-11-10', 'assigned_to_user_id' => $this->ben->id])->json('data.id');
        $theirId = $this->add($theirs, ['due_date' => '2026-11-10', 'assigned_to_user_id' => $this->ben->id])->json('data.id');

        $viewer = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view']])->id]);
        $this->actingAs($viewer, 'sanctum');
        $this->getJson("/api/leads/{$mine->id}/tasks")->assertOk();
        $this->postJson("/api/leads/{$mine->id}/tasks", ['type' => 'call', 'due_date' => '2026-11-12'])->assertStatus(403);
        $this->postJson("/api/leads/tasks/{$mineId}/complete")->assertStatus(403);

        $restricted = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.manage', 'leads.own_only']])->id]);
        $mine->update(['assigned_to_user_id' => $restricted->id]);
        $this->actingAs($restricted, 'sanctum');
        $this->assertSame([$mineId], array_column($this->getJson('/api/leads/tasks?assigned_to=all')->json('data.today'), 'id'));
        $this->getJson("/api/leads/{$theirs->id}/tasks")->assertStatus(404);
        $this->postJson("/api/leads/tasks/{$theirId}/complete")->assertStatus(404);
        $this->putJson("/api/leads/tasks/{$theirId}", ['title' => 'x'])->assertStatus(404);
    }

    public function test_the_kinds_of_action_come_with_the_lead_options(): void
    {
        $this->getJson('/api/leads/options')->assertOk()->assertJsonPath('data.task_types.visit', 'Site visit');
    }
}
