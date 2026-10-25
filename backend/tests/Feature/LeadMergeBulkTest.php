<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAttachment;
use App\Models\LeadImportLog;
use App\Models\LeadPayload;
use App\Models\LeadRequirementLine;
use App\Models\LeadTask;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadIntakeResult;
use App\Services\LeadIntakeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** LEADS_PLAN 5.8 (first part): merging two leads for the same buyer, and select-many actions on the list. */
class LeadMergeBulkTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'name' => 'Asha']);
        $this->ben = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'name' => 'Ben']);
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

    private function mergeInto(Lead $keep, Lead $dup)
    {
        return $this->postJson("/api/leads/{$keep->id}/merge", ['from_lead_id' => $dup->id]);
    }

    private function note(Lead $l): string
    {
        return (string) LeadActivity::where('lead_id', $l->id)->latest('id')->value('description');
    }

    // ── merge: what moves ────────────────────────────────────────────────────

    public function test_the_other_leads_history_moves_onto_the_kept_lead_and_the_other_is_gone(): void
    {
        $keep = $this->lead(['phone' => '9811111111']);
        $dup = $this->lead(['phone' => '9811111111', 'source' => 'Website', 'external_id' => 'web-9']);
        LeadActivity::create(['company_id' => $this->company->id, 'lead_id' => $dup->id, 'user_id' => $this->ben->id, 'type' => 'call', 'description' => 'Call: spoke', 'activity_date' => now()]);
        LeadActivity::create(['company_id' => $this->company->id, 'lead_id' => $dup->id, 'user_id' => null, 'type' => 'note', 'description' => 'Received from Website', 'activity_date' => now()]);
        LeadTask::create(['company_id' => $this->company->id, 'lead_id' => $dup->id, 'assigned_to_user_id' => $this->ben->id, 'type' => 'call', 'due_date' => '2026-11-12', 'status' => 'open']);
        $file = LeadAttachment::create(['company_id' => $this->company->id, 'lead_id' => $dup->id, 'kind' => 'drawing', 'original_name' => 'plan.pdf', 'path' => "lead-attachments/{$this->company->id}/{$dup->id}/x.pdf", 'extension' => 'pdf', 'size_bytes' => 10]);
        Storage::disk('local')->put($file->path, '%PDF-1.4');
        LeadPayload::create(['company_id' => $this->company->id, 'lead_id' => $dup->id, 'source' => 'Website', 'external_id' => 'web-9', 'payload' => ['name' => 'x'], 'received_at' => now()]);
        LeadImportLog::create(['company_id' => $this->company->id, 'source' => 'Website', 'outcome' => 'created', 'lead_id' => $dup->id, 'created_at' => now()]);

        $res = $this->mergeInto($keep, $dup)->assertOk();

        $this->assertSame([2, 1, 1, 1], [$res->json('data.activities'), $res->json('data.tasks'), $res->json('data.files'), $res->json('data.messages')]);
        $this->assertSame(2, LeadActivity::where('lead_id', $keep->id)->whereIn('type', ['call'])->orWhere(fn ($q) => $q->where('lead_id', $keep->id)->where('description', 'Received from Website'))->count());
        $this->assertSame($keep->id, LeadTask::first()->lead_id);
        $this->assertSame($keep->id, $file->fresh()->lead_id);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame($keep->id, LeadPayload::withoutGlobalScope('tenant')->first()->lead_id);
        $this->assertSame($keep->id, LeadImportLog::withoutGlobalScope('tenant')->first()->lead_id);
        $this->assertStringContainsString("Merged {$dup->lead_no} ({$dup->contact_name}) into this lead by Asha. Moved 2 timeline entries, 1 action, 1 file, 1 original message. It had come in from Website (ref web-9)", $this->note($keep));

        $this->getJson("/api/leads/{$dup->id}")->assertStatus(404);
        $gone = Lead::withTrashed()->find($dup->id);
        $this->assertSame([$keep->id, true], [$gone->merged_into_lead_id, $gone->trashed()]);
        $this->assertCount(1, $this->getJson('/api/leads')->json('data'));
    }

    public function test_the_same_enquiry_cannot_come_in_again_after_its_lead_was_merged_away(): void
    {
        $keep = $this->lead(['phone' => '9822222222']);
        $dup = $this->lead(['phone' => '9822222222', 'source' => 'Website', 'external_id' => 'web-77']);
        $this->mergeInto($keep, $dup)->assertOk();

        $r = app(LeadIntakeService::class)->ingest($this->company->id, 'Website', ['contact_name' => 'Again', 'phone' => '9822222222', 'external_id' => 'web-77']);

        $this->assertSame(LeadIntakeResult::ALREADY_RECEIVED, $r->outcome);
    }

    public function test_blanks_are_filled_but_nothing_the_kept_lead_already_has_is_overwritten(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $keep = $this->lead(['phone' => '9833333333', 'city' => 'Rajkot', 'company_name' => null, 'email' => null, 'assigned_to_user_id' => null]);
        $dup = $this->lead(['phone' => '9833333333', 'city' => 'Surat', 'company_name' => 'Zephyr', 'email' => 'r@z.test', 'assigned_to_user_id' => $this->ben->id, 'customer_id' => $customer->id, 'est_qty_sqm' => 120]);

        $this->mergeInto($keep, $dup)->assertOk();

        $k = $keep->fresh();
        $this->assertSame(['Rajkot', 'Zephyr', 'r@z.test', $this->ben->id, $customer->id], [$k->city, $k->company_name, $k->email, $k->assigned_to_user_id, $k->customer_id]);
        $this->assertEquals(120, $k->est_qty_sqm);
        $this->assertSame('z.test', explode('@', (string) $k->email_key)[1], 'the match keys follow the new e-mail');
    }

    public function test_requirement_texts_are_joined_the_earlier_arrival_wins_and_the_more_advanced_stage_is_kept(): void
    {
        $keep = $this->lead(['requirement' => 'Need roof panels', 'first_seen_at' => '2026-11-05 10:00:00', 'status' => 'new']);
        $dup = $this->lead(['requirement' => 'Also wall panels', 'first_seen_at' => '2026-11-02 10:00:00', 'status' => 'qualified']);

        $this->mergeInto($keep, $dup)->assertOk();

        $k = $keep->fresh();
        $this->assertSame("Need roof panels\n\n(From {$dup->lead_no}) Also wall panels", $k->requirement);
        $this->assertSame('2026-11-02', $k->first_seen_at->toDateString());
        $this->assertSame('qualified', $k->status);
        $same = $this->lead(['requirement' => 'Same text']);
        $same2 = $this->lead(['requirement' => 'Same text']);
        $this->mergeInto($same, $same2)->assertOk();
        $this->assertSame('Same text', $same->fresh()->requirement, 'identical text is not repeated');
    }

    public function test_the_follow_up_date_follows_the_open_actions_of_both_leads(): void
    {
        $keep = $this->lead(['next_follow_up_date' => '2026-11-20']);
        $dup = $this->lead(['next_follow_up_date' => '2026-11-14']);
        LeadTask::create(['company_id' => $this->company->id, 'lead_id' => $keep->id, 'assigned_to_user_id' => $this->ben->id, 'type' => 'call', 'due_date' => '2026-11-20', 'status' => 'open']);
        LeadTask::create(['company_id' => $this->company->id, 'lead_id' => $dup->id, 'assigned_to_user_id' => $this->ben->id, 'type' => 'visit', 'due_date' => '2026-11-14', 'status' => 'open']);

        $this->mergeInto($keep, $dup)->assertOk();

        $this->assertSame('2026-11-14', $keep->fresh()->next_follow_up_date->toDateString());
        $this->assertSame(2, LeadTask::where('lead_id', $keep->id)->count());
    }

    public function test_requirement_lines_move_up_to_the_most_a_lead_may_hold(): void
    {
        $pt = PanelType::factory()->create(['company_id' => $this->company->id]);
        $keep = $this->lead();
        $dup = $this->lead();
        foreach ([[$keep, 28], [$dup, 5]] as [$l, $n]) {
            for ($i = 0; $i < $n; $i++) {
                LeadRequirementLine::create(['company_id' => $this->company->id, 'lead_id' => $l->id, 'kind' => 'panel', 'panel_type_id' => $pt->id, 'thickness' => 50, 'area_sqm' => 10, 'sort_order' => $i]);
            }
        }

        $res = $this->mergeInto($keep, $dup)->assertOk();

        $this->assertSame(2, $res->json('data.lines'));
        $this->assertSame(30, LeadRequirementLine::where('lead_id', $keep->id)->count());
    }

    // ── merge: when it is refused ────────────────────────────────────────────

    public function test_closed_leads_a_lead_with_itself_and_conflicting_links_are_refused_in_words(): void
    {
        $open = $this->lead();
        $won = $this->lead(['status' => 'won']);
        $c1 = Customer::factory()->create(['company_id' => $this->company->id]);
        $c2 = Customer::factory()->create(['company_id' => $this->company->id]);
        $q1 = Quotation::create(['company_id' => $this->company->id, 'customer_id' => $c1->id, 'quotation_no' => 'Q-1', 'status' => 'sent']);
        $q2 = Quotation::create(['company_id' => $this->company->id, 'customer_id' => $c1->id, 'quotation_no' => 'Q-2', 'status' => 'sent']);
        $quoted1 = $this->lead(['quotation_id' => $q1->id, 'customer_id' => $c1->id, 'status' => 'quoted']);
        $quoted2 = $this->lead(['quotation_id' => $q2->id, 'customer_id' => $c1->id, 'status' => 'quoted']);
        $cust1 = $this->lead(['customer_id' => $c1->id]);
        $cust2 = $this->lead(['customer_id' => $c2->id]);

        $this->assertStringContainsString('is won. Reopen it first', $this->mergeInto($open, $won)->assertStatus(422)->json('message'));
        $this->assertStringContainsString('is won', $this->mergeInto($won, $open)->assertStatus(422)->json('message'));
        $this->assertStringContainsString('into itself', $this->mergeInto($open, $open)->assertStatus(422)->json('message'));
        $this->assertStringContainsString('Both leads already have a quotation', $this->mergeInto($quoted1, $quoted2)->assertStatus(422)->json('message'));
        $this->assertStringContainsString('different customers', $this->mergeInto($cust1, $cust2)->assertStatus(422)->json('message'));
        $this->assertSame(0, Lead::onlyTrashed()->count());
    }

    public function test_one_quotation_between_the_two_leads_is_fine_and_moves_across(): void
    {
        $c = Customer::factory()->create(['company_id' => $this->company->id]);
        $q = Quotation::create(['company_id' => $this->company->id, 'customer_id' => $c->id, 'quotation_no' => 'Q-9', 'status' => 'sent']);
        $keep = $this->lead();
        $dup = $this->lead(['quotation_id' => $q->id, 'customer_id' => $c->id, 'status' => 'quoted']);

        $this->mergeInto($keep, $dup)->assertOk();

        $k = $keep->fresh();
        $this->assertSame([$q->id, $c->id, 'quoted'], [$k->quotation_id, $k->customer_id, $k->status]);
    }

    public function test_another_company_and_someone_elses_lead_are_not_found(): void
    {
        $mine = $this->lead(['assigned_to_user_id' => $this->admin->id]);
        $theirs = $this->lead(['assigned_to_user_id' => $this->ben->id]);
        $foreign = Lead::create(['company_id' => Company::factory()->create()->id, 'lead_no' => 'X-1', 'contact_name' => 'Elsewhere', 'source' => 'Phone', 'status' => 'new']);

        $this->mergeInto($mine, $foreign)->assertStatus(404);
        $this->getJson("/api/leads/{$foreign->id}/merge-candidates")->assertStatus(404);

        $restricted = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.manage', 'leads.own_only']])->id]);
        $mine->update(['assigned_to_user_id' => $restricted->id]);
        $this->actingAs($restricted, 'sanctum');
        $this->mergeInto($mine, $theirs)->assertStatus(404);
        $this->assertSame(0, Lead::onlyTrashed()->count());
    }

    public function test_merging_needs_leads_manage(): void
    {
        $a = $this->lead();
        $b = $this->lead();
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view']])->id]), 'sanctum');

        $this->getJson("/api/leads/{$a->id}/merge-candidates")->assertOk();
        $this->mergeInto($a, $b)->assertStatus(403);
    }

    // ── merge: finding the other lead ────────────────────────────────────────

    public function test_the_same_buyers_other_leads_are_offered_first_with_why_and_what_would_stop_them(): void
    {
        $keep = $this->lead(['phone' => '9844444444', 'email' => 'r@z.test']);
        $byPhone = $this->lead(['phone' => '+91 98444 44444']);
        $byMail = $this->lead(['phone' => null, 'email' => 'R@Z.test']);
        $closed = $this->lead(['phone' => '9844444444', 'status' => 'lost']);
        $other = $this->lead(['phone' => '9000000000', 'contact_name' => 'Somebody Else']);

        $c = collect($this->getJson("/api/leads/{$keep->id}/merge-candidates")->assertOk()->json('data.candidates'));

        $this->assertEqualsCanonicalizing([$byPhone->id, $byMail->id], $c->pluck('id')->all());
        $this->assertSame('same phone', $c->firstWhere('id', $byPhone->id)['why']);
        $this->assertNull($c->firstWhere('id', $byPhone->id)['blocked']);
        $this->assertNotContains($closed->id, $c->pluck('id')->all());
        $this->assertNotContains($keep->id, $c->pluck('id')->all());
        $this->assertNotContains($other->id, $c->pluck('id')->all());
    }

    public function test_a_search_finds_other_open_leads_by_number_or_name(): void
    {
        $keep = $this->lead(['phone' => '9855555555']);
        $target = $this->lead(['contact_name' => 'Ravi Patel', 'lead_no' => 'LEAD-2026-0042']);
        $this->lead(['contact_name' => 'Closed Ravi', 'status' => 'won']);

        foreach (['0042', 'ravi pat'] as $q) {
            $ids = collect($this->getJson("/api/leads/{$keep->id}/merge-candidates?q=" . urlencode($q))->json('data.candidates'))->pluck('id')->all();
            $this->assertSame([$target->id], $ids, $q);
        }
        $this->assertSame('found by your search', $this->getJson("/api/leads/{$keep->id}/merge-candidates?q=0042")->json('data.candidates.0.why'));
    }

    // ── bulk: assign ─────────────────────────────────────────────────────────

    private function bulk(array $ids, string $action, array $p = [])
    {
        return $this->postJson('/api/leads/bulk', ['ids' => $ids, 'action' => $action] + $p);
    }

    private function bells(User $u, string $type)
    {
        return AppNotification::withoutGlobalScope('tenant')->where('user_id', $u->id)->where('type', $type)->get();
    }

    public function test_assigning_many_skips_what_cannot_move_and_rings_the_new_owner_once(): void
    {
        $a = $this->lead();
        $b = $this->lead(['assigned_to_user_id' => $this->admin->id]);
        $same = $this->lead(['assigned_to_user_id' => $this->ben->id]);
        $closed = $this->lead(['status' => 'lost']);

        $res = $this->bulk([$a->id, $b->id, $same->id, $closed->id, 999999], 'assign', ['user_id' => $this->ben->id])->assertOk();

        $this->assertSame([5, 2, 3], [$res->json('data.requested'), $res->json('data.done'), $res->json('data.skipped_count')]);
        $reasons = collect($res->json('data.skipped'))->pluck('reason', 'id');
        $this->assertSame('Already with that person.', $reasons[$same->id]);
        $this->assertStringContainsString('closed leads are not reassigned', $reasons[$closed->id]);
        $this->assertSame('Not found.', $reasons[999999]);
        $this->assertSame([$this->ben->id, $this->ben->id], [$a->fresh()->assigned_to_user_id, $b->fresh()->assigned_to_user_id]);
        $this->assertStringContainsString('Reassigned: Asha → Ben', $this->note($b));
        $this->assertCount(0, $this->bells($this->ben, 'lead_assigned')->where('title', 'like', 'New lead%'), 'no bell per lead');
        $summary = $this->bells($this->ben, 'lead_assigned');
        $this->assertCount(1, $summary);
        $this->assertSame('2 leads assigned to you by Asha', $summary[0]->title);
    }

    public function test_assigning_to_nobody_clears_the_owner_and_a_bad_user_is_refused(): void
    {
        $a = $this->lead(['assigned_to_user_id' => $this->ben->id]);
        $inactive = User::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);

        $this->bulk([$a->id], 'assign', ['user_id' => 'none'])->assertOk()->assertJsonPath('data.done', 1);
        $this->assertNull($a->fresh()->assigned_to_user_id);
        $this->bulk([$a->id], 'assign', ['user_id' => $inactive->id])->assertStatus(422)->assertJsonValidationErrors('user_id');
        $this->bulk([$a->id], 'assign', ['user_id' => User::factory()->create(['company_id' => Company::factory()->create()->id])->id])->assertStatus(422);
    }

    // ── bulk: status ─────────────────────────────────────────────────────────

    public function test_moving_many_applies_the_ordinary_rules_to_each_and_says_why_one_was_skipped(): void
    {
        $a = $this->lead();
        $b = $this->lead(['status' => 'qualified']);
        $already = $this->lead(['status' => 'contacted']);
        $won = $this->lead(['status' => 'won']);

        $res = $this->bulk([$a->id, $b->id, $already->id, $won->id], 'status', ['status' => 'contacted'])->assertOk();

        $this->assertSame([2, 2], [$res->json('data.done'), $res->json('data.skipped_count')]);
        $this->assertSame(['contacted', 'contacted'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertStringContainsString('Status: new → contacted', $this->note($a));
        $reasons = collect($res->json('data.skipped'))->pluck('reason', 'id');
        $this->assertSame('Already contacted.', $reasons[$already->id]);
        $this->assertStringContainsString('is won. Reopen it on its own first', $reasons[$won->id]);
        $this->assertSame('won', $won->fresh()->status);
    }

    public function test_putting_many_on_hold_needs_the_date_and_losing_many_needs_a_reason_from_the_list(): void
    {
        $a = $this->lead();
        $b = $this->lead();
        $reasonId = collect($this->getJson('/api/leads/options')->json('data.lost_reasons'))->first()['id'];

        $this->bulk([$a->id], 'status', ['status' => 'on_hold'])->assertStatus(422)->assertJsonValidationErrors('hold_until');
        $this->bulk([$a->id, $b->id], 'status', ['status' => 'on_hold', 'hold_until' => '2026-11-20'])->assertOk()->assertJsonPath('data.done', 2);
        $this->assertSame('2026-11-20', $a->fresh()->next_follow_up_date->toDateString());

        $this->bulk([$a->id], 'status', ['status' => 'lost'])->assertStatus(422)->assertJsonValidationErrors('lost_reason_id');
        $this->bulk([$a->id], 'status', ['status' => 'lost', 'lost_reason_id' => 999999])->assertStatus(422);
        $this->bulk([$a->id, $b->id], 'status', ['status' => 'lost', 'lost_reason_id' => $reasonId, 'lost_note' => 'No budget'])->assertOk()->assertJsonPath('data.done', 2);
        $this->assertSame(['lost', 'No budget'], [$a->fresh()->status, $a->fresh()->lost_note]);
    }

    public function test_statuses_that_need_their_own_process_cannot_be_set_in_bulk(): void
    {
        $a = $this->lead();

        foreach (['won', 'quoted', 'new', 'banana'] as $status) {
            $this->bulk([$a->id], 'status', ['status' => $status])->assertStatus(422)->assertJsonValidationErrors('status');
        }
        $this->assertSame('new', $a->fresh()->status);
    }

    // ── bulk: plan an action ─────────────────────────────────────────────────

    public function test_one_action_can_be_planned_for_many_leads_with_one_line_for_the_person_it_is_for(): void
    {
        $a = $this->lead();
        $b = $this->lead();
        $closed = $this->lead(['status' => 'lost']);

        $res = $this->bulk([$a->id, $b->id, $closed->id], 'plan', ['type' => 'call', 'due_date' => '2026-11-12', 'title' => 'Diwali greetings', 'assigned_to_user_id' => $this->ben->id])->assertOk();

        $this->assertSame([2, 1], [$res->json('data.done'), $res->json('data.skipped_count')]);
        $this->assertSame(2, LeadTask::where('assigned_to_user_id', $this->ben->id)->where('title', 'Diwali greetings')->count());
        $this->assertSame('2026-11-12', $a->fresh()->next_follow_up_date->toDateString());
        $this->assertCount(0, $this->bells($this->ben, 'lead_task_assigned')->where('title', 'like', 'New action%'), 'no bell per lead');
        $this->assertSame('2 actions planned for you by Asha', $this->bells($this->ben, 'lead_task_assigned')[0]->title);
        $this->assertStringContainsString('closed', collect($res->json('data.skipped'))->first()['reason']);
    }

    public function test_a_day_in_the_past_skips_every_lead_with_the_reason_and_plans_nothing(): void
    {
        $a = $this->lead();
        $b = $this->lead();

        $res = $this->bulk([$a->id, $b->id], 'plan', ['type' => 'call', 'due_date' => '2026-11-01'])->assertOk();

        $this->assertSame([0, 2], [$res->json('data.done'), $res->json('data.skipped_count')]);
        $this->assertSame('Choose today or a later day.', $res->json('data.skipped.0.reason'));
        $this->assertSame(0, LeadTask::count());
        $this->bulk([$a->id], 'plan', ['type' => 'call'])->assertStatus(422);
    }

    // ── bulk: limits and who may ─────────────────────────────────────────────

    public function test_the_request_itself_must_make_sense(): void
    {
        $a = $this->lead();

        $this->bulk([], 'assign')->assertStatus(422);
        $this->bulk([$a->id], 'delete')->assertStatus(422);
        $this->bulk([$a->id], 'nonsense')->assertStatus(422);
        $this->bulk(range(1, 201), 'assign', ['user_id' => 'none'])->assertStatus(422);
        $this->assertSame(1, Lead::count());
    }

    public function test_the_same_lead_listed_twice_is_done_once(): void
    {
        $a = $this->lead();

        $res = $this->bulk([$a->id, $a->id, (string) $a->id], 'assign', ['user_id' => $this->ben->id])->assertOk();

        $this->assertSame([1, 1], [$res->json('data.requested'), $res->json('data.done')]);
    }

    public function test_bulk_needs_leads_manage_and_a_restricted_person_reaches_only_their_own_leads(): void
    {
        $theirs = $this->lead(['assigned_to_user_id' => $this->ben->id]);
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view']])->id]), 'sanctum');
        $this->bulk([$theirs->id], 'assign', ['user_id' => 'none'])->assertStatus(403);

        $me = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.manage', 'leads.own_only']])->id]);
        $mine = $this->lead(['assigned_to_user_id' => $me->id]);
        $this->actingAs($me, 'sanctum');

        $res = $this->bulk([$mine->id, $theirs->id], 'status', ['status' => 'contacted'])->assertOk();

        $this->assertSame([1, 1], [$res->json('data.done'), $res->json('data.skipped_count')]);
        $this->assertSame('Not found.', $res->json('data.skipped.0.reason'));
        $this->assertSame('new', $theirs->fresh()->status);
    }
}
