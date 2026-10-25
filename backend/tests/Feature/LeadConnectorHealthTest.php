<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\LeadConnector;
use App\Models\User;
use App\Services\IndiaMartPullService;
use App\Services\LeadConnectorHealth;
use App\Services\LeadConnectorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** LEADS_PLAN 3.3: the IndiaMART link tells someone when it stops bringing enquiries in. */
class LeadConnectorHealthTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private const KEY = 'mR5xQ2LpA9kT7vZ3nB1cD8eF';

    private Company $company;
    private User $admin;

    /** @var array what the fake IndiaMART answers with right now */
    private array $answer = [];

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

    private function connector(array $over = [], ?Company $company = null, bool $key = true): LeadConnector
    {
        $c = LeadConnector::forCompany(($company ?? $this->company)->id);
        $c->setKey($key ? self::KEY : null);
        $c->forceFill($over + ['is_enabled' => true, 'enabled_at' => now()->subDay(), 'key_saved_at' => now()->subDay(), 'last_success_at' => now()->subMinutes(10)])->save();

        return $c->fresh();
    }

    private function state(LeadConnector $c): string
    {
        return LeadConnectorHealth::assess($c)['state'];
    }

    private function sync(?Company $company = null): void
    {
        app(LeadConnectorHealth::class)->sync(($company ?? $this->company)->id);
    }

    private function bell(?User $u = null)
    {
        return AppNotification::withoutGlobalScope('tenant')->where('user_id', ($u ?? $this->admin)->id)->where('type', LeadConnectorHealth::TYPE)->get();
    }

    // ── the verdict ──────────────────────────────────────────────────────────

    public function test_a_connector_pulling_regularly_is_ok(): void
    {
        $this->assertSame('ok', $this->state($this->connector()));
    }

    public function test_no_key_is_not_set_up_and_never_alerts(): void
    {
        $c = $this->connector(['is_enabled' => false, 'last_success_at' => null], key: false);

        $this->assertSame('not_set_up', $this->state($c));
        $this->sync();
        $this->assertCount(0, $this->bell());
    }

    public function test_no_good_pull_for_an_hour_is_stale(): void
    {
        $this->assertSame('ok', $this->state($this->connector(['last_success_at' => now()->subMinutes(59)])));

        $c = $this->connector(['last_success_at' => now()->subMinutes(61)]);
        $v = LeadConnectorHealth::assess($c);

        $this->assertSame(['stale', 'danger'], [$v['state'], $v['severity']]);
        $this->assertStringContainsString('last good pull was 10 Nov, 13:29', $v['body']);        // 07:59 UTC = 13:29 India
    }

    public function test_stale_names_the_last_problem(): void
    {
        $c = $this->connector(['last_success_at' => now()->subHours(3), 'last_error_at' => now()->subMinutes(10), 'last_error' => 'Could not reach IndiaMART. Check again in a few minutes.', 'last_error_kind' => 'unreachable']);

        $v = LeadConnectorHealth::assess($c);

        $this->assertSame('stale', $v['state']);
        $this->assertStringContainsString('Could not reach IndiaMART', $v['body']);
    }

    public function test_a_rejected_key_is_its_own_alert_and_says_what_to_do(): void
    {
        $c = $this->connector(['last_error_at' => now()->subMinutes(3), 'last_error' => 'IndiaMART did not accept this key.', 'last_error_kind' => 'bad_key']);

        $v = LeadConnectorHealth::assess($c);

        $this->assertSame(['key_rejected', 'danger'], [$v['state'], $v['severity']]);
        $this->assertStringContainsString('Make a new key', $v['body']);
    }

    public function test_one_unreachable_blip_is_not_an_alert(): void
    {
        $c = $this->connector(['last_error_at' => now()->subMinutes(3), 'last_error' => 'Could not reach IndiaMART.', 'last_error_kind' => 'unreachable']);

        $this->assertSame('ok', $this->state($c));
    }

    public function test_switched_on_and_not_yet_pulled_waits_then_goes_stale_after_an_hour(): void
    {
        $this->assertSame('waiting', $this->state($this->connector(['last_success_at' => null, 'enabled_at' => now()->subMinutes(20)])));
        $this->assertSame('stale', $this->state($this->connector(['last_success_at' => null, 'enabled_at' => now()->subMinutes(90)])));
    }

    public function test_a_key_that_works_but_is_switched_off_is_just_off(): void
    {
        $this->assertSame('off', $this->state($this->connector(['is_enabled' => false, 'last_success_at' => now()->subDays(2)])));
    }

    public function test_a_switched_off_key_unused_for_five_days_warns_with_the_stop_date(): void
    {
        $v = LeadConnectorHealth::assess($this->connector(['is_enabled' => false, 'last_success_at' => now()->subDays(5)]));

        $this->assertSame(['idle_soon', 'warning'], [$v['state'], $v['severity']]);
        $this->assertStringContainsString('stops on 12 Nov', $v['body']);                  // used 5 Nov + 7 days
        $this->assertSame('off', $this->state($this->connector(['is_enabled' => false, 'last_success_at' => now()->subDays(4)])));
    }

    public function test_a_key_saved_and_never_tried_counts_from_the_day_it_was_saved(): void
    {
        $this->assertSame('off', $this->state($this->connector(['is_enabled' => false, 'last_success_at' => null, 'key_saved_at' => now()->subDays(3)])));
        $this->assertSame('idle_soon', $this->state($this->connector(['is_enabled' => false, 'last_success_at' => null, 'key_saved_at' => now()->subDays(6)])));
    }

    public function test_a_test_that_worked_counts_as_use(): void
    {
        $c = $this->connector(['is_enabled' => false, 'last_success_at' => null, 'key_saved_at' => now()->subDays(9), 'last_test_ok' => true, 'last_test_at' => now()->subDay()]);
        $this->assertSame('off', $this->state($c));

        $c = $this->connector(['last_test_ok' => false, 'last_test_at' => now()->subHour()]);        // a failed test is not use
        $this->assertSame('ok', $this->state($c));
        $c->forceFill(['is_enabled' => false, 'last_success_at' => null, 'key_saved_at' => now()->subDays(9)])->save();
        $this->assertSame('idle_soon', $this->state($c->fresh()));
    }

    public function test_a_key_past_seven_days_says_it_may_already_have_stopped(): void
    {
        $v = LeadConnectorHealth::assess($this->connector(['is_enabled' => false, 'last_success_at' => now()->subDays(8)]));

        $this->assertStringContainsString('may already have stopped', $v['body']);
    }

    public function test_a_stale_connector_that_is_also_close_to_the_idle_limit_says_so(): void
    {
        $v = LeadConnectorHealth::assess($this->connector(['last_success_at' => now()->subDays(6)]));

        $this->assertSame('stale', $v['state']);
        $this->assertStringContainsString('stops on 11 Nov', $v['body']);
    }

    // ── the bell ─────────────────────────────────────────────────────────────

    public function test_the_alert_goes_to_company_admins_only_and_links_to_master_data(): void
    {
        $staff = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false]);
        $this->connector(['last_success_at' => now()->subHours(2)]);

        $this->sync();

        $this->assertCount(1, $this->bell());
        $this->assertCount(0, $this->bell($staff));
        $n = $this->bell()->first();
        $this->assertSame(['danger', 'master'], [$n->severity, $n->link_section]);
        $this->assertSame('No IndiaMART enquiries pulled for over an hour', $n->title);
    }

    public function test_running_it_again_changes_nothing_and_a_read_alert_stays_read(): void
    {
        $this->connector(['last_success_at' => now()->subHours(2)]);
        $this->sync();
        $this->bell()->first()->forceFill(['read_at' => now()])->save();

        Carbon::setTestNow(now()->addMinutes(30));              // the wording has no "hours ago", so it does not change
        $this->sync();
        $this->sync();

        $this->assertCount(1, $this->bell());
        $this->assertNotNull($this->bell()->first()->read_at);
    }

    public function test_the_alert_clears_by_itself_once_a_pull_succeeds(): void
    {
        $c = $this->connector(['last_success_at' => now()->subHours(2)]);
        $this->sync();
        $this->assertCount(1, $this->bell());

        $c->forceFill(['last_success_at' => now()])->save();
        $this->sync();

        $this->assertCount(0, $this->bell());
    }

    public function test_a_different_problem_replaces_the_alert_rather_than_adding_one(): void
    {
        $c = $this->connector(['last_success_at' => now()->subHours(2)]);
        $this->sync();

        $c->forceFill(['last_error_at' => now(), 'last_error' => 'IndiaMART did not accept this key.', 'last_error_kind' => 'bad_key'])->save();
        $this->sync();

        $this->assertCount(1, $this->bell());
        $this->assertSame('IndiaMART did not accept the key', $this->bell()->first()->title);
    }

    public function test_the_idle_warning_is_a_warning_not_an_alarm(): void
    {
        $this->connector(['is_enabled' => false, 'last_success_at' => now()->subDays(6)]);

        $this->sync();

        $this->assertSame('warning', $this->bell()->first()->severity);
    }

    public function test_another_company_does_not_see_this_companys_alert(): void
    {
        $other = Company::factory()->create();
        $otherAdmin = User::factory()->create(['company_id' => $other->id, 'is_company_admin' => true]);
        $this->connector(['last_success_at' => now()->subHours(2)]);

        $this->sync();
        $this->sync($other);

        $this->assertCount(0, $this->bell($otherAdmin));
    }

    public function test_the_hourly_scan_raises_the_alert(): void
    {
        $this->connector(['last_success_at' => now()->subHours(2)]);

        $this->artisan('notifications:scan')->assertExitCode(0);

        $this->assertCount(1, $this->bell());
    }

    // ── what the pull records ────────────────────────────────────────────────

    public function test_a_refused_key_during_a_pull_is_recorded_as_such_and_a_good_pull_clears_it(): void
    {
        $c = $this->connector(['last_success_at' => null, 'last_error_at' => null, 'last_request_at' => null]);
        // Registered once (a second Http::fake() would sit behind the first), so the stub reads $this->answer.
        $this->answer = ['CODE' => 401, 'STATUS' => 'FAILURE', 'MESSAGE' => 'Invalid key'];
        Http::fake(['mapi.indiamart.com/*' => fn () => Http::response($this->answer, 200)]);

        app(IndiaMartPullService::class)->pullSinceLast($c);

        $c = $c->fresh();
        $this->assertSame('bad_key', $c->last_error_kind);
        $this->assertSame('key_rejected', $this->state($c));

        Carbon::setTestNow(now()->addMinutes(6));
        $this->answer = ['CODE' => 200, 'STATUS' => 'SUCCESS', 'MESSAGE' => '', 'TOTAL_RECORDS' => 0, 'RESPONSE' => []];
        app(IndiaMartPullService::class)->pullSinceLast($c->fresh());

        $c = $c->fresh();
        $this->assertNull($c->last_error_kind);
        $this->assertSame('ok', $this->state($c));
    }

    public function test_saving_a_new_key_forgets_the_refusal_and_restarts_the_idle_clock(): void
    {
        $c = $this->connector(['last_error_at' => now(), 'last_error' => 'IndiaMART did not accept this key.', 'last_error_kind' => 'bad_key', 'key_saved_at' => now()->subDays(30)]);

        app(LeadConnectorService::class)->saveIndiaMart($this->company->id, 'nEwKeyFromIndiaMart99');

        $c = $c->fresh();
        $this->assertNull($c->last_error_kind);
        $this->assertSame(now()->toDateTimeString(), $c->key_saved_at->toDateTimeString());
        $this->assertSame('ok', $this->state($c));
    }

    public function test_switching_on_stamps_the_time_and_switching_off_clears_it(): void
    {
        $c = $this->connector(['is_enabled' => false, 'enabled_at' => null, 'last_success_at' => null]);
        $svc = app(LeadConnectorService::class);

        $svc->setEnabled($this->company->id, true);
        $this->assertSame(now()->toDateTimeString(), $c->fresh()->enabled_at->toDateTimeString());

        Carbon::setTestNow(now()->addMinutes(5));
        $svc->setEnabled($this->company->id, true);              // already on: the clock is not restarted
        $this->assertSame(now()->subMinutes(5)->toDateTimeString(), $c->fresh()->enabled_at->toDateTimeString());

        $svc->setEnabled($this->company->id, false);
        $this->assertNull($c->fresh()->enabled_at);
    }

    public function test_removing_the_key_clears_the_health_fields(): void
    {
        $this->connector(['last_error_at' => now(), 'last_error' => 'x', 'last_error_kind' => 'bad_key']);

        $c = app(LeadConnectorService::class)->saveIndiaMart($this->company->id, null, clear: true)->fresh();

        $this->assertSame([null, null, null, null], [$c->last_error_kind, $c->enabled_at, $c->key_saved_at, $c->last_error_at]);
        $this->assertSame('not_set_up', $this->state($c));
    }

    // ── screens and /api/health ──────────────────────────────────────────────

    public function test_the_settings_screen_gets_the_verdict(): void
    {
        $this->connector(['last_success_at' => now()->subHours(2)]);
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/settings/lead-connectors/indiamart')->assertOk()
            ->assertJsonPath('data.health.state', 'stale')
            ->assertJsonPath('data.health.title', 'No IndiaMART enquiries pulled for over an hour');
    }

    public function test_health_has_a_line_for_the_pull_job(): void
    {
        $this->getJson('/api/health')->assertJsonPath('lead_pull', 'NONE');

        $this->connector(['last_request_at' => now()->subMinutes(10)]);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('lead_pull', 'OK');
    }

    public function test_health_goes_503_when_a_switched_on_connector_has_not_been_asked_for_an_hour(): void
    {
        $this->connector(['last_request_at' => now()->subMinutes(90)]);

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('status', 'DEGRADED')->assertJsonPath('lead_pull', 'STALE');
    }

    public function test_a_refused_key_alone_does_not_page_the_operator(): void
    {
        // The job is running (it asked 5 minutes ago and was refused); fixing the key is the company's job and shows in their bell.
        $this->connector(['last_request_at' => now()->subMinutes(5), 'last_success_at' => now()->subDays(1), 'last_error_at' => now()->subMinutes(5), 'last_error_kind' => 'bad_key']);

        $this->getJson('/api/health')->assertOk()->assertJsonPath('lead_pull', 'OK');
    }

    public function test_a_switched_off_connector_is_not_expected_to_pull(): void
    {
        $this->connector(['is_enabled' => false, 'last_request_at' => now()->subDays(3)]);

        $this->getJson('/api/health')->assertOk()->assertJsonPath('lead_pull', 'NONE');
    }
}
