<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStage;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadEngagement;
use App\Services\LeadStages;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** LEADS_PLAN 5.8 (editable stages, LP-11): a company's own open stages with names, order and win probability; every part of the lead module follows them. */
class LeadStagesTest extends TestCase
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

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "LS-{$n}", 'contact_name' => "Buyer {$n}", 'source' => 'Phone', 'status' => 'new']);
    }

    private function stages(): array
    {
        return collect($this->getJson('/api/settings/lead-stages')->assertOk()->json('data.stages'))->keyBy('key')->all();
    }

    private function add(string $name, $probability = 40)
    {
        return $this->postJson('/api/settings/lead-stages', ['name' => $name, 'probability' => $probability]);
    }

    private function keys(): array
    {
        return array_keys($this->stages());
    }

    private function order(array $keys): void
    {
        $ids = array_column($this->stages(), 'id', 'key');
        $this->putJson('/api/settings/lead-stages/order', ['ids' => array_map(fn ($k) => $ids[$k], $keys)])->assertOk();
    }

    public function test_a_company_that_never_touched_its_stages_has_exactly_the_old_pipeline(): void
    {
        $s = $this->stages();

        $this->assertSame(['new', 'contacted', 'qualified', 'quoted'], array_keys($s));
        $this->assertSame(['New', 'Contacted', 'Qualified', 'Quoted'], array_values(array_column($s, 'name')));
        $this->assertSame([10, 25, 50, 75], array_values(array_column($s, 'probability')));
        $this->assertSame([true, false, false, true], array_values(array_column($s, 'built_in')));

        $o = $this->getJson('/api/leads/options')->assertOk();
        $this->assertSame(['new', 'contacted', 'qualified', 'quoted', 'on_hold', 'won', 'lost', 'invalid'], $o->json('data.statuses'));
        $this->assertEqualsCanonicalizing(['contacted', 'qualified', 'quoted', 'on_hold', 'won', 'lost', 'invalid'], $o->json('data.transitions.new'));
    }

    public function test_the_list_shows_how_many_leads_are_in_each_stage(): void
    {
        $this->lead(['status' => 'contacted']);
        $this->lead(['status' => 'contacted']);
        $this->lead(['status' => 'won']);

        $this->assertSame([0, 2, 0, 0], array_values(array_column($this->stages(), 'leads')));
    }

    public function test_renaming_a_stage_changes_what_people_see_and_touches_no_lead(): void
    {
        $lead = $this->lead(['status' => 'qualified']);
        $id = $this->stages()['qualified']['id'];

        $this->putJson("/api/settings/lead-stages/{$id}", ['name' => 'Site visit done', 'probability' => 60])->assertOk()->assertJsonPath('data.stages.2.name', 'Site visit done');

        $this->assertSame('qualified', $lead->fresh()->status);
        $board = collect($this->getJson('/api/leads/board')->json('data.columns'))->keyBy('status');
        $this->assertSame(['Site visit done', 60, 1], [$board['qualified']['name'], $board['qualified']['probability'], $board['qualified']['count']]);
        $this->assertSame('Site visit done', collect($this->getJson('/api/leads/options')->json('data.stages'))->firstWhere('key', 'qualified')['name']);
    }

    public function test_the_timeline_uses_the_stage_names_and_the_status_change_stores_keys(): void
    {
        $lead = $this->lead(['status' => 'new']);
        $this->putJson('/api/settings/lead-stages/' . $this->stages()['contacted']['id'], ['name' => 'Called back'])->assertOk();

        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'contacted'])->assertOk();

        $a = LeadActivity::where('lead_id', $lead->id)->where('type', 'status_change')->latest('id')->first();
        $this->assertSame(['new', 'contacted', 'Status: new → called back'], [$a->from_status, $a->to_status, $a->description]);
    }

    public function test_a_new_stage_goes_to_the_end_with_a_key_made_from_its_name(): void
    {
        $this->add('Site visit', 55)->assertCreated()->assertJsonPath('data.stages.4.name', 'Site visit')->assertJsonPath('data.stages.4.key', 'site_visit')->assertJsonPath('data.stages.4.probability', 55);

        $this->assertSame(['new', 'contacted', 'qualified', 'quoted', 'site_visit'], $this->keys());
    }

    public function test_a_new_stage_can_be_chosen_for_a_lead_and_the_old_ones_still_work(): void
    {
        $this->add('Site visit');
        $lead = $this->lead(['status' => 'qualified']);

        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'site_visit'])->assertOk();
        $this->assertSame('site_visit', $lead->fresh()->status);
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'contacted'])->assertOk();
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'nonsense'])->assertStatus(422);

        $this->assertContains('site_visit', $this->getJson('/api/leads/options')->json('data.transitions.contacted'));
    }

    public function test_a_lead_can_be_created_in_a_custom_stage_but_not_in_quoted(): void
    {
        $this->add('Site visit');
        $new = ['contact_name' => 'Ravi', 'phone' => '9876543210', 'source' => 'Phone'];

        $this->postJson('/api/leads', [...$new, 'status' => 'site_visit'])->assertCreated();
        $this->postJson('/api/leads', [...$new, 'phone' => '9123456780', 'contact_name' => 'Sunil', 'status' => 'quoted'])->assertStatus(422);
        $this->postJson('/api/leads', [...$new, 'phone' => '9811122233', 'contact_name' => 'Meera'])->assertCreated()->assertJsonPath('data.status', 'new');
    }

    public function test_the_stage_order_decides_the_board_columns_and_the_funnel(): void
    {
        $this->add('Site visit');
        $this->lead(['status' => 'site_visit']);

        $this->order(['new', 'site_visit', 'contacted', 'qualified', 'quoted']);

        $board = $this->getJson('/api/leads/board')->assertOk();
        $this->assertSame(['new', 'site_visit', 'contacted', 'qualified', 'quoted', 'on_hold'], array_column($board->json('data.columns'), 'status'));
        $this->assertSame(1, collect($board->json('data.columns'))->firstWhere('status', 'site_visit')['count']);
        $funnel = $this->getJson('/api/leads/dashboard')->assertOk()->json('data.funnel');
        $this->assertSame(['new', 'site_visit', 'contacted', 'qualified', 'quoted', 'won', 'lost'], array_column($funnel, 'status'));
        $this->assertSame('Site visit', $funnel[1]['name']);
    }

    public function test_the_order_must_list_every_stage_once(): void
    {
        $ids = array_column($this->stages(), 'id');

        $this->putJson('/api/settings/lead-stages/order', ['ids' => array_slice($ids, 1)])->assertStatus(422);
        $this->putJson('/api/settings/lead-stages/order', ['ids' => [...$ids, 99999]])->assertStatus(422);
        $this->putJson('/api/settings/lead-stages/order', ['ids' => [$ids[0], $ids[0], $ids[1], $ids[2]]])->assertStatus(422);
        $this->assertSame(['new', 'contacted', 'qualified', 'quoted'], $this->keys());
    }

    public function test_an_empty_stage_can_be_removed_and_its_key_is_never_reused(): void
    {
        $this->add('Site visit');
        $id = $this->stages()['site_visit']['id'];

        $this->deleteJson("/api/settings/lead-stages/{$id}")->assertOk();
        $this->assertNotContains('site_visit', $this->keys());

        $this->add('Site visit')->assertCreated();
        $this->assertContains('site_visit_2', $this->keys());
    }

    public function test_a_stage_that_has_leads_cannot_be_removed_and_the_message_says_how_many(): void
    {
        $id = $this->stages()['contacted']['id'];
        $this->lead(['status' => 'contacted']);
        $this->lead(['status' => 'contacted']);

        $this->deleteJson("/api/settings/lead-stages/{$id}")->assertStatus(422)->assertJsonPath('message', '2 leads are in "Contacted". Move them to another stage first (select them on the Leads screen and use Move to).');

        $this->assertContains('contacted', $this->keys());
    }

    public function test_the_two_built_in_stages_can_be_renamed_but_never_removed(): void
    {
        $s = $this->stages();

        $this->deleteJson('/api/settings/lead-stages/' . $s['new']['id'])->assertStatus(422);
        $this->deleteJson('/api/settings/lead-stages/' . $s['quoted']['id'])->assertStatus(422);
        $this->putJson('/api/settings/lead-stages/' . $s['new']['id'], ['name' => 'Enquiry'])->assertOk();

        $this->assertSame('new', $this->lead()->status);
        $this->assertSame('Enquiry', $this->stages()['new']['name']);
    }

    public function test_names_must_be_unique_and_never_the_fixed_statuses_and_the_chance_is_a_whole_number_to_100(): void
    {
        $this->add('contacted')->assertStatus(422);
        $this->add('  CONTACTED  ')->assertStatus(422);
        $this->add('Won')->assertStatus(422);
        $this->add('On hold')->assertStatus(422);
        $this->add('   ')->assertStatus(422);
        $this->add(str_repeat('x', 41))->assertStatus(422);
        $this->add('Fine', 101)->assertStatus(422);
        $this->add('Fine', -1)->assertStatus(422);
        $this->add('Fine', 12.5)->assertStatus(422);
        $this->add('Fine', 'abc')->assertStatus(422);
        $this->putJson('/api/settings/lead-stages/' . $this->stages()['qualified']['id'], ['name' => 'Contacted'])->assertStatus(422);
        $this->putJson('/api/settings/lead-stages/' . $this->stages()['qualified']['id'], ['name' => 'Qualified'])->assertOk();

        $this->add('Zero', 0)->assertCreated();
        $this->add('Hundred', 100)->assertCreated();
    }

    public function test_a_pipeline_has_at_most_twelve_stages(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->add("Extra {$i}")->assertCreated();
        }

        $this->add('One too many')->assertStatus(422);
        $this->assertCount(12, $this->keys());
    }

    public function test_a_name_made_only_of_symbols_still_gets_a_working_key(): void
    {
        $this->add('★★★')->assertCreated();
        $this->add('???')->assertCreated();

        $this->assertSame(['new', 'contacted', 'qualified', 'quoted', 'stage', 'stage_2'], $this->keys());
    }

    public function test_bulk_move_offers_the_custom_stage_but_never_new_or_quoted(): void
    {
        $this->add('Site visit');
        $a = $this->lead(['status' => 'contacted']);
        $b = $this->lead(['status' => 'contacted']);

        $this->postJson('/api/leads/bulk', ['ids' => [$a->id, $b->id], 'action' => 'status', 'status' => 'site_visit'])->assertOk();
        $this->assertSame(['site_visit', 'site_visit'], [$a->fresh()->status, $b->fresh()->status]);

        $this->postJson('/api/leads/bulk', ['ids' => [$a->id], 'action' => 'status', 'status' => 'quoted'])->assertStatus(422);
        $this->postJson('/api/leads/bulk', ['ids' => [$a->id], 'action' => 'status', 'status' => 'new'])->assertStatus(422);
    }

    public function test_merging_keeps_the_later_stage_of_the_pipeline_as_the_company_ordered_it(): void
    {
        $this->add('Site visit');
        $this->order(['new', 'site_visit', 'contacted', 'qualified', 'quoted']);
        $keep = $this->lead(['status' => 'contacted', 'phone' => '9000000001']);
        $dup = $this->lead(['status' => 'site_visit', 'phone' => '9000000002']);

        $this->postJson("/api/leads/{$keep->id}/merge", ['from_lead_id' => $dup->id])->assertOk();

        $this->assertSame('contacted', $keep->fresh()->status);   // contacted now comes after site visit

        $keep2 = $this->lead(['status' => 'site_visit', 'phone' => '9000000003']);
        $dup2 = $this->lead(['status' => 'qualified', 'phone' => '9000000004']);
        $this->postJson("/api/leads/{$keep2->id}/merge", ['from_lead_id' => $dup2->id])->assertOk();
        $this->assertSame('qualified', $keep2->fresh()->status);
    }

    public function test_first_response_is_still_the_first_move_out_of_new_after_a_rename(): void
    {
        $lead = $this->lead(['status' => 'new', 'first_seen_at' => now()->subHour()]);
        $this->putJson('/api/settings/lead-stages/' . $this->stages()['new']['id'], ['name' => 'Enquiry'])->assertOk();
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'contacted'])->assertOk();

        $row = Lead::where('leads.id', $lead->id)->selectRaw('(' . LeadEngagement::firstResponseSql() . ') as r')->first();

        $this->assertNotNull($row->r);
    }

    public function test_the_automatic_quoted_stage_still_works_with_the_stages_edited(): void
    {
        $this->putJson('/api/settings/lead-stages/' . $this->stages()['quoted']['id'], ['name' => 'Offer sent'])->assertOk();
        $lead = $this->lead(['status' => 'qualified']);

        $customer = \App\Models\Customer::factory()->create(['company_id' => $this->company->id]);
        app(\App\Services\LeadService::class)->linkQuotation($lead->id, $this->company->id, 987654, $customer->id);

        $this->assertSame('quoted', $lead->fresh()->status);
    }

    public function test_saved_views_accept_a_custom_stage_and_drop_an_unknown_one(): void
    {
        $this->add('Site visit');

        $ok = $this->postJson('/api/leads/views', ['name' => 'Visits', 'filters' => ['status' => 'site_visit']])->assertCreated();
        $bad = $this->postJson('/api/leads/views', ['name' => 'Ghost', 'filters' => ['status' => 'ghost']])->assertCreated();

        $this->assertSame('site_visit', $ok->json('data.filters.status'));
        $this->assertNull($bad->json('data.filters.status'));
    }

    public function test_companies_do_not_see_or_change_each_others_stages(): void
    {
        $other = Company::factory()->create();
        $theirs = LeadStages::for($other->id)->rows[1]['id'];

        $this->putJson("/api/settings/lead-stages/{$theirs}", ['name' => 'Hacked'])->assertStatus(404);
        $this->deleteJson("/api/settings/lead-stages/{$theirs}")->assertStatus(404);
        $this->add('Mine')->assertCreated();

        LeadStages::forget();
        $this->assertSame(['new', 'contacted', 'qualified', 'quoted'], LeadStages::for($other->id)->open());
        $this->assertSame('Contacted', LeadStage::withoutGlobalScope('tenant')->find($theirs)->name);
    }

    public function test_reading_is_open_to_staff_but_changing_stages_needs_settings_manage(): void
    {
        $staff = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.manage']])->id]);
        $id = $this->stages()['contacted']['id'];
        $this->actingAs($staff, 'sanctum');

        $this->getJson('/api/settings/lead-stages')->assertOk();
        $this->postJson('/api/settings/lead-stages', ['name' => 'Sneaky', 'probability' => 5])->assertStatus(403);
        $this->putJson("/api/settings/lead-stages/{$id}", ['name' => 'Sneaky'])->assertStatus(403);
        $this->putJson('/api/settings/lead-stages/order', ['ids' => [1]])->assertStatus(403);
        $this->deleteJson("/api/settings/lead-stages/{$id}")->assertStatus(403);
    }

    public function test_the_migration_turns_old_timeline_text_into_stage_keys_and_leaves_the_rest_alone(): void
    {
        $lead = $this->lead();
        $row = fn (string $text, string $type = 'status_change') => LeadActivity::create(['company_id' => $this->company->id, 'lead_id' => $lead->id, 'type' => $type, 'description' => $text, 'activity_date' => now()]);
        $a = $row('Status: new → contacted');
        $b = $row('Status: on hold → qualified (reopened: asked to call back)');
        $c = $row('Status: qualified → lost (Price too high)');
        $d = $row('Status: won → qualified');
        $e = $row('Status: new → somethingelse');
        $f = $row('Status: new → contacted', 'note');
        LeadActivity::whereIn('id', [$a->id, $b->id, $c->id, $d->id, $e->id, $f->id])->update(['from_status' => null, 'to_status' => null]);

        (require database_path('migrations/2026_10_26_000013_lead_stages.php'))->up();

        $got = fn ($x) => [$x->fresh()->from_status, $x->fresh()->to_status];
        $this->assertSame(['new', 'contacted'], $got($a));
        $this->assertSame(['on_hold', 'qualified'], $got($b));
        $this->assertSame(['qualified', 'lost'], $got($c));
        $this->assertSame(['won', 'qualified'], $got($d));
        $this->assertSame([null, null], $got($e));
        $this->assertSame([null, null], $got($f));
    }

    public function test_the_home_dashboard_funnel_uses_the_stage_names_and_order(): void
    {
        $this->add('Site visit');
        $this->putJson('/api/settings/lead-stages/' . $this->stages()['new']['id'], ['name' => 'Enquiry'])->assertOk();
        $this->lead(['status' => 'site_visit']);

        $funnel = $this->getJson('/api/dashboard')->assertOk()->json('data.analytics.lead_funnel');

        $this->assertSame(['Enquiry', 'Contacted', 'Qualified', 'Quoted', 'Site visit', 'Won', 'Lost'], array_column($funnel, 'label'));
        $this->assertSame(1, $funnel[4]['value']);
    }
}
