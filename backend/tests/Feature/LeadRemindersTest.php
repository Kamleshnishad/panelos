<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Lead;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 1.7: a follow-up date that actually tells someone. */
class LeadRemindersTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private User $sam;
    private User $ria;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);
        $this->sam = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false]);
        $this->ria = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(?string $due, ?User $to = null, array $over = []): Lead
    {
        return app(LeadService::class)->create($this->company->id, $over + [
            'contact_name' => 'Buyer ' . (Lead::withoutGlobalScope('tenant')->count() + 1), 'source' => 'Phone',
            'next_follow_up_date' => $due, 'assigned_to_user_id' => $to?->id,
        ]);
    }

    private function scan(): void
    {
        $this->artisan('notifications:scan')->assertSuccessful();
    }

    private function bell(User $u, string $type = 'lead_follow_up')
    {
        return AppNotification::withoutGlobalScope('tenant')->where('user_id', $u->id)->where('type', $type)->get();
    }

    // ── the assignee's reminder ──────────────────────────────────────────────

    public function test_a_follow_up_due_today_reaches_the_assignee_and_only_the_assignee(): void
    {
        $l = $this->lead('2026-11-10', $this->sam);

        $this->scan();

        $n = $this->bell($this->sam);
        $this->assertCount(1, $n);
        $this->assertSame("Follow up today: {$l->lead_no}", $n[0]->title);
        $this->assertSame('warning', $n[0]->severity);
        $this->assertSame('leads', $n[0]->link_section);
        $this->assertSame($l->id, (int) $n[0]->link_id);
        $this->assertCount(0, $this->bell($this->ria));
        $this->assertCount(0, $this->bell($this->admin), 'The owner is told about late leads only (the summary).');
    }

    public function test_scanning_again_the_same_day_keeps_one_entry_and_leaves_a_read_one_read(): void
    {
        $this->lead('2026-11-10', $this->sam);
        $this->scan();
        $this->bell($this->sam)[0]->update(['read_at' => now()]);

        $this->scan();
        $this->scan();

        $n = $this->bell($this->sam);
        $this->assertCount(1, $n);
        $this->assertNotNull($n[0]->read_at, 'An unchanged reminder stays as the user left it.');
    }

    public function test_the_next_day_it_becomes_overdue_red_and_unread_again_in_the_same_entry(): void
    {
        $l = $this->lead('2026-11-10', $this->sam);
        $this->scan();
        $this->bell($this->sam)[0]->update(['read_at' => now()]);

        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->scan();

        $n = $this->bell($this->sam);
        $this->assertCount(1, $n);
        $this->assertSame("Overdue follow-up: {$l->lead_no}", $n[0]->title);
        $this->assertSame('danger', $n[0]->severity);
        $this->assertNull($n[0]->read_at);
        $this->assertStringContainsString('10 Nov', $n[0]->body);
    }

    public function test_it_does_not_turn_unread_every_morning_while_overdue(): void
    {
        $this->lead('2026-11-05', $this->sam);
        $this->scan();
        $this->bell($this->sam)[0]->update(['read_at' => now()]);

        Carbon::setTestNow('2026-11-12 09:00:00');
        $this->scan();

        $this->assertNotNull($this->bell($this->sam)[0]->read_at);
    }

    public function test_future_and_missing_dates_are_not_reminded(): void
    {
        $this->lead('2026-11-11', $this->sam);
        $this->lead(null, $this->sam);

        $this->scan();

        $this->assertCount(0, $this->bell($this->sam));
    }

    public function test_won_lost_and_invalid_leads_are_never_reminded(): void
    {
        $svc = app(LeadService::class);
        $won = $this->lead('2026-11-01', $this->sam);
        $lost = $this->lead('2026-11-01', $this->sam);
        $junk = $this->lead('2026-11-01', $this->sam);
        $svc->transition($won, 'won');
        $svc->transition($lost, 'lost', ['lost_reason' => 'Price']);
        $svc->transition($junk, 'invalid');

        $this->scan();

        $this->assertCount(0, $this->bell($this->sam));
        $this->assertCount(0, $this->bell($this->admin, 'lead_follow_up_digest'));
    }

    public function test_a_lead_on_hold_is_reminded_on_its_return_date(): void
    {
        $l = $this->lead(null, $this->sam);
        app(LeadService::class)->transition($l, 'on_hold', ['hold_until' => '2026-11-12']);
        $this->scan();
        $this->assertCount(0, $this->bell($this->sam), 'Not before the return date.');

        Carbon::setTestNow('2026-11-12 09:00:00');
        $this->scan();

        $this->assertCount(1, $this->bell($this->sam));
    }

    public function test_the_reminder_goes_away_by_itself_once_the_lead_is_followed_up_or_closed(): void
    {
        $a = $this->lead('2026-11-10', $this->sam);
        $b = $this->lead('2026-11-10', $this->sam);
        $this->scan();
        $this->assertCount(2, $this->bell($this->sam));

        $a->update(['next_follow_up_date' => '2026-11-20']);          // followed up, next date set
        app(LeadService::class)->transition($b, 'won');                // or closed
        $this->scan();

        $this->assertCount(0, $this->bell($this->sam));
    }

    public function test_reassigning_moves_the_reminder_to_the_new_assignee(): void
    {
        $l = $this->lead('2026-11-10', $this->sam);
        $this->scan();

        $l->update(['assigned_to_user_id' => $this->ria->id]);
        $this->scan();

        $this->assertCount(0, $this->bell($this->sam));
        $this->assertCount(1, $this->bell($this->ria));
    }

    public function test_an_inactive_assignee_is_not_notified(): void
    {
        $this->sam->update(['is_active' => false]);
        $this->lead('2026-11-10', $this->sam);

        $this->scan();

        $this->assertCount(0, $this->bell($this->sam));
    }

    // ── the owner's summary ──────────────────────────────────────────────────

    public function test_the_owner_hears_about_leads_overdue_more_than_three_days_unassigned_ones_included(): void
    {
        $this->lead('2026-11-06', $this->sam);          // 4 days late
        $this->lead('2026-11-01', null);                 // 9 days late, nobody's
        $this->lead('2026-11-08', $this->sam);          // 2 days late: not yet

        $this->scan();

        $d = $this->bell($this->admin, 'lead_follow_up_digest');
        $this->assertCount(1, $d);
        $this->assertSame('2 leads overdue for more than 3 days', $d[0]->title);
        $this->assertSame('danger', $d[0]->severity);
        $this->assertStringContainsString('1 Nov', $d[0]->body, 'It names the oldest.');
        $this->assertCount(0, $this->bell($this->sam, 'lead_follow_up_digest'), 'Only company admins get the summary.');
    }

    public function test_the_number_of_days_is_the_companys_setting(): void
    {
        $this->lead('2026-11-08', $this->sam);          // 2 days late
        $this->scan();
        $this->assertCount(0, $this->bell($this->admin, 'lead_follow_up_digest'));

        NotificationSetting::forCompany($this->company->id)->update(['lead_overdue_alert_days' => 1]);
        $this->scan();

        $this->assertCount(1, $this->bell($this->admin, 'lead_follow_up_digest'));
    }

    public function test_the_summary_clears_when_nothing_is_that_late_any_more(): void
    {
        $l = $this->lead('2026-11-01', $this->sam);
        $this->scan();
        $this->assertCount(1, $this->bell($this->admin, 'lead_follow_up_digest'));

        $l->update(['next_follow_up_date' => '2026-11-15']);
        $this->scan();

        $this->assertCount(0, $this->bell($this->admin, 'lead_follow_up_digest'));
    }

    // ── the switch and the other company ─────────────────────────────────────

    public function test_switching_it_off_stops_the_reminders_and_clears_the_ones_showing(): void
    {
        $this->lead('2026-11-01', $this->sam);
        $this->scan();
        $this->assertCount(1, $this->bell($this->sam));

        NotificationSetting::forCompany($this->company->id)->update(['notify_lead_followups' => false]);
        $this->scan();

        $this->assertCount(0, $this->bell($this->sam));
        $this->assertCount(0, $this->bell($this->admin, 'lead_follow_up_digest'));
    }

    public function test_it_is_on_by_default_for_a_company_with_no_settings_row(): void
    {
        $this->assertNull(NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $this->company->id)->first());
        $this->lead('2026-11-10', $this->sam);

        $this->scan();

        $this->assertCount(1, $this->bell($this->sam));
    }

    public function test_one_companys_scan_never_touches_anothers_reminders(): void
    {
        $other = Company::factory()->create();
        $theirUser = User::factory()->create(['company_id' => $other->id]);
        app(LeadService::class)->create($other->id, ['contact_name' => 'Theirs', 'source' => 'Phone', 'next_follow_up_date' => '2026-11-10', 'assigned_to_user_id' => $theirUser->id]);
        $this->scan();
        $this->assertCount(1, $this->bell($theirUser));

        $this->lead('2026-11-10', $this->sam);
        $this->scan();

        $this->assertCount(1, $this->bell($theirUser));
        $this->assertCount(1, $this->bell($this->sam));
    }

    public function test_the_settings_endpoint_saves_the_switch_and_the_days(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/settings/notifications')->assertOk()->assertJsonPath('data.notify_lead_followups', true)->assertJsonPath('data.lead_overdue_alert_days', 3);
        $this->putJson('/api/settings/notifications', ['notify_lead_followups' => false, 'lead_overdue_alert_days' => 5])->assertOk();
        $this->putJson('/api/settings/notifications', ['lead_overdue_alert_days' => 0])->assertStatus(422);

        $s = NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $this->company->id)->first();
        $this->assertFalse($s->notify_lead_followups);
        $this->assertSame(5, $s->lead_overdue_alert_days);
    }
}
