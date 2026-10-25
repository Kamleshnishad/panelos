<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAssignmentRule;
use App\Models\LeadConnector;
use App\Models\LeadImportLog;
use App\Models\LeadPayload;
use App\Models\User;
use App\Services\IndiaMartClient;
use App\Services\IndiaMartMapper;
use App\Services\IndiaMartPullService;
use App\Services\LeadIntakeService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** LEADS_PLAN 3.2: the IndiaMART pull job. Everything runs against a fake IndiaMART. */
class IndiaMartPullTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function connector(?Company $company = null, bool $on = true, string $key = self::KEY): LeadConnector
    {
        $c = LeadConnector::forCompany(($company ?? $this->company)->id);
        $c->setKey($key);
        $c->forceFill(['is_enabled' => $on])->save();

        return $c;
    }

    private function row(string $id, array $over = []): array
    {
        return $over + ['UNIQUE_QUERY_ID' => $id, 'QUERY_TYPE' => 'W', 'QUERY_TIME' => '2026-11-10 13:50:00', 'SENDER_NAME' => 'Ravi Patel', 'SENDER_MOBILE' => '+91-9876543210',
            'SENDER_EMAIL' => 'ravi@zephyr.test', 'SENDER_COMPANY' => 'Zephyr Cold Storage', 'SENDER_CITY' => 'Rajkot', 'QUERY_PRODUCT_NAME' => 'PUF Wall Panel', 'QUERY_MESSAGE' => 'Need 300 sqm'];
    }

    /** @var list<array> what the fake IndiaMART answers with right now; a test can change it between pulls */
    private array $answer = [];

    private function fake(array ...$rows): void
    {
        $this->answer = $rows;
        // Registered once: a second Http::fake() would sit behind the first, so the stub reads $this->answer instead.
        Http::fake(['mapi.indiamart.com/*' => fn () => Http::response(['CODE' => 200, 'STATUS' => 'SUCCESS', 'MESSAGE' => '', 'TOTAL_RECORDS' => count($this->answer), 'RESPONSE' => $this->answer], 200)]);
    }

    private function pull(?LeadConnector $c = null): array
    {
        return app(IndiaMartPullService::class)->pullSinceLast($c ?? LeadConnector::withoutGlobalScope('tenant')->first());
    }

    private function queries(): array
    {
        $out = [];
        Http::assertSent(function (Request $r) use (&$out) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $out[] = $q;

            return true;
        });

        return $out;
    }

    // ── the mapper ───────────────────────────────────────────────────────────

    public function test_a_row_is_mapped_to_the_intake_shape(): void
    {
        $m = IndiaMartMapper::map($this->row('Q1', ['QUERY_TYPE' => 'B']));

        $this->assertSame(['Q1', 'Ravi Patel', 'Zephyr Cold Storage', '+91-9876543210', 'ravi@zephyr.test', 'Rajkot', 'Buy-lead'],
            [$m['external_id'], $m['contact_name'], $m['company_name'], $m['phone'], $m['email'], $m['city'], $m['campaign']]);
        $this->assertSame('Product: PUF Wall Panel. Need 300 sqm', $m['requirement']);
        $this->assertSame('2026-11-10T08:20:00+00:00', $m['first_seen_at'], 'IndiaMART times are Indian time: 13:50 IST is 08:20 UTC.');
    }

    public function test_their_placeholder_name_is_dropped_and_alternative_spellings_are_found(): void
    {
        $m = IndiaMartMapper::map(['UNIQUE_QUERY_ID' => 'Q2', 'SENDER_NAME' => 'IndiaMART Buyer', 'SENDER_MOBILE_ALT' => '9811111111', 'SENDER_EMAIL_ALT' => 'a@b.test',
            'SENDER_COMPANY_NAME' => 'Acme', 'QUERY_TYPE' => 'P']);

        $this->assertNull($m['contact_name']);
        $this->assertSame(['9811111111', 'a@b.test', 'Acme', 'Call (PNS)'], [$m['phone'], $m['email'], $m['company_name'], $m['campaign']]);
        $this->assertNull($m['requirement']);
    }

    public function test_the_different_time_spellings_all_give_the_same_moment(): void
    {
        foreach (['2026-11-10 13:50:00', '10-Nov-2026 13:50:00', '10-Nov-202613:50:00', '10-11-202613:50:00', '2026-11-10T13:50:00+05:30', '1794298800'] as $t) {
            $this->assertSame('2026-11-10T08:20:00+00:00', IndiaMartMapper::map(['QUERY_TIME' => $t])['first_seen_at'], $t);
        }
        $this->assertNull(IndiaMartMapper::map(['QUERY_TIME' => 'last tuesday-ish'])['first_seen_at']);
        $this->assertNull(IndiaMartMapper::map([])['first_seen_at']);
    }

    public function test_odd_values_do_not_break_the_mapper(): void
    {
        $m = IndiaMartMapper::map(['UNIQUE_QUERY_ID' => ['x'], 'SENDER_NAME' => true, 'SENDER_MOBILE' => '   ', 'QUERY_MESSAGE' => null]);

        $this->assertNull($m['external_id']);
        $this->assertNull($m['contact_name']);
        $this->assertNull($m['phone']);
    }

    // ── a pull ───────────────────────────────────────────────────────────────

    public function test_a_pull_makes_leads_from_enquiries_with_the_raw_row_kept_and_the_cursor_moved(): void
    {
        $this->connector();
        $row = $this->row('Q100');
        $this->fake($row);

        $r = $this->pull();

        $this->assertSame('ok', $r['status']);
        $this->assertSame(1, $r['created']);
        $lead = Lead::withoutGlobalScope('tenant')->first();
        $this->assertSame($this->company->id, $lead->company_id);
        $this->assertSame(['IndiaMART', 'Q100', 'Direct enquiry', 'Rajkot', 'Ravi Patel'], [$lead->source, $lead->external_id, $lead->campaign, $lead->city, $lead->contact_name]);
        $this->assertSame('2026-11-10 08:20:00', $lead->first_seen_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertEquals($row, LeadPayload::withoutGlobalScope('tenant')->first()->payload, 'The row exactly as IndiaMART sent it.');
        $c = LeadConnector::withoutGlobalScope('tenant')->first();
        $this->assertEquals(now()->toDateTimeString(), $c->cursor_at->toDateTimeString());
        $this->assertNotNull($c->last_success_at);
        $this->assertNull($c->last_error);
        $this->assertSame('created', LeadImportLog::withoutGlobalScope('tenant')->first()->outcome);
    }

    public function test_the_first_pull_asks_for_the_last_24_hours_and_the_next_from_the_cursor_less_the_overlap(): void
    {
        $this->connector();
        $this->fake();
        $this->pull();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:12:00', 'UTC'));
        $this->pull();

        $q = $this->queries();
        $this->assertCount(2, $q);
        $this->assertSame(['09-11-202614:30:00', '10-11-202614:30:00'], [$q[0]['start_time'], $q[0]['end_time']], 'The first time: the last 24 hours, in Indian time.');
        $this->assertSame(['10-11-202614:25:00', '10-11-202614:42:00'], [$q[1]['start_time'], $q[1]['end_time']], 'Then from the last success minus 5 minutes, to now.');
        $this->assertSame(self::KEY, $q[1]['glusr_crm_key']);
    }

    public function test_the_overlap_repeats_are_recognised_and_nothing_doubles_or_floods_the_log(): void
    {
        $this->connector();
        $this->fake($this->row('Q1'), $this->row('Q2', ['SENDER_MOBILE' => '9822222222', 'SENDER_EMAIL' => 'b@b.test', 'SENDER_NAME' => 'Meera']));
        $first = $this->pull();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:11:00', 'UTC'));

        $again = $this->pull();

        $this->assertSame(2, $first['created']);
        $this->assertSame(['created' => 0, 'already_received' => 2], ['created' => $again['created'], 'already_received' => $again['already_received']]);
        $this->assertSame(2, Lead::withoutGlobalScope('tenant')->count());
        $this->assertSame(2, LeadImportLog::withoutGlobalScope('tenant')->count(), 'Repeats are not logged.');
    }

    public function test_a_known_buyer_with_a_new_enquiry_is_added_to_their_lead_and_the_assignee_is_told(): void
    {
        $ben = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Ben']);
        LeadAssignmentRule::create(['company_id' => $this->company->id, 'name' => 'All', 'priority' => 1, 'is_active' => true, 'user_ids' => [$ben->id]]);
        $this->connector();
        $this->fake($this->row('Q1'));
        $this->pull();
        AppNotification::withoutGlobalScope('tenant')->delete();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:20:00', 'UTC'));
        $this->fake($this->row('Q2', ['QUERY_MESSAGE' => 'Also roof sheets']));

        $r = $this->pull();

        $this->assertSame(1, $r['merged']);
        $this->assertSame(1, Lead::withoutGlobalScope('tenant')->count());
        $lead = Lead::withoutGlobalScope('tenant')->first();
        $this->assertSame($ben->id, $lead->assigned_to_user_id);
        $this->assertTrue(LeadActivity::where('lead_id', $lead->id)->where('description', 'like', 'Enquired again on IndiaMART (ref Q2)%')->exists());
        $this->assertSame(1, AppNotification::withoutGlobalScope('tenant')->where('user_id', $ben->id)->where('type', 'lead_assigned')->count());
    }

    public function test_an_enquiry_with_no_way_to_reach_the_buyer_is_rejected_and_the_rest_carry_on(): void
    {
        $this->connector();
        $this->fake($this->row('BAD', ['SENDER_MOBILE' => null, 'SENDER_EMAIL' => null]), $this->row('OK'));

        $r = $this->pull();

        $this->assertSame(['created' => 1, 'rejected' => 1], ['created' => $r['created'], 'rejected' => $r['rejected']]);
        $bad = LeadImportLog::withoutGlobalScope('tenant')->where('external_id', 'BAD')->first();
        $this->assertSame('rejected', $bad->outcome);
        $this->assertStringContainsString('phone', $bad->reason);
        $this->assertSame(1, Lead::withoutGlobalScope('tenant')->count());
    }

    public function test_one_enquiry_that_blows_up_does_not_stop_the_others(): void
    {
        $this->connector();
        $this->app->bind(LeadIntakeService::class, fn ($app) => new class($app->make(\App\Services\LeadService::class), $app->make(\App\Services\LeadMatcher::class), $app->make(\App\Services\LeadListService::class)) extends LeadIntakeService {
            public function ingest(int $companyId, string $source, array $data, ?array $raw = null): \App\Services\LeadIntakeResult
            {
                if (($data['external_id'] ?? null) === 'BOOM') {
                    throw new \RuntimeException('database exploded');
                }

                return parent::ingest($companyId, $source, $data, $raw);
            }
        });
        $this->fake($this->row('BOOM'), $this->row('OK', ['SENDER_MOBILE' => '9833333333', 'SENDER_EMAIL' => 'c@c.test']));

        $r = $this->pull(LeadConnector::withoutGlobalScope('tenant')->first());

        $this->assertSame(['ok', 1, 1], [$r['status'], $r['created'], $r['errors']]);
        $log = LeadImportLog::withoutGlobalScope('tenant')->where('outcome', 'error')->first();
        $this->assertStringNotContainsString('exploded', (string) $log->reason);
    }

    // ── limits, failures, windows ────────────────────────────────────────────

    public function test_a_run_within_five_minutes_of_the_last_request_is_skipped_without_calling_indiamart(): void
    {
        $c = $this->connector();
        $c->forceFill(['last_request_at' => now()->subMinutes(3)])->save();
        Http::fake();

        $r = $this->pull($c);

        $this->assertSame('skipped', $r['status']);
        Http::assertNothingSent();
    }

    public function test_a_failed_request_is_recorded_leaves_the_cursor_and_does_not_throw(): void
    {
        $c = $this->connector();
        $c->forceFill(['cursor_at' => now()->subHour()])->save();
        Http::fake(['mapi.indiamart.com/*' => Http::response(['CODE' => 401, 'MESSAGE' => 'Invalid key'], 200)]);

        $r = $this->pull($c);

        $this->assertSame('failed', $r['status']);
        $fresh = $c->fresh();
        $this->assertStringContainsString('did not accept this key', $fresh->last_error);
        $this->assertNotNull($fresh->last_error_at);
        $this->assertSame(now()->subHour()->toDateTimeString(), $fresh->cursor_at->toDateTimeString(), 'The next run covers the gap.');
        $this->assertSame('pull_error', LeadImportLog::withoutGlobalScope('tenant')->first()->outcome);
        $this->assertStringNotContainsString(self::KEY, json_encode(LeadImportLog::withoutGlobalScope('tenant')->get()) . $fresh->last_error);
    }

    public function test_a_success_after_a_failure_clears_the_error(): void
    {
        $c = $this->connector();
        $c->forceFill(['last_error' => 'earlier trouble', 'last_error_at' => now()->subHour()])->save();
        $this->fake();

        $this->pull($c);

        $this->assertNull($c->fresh()->last_error);
    }

    public function test_after_a_long_silence_the_window_is_cut_to_seven_days(): void
    {
        $c = $this->connector();
        $c->forceFill(['cursor_at' => now()->subDays(10)])->save();
        $this->fake();

        $this->pull($c);

        $q = $this->queries()[0];
        $this->assertSame('03-11-202614:31:00', $q['start_time']);
        $this->assertSame('10-11-202614:30:00', $q['end_time']);
    }

    public function test_two_pulls_for_the_same_company_never_run_together(): void
    {
        $c = $this->connector();
        $lock = Cache::lock("lead-pull:indiamart:{$c->company_id}", 60);
        $lock->get();
        Http::fake();

        $r = $this->pull($c);

        $this->assertSame('skipped', $r['status']);
        Http::assertNothingSent();
        $lock->release();
    }

    // ── the scheduled command ────────────────────────────────────────────────

    public function test_the_command_is_scheduled_every_ten_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'leads:pull-indiamart'));

        $this->assertNotNull($event, 'Registered in routes/console.php.');
        $this->assertSame('*/10 * * * *', $event->expression);
    }

    public function test_the_command_pulls_only_switched_on_companies_with_a_key_and_each_with_its_own_key(): void
    {
        $other = Company::factory()->create();
        $off = Company::factory()->create();
        $gone = Company::factory()->create(['is_active' => false]);
        $this->connector();
        $this->connector($other, true, 'zzzzOtherCompanyKey1234');
        $this->connector($off, false, 'offOffOffOffOff1234');
        $this->connector($gone, true, 'inactiveCompanyKey12345');
        $this->fake($this->row('Q1'));

        $this->artisan('leads:pull-indiamart')->assertSuccessful();

        $keys = array_column($this->queries(), 'glusr_crm_key');
        sort($keys);
        $this->assertSame([self::KEY, 'zzzzOtherCompanyKey1234'], $keys);
        $this->assertSame(1, Lead::withoutGlobalScope('tenant')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, Lead::withoutGlobalScope('tenant')->where('company_id', $off->id)->count());
    }

    public function test_one_companys_trouble_does_not_stop_the_next_one(): void
    {
        $other = Company::factory()->create();
        $this->connector();
        $this->connector($other, true, 'zzzzOtherCompanyKey1234');
        Http::fake(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q['glusr_crm_key'] === self::KEY
                ? Http::response('<html>down</html>', 500)
                : Http::response(['CODE' => 200, 'STATUS' => 'SUCCESS', 'RESPONSE' => [$this->row('Q9')]], 200);
        });

        $this->artisan('leads:pull-indiamart')->assertSuccessful();

        $this->assertSame(1, Lead::withoutGlobalScope('tenant')->where('company_id', $other->id)->count());
    }

    // ── switching on, and pulling a range by hand ────────────────────────────

    public function test_the_import_can_only_be_switched_on_with_a_key_and_clearing_the_key_switches_it_off(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson('/api/settings/lead-connectors/indiamart', ['is_enabled' => true])->assertStatus(422)->assertJsonValidationErrors('is_enabled');
        $this->putJson('/api/settings/lead-connectors/indiamart', ['crm_key' => self::KEY])->assertOk();
        $this->putJson('/api/settings/lead-connectors/indiamart', ['is_enabled' => true])->assertOk()->assertJsonPath('data.is_enabled', true);
        $this->putJson('/api/settings/lead-connectors/indiamart', ['clear_key' => true])->assertOk()->assertJsonPath('data.is_enabled', false);
    }

    public function test_a_range_pulled_by_hand_covers_whole_indian_days_and_leaves_the_automatic_cursor_alone(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $c = $this->connector();
        $c->forceFill(['cursor_at' => now()->subHour()])->save();
        $this->fake($this->row('Q1'));

        $res = $this->postJson('/api/settings/lead-connectors/indiamart/pull', ['from' => '2026-11-08', 'to' => '2026-11-09'])->assertOk();

        $res->assertJsonPath('data.created', 1)->assertJsonPath('data.ok', true);
        $q = $this->queries()[0];
        $this->assertSame(['08-11-202600:00:00', '09-11-202623:59:59'], [$q['start_time'], $q['end_time']]);
        $this->assertSame(now()->subHour()->toDateTimeString(), $c->fresh()->cursor_at->toDateTimeString());
    }

    public function test_a_range_that_is_too_long_future_too_old_or_too_soon_is_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $c = $this->connector();
        Http::fake();

        $this->postJson('/api/settings/lead-connectors/indiamart/pull', ['from' => '2026-10-01', 'to' => '2026-10-20'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->postJson('/api/settings/lead-connectors/indiamart/pull', ['from' => '2026-12-01', 'to' => '2026-12-02'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->postJson('/api/settings/lead-connectors/indiamart/pull', ['from' => '2025-01-01', 'to' => '2025-01-02'])->assertStatus(422)->assertJsonValidationErrors('from');
        $c->forceFill(['last_request_at' => now()->subMinute()])->save();
        $this->postJson('/api/settings/lead-connectors/indiamart/pull', ['from' => '2026-11-09', 'to' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('pull');

        Http::assertNothingSent();
    }

    public function test_a_range_needs_a_key_and_the_settings_permission(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/settings/lead-connectors/indiamart/pull', ['from' => '2026-11-09', 'to' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('crm_key');

        $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false]), 'sanctum');
        $this->postJson('/api/settings/lead-connectors/indiamart/pull', ['from' => '2026-11-09', 'to' => '2026-11-10'])->assertStatus(403);
    }

    public function test_the_key_is_never_in_what_the_screen_gets_back_from_a_pull(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->connector();
        $this->fake($this->row('Q1'));

        $body = $this->postJson('/api/settings/lead-connectors/indiamart/pull', ['from' => '2026-11-09', 'to' => '2026-11-10'])->assertOk()->getContent()
            . $this->getJson('/api/settings/lead-connectors/indiamart')->getContent();

        $this->assertStringNotContainsString(self::KEY, $body);
    }

    public function test_the_client_still_refuses_a_window_over_seven_days(): void
    {
        Http::fake();
        $this->expectException(\App\Services\IndiaMartException::class);

        app(IndiaMartClient::class)->fetch(self::KEY, now()->subDays(8), now());
    }
}
