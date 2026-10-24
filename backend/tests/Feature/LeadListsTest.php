<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadListService;
use App\Services\LeadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * LEADS_PLAN 1.1. Sources and lost reasons are lists a company edits, not a database enum.
 */
class LeadListsTest extends TestCase
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

    private function lists(): array
    {
        return $this->getJson('/api/settings/lead-lists')->assertOk()->json('data');
    }

    private function source(string $name): ?array
    {
        return collect($this->lists()['sources'])->firstWhere('name', $name);
    }

    private function newLead(array $over = [])
    {
        return $this->postJson('/api/leads', $over + ['contact_name' => 'Test Buyer']);
    }

    public function test_the_columns_are_plain_strings_and_the_old_values_still_fit(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('leads', 'source'));
        $this->assertSame('varchar', Schema::getColumnType('leads', 'status'));
        foreach (['Website', 'Phone', 'WhatsApp', 'Referral', 'IndiaMART', 'Justdial', 'Exhibition', 'Walk-in', 'Other'] as $old) {
            $this->newLead(['source' => $old])->assertStatus(201)->assertJsonPath('data.source', $old);
        }
        $this->assertContains('lost_reason_id', Schema::getColumnListing('leads'));
    }

    public function test_every_company_gets_the_starter_lists_the_first_time_they_are_read(): void
    {
        $l = $this->lists();

        $names = array_column($l['sources'], 'name');
        foreach (LeadListService::DEFAULT_SOURCES as $default) {
            $this->assertContains($default, $names);
        }
        $this->assertContains('TradeIndia', $names, 'The channels the audit asked for are there from the start.');
        $this->assertSame(LeadListService::DEFAULT_REASONS, array_column($l['lost_reasons'], 'name'));
        $this->assertTrue($this->source('Other')['is_system']);
        $this->assertSame(15, DB::table('lead_sources')->where('company_id', $this->company->id)->count(), 'Seeded once, not on every read.');
        $this->lists();
        $this->assertSame(15, DB::table('lead_sources')->where('company_id', $this->company->id)->count());
    }

    public function test_a_new_channel_can_be_added_and_used_without_any_code_change(): void
    {
        $this->newLead(['source' => 'Trade Fair 2027'])->assertStatus(422);

        $this->postJson('/api/settings/lead-sources', ['name' => 'Trade Fair 2027'])->assertOk();

        $this->newLead(['source' => 'trade fair 2027'])->assertStatus(201)->assertJsonPath('data.source', 'Trade Fair 2027');
        $this->assertSame(1, $this->source('Trade Fair 2027')['used']);
        $this->assertContains('Trade Fair 2027', $this->getJson('/api/leads/options')->json('data.sources'));
    }

    public function test_names_are_unique_per_company_ignoring_case_and_spacing(): void
    {
        $this->postJson('/api/settings/lead-sources', ['name' => 'indiamart'])->assertStatus(422)->assertJsonPath('message', '"indiamart" is already in the list.');
        $this->postJson('/api/settings/lead-sources', ['name' => '  Walk-in  '])->assertStatus(422);
        $this->postJson('/api/settings/lead-sources', ['name' => ''])->assertStatus(422);
        $this->postJson('/api/settings/lead-sources', ['name' => str_repeat('x', 61)])->assertStatus(422);
        $this->postJson('/api/settings/lead-sources', ['name' => 'Radio  Ad'])->assertOk();
        $this->assertNotNull($this->source('Radio Ad'), 'Runs of spaces are tidied.');
    }

    public function test_a_switched_off_source_cannot_be_chosen_for_new_leads_but_stays_on_old_ones(): void
    {
        $id = $this->newLead(['source' => 'Justdial'])->json('data.id');
        $justdial = $this->source('Justdial');

        $this->putJson("/api/settings/lead-sources/{$justdial['id']}", ['is_active' => false])->assertOk();

        $this->newLead(['source' => 'Justdial'])->assertStatus(422);
        $this->assertNotContains('Justdial', $this->getJson('/api/leads/options')->json('data.sources'));
        $this->putJson("/api/leads/{$id}", ['city' => 'Surat'])->assertOk()->assertJsonPath('data.source', 'Justdial');     // untouched source: no complaint
        $this->putJson("/api/leads/{$id}", ['source' => 'justdial'])->assertOk()->assertJsonPath('data.source', 'Justdial');  // unchanged, any case
        $this->assertSame('Justdial', Lead::find($id)->source);

        $this->putJson("/api/settings/lead-sources/{$justdial['id']}", ['is_active' => true])->assertOk();
        $this->newLead(['source' => 'Justdial'])->assertStatus(201);
    }

    public function test_renaming_a_source_follows_the_leads_and_keeps_the_dashboard_grouping(): void
    {
        $svc = app(LeadService::class);
        $a = $svc->create($this->company->id, ['contact_name' => 'A', 'source' => 'Referral']);
        $b = $svc->create($this->company->id, ['contact_name' => 'B', 'source' => 'Referral']);
        $b->delete();                                       // a soft-deleted lead is renamed too, so restoring it stays consistent
        $svc->changeStatus($a, 'won');
        $other = Company::factory()->create();
        $theirs = $svc->create($other->id, ['contact_name' => 'C', 'source' => 'Referral']);

        $this->putJson('/api/settings/lead-sources/' . $this->source('Referral')['id'], ['name' => 'Customer referral'])->assertOk();

        $this->assertSame('Customer referral', $a->fresh()->source);
        $this->assertSame('Customer referral', Lead::withTrashed()->find($b->id)->source);
        $this->assertSame('Referral', $theirs->fresh()->source, 'Another company is untouched.');
        $row = collect($this->getJson('/api/leads/dashboard')->json('data.by_source'))->firstWhere('source', 'Customer referral');
        $this->assertSame(1, $row['total']);
        $this->assertSame(1, $row['won']);
    }

    public function test_a_source_in_use_is_switched_off_not_deleted_and_the_built_in_one_stays(): void
    {
        $this->newLead(['source' => 'Phone']);
        $this->postJson('/api/settings/lead-sources', ['name' => 'Radio Ad'])->assertOk();

        $res = $this->deleteJson('/api/settings/lead-sources/' . $this->source('Phone')['id'])->assertStatus(422);
        $this->assertStringContainsString('1 lead uses "Phone"', $res->json('message'));
        $this->assertStringContainsString('Switch it off instead', $res->json('message'));

        $this->deleteJson('/api/settings/lead-sources/' . $this->source('Radio Ad')['id'])->assertOk();
        $this->assertNull($this->source('Radio Ad'));

        $other = $this->source('Other');
        $this->deleteJson("/api/settings/lead-sources/{$other['id']}")->assertStatus(422);
        $this->putJson("/api/settings/lead-sources/{$other['id']}", ['is_active' => false])->assertStatus(422);
        $this->putJson("/api/settings/lead-sources/{$other['id']}", ['name' => 'Misc'])->assertStatus(422);
    }

    public function test_lost_reasons_are_a_list_and_a_chosen_reason_is_stored_by_name_with_its_id(): void
    {
        $price = collect($this->lists()['lost_reasons'])->firstWhere('name', 'Price too high');
        $lead = $this->newLead()->json('data.id');

        $this->postJson("/api/leads/{$lead}/status", ['status' => 'lost', 'lost_reason_id' => $price['id'], 'lost_note' => 'Quoted 12% above a local maker'])->assertOk();

        $fresh = Lead::find($lead);
        $this->assertSame('Price too high', $fresh->lost_reason, 'The name is what reports group on.');
        $this->assertSame($price['id'], (int) $fresh->lost_reason_id);
        $this->assertSame('Quoted 12% above a local maker', $fresh->lost_note);
        $this->assertSame('Status: new → lost (reason: Price too high — Quoted 12% above a local maker)',
            \App\Models\LeadActivity::where('lead_id', $lead)->latest('id')->value('description'));
        $top = $this->getJson('/api/leads/dashboard')->json('data.lost_reasons');
        $this->assertSame([['reason' => 'Price too high', 'count' => 1]], $top);
    }

    public function test_free_text_lost_reasons_still_work_and_an_unknown_reason_id_is_refused(): void
    {
        $lead = $this->newLead()->json('data.id');

        $this->postJson("/api/leads/{$lead}/status", ['status' => 'lost', 'lost_reason_id' => 999999])->assertStatus(422);
        $this->postJson("/api/leads/{$lead}/status", ['status' => 'lost', 'lost_reason' => 'Went with a friend'])->assertOk();

        $this->assertSame('Went with a friend', Lead::find($lead)->lost_reason);
        $this->assertNull(Lead::find($lead)->lost_reason_id);

        $old = collect($this->lists()['lost_reasons'])->firstWhere('name', 'No response');
        $this->putJson("/api/settings/lead-lost-reasons/{$old['id']}", ['is_active' => false])->assertOk();
        $again = $this->newLead()->json('data.id');
        $this->postJson("/api/leads/{$again}/status", ['status' => 'lost', 'lost_reason_id' => $old['id']])->assertStatus(422);
    }

    public function test_renaming_a_lost_reason_follows_the_leads_that_chose_it(): void
    {
        $r = collect($this->lists()['lost_reasons'])->firstWhere('name', 'Chose a competitor');
        $lead = $this->newLead()->json('data.id');
        $this->postJson("/api/leads/{$lead}/status", ['status' => 'lost', 'lost_reason_id' => $r['id']])->assertOk();

        $this->putJson("/api/settings/lead-lost-reasons/{$r['id']}", ['name' => 'Went to a competitor'])->assertOk();

        $this->assertSame('Went to a competitor', Lead::find($lead)->lost_reason);
        $this->postJson('/api/settings/lead-lost-reasons', ['name' => 'Went to a competitor'])->assertStatus(422);
        $this->deleteJson("/api/settings/lead-lost-reasons/{$r['id']}")->assertStatus(422);
    }

    public function test_each_company_has_its_own_lists(): void
    {
        $this->postJson('/api/settings/lead-sources', ['name' => 'Our Own Channel'])->assertOk();
        $them = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);

        $this->actingAs($them, 'sanctum');

        $this->assertNull($this->source('Our Own Channel'));
        $this->newLead(['source' => 'Our Own Channel'])->assertStatus(422);
        $mine = DB::table('lead_sources')->where('company_id', $this->company->id)->where('name', 'Our Own Channel')->value('id');
        $this->putJson("/api/settings/lead-sources/{$mine}", ['name' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson("/api/settings/lead-sources/{$mine}")->assertStatus(404);
        $this->assertSame('Our Own Channel', DB::table('lead_sources')->where('id', $mine)->value('name'));
    }

    public function test_anyone_in_settings_can_read_the_lists_but_changing_them_needs_settings_manage(): void
    {
        $reader = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false,
            'role_id' => Role::factory()->for($this->company)->create(['permissions' => ['leads.view']])->id]);
        $this->actingAs($reader, 'sanctum');

        $this->getJson('/api/settings/lead-lists')->assertOk();
        $this->getJson('/api/leads/options')->assertOk();
        $this->postJson('/api/settings/lead-sources', ['name' => 'Sneaky'])->assertStatus(403);
        $this->deleteJson('/api/settings/lead-sources/1')->assertStatus(403);

        $manager = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false,
            'role_id' => Role::factory()->for($this->company)->create(['permissions' => ['settings.manage']])->id]);
        $this->actingAs($manager, 'sanctum');
        $this->postJson('/api/settings/lead-sources', ['name' => 'Allowed'])->assertOk();
    }

    public function test_the_lead_form_gets_its_drop_downs_from_the_options_endpoint(): void
    {
        $o = $this->getJson('/api/leads/options')->assertOk()->json('data');

        $this->assertSame(LeadListService::DEFAULT_SOURCES, $o['sources']);
        $this->assertSame(['new', 'contacted', 'qualified', 'quoted', 'won', 'lost'], $o['statuses']);
        $this->assertSame('Price too high', $o['lost_reasons'][0]['name']);
        $this->assertArrayHasKey('id', $o['lost_reasons'][0]);
    }
}
