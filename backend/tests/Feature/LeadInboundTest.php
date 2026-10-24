<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadImportLog;
use App\Models\LeadInboundToken;
use App\Models\LeadPayload;
use App\Models\User;
use App\Services\LeadInboundService;
use App\Services\LeadIntakeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** LEADS_PLAN 2.3: anything outside can create a lead safely. */
class LeadInboundTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);
    }

    /** Make a token the way the screen does, and give back the plain token. */
    private function makeToken(?Company $company = null, string $source = 'Website'): string
    {
        $company ??= $this->company;
        [, $plain] = app(LeadInboundService::class)->createToken($company->id, 'Website form', $source);

        return $plain;
    }

    private function send(string $token, array $body = [], array $headers = [])
    {
        return $this->postJson("/api/inbound/leads/{$token}", $body + ['name' => 'Ravi Patel', 'mobile' => '9876543210', 'message' => 'Need 300 sqm PUF'], $headers);
    }

    // ── tokens ───────────────────────────────────────────────────────────────

    public function test_a_token_is_long_random_and_only_its_hash_is_stored(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $res = $this->postJson('/api/settings/lead-inbound-tokens', ['name' => 'Website form', 'source' => 'website'])->assertStatus(201);

        $plain = $res->json('data.token');
        $this->assertMatchesRegularExpression('/^lt_[A-Za-z0-9]{48}$/', $plain);
        $this->assertSame("/api/inbound/leads/{$plain}", $res->json('data.path'));
        $this->assertSame('Website', $res->json('data.source'), 'The company\'s spelling of the source.');
        $row = DB::table('lead_inbound_tokens')->first();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertSame(substr($plain, -4), $row->token_hint);
        $this->assertStringNotContainsString($plain, json_encode($row), 'The plain token is nowhere in the database.');
        $this->assertArrayNotHasKey('token_hash', $res->json('data'));
    }

    public function test_the_list_never_shows_the_token_or_its_hash(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $plain = $this->postJson('/api/settings/lead-inbound-tokens', ['name' => 'A', 'source' => 'Website'])->json('data.token');

        $body = $this->getJson('/api/settings/lead-inbound-tokens')->assertOk()->getContent();

        $this->assertStringNotContainsString($plain, $body);
        $this->assertStringNotContainsString('token_hash', $body);
        $this->assertStringContainsString(substr($plain, -4), $body);
    }

    public function test_the_source_must_be_one_of_the_companys_sources(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/settings/lead-inbound-tokens', ['name' => 'A', 'source' => 'Smoke Signals'])->assertStatus(422)->assertJsonValidationErrors('source');
        $this->postJson('/api/settings/lead-inbound-tokens', ['source' => 'Website'])->assertStatus(422);
    }

    public function test_only_someone_who_can_manage_settings_makes_tokens(): void
    {
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false]), 'sanctum');

        $this->postJson('/api/settings/lead-inbound-tokens', ['name' => 'A', 'source' => 'Website'])->assertStatus(403);
    }

    public function test_regenerating_replaces_the_token_at_once(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $made = $this->postJson('/api/settings/lead-inbound-tokens', ['name' => 'A', 'source' => 'Website'])->json('data');

        $new = $this->postJson("/api/settings/lead-inbound-tokens/{$made['id']}/regenerate")->assertOk()->json('data.token');

        $this->assertNotSame($made['token'], $new);
        $this->send($made['token'])->assertStatus(401);
        $this->send($new)->assertStatus(201);
    }

    public function test_a_token_can_be_switched_off_and_on_and_deleted(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $made = $this->postJson('/api/settings/lead-inbound-tokens', ['name' => 'A', 'source' => 'Website'])->json('data');

        $this->putJson("/api/settings/lead-inbound-tokens/{$made['id']}", ['is_active' => false])->assertOk();
        $this->send($made['token'])->assertStatus(401);
        $this->putJson("/api/settings/lead-inbound-tokens/{$made['id']}", ['is_active' => true, 'source' => 'phone'])->assertOk()->assertJsonPath('data.source', 'Phone');
        $this->send($made['token'])->assertStatus(201);
        $this->deleteJson("/api/settings/lead-inbound-tokens/{$made['id']}")->assertStatus(204);
        $this->send($made['token'])->assertStatus(401);
    }

    // ── posting ──────────────────────────────────────────────────────────────

    public function test_a_valid_token_creates_a_lead_for_its_company_under_its_source_and_keeps_the_message(): void
    {
        $token = $this->makeToken(source: 'Exhibition');

        $res = $this->send($token, ['external_id' => 'F-1', 'campaign' => 'Expo 2027', 'city' => 'Surat'])->assertStatus(201);

        $res->assertJsonPath('data.outcome', 'created');
        $lead = Lead::where('lead_no', $res->json('data.lead_no'))->first();
        $this->assertSame($this->company->id, $lead->company_id);
        $this->assertSame(['Exhibition', 'Ravi Patel', '9876543210', 'Need 300 sqm PUF', 'F-1', 'Expo 2027', 'Surat'],
            [$lead->source, $lead->contact_name, $lead->phone, $lead->requirement, $lead->external_id, $lead->campaign, $lead->city]);
        $this->assertSame('Ravi Patel', $lead->payloads()->first()->payload['name'], 'The message as it arrived.');
        $this->assertNotNull(LeadInboundToken::first()->last_used_at);
    }

    public function test_the_intake_names_work_too_and_a_form_post_works(): void
    {
        $token = $this->makeToken();

        $this->postJson("/api/inbound/leads/{$token}", ['contact_name' => 'Meera', 'phone' => '9811111111', 'requirement' => 'Roof'])->assertStatus(201);
        $this->post("/api/inbound/leads/{$token}", ['name' => 'Form Person', 'mobile' => '9822222222'], ['Accept' => 'application/json'])->assertStatus(201);

        $this->assertSame(['Meera', 'Form Person'], Lead::orderBy('id')->pluck('contact_name')->all());
    }

    public function test_a_token_for_one_company_never_creates_or_touches_another_companys_leads(): void
    {
        $other = Company::factory()->create();
        $theirs = $this->makeToken($other);
        $mine = $this->makeToken();
        $this->send($theirs, ['external_id' => 'X-1'])->assertStatus(201);

        $this->send($mine, ['external_id' => 'X-1', 'mobile' => '9899999999', 'name' => 'Different'])->assertStatus(201);   // same id, own company: a different enquiry

        $this->assertSame(1, Lead::withoutGlobalScope('tenant')->where('company_id', $other->id)->count());
        $this->assertSame(1, Lead::withoutGlobalScope('tenant')->where('company_id', $this->company->id)->count());
        $this->assertSame(2, LeadImportLog::withoutGlobalScope('tenant')->count());
        $this->assertSame(1, LeadImportLog::withoutGlobalScope('tenant')->where('company_id', $other->id)->count());
    }

    public function test_another_companys_admin_cannot_see_or_change_a_token_or_its_log(): void
    {
        $other = Company::factory()->create();
        $theirs = LeadInboundToken::withoutGlobalScope('tenant')->where('company_id', $other->id);
        $this->makeToken($other);
        $this->send($this->makeToken($other));
        $id = $theirs->value('id');
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/settings/lead-inbound-tokens/{$id}", ['is_active' => false])->assertStatus(404);
        $this->postJson("/api/settings/lead-inbound-tokens/{$id}/regenerate")->assertStatus(404);
        $this->deleteJson("/api/settings/lead-inbound-tokens/{$id}")->assertStatus(404);
        $this->assertCount(0, $this->getJson('/api/settings/lead-inbound-tokens')->json('data'));
        $this->assertCount(0, $this->getJson('/api/settings/lead-import-logs')->json('data.logs'));
    }

    // ── refusals ─────────────────────────────────────────────────────────────

    public function test_wrong_malformed_and_missing_tokens_get_a_clear_401_and_make_nothing(): void
    {
        $this->makeToken();

        $this->send('lt_' . str_repeat('a', 48))->assertStatus(401)->assertJsonPath('error_code', 'INVALID_TOKEN');
        $this->send('short')->assertStatus(401);
        $this->send('lt_' . str_repeat('%', 48))->assertStatus(401);

        $this->assertSame(0, Lead::count());
    }

    public function test_a_token_of_a_switched_off_company_does_not_work(): void
    {
        $token = $this->makeToken();
        $this->company->forceFill(['is_active' => false])->save();

        $this->send($token)->assertStatus(401);
    }

    public function test_a_message_over_the_size_cap_is_refused_with_413_and_logged(): void
    {
        $token = $this->makeToken();

        $this->send($token, ['message' => str_repeat('x', 70000)])->assertStatus(413)->assertJsonPath('error_code', 'PAYLOAD_TOO_LARGE');

        $this->assertSame(0, Lead::count());
        $this->assertSame('too_large', LeadImportLog::first()->outcome);
    }

    public function test_the_hidden_field_makes_it_look_like_success_but_creates_nothing(): void
    {
        $token = $this->makeToken();

        $this->send($token, ['_hp' => 'http://spam.example'])->assertStatus(200)->assertJsonPath('data.outcome', 'received');

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, LeadPayload::count());
        $this->assertSame('spam', LeadImportLog::first()->outcome);
    }

    public function test_a_message_that_cannot_be_used_is_a_422_and_logged_with_why(): void
    {
        $token = $this->makeToken();

        $this->postJson("/api/inbound/leads/{$token}", ['name' => 'Ghost'])->assertStatus(422)->assertJsonPath('error_code', 'REJECTED');

        $log = LeadImportLog::first();
        $this->assertSame('rejected', $log->outcome);
        $this->assertStringContainsString('phone', $log->reason);
    }

    public function test_an_empty_body_does_not_crash(): void
    {
        $token = $this->makeToken();

        $this->call('POST', "/api/inbound/leads/{$token}", [], [], [], ['CONTENT_TYPE' => 'application/json'], '{not json')->assertStatus(422);
    }

    public function test_a_failure_inside_is_a_plain_500_that_leaks_nothing_and_is_logged(): void
    {
        $token = $this->makeToken();
        $this->mock(LeadIntakeService::class, fn ($m) => $m->shouldReceive('ingest')->andThrow(new \RuntimeException('secret internal detail')));

        $res = $this->send($token)->assertStatus(500);

        $this->assertStringNotContainsString('secret internal detail', $res->getContent());
        $this->assertSame('error', LeadImportLog::first()->outcome);
    }

    // ── retries and repeats ──────────────────────────────────────────────────

    public function test_sending_the_same_enquiry_id_again_is_safe(): void
    {
        $token = $this->makeToken();

        $this->send($token, ['external_id' => 'F-9'])->assertStatus(201);
        $this->send($token, ['external_id' => 'F-9'])->assertStatus(200)->assertJsonPath('data.outcome', 'already_received');

        $this->assertSame(1, Lead::count());
        $this->assertSame(['created', 'already_received'], LeadImportLog::orderBy('id')->pluck('outcome')->all());
    }

    public function test_the_same_buyer_again_is_added_to_the_existing_lead(): void
    {
        $token = $this->makeToken();
        $first = $this->send($token)->json('data.lead_no');

        $this->send($token, ['message' => 'Also roof sheets'])->assertStatus(200)->assertJsonPath('data.outcome', 'merged')->assertJsonPath('data.lead_no', $first);

        $this->assertSame(1, Lead::count());
    }

    // ── rate limits ──────────────────────────────────────────────────────────

    public function test_a_token_is_limited_to_60_messages_a_minute_with_a_clear_429(): void
    {
        $token = $this->makeToken();
        for ($i = 0; $i < 60; $i++) {
            $this->postJson("/api/inbound/leads/{$token}", ['name' => 'x'])->assertStatus(422);       // rejected, but it counts
        }

        $res = $this->postJson("/api/inbound/leads/{$token}", ['name' => 'x'])->assertStatus(429);

        $res->assertJsonPath('error_code', 'RATE_LIMITED');
        $this->assertNotNull($res->headers->get('Retry-After'));
    }

    public function test_another_token_is_not_slowed_by_the_first_ones_limit(): void
    {
        $a = $this->makeToken();
        $b = $this->makeToken();
        for ($i = 0; $i < 61; $i++) {
            $this->postJson("/api/inbound/leads/{$a}", ['name' => 'x']);
        }

        $this->send($b)->assertStatus(201);
    }

    public function test_guessing_tokens_is_slowed_per_address_but_real_senders_are_never_caught_by_it(): void
    {
        $real = $this->makeToken();
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/inbound/leads/lt_' . str_pad((string) $i, 48, 'z'), ['name' => 'x'])->assertStatus(401);
        }

        $this->postJson('/api/inbound/leads/lt_' . str_repeat('q', 48), ['name' => 'x'])->assertStatus(429)->assertJsonPath('error_code', 'RATE_LIMITED');
        // Even from the same address, a valid token still works: a flood of wrong guesses cannot block real senders.
        $this->send($real)->assertStatus(201);
    }

    // ── the log ──────────────────────────────────────────────────────────────

    public function test_the_log_screen_data_has_a_seven_day_summary_and_the_newest_first(): void
    {
        $token = $this->makeToken();
        $this->send($token, ['external_id' => '1'])->assertStatus(201);
        $this->send($token, ['external_id' => '1']);
        $this->postJson("/api/inbound/leads/{$token}", ['name' => 'Ghost']);
        $this->actingAs($this->admin, 'sanctum');

        $d = $this->getJson('/api/settings/lead-import-logs')->assertOk()->json('data');

        $this->assertEquals(['created' => 1, 'already_received' => 1, 'rejected' => 1], $d['summary']);
        $this->assertSame(['rejected', 'already_received', 'created'], array_column($d['logs'], 'outcome'));
        $this->assertNotNull($d['logs'][2]['lead_no']);
        $this->assertCount(1, $this->getJson('/api/settings/lead-import-logs?outcome=rejected')->json('data.logs'));
    }

    public function test_log_rows_older_than_90_days_are_pruned_by_the_hourly_scan(): void
    {
        $token = $this->makeToken();
        $this->send($token);
        $old = LeadImportLog::first()->replicate();
        $old->created_at = now()->subDays(100);
        $old->save();

        $this->artisan('notifications:scan')->assertSuccessful();

        $this->assertSame(1, LeadImportLog::count());
    }
}
