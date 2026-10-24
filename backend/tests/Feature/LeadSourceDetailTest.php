<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadPayload;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/** LEADS_PLAN 2.1: where did this lead come from, exactly? */
class LeadSourceDetailTest extends TestCase
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

    private function lead(array $over = [], ?Company $company = null): Lead
    {
        return app(LeadService::class)->create(($company ?? $this->company)->id, $over + ['contact_name' => 'Ravi Patel', 'source' => 'IndiaMART']);
    }

    public function test_the_source_detail_is_stored_with_the_lead(): void
    {
        $lead = $this->lead([
            'external_id' => 'IM-778899', 'campaign' => 'Cold room panels', 'utm_source' => 'google', 'utm_medium' => 'cpc',
            'utm_campaign' => 'winter', 'utm_term' => 'puf panel', 'utm_content' => 'ad-2', 'first_seen_at' => '2026-11-02 10:15:00',
        ]);

        $fresh = Lead::find($lead->id);
        $this->assertSame('IM-778899', $fresh->external_id);
        $this->assertSame('Cold room panels', $fresh->campaign);
        $this->assertSame(['google', 'cpc', 'winter', 'puf panel', 'ad-2'], [$fresh->utm_source, $fresh->utm_medium, $fresh->utm_campaign, $fresh->utm_term, $fresh->utm_content]);
        $this->assertSame('2026-11-02 10:15:00', $fresh->first_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_manual_leads_have_none_of_it_and_there_can_be_any_number_of_them(): void
    {
        $a = $this->lead(['source' => 'Phone']);
        $b = $this->lead(['source' => 'Phone']);

        $this->assertNull($a->fresh()->external_id);
        $this->assertNull($b->fresh()->external_id);
        $this->assertNull($a->fresh()->first_seen_at);
    }

    public function test_the_same_enquiry_id_cannot_become_two_leads_from_the_same_source(): void
    {
        $this->lead(['external_id' => 'IM-1']);

        $this->expectException(QueryException::class);
        Lead::create(['company_id' => $this->company->id, 'lead_no' => 'LEAD-X-0099', 'contact_name' => 'Twice', 'source' => 'IndiaMART', 'external_id' => 'IM-1', 'status' => 'new']);
    }

    public function test_the_same_id_is_fine_from_another_source_or_another_company(): void
    {
        $this->lead(['external_id' => 'X-1', 'source' => 'IndiaMART']);

        $other = $this->lead(['external_id' => 'X-1', 'source' => 'TradeIndia']);
        $theirs = $this->lead(['external_id' => 'X-1', 'source' => 'IndiaMART'], Company::factory()->create());

        $this->assertNotNull($other->id);
        $this->assertNotNull($theirs->id);
    }

    public function test_the_lead_screens_cannot_set_the_system_fields(): void
    {
        $id = $this->postJson('/api/leads', ['contact_name' => 'Manual', 'external_id' => 'HACK', 'campaign' => 'x', 'utm_source' => 'y', 'first_seen_at' => '2020-01-01'])->assertStatus(201)->json('data.id');
        $this->putJson("/api/leads/{$id}", ['external_id' => 'HACK2', 'campaign' => 'z'])->assertOk();

        $l = Lead::find($id);
        $this->assertNull($l->external_id);
        $this->assertNull($l->campaign);
        $this->assertNull($l->utm_source);
        $this->assertNull($l->first_seen_at);
    }

    public function test_the_lead_detail_carries_the_source_detail(): void
    {
        $lead = $this->lead(['external_id' => 'IM-5', 'campaign' => 'Roof sheets']);

        $this->getJson("/api/leads/{$lead->id}")->assertOk()->assertJsonPath('data.external_id', 'IM-5')->assertJsonPath('data.campaign', 'Roof sheets');
    }

    // ── the raw message ──────────────────────────────────────────────────────

    private function payload(Lead $lead, array $raw = ['QUERY_ID' => '778899', 'SENDER_NAME' => 'Ravi', 'QUERY_MESSAGE' => 'Need 500 sqm PUF']): LeadPayload
    {
        return LeadPayload::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'source' => $lead->source, 'external_id' => $lead->external_id, 'payload' => $raw]);
    }

    public function test_the_raw_message_is_kept_exactly_and_hangs_off_the_lead(): void
    {
        $lead = $this->lead(['external_id' => 'IM-6']);
        $raw = ['QUERY_ID' => 'IM-6', 'nested' => ['a' => 1, 'b' => [true, null, 'ü']], 'QUERY_MESSAGE' => "Line 1\nLine 2"];

        $this->payload($lead, $raw);

        $this->assertEquals($raw, $lead->payloads()->first()->payload);
        $this->assertSame(1, $lead->payloads()->count());
    }

    public function test_the_payload_endpoint_returns_the_messages_for_that_lead_only(): void
    {
        $a = $this->lead(['external_id' => 'A']);
        $b = $this->lead(['external_id' => 'B']);
        $this->payload($a, ['who' => 'a']);
        $this->payload($b, ['who' => 'b']);

        $res = $this->getJson("/api/leads/{$a->id}/payloads")->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertEquals(['who' => 'a'], $res->json('data.0.payload'));
    }

    public function test_the_raw_message_is_not_part_of_the_lead_detail_or_the_list(): void
    {
        $lead = $this->lead(['external_id' => 'IM-7']);
        $this->payload($lead, ['SECRET_FIELD' => 'raw']);

        $this->assertStringNotContainsString('SECRET_FIELD', $this->getJson("/api/leads/{$lead->id}")->getContent());
        $this->assertStringNotContainsString('SECRET_FIELD', $this->getJson('/api/leads')->getContent());
    }

    public function test_another_companys_lead_payloads_are_not_reachable(): void
    {
        $other = Company::factory()->create();
        $theirs = $this->lead(['external_id' => 'T'], $other);
        $this->payload($theirs);

        $this->getJson("/api/leads/{$theirs->id}/payloads")->assertStatus(404);
    }

    public function test_deleting_a_lead_keeps_what_it_arrived_as(): void
    {
        $lead = $this->lead(['external_id' => 'IM-8']);
        $this->payload($lead);

        $this->deleteJson("/api/leads/{$lead->id}")->assertStatus(204);

        $this->assertSame(1, LeadPayload::where('lead_id', $lead->id)->count());
    }

    public function test_a_message_can_be_kept_without_a_lead_for_one_that_was_rejected(): void
    {
        $p = LeadPayload::create(['company_id' => $this->company->id, 'lead_id' => null, 'source' => 'IndiaMART', 'external_id' => 'BAD-1', 'payload' => ['broken' => true]]);

        $this->assertNull($p->fresh()->lead_id);
        $this->assertNotNull($p->fresh()->received_at);
    }
}
