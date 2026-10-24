<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAssignmentRule;
use App\Models\User;
use App\Services\LeadIntakeService;
use App\Services\LeadService;
use Tests\TestCase;

/** LEADS_PLAN 2.4: who gets a new lead, and telling them at once. */
class LeadAssignmentTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private User $asha;
    private User $ben;
    private User $chitra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'name' => 'Boss']);
        $this->asha = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Asha']);
        $this->ben = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Ben']);
        $this->chitra = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Chitra']);
    }

    private function rule(array $over = []): LeadAssignmentRule
    {
        return LeadAssignmentRule::create($over + [
            'company_id' => $this->company->id, 'name' => 'Everyone', 'priority' => 1, 'is_active' => true,
            'user_ids' => [$this->asha->id, $this->ben->id, $this->chitra->id],
        ]);
    }

    private function newLead(array $over = []): Lead
    {
        static $n = 0;
        $n++;

        return app(LeadService::class)->create($this->company->id, $over + ['contact_name' => "Buyer {$n}", 'phone' => '98' . str_pad((string) (10000000 + $n), 8, '0', STR_PAD_LEFT), 'source' => 'Website']);
    }

    private function bell(User $u)
    {
        return AppNotification::withoutGlobalScope('tenant')->where('user_id', $u->id)->where('type', 'lead_assigned')->get();
    }

    private function log(Lead $l): array
    {
        return LeadActivity::where('lead_id', $l->id)->orderBy('id')->pluck('description')->all();
    }

    // ── no rules, no change ──────────────────────────────────────────────────

    public function test_with_no_rules_a_new_lead_stays_unassigned_as_before(): void
    {
        $l = $this->newLead();

        $this->assertNull($l->fresh()->assigned_to_user_id);
        $this->assertSame([], $this->log($l));
        $this->assertCount(0, AppNotification::withoutGlobalScope('tenant')->get());
    }

    public function test_a_rule_that_is_switched_off_does_nothing(): void
    {
        $this->rule(['is_active' => false]);

        $this->assertNull($this->newLead()->fresh()->assigned_to_user_id);
    }

    // ── one person, and the alert ────────────────────────────────────────────

    public function test_a_rule_with_one_person_gives_every_matching_lead_to_them_and_only_they_are_alerted(): void
    {
        $this->rule(['name' => 'Website leads', 'source' => 'Website', 'user_ids' => [$this->ben->id]]);

        $l = $this->newLead(['contact_name' => 'Ravi Patel', 'city' => 'Rajkot', 'requirement' => 'Need 300 sqm PUF', 'phone' => '9876543210']);

        $this->assertSame($this->ben->id, $l->fresh()->assigned_to_user_id);
        $this->assertSame(['Assigned to Ben (rule: Website leads)'], $this->log($l));
        $n = $this->bell($this->ben);
        $this->assertCount(1, $n);
        $this->assertSame("New lead: {$l->lead_no} Ravi Patel", $n[0]->title);
        $this->assertStringContainsString('Website · Rajkot', $n[0]->body);
        $this->assertStringContainsString('Need 300 sqm PUF', $n[0]->body);
        $this->assertStringContainsString('Phone 9876543210', $n[0]->body);
        $this->assertSame('leads', $n[0]->link_section);
        $this->assertSame($l->id, (int) $n[0]->link_id);
        foreach ([$this->asha, $this->chitra, $this->admin] as $other) {
            $this->assertCount(0, $this->bell($other), 'No alert for the wrong person.');
        }
    }

    // ── taking turns ─────────────────────────────────────────────────────────

    public function test_people_in_a_rule_take_turns_in_the_order_listed_and_wrap_round(): void
    {
        $this->rule();

        $got = collect(range(1, 7))->map(fn () => $this->newLead()->fresh()->assigned_to_user_id)->all();

        $a = $this->asha->id; $b = $this->ben->id; $c = $this->chitra->id;
        $this->assertSame([$a, $b, $c, $a, $b, $c, $a], $got);
    }

    public function test_the_rotation_carries_on_across_manual_form_and_channel_leads(): void
    {
        $this->rule();
        $this->actingAs($this->admin, 'sanctum');

        $first = $this->postJson('/api/leads', ['contact_name' => 'Form one', 'phone' => '9811111111'])->assertStatus(201)->json('data.assigned_to_user_id');
        $second = app(LeadIntakeService::class)->ingest($this->company->id, 'IndiaMART', ['contact_name' => 'Channel one', 'phone' => '9822222222']);

        $this->assertSame($this->asha->id, $first);
        $this->assertSame($this->ben->id, Lead::find($second->leadId)->assigned_to_user_id);
    }

    public function test_an_inactive_person_is_skipped_and_gets_nothing(): void
    {
        $this->rule();
        $this->ben->update(['is_active' => false]);

        $got = collect(range(1, 4))->map(fn () => $this->newLead()->fresh()->assigned_to_user_id)->all();

        $this->assertSame([$this->asha->id, $this->chitra->id, $this->asha->id, $this->chitra->id], $got);
        $this->assertCount(0, $this->bell($this->ben));
    }

    public function test_a_rule_whose_people_are_all_unable_passes_the_lead_to_the_next_rule_or_leaves_it_unassigned(): void
    {
        $this->asha->update(['is_active' => false]);
        $this->rule(['name' => 'Only Asha', 'priority' => 1, 'user_ids' => [$this->asha->id]]);
        $this->assertNull($this->newLead()->fresh()->assigned_to_user_id);

        $this->rule(['name' => 'Fallback', 'priority' => 2, 'user_ids' => [$this->chitra->id]]);

        $this->assertSame($this->chitra->id, $this->newLead()->fresh()->assigned_to_user_id);
    }

    public function test_someone_from_another_company_in_the_list_is_never_used(): void
    {
        $foreign = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->rule(['user_ids' => [$foreign->id]]);

        $this->assertNull($this->newLead()->fresh()->assigned_to_user_id);
        $this->assertCount(0, $this->bell($foreign));
    }

    // ── which rule wins ──────────────────────────────────────────────────────

    public function test_the_first_matching_rule_by_priority_wins_and_the_catch_all_is_last(): void
    {
        $this->rule(['name' => 'Catch-all', 'priority' => 9, 'user_ids' => [$this->chitra->id]]);
        $this->rule(['name' => 'IndiaMART', 'priority' => 2, 'source' => 'IndiaMART', 'user_ids' => [$this->ben->id]]);
        $this->rule(['name' => 'IndiaMART Rajkot', 'priority' => 1, 'source' => 'IndiaMART', 'city' => 'rajkot', 'user_ids' => [$this->asha->id]]);

        $this->assertSame($this->asha->id, $this->newLead(['source' => 'IndiaMART', 'city' => 'Rajkot'])->fresh()->assigned_to_user_id, 'Most specific rule first, city in any case.');
        $this->assertSame($this->ben->id, $this->newLead(['source' => 'IndiaMART', 'city' => 'Surat'])->fresh()->assigned_to_user_id);
        $this->assertSame($this->chitra->id, $this->newLead(['source' => 'Website'])->fresh()->assigned_to_user_id);
    }

    public function test_a_rule_can_match_on_the_application(): void
    {
        $this->rule(['name' => 'Cold rooms', 'priority' => 1, 'application' => 'Cold Room', 'user_ids' => [$this->ben->id]]);

        $this->assertSame($this->ben->id, $this->newLead(['application' => 'cold room'])->fresh()->assigned_to_user_id);
        $this->assertNull($this->newLead(['application' => 'Roof'])->fresh()->assigned_to_user_id);
    }

    public function test_another_companys_rules_never_apply(): void
    {
        $other = Company::factory()->create();
        $theirUser = User::factory()->create(['company_id' => $other->id]);
        LeadAssignmentRule::create(['company_id' => $other->id, 'name' => 'Theirs', 'priority' => 1, 'is_active' => true, 'user_ids' => [$theirUser->id]]);

        $this->assertNull($this->newLead()->fresh()->assigned_to_user_id);
    }

    // ── chosen by hand ───────────────────────────────────────────────────────

    public function test_a_person_chosen_by_hand_is_respected_and_does_not_use_up_a_turn(): void
    {
        $this->rule();

        $manual = $this->newLead(['assigned_to_user_id' => $this->chitra->id]);
        $next = $this->newLead();

        $this->assertSame($this->chitra->id, $manual->fresh()->assigned_to_user_id);
        $this->assertSame($this->asha->id, $next->fresh()->assigned_to_user_id, 'Asha was first in line and still is.');
        $this->assertSame(['Assigned to Chitra (by hand)'], $this->log($manual));
        $this->assertCount(1, $this->bell($this->chitra));
    }

    public function test_assigning_a_lead_to_yourself_rings_nobody(): void
    {
        $this->asha->update(['is_company_admin' => true]);          // able to add leads
        $this->actingAs($this->asha, 'sanctum');

        $id = $this->postJson('/api/leads', ['contact_name' => 'Mine', 'assigned_to_user_id' => $this->asha->id])->assertStatus(201)->json('data.id');

        $this->assertCount(0, $this->bell($this->asha));
        $this->assertSame(['Assigned to Asha (by hand)'], $this->log(Lead::find($id)));
    }

    // ── reassigning ──────────────────────────────────────────────────────────

    public function test_reassigning_leaves_history_and_alerts_only_the_new_person(): void
    {
        $l = $this->newLead(['assigned_to_user_id' => $this->asha->id]);
        AppNotification::withoutGlobalScope('tenant')->delete();
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/leads/{$l->id}", ['assigned_to_user_id' => $this->ben->id])->assertOk();

        $this->assertSame($this->ben->id, $l->fresh()->assigned_to_user_id);
        $this->assertContains('Reassigned: Asha → Ben (by Boss)', $this->log($l));
        $this->assertCount(1, $this->bell($this->ben));
        $this->assertCount(0, $this->bell($this->asha), 'The previous person is not alerted.');
        $this->assertCount(0, $this->bell($this->admin));
    }

    public function test_saving_without_changing_the_person_writes_nothing(): void
    {
        $l = $this->newLead(['assigned_to_user_id' => $this->asha->id]);
        $before = count($this->log($l));
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/leads/{$l->id}", ['assigned_to_user_id' => $this->asha->id, 'city' => 'Surat'])->assertOk();
        $this->putJson("/api/leads/{$l->id}", ['city' => 'Vapi'])->assertOk();

        $this->assertSame($before, count($this->log($l)));
    }

    public function test_unassigning_is_recorded_and_alerts_nobody(): void
    {
        $l = $this->newLead(['assigned_to_user_id' => $this->asha->id]);
        AppNotification::withoutGlobalScope('tenant')->delete();
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/leads/{$l->id}", ['assigned_to_user_id' => null])->assertOk();

        $this->assertNull($l->fresh()->assigned_to_user_id);
        $this->assertContains('Unassigned (was Asha, by Boss)', $this->log($l));
        $this->assertCount(0, AppNotification::withoutGlobalScope('tenant')->where('type', 'lead_assigned')->get());
    }

    // ── a repeat enquiry ─────────────────────────────────────────────────────

    public function test_a_repeat_enquiry_rings_the_person_who_has_the_lead_and_nobody_else(): void
    {
        $l = $this->newLead(['assigned_to_user_id' => $this->ben->id, 'phone' => '9876543210']);
        AppNotification::withoutGlobalScope('tenant')->delete();

        $r = app(LeadIntakeService::class)->ingest($this->company->id, 'IndiaMART', ['contact_name' => 'Same', 'phone' => '9876543210', 'requirement' => 'More panels', 'external_id' => 'IM-5']);

        $this->assertSame('merged', $r->outcome);
        $n = $this->bell($this->ben);
        $this->assertCount(1, $n);
        $this->assertSame("Enquired again: {$l->lead_no} Buyer " . substr($l->contact_name, 6), $n[0]->title);
        $this->assertCount(0, $this->bell($this->asha));
    }

    public function test_a_repeat_enquiry_on_an_unassigned_lead_has_nobody_to_ring(): void
    {
        $this->newLead(['phone' => '9876543210']);

        app(LeadIntakeService::class)->ingest($this->company->id, 'IndiaMART', ['contact_name' => 'Same', 'phone' => '9876543210']);

        $this->assertCount(0, AppNotification::withoutGlobalScope('tenant')->where('type', 'lead_assigned')->get());
    }

    // ── managing the rules ───────────────────────────────────────────────────

    public function test_rules_can_be_made_changed_and_deleted_by_someone_who_manages_settings(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $made = $this->postJson('/api/settings/lead-assignment-rules', ['name' => 'Web', 'source' => 'website', 'city' => '  ', 'user_ids' => [$this->asha->id, $this->ben->id]])->assertStatus(201);

        $this->assertSame('Website', $made->json('data.source'), 'The company\'s spelling.');
        $this->assertNull($made->json('data.city'));
        $this->assertSame(1, $made->json('data.priority'));
        $id = $made->json('data.id');
        $second = $this->postJson('/api/settings/lead-assignment-rules', ['name' => 'Rest', 'user_ids' => [$this->chitra->id]])->json('data');
        $this->assertSame(2, $second['priority'], 'New rules go last.');

        $this->putJson("/api/settings/lead-assignment-rules/{$id}", ['priority' => 5, 'is_active' => false])->assertOk();
        $this->assertSame(['Rest', 'Web'], collect($this->getJson('/api/settings/lead-assignment-rules')->json('data'))->pluck('name')->all());
        $this->deleteJson("/api/settings/lead-assignment-rules/{$id}")->assertStatus(204);
        $this->assertCount(1, $this->getJson('/api/settings/lead-assignment-rules')->json('data'));
    }

    public function test_changing_the_people_in_a_rule_restarts_its_rotation(): void
    {
        $r = $this->rule();
        $this->newLead();
        $this->newLead();
        $this->assertSame($this->ben->id, $r->fresh()->last_user_id);
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/settings/lead-assignment-rules/{$r->id}", ['user_ids' => [$this->chitra->id, $this->asha->id]])->assertOk();

        $this->assertNull($r->fresh()->last_user_id);
        $this->assertSame($this->chitra->id, $this->newLead()->fresh()->assigned_to_user_id);
    }

    public function test_a_rule_needs_real_active_people_of_this_company_and_a_known_source(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $foreign = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->ben->update(['is_active' => false]);

        $this->postJson('/api/settings/lead-assignment-rules', ['name' => 'X', 'user_ids' => [$foreign->id]])->assertStatus(422)->assertJsonValidationErrors('user_ids');
        $this->postJson('/api/settings/lead-assignment-rules', ['name' => 'X', 'user_ids' => [$this->ben->id]])->assertStatus(422);
        $this->postJson('/api/settings/lead-assignment-rules', ['name' => 'X', 'user_ids' => []])->assertStatus(422);
        $this->postJson('/api/settings/lead-assignment-rules', ['name' => 'X', 'user_ids' => [$this->asha->id], 'source' => 'Smoke Signals'])->assertStatus(422)->assertJsonValidationErrors('source');
        $this->postJson('/api/settings/lead-assignment-rules', ['user_ids' => [$this->asha->id]])->assertStatus(422);
    }

    public function test_another_companys_admin_cannot_see_or_change_the_rules_and_a_plain_user_cannot_write(): void
    {
        $rule = $this->rule();
        $this->actingAs(User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]), 'sanctum');

        $this->assertCount(0, $this->getJson('/api/settings/lead-assignment-rules')->json('data'));
        $this->putJson("/api/settings/lead-assignment-rules/{$rule->id}", ['name' => 'Hijack'])->assertStatus(404);
        $this->deleteJson("/api/settings/lead-assignment-rules/{$rule->id}")->assertStatus(404);

        $this->actingAs($this->asha, 'sanctum');
        $this->postJson('/api/settings/lead-assignment-rules', ['name' => 'X', 'user_ids' => [$this->asha->id]])->assertStatus(403);
        $this->assertSame('Everyone', $rule->fresh()->name);
    }
}
