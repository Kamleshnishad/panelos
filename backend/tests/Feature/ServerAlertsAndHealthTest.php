<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\AlertService;
use App\Support\SystemHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * EN-03 / EN-04: the health endpoint tells an uptime monitor when the scheduler or the app is failing, and a server
 * error e-mails the operator (throttled, switchable, never a second error).
 */
class ServerAlertsAndHealthTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** Messages the (array) mail transport has been handed in this test. */
    private function mailed(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    public function test_health_is_ok_while_the_scheduler_beats_and_unknown_right_after_a_deploy(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('scheduler', 'UNKNOWN')->assertJsonPath('status', 'OK');

        SystemHealth::heartbeat();
        $this->getJson('/api/health')->assertOk()->assertJsonPath('scheduler', 'OK')->assertJsonPath('database', 'OK');
    }

    public function test_health_goes_503_when_the_scheduler_has_stopped(): void
    {
        Cache::put('health:scheduler', now()->subMinutes(10)->timestamp);

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('status', 'DEGRADED')->assertJsonPath('scheduler', 'STALE');
    }

    public function test_health_goes_503_on_a_spike_of_server_errors(): void
    {
        for ($i = 0; $i < SystemHealth::ERROR_SPIKE; $i++) {
            SystemHealth::recordError();
        }

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('errors_last_hour', SystemHealth::ERROR_SPIKE);
    }

    public function test_health_reports_backups_and_goes_503_when_they_have_stopped(): void
    {
        SystemHealth::heartbeat();
        $this->getJson('/api/health')->assertOk()->assertJsonPath('backup', 'UNKNOWN');

        SystemHealth::markBackup();
        $this->getJson('/api/health')->assertOk()->assertJsonPath('backup', 'OK');

        Cache::forever('health:backup', now()->subHours(40)->timestamp);
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('status', 'DEGRADED')->assertJsonPath('backup', 'STALE');
    }

    public function test_the_file_cache_stays_writable_for_whoever_writes_next(): void
    {
        // PB-062: a cache/lock file written by root locked the scheduler (user panelos) out of every withoutOverlapping task.
        $this->assertSame(0666, config('cache.stores.file.permission'));
    }

    public function test_the_scheduler_actually_has_a_heartbeat_task(): void
    {
        $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();
        $this->assertContains('health-heartbeat', $names);
    }

    public function test_a_real_server_error_counts_and_e_mails_the_super_admin_once_per_quarter_hour(): void
    {
        $admin = $this->createAdminUser(['email' => 'boss@example.test']);
        Route::get('/api/__boom', fn () => throw new \RuntimeException('kaboom'));

        $this->getJson('/api/__boom')->assertStatus(500);
        $this->getJson('/api/__boom')->assertStatus(500);

        $this->assertCount(1, $this->mailed(), 'The second error inside the throttle window must not send another e-mail.');
        $msg = $this->mailed()[0]->getOriginalMessage();
        $this->assertSame('boss@example.test', $msg->getTo()[0]->getAddress());
        $this->assertStringContainsString('kaboom', $msg->getBody()->bodyToString());
        $this->assertSame(2, $this->getJson('/api/health')->json('errors_last_hour'));
    }

    public function test_client_mistakes_are_not_server_errors(): void
    {
        $this->createAdminUser();
        $this->getJson('/api/quotations')->assertStatus(401);
        $this->getJson('/api/no-such-route')->assertStatus(404);

        $this->assertCount(0, $this->mailed());
        $this->assertSame(0, $this->getJson('/api/health')->json('errors_last_hour'));
    }

    public function test_the_switch_the_log_mailer_and_a_saved_address_are_respected(): void
    {
        $this->createAdminUser(['email' => 'boss@example.test']);

        PlatformSetting::current()->update(['alerts_enabled' => false]);
        AlertService::serverError(new \RuntimeException('x'));
        $this->assertCount(0, $this->mailed(), 'Switched off: nothing is sent.');
        $this->assertSame(1, $this->getJson('/api/health')->json('errors_last_hour'), 'Errors are still counted for /api/health.');

        PlatformSetting::current()->update(['alerts_enabled' => true]);
        config(['mail.default' => 'log']);
        AlertService::serverError(new \RuntimeException('x'));
        $this->assertCount(0, $this->mailed(), 'The log mailer delivers nothing, so do not pretend.');

        config(['mail.default' => 'array']);
        PlatformSetting::current()->update(['alert_email' => 'ops@example.test']);
        AlertService::serverError(new \RuntimeException('x'));
        $this->assertSame('ops@example.test', $this->mailed()[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_a_failing_mail_server_never_turns_an_error_into_a_second_one(): void
    {
        $this->createAdminUser();
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('smtp down'));

        AlertService::serverError(new \RuntimeException('original'));

        $this->assertTrue(true, 'serverError() returned instead of throwing.');
    }

    public function test_the_super_admin_can_switch_alerts_set_the_address_and_send_a_test(): void
    {
        $admin = $this->createAdminUser(['email' => 'boss@example.test']);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings')->assertOk();
        $this->assertTrue($res->json('data.alerts_enabled'), 'Alerts default to on.');
        $this->assertSame(['boss@example.test'], $res->json('data.alert_recipients'));
        $this->assertSame('OK', $res->json('data.health.database'));

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', ['alerts_enabled' => false, 'alert_email' => 'ops@example.test'])->assertOk();
        $this->assertFalse(PlatformSetting::current()->alerts_enabled);
        $this->assertSame('ops@example.test', PlatformSetting::current()->alert_email);

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', ['alert_email' => 'not-an-email'])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/settings/test-alert')->assertOk();
        $this->assertSame('ops@example.test', $this->mailed()[0]->getOriginalMessage()->getTo()[0]->getAddress(), 'A test goes out even while alerts are switched off.');

        config(['mail.default' => 'log']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/settings/test-alert')->assertStatus(422)->assertJsonPath('error_code', 'MAIL_NOT_SET_UP');
    }

    public function test_a_company_admin_cannot_use_the_platform_alert_settings(): void
    {
        $user = \App\Models\User::factory()->create(['company_id' => \App\Models\Company::factory()->create()->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/admin/settings/test-alert')->assertStatus(403);
    }
}
