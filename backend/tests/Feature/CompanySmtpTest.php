<?php

namespace Tests\Feature;

use App\Models\Dispatch;
use App\Models\NotificationSetting;
use App\Services\EmailService;
use App\Services\InvoiceService;
use App\Support\CompanyMail;
use App\Support\SmtpHostGuard;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * A company can send its customer e-mail from its own mailbox (Settings -> Customer Messaging -> E-mail). The password is
 * stored encrypted and never returned; the host cannot point inside our own network; mail goes out from the company's
 * address, synchronously; companies without a mailbox of their own are unaffected.
 */
class CompanySmtpTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private const SECRET = 'S3cr3t-App-Pass!';

    private function mailbox(array $over = []): array
    {
        return $over + [
            'smtp_enabled' => true, 'smtp_host' => 'smtppro.zoho.in', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'smtp_username' => 'accounts@signature.test', 'smtp_password' => self::SECRET,
            'smtp_from_address' => 'accounts@signature.test', 'smtp_from_name' => 'Signature PUF Panel',
        ];
    }

    private function save(array $payload)
    {
        return $this->putJson('/api/settings/notifications', $payload);
    }

    /** A mailer with its own in-memory transport, standing in for the real SMTP connection. */
    private function fakeMailbox(?\Closure $onBuild = null): \Illuminate\Mail\Transport\ArrayTransport
    {
        $transport = new \Illuminate\Mail\Transport\ArrayTransport();
        CompanyMail::useFactory(function (NotificationSetting $s) use ($transport, $onBuild) {
            if ($onBuild) $onBuild($s);

            return new Mailer('company', app('view'), $transport, app('events'));
        });

        return $transport;
    }

    protected function tearDown(): void
    {
        CompanyMail::useFactory(null);
        parent::tearDown();
    }

    public function test_the_config_matches_what_each_port_needs_and_the_password_is_stored_encrypted(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->mailbox())->assertOk();

        $s = NotificationSetting::forCompany($admin->company_id);
        $cfg = CompanyMail::config($s);
        $this->assertSame('smtps', $cfg['scheme']);          // 465 = implicit SSL
        $this->assertSame('smtppro.zoho.in', $cfg['host']);
        $this->assertSame(465, $cfg['port']);
        $this->assertSame(self::SECRET, $cfg['password']);   // decrypted for the connection
        $this->assertSame(10, $cfg['timeout'], 'A dead mail server must not hold a request open.');

        $this->save($this->mailbox(['smtp_port' => 587, 'smtp_encryption' => 'tls']))->assertOk();
        $this->assertSame('smtp', CompanyMail::config($s->fresh())['scheme']);   // 587 = STARTTLS

        $raw = DB::table('notification_settings')->where('company_id', $admin->company_id)->value('smtp_password');
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::SECRET, (string) $raw, 'The password must not be stored in the clear.');
    }

    public function test_the_api_never_returns_the_password_and_a_blank_one_keeps_it(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->mailbox())->assertOk();

        $res = $this->getJson('/api/settings/notifications')->assertOk();
        $this->assertTrue($res->json('data.smtp_password_set'));
        $this->assertSame('smtppro.zoho.in', $res->json('data.smtp_host'));
        $this->assertStringNotContainsString(self::SECRET, $res->getContent());
        $this->assertArrayNotHasKey('smtp_password', $res->json('data'));

        $this->save(['smtp_host' => 'smtp.zoho.in', 'smtp_password' => ''])->assertOk();        // blank = keep
        $this->assertSame(self::SECRET, NotificationSetting::forCompany($admin->company_id)->smtp_password);

        $this->save(['smtp_password' => 'a-new-one'])->assertOk();
        $this->assertSame('a-new-one', NotificationSetting::forCompany($admin->company_id)->smtp_password);

        $this->save(['clear_smtp_password' => true])->assertOk();
        $s = NotificationSetting::forCompany($admin->company_id);
        $this->assertEmpty($s->getAttribute('smtp_password'));
        $this->assertFalse($s->smtp_enabled, 'Without a password the mailbox cannot stay switched on.');
    }

    public function test_it_cannot_be_switched_on_until_everything_needed_is_filled_in(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $res = $this->save(['smtp_enabled' => true, 'smtp_host' => 'smtppro.zoho.in', 'smtp_port' => 465])->assertStatus(422);
        $this->assertStringContainsString('user name', $res->json('message'));
        $this->assertStringContainsString('password', $res->json('message'));
        $this->assertStringContainsString('send as', $res->json('message'));
        $this->assertFalse(NotificationSetting::forCompany($admin->company_id)->smtp_enabled);

        // Filling it in over several saves works too: the missing parts are read from what is already saved.
        $this->save(['smtp_host' => 'smtppro.zoho.in', 'smtp_port' => 465, 'smtp_encryption' => 'ssl'])->assertOk();
        $this->save(['smtp_username' => 'a@b.test', 'smtp_password' => 'pw', 'smtp_from_address' => 'a@b.test'])->assertOk();
        $this->save(['smtp_enabled' => true])->assertOk();
        $this->assertTrue(NotificationSetting::forCompany($admin->company_id)->smtp_enabled);
    }

    public function test_the_mail_server_cannot_be_something_inside_our_own_network(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.20', '169.254.169.254', 'localhost', 'db.internal', 'mail.local', 'http://smtp.example.com', 'smtp.example.com:465', '', 'nodots'] as $bad) {
            if ($bad === '') continue;     // blank just clears the field
            $this->save($this->mailbox(['smtp_host' => $bad]))->assertStatus(422);
        }
        foreach ([22, 3306, 80, 6379] as $port) {
            $this->save($this->mailbox(['smtp_port' => $port]))->assertStatus(422);
        }
        $this->save($this->mailbox(['smtp_from_address' => 'not-an-email']))->assertStatus(422);
        $this->save($this->mailbox(['smtp_host' => '8.8.8.8']))->assertOk();   // a public address is fine
    }

    public function test_the_connection_is_refused_when_a_name_points_inside(): void
    {
        $this->expectException(\RuntimeException::class);
        SmtpHostGuard::assertSafeToConnect('127.0.0.1');
    }

    public function test_customer_mail_goes_from_the_companys_mailbox_now_not_through_the_server_queue(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        NotificationSetting::forCompany($admin->company_id)->update($this->mailbox());
        $transport = $this->fakeMailbox();

        $dispatch = Dispatch::factory()->for($admin->company)->create();
        $invoice = app(InvoiceService::class)->createFromDispatch($dispatch->id);
        $invoice->update(['due_date' => now()->subDays(3)]);

        app(EmailService::class)->sendPaymentReminder($invoice->fresh(), $admin->company_id);

        $this->assertCount(1, $transport->messages(), 'The reminder went through the company mailbox.');
        $from = $transport->messages()[0]->getOriginalMessage()->getFrom()[0];
        $this->assertSame('accounts@signature.test', $from->getAddress());
        $this->assertSame('Signature PUF Panel', $from->getName());
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages(), 'Nothing went through the server mailer.');
    }

    public function test_a_company_without_its_own_mailbox_still_uses_the_server_mailer(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $transport = $this->fakeMailbox();        // would be used if anything chose the company path

        $dispatch = Dispatch::factory()->for($admin->company)->create();
        $invoice = app(InvoiceService::class)->createFromDispatch($dispatch->id);
        $invoice->update(['due_date' => now()->subDays(3)]);
        app(EmailService::class)->sendPaymentReminder($invoice->fresh(), $admin->company_id);

        Mail::assertQueued(\App\Mail\PaymentReminderMailable::class, 1);
        $this->assertCount(0, $transport->messages());
    }

    public function test_one_companys_mailbox_is_never_used_for_another_company(): void
    {
        $a = $this->createAdminUser();
        $b = $this->createAdminUser();
        NotificationSetting::forCompany($a->company_id)->update($this->mailbox());

        $this->assertNotNull(CompanyMail::activeFor($a->company_id));
        $this->assertNull(CompanyMail::activeFor($b->company_id));
        $this->assertNull(CompanyMail::activeFor(null));
    }

    public function test_the_settings_say_so_and_the_test_button_uses_the_companys_mailbox(): void
    {
        config(['mail.default' => 'log']);                       // the server itself cannot deliver anything
        $admin = $this->createAdminUser(['email' => 'boss@signature.test']);
        $this->actingAs($admin, 'sanctum');
        $this->save($this->mailbox())->assertOk();
        $transport = $this->fakeMailbox();

        $mail = $this->getJson('/api/settings/notifications')->json('data.mail');
        $this->assertTrue($mail['delivers']);
        $this->assertTrue($mail['own']);
        $this->assertStringContainsString('accounts@signature.test through smtppro.zoho.in', $mail['headline']);

        $this->postJson('/api/settings/notifications/test-email')->assertOk();
        $msg = $transport->messages()[0]->getOriginalMessage();
        $this->assertSame('boss@signature.test', $msg->getTo()[0]->getAddress());
        $this->assertSame('accounts@signature.test', $msg->getFrom()[0]->getAddress());
    }

    public function test_a_refused_login_is_shown_to_the_admin_not_hidden(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->mailbox())->assertOk();
        CompanyMail::useFactory(function () {
            throw new TransportException('Failed to authenticate on SMTP server with username "accounts@signature.test": 535 Authentication Failed');
        });

        $res = $this->postJson('/api/settings/notifications/test-email')->assertStatus(422)->assertJsonPath('error_code', 'MAIL_SEND_FAILED');

        $this->assertStringContainsString('535 Authentication Failed', $res->json('message'));
        $this->assertStringNotContainsString(self::SECRET, $res->getContent());
    }

    public function test_platform_mail_never_uses_a_company_mailbox(): void
    {
        // Login codes and server alerts keep using the server mailer: a tenant with a broken password must not lock its staff out.
        $admin = $this->createAdminUser();
        NotificationSetting::forCompany($admin->company_id)->update($this->mailbox());
        $transport = $this->fakeMailbox();

        \App\Services\AlertService::send('x', 'y');

        $this->assertCount(0, $transport->messages());
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }
}
