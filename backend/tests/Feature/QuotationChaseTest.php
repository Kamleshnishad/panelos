<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadTask;
use App\Models\NotificationSetting;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\User;
use App\Services\LeadService;
use App\Services\QuotationChaseService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LEADS_PLAN 5.5: a sent quotation is chased with next actions at +3 and +7 days and before it expires. Chasing only READS quotations: these tests also send
 * a quotation through the real endpoints to prove it needs no hook in the quotation module.
 */
class QuotationChaseTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));                // 10 Nov, 14:30 in India
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

    private function sentQuotation(string $sentAt = '2026-11-10 08:00:00', int $validDays = 10, string $status = 'sent'): Quotation
    {
        static $n = 0;
        $n++;
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $sent = Carbon::parse($sentAt, 'UTC');

        return Quotation::create(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'quotation_no' => "SCP-2027-{$n}", 'status' => $status,
            'quoted_on' => $sent->toDateString(), 'valid_until' => $sent->copy()->addDays($validDays)->toDateString(), 'sent_at' => $sent]);
    }

    private function lead(Quotation $q, array $o = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "LD-{$n}", 'contact_name' => 'Ravi Patel', 'source' => 'Phone', 'status' => 'quoted', 'quotation_id' => $q->id, 'assigned_to_user_id' => $this->ben->id]);
    }

    private function sync(): void
    {
        app(QuotationChaseService::class)->sync($this->company->id);
    }

    private function chase(?Lead $l = null)
    {
        return LeadTask::where('company_id', $this->company->id)->whereNotNull('chase_step')->when($l, fn ($q) => $q->where('lead_id', $l->id))->orderBy('due_date')->get();
    }

    // ── planning ─────────────────────────────────────────────────────────────

    public function test_a_just_sent_quotation_gets_three_steps_for_the_leads_owner(): void
    {
        $q = $this->sentQuotation();
        $l = $this->lead($q);

        $this->sync();

        $t = $this->chase($l);
        $this->assertSame(['d3', 'd7', 'expiry'], $t->pluck('chase_step')->all());
        $this->assertSame(['2026-11-13', '2026-11-17', '2026-11-18'], $t->map(fn ($x) => $x->due_date->toDateString())->all());     // sent 10 Nov, valid until 20 Nov
        $this->assertSame(['call', 'whatsapp', 'call'], $t->pluck('type')->all());
        $this->assertSame([$this->ben->id], $t->pluck('assigned_to_user_id')->unique()->all());
        $this->assertSame([$q->id], $t->pluck('quotation_id')->unique()->all());
        $this->assertSame('Follow up on quotation ' . $q->quotation_no . ': sent 3 days ago, any questions?', $t[0]->title);
        $this->assertStringContainsString('valid until 20 Nov: ask for a decision', $t[2]->title);
        $this->assertSame('2026-11-13', $l->fresh()->next_follow_up_date->toDateString(), 'the lead date follows the earliest action');
    }

    public function test_running_again_plans_nothing_new_and_a_finished_or_removed_step_never_comes_back(): void
    {
        $l = $this->lead($this->sentQuotation());
        $this->sync();
        $this->sync();
        $this->assertCount(3, $this->chase($l));

        $d3 = $this->chase($l)[0];
        $this->postJson("/api/leads/tasks/{$d3->id}/complete", ['note' => 'Spoke to him'])->assertOk();
        $d7 = $this->chase($l)[1];
        $this->deleteJson("/api/leads/tasks/{$d7->id}")->assertNoContent();

        $this->sync();

        $this->assertSame(['d3', 'expiry'], LeadTask::where('lead_id', $l->id)->whereNull('deleted_at')->orderBy('due_date')->pluck('chase_step')->all());
        $this->assertSame(1, LeadTask::where('lead_id', $l->id)->where('status', 'open')->count());
    }

    public function test_steps_already_in_the_past_are_not_planned(): void
    {
        $l = $this->lead($this->sentQuotation('2026-11-05 08:00:00'));                        // sent 5 days ago: +3 was 8 Nov

        $this->sync();

        $this->assertSame(['d7', 'expiry'], $this->chase($l)->pluck('chase_step')->all());
    }

    public function test_an_old_quotation_that_has_expired_gets_nothing(): void
    {
        $l = $this->lead($this->sentQuotation('2026-10-10 08:00:00', 10));                    // valid until 20 Oct

        $this->sync();

        $this->assertCount(0, $this->chase($l));
    }

    public function test_a_short_validity_skips_steps_after_expiry_and_a_crowded_expiry_step(): void
    {
        $short = $this->lead($this->sentQuotation('2026-11-10 08:00:00', 5));                  // valid until 15 Nov: +3 only; expiry (13 Nov) is the same day as +3
        $mid = $this->lead($this->sentQuotation('2026-11-10 08:00:00', 8));                    // valid until 18 Nov: +3, +7; expiry (16 Nov) is before +7

        $this->sync();

        $this->assertSame(['d3'], $this->chase($short)->pluck('chase_step')->all());
        $this->assertSame(['d3', 'd7'], $this->chase($mid)->pluck('chase_step')->all());
    }

    public function test_only_sent_quotations_with_an_open_lead_are_chased(): void
    {
        $draft = $this->sentQuotation(status: 'draft');
        $boq = $this->sentQuotation(status: 'boq');
        $this->lead($draft);
        $this->lead($boq);
        $this->sentQuotation();                                                                  // sent, but no lead
        $won = $this->lead($this->sentQuotation(), ['status' => 'won']);

        $this->sync();

        $this->assertCount(0, $this->chase());
        $this->assertCount(0, $this->chase($won));
    }

    public function test_an_unowned_lead_falls_back_to_a_company_admin_and_nobody_is_rung_when_steps_are_planned(): void
    {
        $l = $this->lead($this->sentQuotation(), ['assigned_to_user_id' => null]);

        $this->sync();

        $this->assertSame([$this->admin->id], $this->chase($l)->pluck('assigned_to_user_id')->unique()->all());
        $this->assertSame(0, AppNotification::withoutGlobalScope('tenant')->where('type', 'lead_task_assigned')->count(), 'three bells at once would be noise');
    }

    public function test_a_revised_quotation_is_chased_for_the_same_lead(): void
    {
        $orig = $this->sentQuotation('2026-11-02 08:00:00', 30);
        $l = $this->lead($orig);
        $rev = $this->sentQuotation('2026-11-10 08:00:00', 10);
        $rev->update(['parent_quotation_id' => $orig->id]);
        $orig->update(['status' => 'revised']);

        $this->sync();

        $this->assertSame([$l->id], $this->chase()->pluck('lead_id')->unique()->all());
        $this->assertSame([$rev->id], $this->chase()->pluck('quotation_id')->unique()->all());
    }

    // ── cancelling ───────────────────────────────────────────────────────────

    public function test_when_the_quotation_is_answered_its_open_chase_actions_are_cancelled_and_the_date_clears(): void
    {
        $q = $this->sentQuotation();
        $l = $this->lead($q);
        $this->sync();
        $own = LeadTask::create(['company_id' => $this->company->id, 'lead_id' => $l->id, 'assigned_to_user_id' => $this->ben->id, 'type' => 'visit', 'due_date' => '2026-11-12', 'status' => 'open']);

        $q->update(['status' => 'rejected']);
        $this->sync();

        $this->assertSame(['cancelled', 'cancelled', 'cancelled'], $this->chase($l)->pluck('status')->all());
        $this->assertSame('open', $own->fresh()->status, 'an action somebody planned by hand is never touched');
        $this->assertSame('2026-11-12', $l->fresh()->next_follow_up_date->toDateString());
        $this->assertStringContainsString('Quotation follow-ups cancelled: the quotation is now rejected', LeadActivity::where('lead_id', $l->id)->latest('id')->value('description'));
        $this->sync();
        $this->assertSame(1, LeadActivity::where('lead_id', $l->id)->where('description', 'like', 'Quotation follow-ups cancelled%')->count(), 'said once');
    }

    public function test_with_nothing_else_planned_cancelling_clears_the_leads_follow_up_date(): void
    {
        $q = $this->sentQuotation();
        $l = $this->lead($q);
        $this->sync();
        $this->assertNotNull($l->fresh()->next_follow_up_date);

        $q->update(['status' => 'accepted']);
        $this->sync();

        $this->assertNull($l->fresh()->next_follow_up_date);
    }

    public function test_closing_the_lead_cancels_the_chase_through_the_existing_rule(): void
    {
        $l = $this->lead($this->sentQuotation());
        $this->sync();

        app(LeadService::class)->transition($l->fresh(), 'lost', ['lost_reason' => 'Price too high'], $this->admin->id);

        $this->assertSame(['cancelled', 'cancelled', 'cancelled'], $this->chase($l)->pluck('status')->all());
        $this->sync();
        $this->assertSame(3, $this->chase($l)->count(), 'and nothing new is planned for a closed lead');
    }

    // ── switch, reminders, scan ──────────────────────────────────────────────

    public function test_the_company_can_switch_chasing_off(): void
    {
        $l = $this->lead($this->sentQuotation());
        $this->getJson('/api/settings/notifications')->assertOk()->assertJsonPath('data.chase_quotations', true);        // a fresh company: on
        $this->putJson('/api/settings/notifications', ['chase_quotations' => false])->assertOk();
        $this->getJson('/api/settings/notifications')->assertOk()->assertJsonPath('data.chase_quotations', false);

        $this->sync();
        $this->assertCount(0, $this->chase($l));

        $this->putJson('/api/settings/notifications', ['chase_quotations' => true])->assertOk();
        $this->sync();
        $this->assertCount(3, $this->chase($l));
    }

    public function test_a_company_that_never_touched_the_setting_is_chased(): void
    {
        $this->assertNull(NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $this->company->id)->first());
        $l = $this->lead($this->sentQuotation());

        $this->sync();

        $this->assertCount(3, $this->chase($l));
    }

    public function test_the_hourly_scan_plans_the_steps_and_rings_the_person_on_the_day(): void
    {
        $l = $this->lead($this->sentQuotation('2026-11-07 08:00:00'));                         // +3 is today, 10 Nov... sent 7 Nov, valid until 17 Nov

        $this->artisan('notifications:scan')->assertExitCode(0);

        $this->assertSame(['d3', 'd7', 'expiry'], $this->chase($l)->pluck('chase_step')->all());
        $bell = AppNotification::withoutGlobalScope('tenant')->where('user_id', $this->ben->id)->where('type', 'lead_task')->get();
        $this->assertCount(1, $bell);
        $this->assertStringStartsWith('Due today: Call ' . $l->lead_no, $bell[0]->title);
    }

    public function test_the_actions_say_they_were_made_automatically(): void
    {
        $l = $this->lead($this->sentQuotation());
        $this->sync();
        $own = $this->postJson("/api/leads/{$l->id}/tasks", ['type' => 'call', 'due_date' => '2026-11-11'])->assertStatus(201)->json('data.id');

        $rows = collect($this->getJson("/api/leads/{$l->id}/tasks")->json('data.open'));

        $this->assertSame([false, true, true, true], $rows->pluck('auto')->all());
        $this->assertSame($own, $rows[0]['id']);
    }

    // ── the quotation module is not involved ─────────────────────────────────

    public function test_a_quotation_sent_through_the_real_endpoints_is_chased_with_no_hook_in_the_quotation_code(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $pt = PanelType::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
        $res = $this->postJson('/api/quotations', ['customer_id' => $customer->id, 'panel_rows' => [[
            'panel_type_id' => $pt->id, 'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40, 'top_skin_material' => 'PPGI', 'top_skin_thickness' => 0.4, 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => 0.4, 'sizes' => [['length_mm' => 3000, 'nos' => 10, 'rate_per_sqm' => 1200]],
        ]]])->assertStatus(201);
        $id = $res->json('data.id');
        $lead = Lead::create(['company_id' => $this->company->id, 'lead_no' => 'LD-E2E', 'contact_name' => 'Ravi', 'source' => 'Phone', 'status' => 'quoted', 'quotation_id' => $id, 'assigned_to_user_id' => $this->ben->id]);

        $this->sync();
        $this->assertCount(0, $this->chase($lead), 'a draft is not chased');

        $this->postJson("/api/quotations/{$id}/send")->assertOk();
        $this->sync();
        $this->assertSame(['d3', 'd7', 'expiry'], $this->chase($lead)->pluck('chase_step')->all());

        $this->postJson("/api/quotations/{$id}/accept")->assertOk();
        $this->sync();
        $this->assertSame(['cancelled', 'cancelled', 'cancelled'], $this->chase($lead)->pluck('status')->all());
    }

    public function test_the_chase_code_never_writes_to_a_quotation(): void
    {
        $q = $this->sentQuotation();
        $this->lead($q);
        $before = Quotation::withoutGlobalScope('tenant')->whereKey($q->id)->first()->getAttributes();

        $this->sync();
        $q->update(['status' => 'rejected']);
        $before['status'] = 'rejected';
        $this->sync();

        $after = Quotation::withoutGlobalScope('tenant')->whereKey($q->id)->first()->getAttributes();
        unset($before['updated_at'], $after['updated_at']);
        $this->assertEquals($before, $after);
    }
}
