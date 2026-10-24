<?php

namespace Tests\Feature;

use App\Mail\PaymentReminderMailable;
use App\Models\Dispatch;
use App\Models\NotificationSetting;
use App\Models\PaymentReminder;
use App\Services\InvoiceService;
use App\Services\PaymentReminderService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The admin side of e-mail: see whether the server can really deliver mail, send a test, and switch the automatic
 * customer reminder e-mails on or off (off unless an admin opts in).
 */
class MailSettingsTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_the_settings_report_whether_mail_can_be_delivered_and_reminders_default_to_off(): void
    {
        $admin = $this->createAdminUser();

        config(['mail.default' => 'log']);
        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/settings/notifications')->assertOk();
        $this->assertFalse($res->json('data.mail.delivers'));
        $this->assertStringContainsString('NOT set up', $res->json('data.mail.headline'));
        $this->assertFalse($res->json('data.email_payment_reminders'), 'Automatic customer e-mails must be opt-in.');

        config(['mail.default' => 'smtp']);
        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/settings/notifications')->assertOk();
        $this->assertTrue($res->json('data.mail.delivers'));
        $this->assertArrayNotHasKey('password', $res->json('data.mail'), 'Credentials must never reach the browser.');
    }

    public function test_an_admin_can_switch_the_automatic_reminders_on_and_off(): void
    {
        $admin = $this->createAdminUser();
        $put = fn (bool $on) => $this->actingAs($admin, 'sanctum')->putJson('/api/settings/notifications', ['email_payment_reminders' => $on])->assertOk();

        $put(true);
        $this->assertTrue(NotificationSetting::emailRemindersEnabled($admin->company_id));
        $put(false);
        $this->assertFalse(NotificationSetting::emailRemindersEnabled($admin->company_id));
    }

    public function test_someone_without_settings_permission_cannot_change_it(): void
    {
        $viewer = $this->createTestUser();   // no admin rights, no permissions

        $this->actingAs($viewer, 'sanctum')->putJson('/api/settings/notifications', ['email_payment_reminders' => true])->assertStatus(403);
        $this->assertFalse(NotificationSetting::emailRemindersEnabled($viewer->company_id));
    }

    public function test_the_test_email_refuses_clearly_when_the_server_only_logs_mail(): void
    {
        $admin = $this->createAdminUser();
        config(['mail.default' => 'log']);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/settings/notifications/test-email');

        $res->assertStatus(422)->assertJsonPath('error_code', 'MAIL_NOT_SET_UP');
        $this->assertStringContainsString('never delivered', $res->json('message'));
    }

    public function test_the_test_email_is_sent_to_the_signed_in_admin(): void
    {
        $admin = $this->createAdminUser();
        config(['mail.default' => 'array']);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/settings/notifications/test-email')->assertOk();

        $this->assertSame($admin->email, $res->json('data.to'));
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('PanelOS test e-mail', $sent[0]->getOriginalMessage()->getSubject());
        $this->assertSame($admin->email, $sent[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_a_mail_server_that_refuses_gives_a_readable_error_not_a_crash(): void
    {
        $admin = $this->createAdminUser();
        // An SMTP server nobody is listening on.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/settings/notifications/test-email');

        $res->assertStatus(422)->assertJsonPath('error_code', 'MAIL_SEND_FAILED');
        $this->assertStringContainsString('mail server refused', $res->json('message'));
    }

    /** @return array{0:\App\Models\User,1:int} an admin and the id of an overdue invoice with a reminder that is due now */
    private function dueReminder(): array
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $dispatch = Dispatch::factory()->for($admin->company)->create();
        $invoice = app(InvoiceService::class)->createFromDispatch($dispatch->id);
        $invoice->update(['due_date' => now()->subDays(10)]);
        app(PaymentReminderService::class)->scheduleRemindersForInvoice($invoice->fresh(), $admin->company_id);
        PaymentReminder::where('invoice_id', $invoice->id)->update(['next_reminder_at' => now()->subMinute()]);

        return [$admin, $invoice->id];
    }

    public function test_the_nine_oclock_job_sends_nothing_while_the_switch_is_off(): void
    {
        Mail::fake();
        [, $invoiceId] = $this->dueReminder();

        $this->artisan('reminders:send')->assertExitCode(0);

        Mail::assertNothingQueued();
        $this->assertSame(0, (int) PaymentReminder::where('invoice_id', $invoiceId)->value('reminder_count'));
    }

    public function test_the_nine_oclock_job_sends_once_an_admin_turns_it_on(): void
    {
        Mail::fake();
        [$admin, $invoiceId] = $this->dueReminder();
        NotificationSetting::forCompany($admin->company_id)->update(['email_payment_reminders' => true]);

        $this->artisan('reminders:send')->assertExitCode(0);

        Mail::assertQueued(PaymentReminderMailable::class, 1);
        $this->assertSame(1, (int) PaymentReminder::where('invoice_id', $invoiceId)->value('reminder_count'));
    }

    public function test_one_companys_switch_does_not_turn_on_another_companys_reminders(): void
    {
        Mail::fake();
        $this->dueReminder();                               // company A: switch off, reminder due
        $other = $this->createAdminUser();                  // company B: switch ON, nothing due
        NotificationSetting::forCompany($other->company_id)->update(['email_payment_reminders' => true]);

        $this->artisan('reminders:send')->assertExitCode(0);

        Mail::assertNothingQueued();
    }
}
