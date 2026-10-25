<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadConnector;
use App\Models\LeadPayload;
use App\Models\User;
use App\Services\IndiaMartClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** LEADS_PLAN 3.1: the IndiaMART key is kept safely and can be tested without importing anything. Everything runs against a fake IndiaMART. */
class IndiaMartConnectionTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private const KEY = 'mR5xQ2LpA9kT7vZ3nB1cD8eF';

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'UTC'));       // 14:30 in India
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function saveKey(string $key = self::KEY)
    {
        return $this->putJson('/api/settings/lead-connectors/indiamart', ['crm_key' => $key]);
    }

    private function fakeIndiaMart(array $body, int $status = 200): void
    {
        Http::fake(['mapi.indiamart.com/*' => Http::response($body, $status)]);
    }

    private function row(string $id): array
    {
        return ['UNIQUE_QUERY_ID' => $id, 'QUERY_TYPE' => 'W', 'SENDER_NAME' => 'Ravi', 'SENDER_MOBILE' => '+91-9876543210', 'QUERY_MESSAGE' => 'Need PUF panels'];
    }

    // ── keeping the key ──────────────────────────────────────────────────────

    public function test_the_key_is_stored_encrypted_and_never_returned(): void
    {
        $res = $this->saveKey()->assertOk();

        $this->assertTrue($res->json('data.configured'));
        $this->assertSame(substr(self::KEY, -4), $res->json('data.key_hint'));
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $this->assertStringNotContainsString(self::KEY, $this->getJson('/api/settings/lead-connectors/indiamart')->assertOk()->getContent());
        $raw = DB::table('lead_connectors')->first();
        $this->assertStringNotContainsString(self::KEY, json_encode($raw), 'Not in the database in the clear.');
        $this->assertSame(self::KEY, LeadConnector::withoutGlobalScope('tenant')->first()->key(), 'It can still be read back by the server.');
    }

    public function test_a_blank_key_keeps_the_saved_one_and_clearing_removes_it(): void
    {
        $this->saveKey();

        $this->putJson('/api/settings/lead-connectors/indiamart', ['crm_key' => ''])->assertOk()->assertJsonPath('data.configured', true);
        $this->putJson('/api/settings/lead-connectors/indiamart', ['clear_key' => true])->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.key_hint', null);

        $this->assertNull(LeadConnector::withoutGlobalScope('tenant')->first()->key());
    }

    public function test_a_key_that_does_not_look_like_one_is_refused(): void
    {
        $this->saveKey('short')->assertStatus(422)->assertJsonValidationErrors('crm_key');
        $this->saveKey('has some spaces in it here')->assertStatus(422);
        $this->saveKey('<script>alert(1)</script>')->assertStatus(422);
        $this->assertFalse($this->getJson('/api/settings/lead-connectors/indiamart')->json('data.configured'));
    }

    public function test_only_someone_who_manages_settings_can_save_or_test(): void
    {
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false]), 'sanctum');

        $this->saveKey()->assertStatus(403);
        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertStatus(403);
    }

    public function test_each_company_has_its_own_key(): void
    {
        $this->saveKey();
        $other = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);
        $this->actingAs($other, 'sanctum');

        $this->assertFalse($this->getJson('/api/settings/lead-connectors/indiamart')->json('data.configured'));
        $this->saveKey('zzzzzzzzzzzzzzzzzzzz')->assertOk();

        $this->assertSame(2, LeadConnector::withoutGlobalScope('tenant')->count());
        $this->assertSame(self::KEY, LeadConnector::withoutGlobalScope('tenant')->where('company_id', $this->company->id)->first()->key());
    }

    // ── testing it ───────────────────────────────────────────────────────────

    public function test_a_test_asks_for_the_last_six_hours_in_indian_time_counts_and_imports_nothing(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 200, 'STATUS' => 'SUCCESS', 'MESSAGE' => '', 'TOTAL_RECORDS' => 2, 'RESPONSE' => [$this->row('A1'), $this->row('A2')]]);

        $res = $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk();

        $res->assertJsonPath('data.ok', true)->assertJsonPath('data.count', 2);
        $this->assertStringContainsString('2 enquiries from the last 6 hours', $res->json('data.message'));
        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_starts_with($r->url(), IndiaMartClient::URL)
                && $q['glusr_crm_key'] === self::KEY
                && $q['start_time'] === '10-11-202608:30:00'
                && $q['end_time'] === '10-11-202614:30:00';
        });
        $this->assertSame(0, Lead::withoutGlobalScope('tenant')->count());
        $this->assertSame(0, LeadPayload::withoutGlobalScope('tenant')->count());
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $c = LeadConnector::withoutGlobalScope('tenant')->first();
        $this->assertTrue($c->last_test_ok);
        $this->assertStringContainsString('Connected', $c->last_test_message);
    }

    public function test_a_key_with_no_recent_enquiries_is_still_connected(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 200, 'STATUS' => 'SUCCESS', 'MESSAGE' => 'No data found', 'TOTAL_RECORDS' => 0, 'RESPONSE' => []]);

        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.count', 0);
    }

    public function test_a_friendly_success_message_is_never_mistaken_for_an_error(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 200, 'STATUS' => 'SUCCESS', 'MESSAGE' => 'Keys verified, rate within limit, please wait for more', 'RESPONSE' => [$this->row('X')]]);

        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertJsonPath('data.ok', true)->assertJsonPath('data.count', 1);
    }

    public function test_a_key_indiamart_does_not_accept_gets_a_readable_message(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 401, 'STATUS' => 'FAILURE', 'MESSAGE' => 'Invalid CRM key']);

        $res = $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk();

        $res->assertJsonPath('data.ok', false)->assertJsonPath('data.count', null);
        $this->assertStringContainsString('did not accept this key', $res->json('data.message'));
        $this->assertStringContainsString('7 days', $res->json('data.message'));
        $this->assertFalse(LeadConnector::withoutGlobalScope('tenant')->first()->last_test_ok);
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
    }

    public function test_an_http_401_is_a_bad_key_even_with_no_body(): void
    {
        $this->saveKey();
        Http::fake(['mapi.indiamart.com/*' => Http::response('Forbidden', 403)]);

        $this->assertStringContainsString('did not accept this key', $this->postJson('/api/settings/lead-connectors/indiamart/test')->json('data.message'));
    }

    public function test_indiamart_saying_too_many_requests_is_explained(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 429, 'STATUS' => 'FAILURE', 'MESSAGE' => 'There is a restriction of minimum 5 minutes between requests']);

        $this->assertStringContainsString('at least 5 minutes between requests', $this->postJson('/api/settings/lead-connectors/indiamart/test')->json('data.message'));
    }

    public function test_an_answer_that_is_not_json_is_reported_not_crashed_on(): void
    {
        $this->saveKey();
        Http::fake(['mapi.indiamart.com/*' => Http::response('<html>maintenance</html>', 200)]);

        $res = $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk();

        $res->assertJsonPath('data.ok', false);
        $this->assertStringContainsString('could not read', $res->json('data.message'));
    }

    public function test_an_unknown_refusal_shows_their_words_with_the_key_blanked(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 500, 'STATUS' => 'FAILURE', 'MESSAGE' => 'Something odd with ' . self::KEY . ' happened']);

        $msg = $this->postJson('/api/settings/lead-connectors/indiamart/test')->json('data.message');

        $this->assertStringNotContainsString(self::KEY, $msg);
    }

    public function test_when_indiamart_cannot_be_reached_the_message_has_no_address_and_no_key(): void
    {
        $this->saveKey();
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: mapi.indiamart.com?glusr_crm_key=' . self::KEY));

        $res = $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk();

        $res->assertJsonPath('data.ok', false);
        $this->assertStringContainsString('Could not reach IndiaMART', $res->json('data.message'));
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $this->assertStringNotContainsString(self::KEY, (string) LeadConnector::withoutGlobalScope('tenant')->first()->last_test_message);
    }

    public function test_testing_without_a_key_says_to_save_one_first_and_calls_nobody(): void
    {
        Http::fake();

        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertStatus(422)->assertJsonValidationErrors('crm_key');

        Http::assertNothingSent();
    }

    public function test_a_second_test_within_five_minutes_is_refused_before_calling_indiamart(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 200, 'STATUS' => 'SUCCESS', 'RESPONSE' => []]);
        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk();

        $res = $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertStatus(422);

        $this->assertStringContainsString('one request every 5 minutes', $res->json('message'));
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::parse('2026-11-10 09:05:01', 'UTC'));
        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_a_failed_test_still_counts_toward_the_five_minute_gap(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 401, 'MESSAGE' => 'Invalid key']);
        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk();

        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertStatus(422);

        Http::assertSentCount(1);
    }

    public function test_saving_a_different_key_forgets_the_old_test_result(): void
    {
        $this->saveKey();
        $this->fakeIndiaMart(['CODE' => 200, 'STATUS' => 'SUCCESS', 'RESPONSE' => []]);
        $this->postJson('/api/settings/lead-connectors/indiamart/test')->assertOk();
        $this->assertTrue($this->getJson('/api/settings/lead-connectors/indiamart')->json('data.last_test_ok'));

        $this->saveKey('AnotherKey1234567890')->assertOk()->assertJsonPath('data.last_test_ok', null);
    }

    // ── the client on its own ────────────────────────────────────────────────

    public function test_times_are_formatted_in_indian_time_the_way_indiamart_wants(): void
    {
        $this->assertSame('01-01-202216:30:00', IndiaMartClient::formatTime(Carbon::parse('2022-01-01 11:00:00', 'UTC')));
    }

    public function test_a_window_over_seven_days_or_backwards_is_refused_before_any_request(): void
    {
        Http::fake();
        $client = app(IndiaMartClient::class);

        foreach ([[now()->subDays(8), now()], [now(), now()->subHour()]] as [$from, $to]) {
            try {
                $client->fetch(self::KEY, $from, $to);
                $this->fail('Should have refused.');
            } catch (\App\Services\IndiaMartException $e) {
                $this->assertSame('rejected', $e->kind);
            }
        }
        Http::assertNothingSent();
    }
}
