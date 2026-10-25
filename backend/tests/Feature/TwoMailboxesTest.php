<?php

namespace Tests\Feature;

use App\Models\Dispatch;
use App\Models\NotificationSetting;
use App\Services\EmailService;
use App\Services\InvoiceService;
use App\Support\CompanyMail;
use App\Support\CompanyMailbox;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A company has two mailboxes: MAIN (quotations, invoices, everything the team sends) and NOTIFICATIONS (the automatic
 * messages), so the main address is not the one sending every reminder. Which kind of e-mail uses which mailbox is the
 * admin's choice (Settings -> Customer Messaging), with sensible defaults, and notifications fall back to the main one.
 */
class TwoMailboxesTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** @var array<string,ArrayTransport> in-memory mailboxes keyed by the From address that built them */
    private array $boxes = [];

    private function main(array $over = []): array
    {
        return $over + [
            'smtp_enabled' => true, 'smtp_host' => 'smtppro.zoho.in', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'smtp_username' => 'accounts@signature.test', 'smtp_password' => 'Main-Pass-1!',
            'smtp_from_address' => 'accounts@signature.test', 'smtp_from_name' => 'Signature PUF Panel',
        ];
    }

    private function notifications(array $over = []): array
    {
        return $over + [
            'notif_smtp_enabled' => true, 'notif_smtp_host' => 'smtppro.zoho.in', 'notif_smtp_port' => 587, 'notif_smtp_encryption' => 'tls',
            'notif_smtp_username' => 'notifications@signature.test', 'notif_smtp_password' => 'N0tify-Pass!',
            'notif_smtp_from_address' => 'notifications@signature.test', 'notif_smtp_from_name' => 'Signature Notifications',
        ];
    }

    private function save(array $payload)
    {
        return $this->putJson('/api/settings/notifications', $payload);
    }

    private function useInMemoryMailboxes(): void
    {
        CompanyMail::useFactory(function (CompanyMailbox $b) {
            $this->boxes[$b->fromAddress] ??= new ArrayTransport();

            return new Mailer('company', app('view'), $this->boxes[$b->fromAddress], app('events'));
        });
    }

    protected function tearDown(): void
    {
        CompanyMail::useFactory(null);
        parent::tearDown();
    }

    private function overdueInvoice($admin)
    {
        $dispatch = Dispatch::factory()->for($admin->company)->create();
        $invoice = app(InvoiceService::class)->createFromDispatch($dispatch->id);
        $invoice->update(['due_date' => now()->subDays(3)]);

        return $invoice->fresh();
    }

    private function sent(string $from): int
    {
        return isset($this->boxes[$from]) ? count($this->boxes[$from]->messages()) : 0;
    }

    public function test_notifications_go_from_their_own_mailbox_and_invoices_from_the_main_one(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main() + $this->notifications())->assertOk();
        $this->useInMemoryMailboxes();

        $invoice = $this->overdueInvoice($admin);
        $svc = app(EmailService::class);
        $svc->sendInvoice($invoice, $admin->company_id);
        $svc->sendPaymentReminder($invoice, $admin->company_id);

        $this->assertSame(1, $this->sent('accounts@signature.test'), 'The invoice went from the main mailbox.');
        $this->assertSame(1, $this->sent('notifications@signature.test'), 'The reminder went from the notifications mailbox.');
        $reminder = $this->boxes['notifications@signature.test']->messages()[0]->getOriginalMessage();
        $this->assertSame('notifications@signature.test', $reminder->getFrom()[0]->getAddress());
        $this->assertSame('Signature Notifications', $reminder->getFrom()[0]->getName());
        $this->assertStringNotContainsString('N0tify-Pass', $reminder->toString());
    }

    public function test_notifications_use_the_main_mailbox_when_there_is_no_separate_one(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main())->assertOk();
        $this->useInMemoryMailboxes();

        app(EmailService::class)->sendPaymentReminder($this->overdueInvoice($admin), $admin->company_id);

        $this->assertSame(1, $this->sent('accounts@signature.test'));
        $this->assertSame(0, $this->sent('notifications@signature.test'));
    }

    public function test_only_a_notifications_mailbox_leaves_invoices_on_the_server_mailer(): void
    {
        Mail::fake();
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->notifications())->assertOk();
        $this->useInMemoryMailboxes();

        $invoice = $this->overdueInvoice($admin);
        app(EmailService::class)->sendInvoice($invoice, $admin->company_id);              // routed to main: not set up, server mailer
        app(EmailService::class)->sendPaymentReminder($invoice, $admin->company_id);      // routed to notifications: its own mailbox

        Mail::assertQueued(\App\Mail\InvoiceSentMailable::class, 1);
        $this->assertSame(1, $this->sent('notifications@signature.test'));
        $this->assertSame(0, $this->sent('accounts@signature.test'));
    }

    public function test_the_admin_chooses_which_mailbox_each_kind_of_email_uses(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main() + $this->notifications())->assertOk();

        $res = $this->getJson('/api/settings/notifications')->assertOk();
        $this->assertSame(['invoice' => 'main', 'payment_reminder' => 'notifications', 'payment_receipt' => 'notifications', 'lead_message' => 'main'], $res->json('data.mail_routing'), 'Sensible defaults until the admin chooses.');
        $this->assertSame(['invoice', 'payment_reminder', 'payment_receipt', 'lead_message'], array_column($res->json('data.mail_types'), 'type'));

        // The admin sends reminders through the main mailbox too.
        $this->save(['mail_routing' => ['payment_reminder' => 'main']])->assertOk();
        $this->useInMemoryMailboxes();
        app(EmailService::class)->sendPaymentReminder($this->overdueInvoice($admin), $admin->company_id);
        $this->assertSame(1, $this->sent('accounts@signature.test'), 'The reminder follows the admin choice: main.');
        $this->assertSame(0, $this->sent('notifications@signature.test'));

        // A partial save keeps the other choices.
        $this->save(['mail_routing' => ['invoice' => 'notifications']])->assertOk();
        $routing = $this->getJson('/api/settings/notifications')->json('data.mail_routing');
        $this->assertSame('notifications', $routing['invoice']);
        $this->assertSame('main', $routing['payment_reminder']);

        // The screen says what will really send each kind.
        $routes = collect($this->getJson('/api/settings/notifications')->json('data.mail.routes'))->keyBy('type');
        $this->assertSame('own', $routes['invoice']['via']);
        $this->assertSame('notifications@signature.test', $routes['invoice']['from']);
        $this->assertSame('accounts@signature.test', $routes['payment_reminder']['from']);
    }

    public function test_the_screen_says_when_a_kind_falls_back_to_another_mailbox_or_the_server(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main())->assertOk();

        $routes = collect($this->getJson('/api/settings/notifications')->json('data.mail.routes'))->keyBy('type');
        $this->assertSame('own', $routes['invoice']['via']);
        $this->assertSame('main', $routes['payment_reminder']['via'], 'No notifications mailbox: it falls back to the main one.');

        $this->save(['clear_smtp_password' => true])->assertOk();
        $routes = collect($this->getJson('/api/settings/notifications')->json('data.mail.routes'))->keyBy('type');
        $this->assertSame('server', $routes['invoice']['via']);
        $this->assertNull($routes['invoice']['from']);
    }

    public function test_routing_only_accepts_known_kinds_and_known_mailboxes(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $this->save(['mail_routing' => ['invoice' => 'somewhere-else']])->assertStatus(422);
        $this->save(['mail_routing' => ['made_up_kind' => 'main']])->assertOk();
        $this->assertArrayNotHasKey('made_up_kind', NotificationSetting::forCompany($admin->company_id)->mail_routing ?? [], 'Unknown kinds are dropped, not stored.');
    }

    public function test_the_second_mailbox_is_validated_encrypted_and_never_returned_like_the_first(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $this->save($this->notifications(['notif_smtp_host' => '10.0.0.5']))->assertStatus(422);
        $this->save($this->notifications(['notif_smtp_port' => 22]))->assertStatus(422);
        $res = $this->save(['notif_smtp_enabled' => true])->assertStatus(422);
        $this->assertStringContainsString('notifications mailbox', $res->json('message'));

        $this->save($this->notifications())->assertOk();
        $res = $this->getJson('/api/settings/notifications')->assertOk();
        $this->assertTrue($res->json('data.notif_smtp_password_set'));
        $this->assertStringNotContainsString('N0tify-Pass', $res->getContent());
        $raw = DB::table('notification_settings')->where('company_id', $admin->company_id)->value('notif_smtp_password');
        $this->assertStringNotContainsString('N0tify-Pass', (string) $raw);

        $this->save(['notif_smtp_host' => 'smtp.zoho.in', 'notif_smtp_password' => ''])->assertOk();
        $this->assertSame('N0tify-Pass!', NotificationSetting::forCompany($admin->company_id)->notif_smtp_password);

        $this->save(['clear_notif_smtp_password' => true])->assertOk();
        $s = NotificationSetting::forCompany($admin->company_id);
        $this->assertEmpty($s->getAttribute('notif_smtp_password'));
        $this->assertFalse($s->notif_smtp_enabled);
    }

    public function test_the_two_mailboxes_do_not_touch_each_others_password(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main() + $this->notifications())->assertOk();

        $this->save(['clear_notif_smtp_password' => true])->assertOk();

        $s = NotificationSetting::forCompany($admin->company_id);
        $this->assertSame('Main-Pass-1!', $s->smtp_password, 'Removing the notifications password leaves the main mailbox alone.');
        $this->assertTrue($s->smtp_enabled);
    }

    public function test_each_mailbox_has_its_own_test_button(): void
    {
        $admin = $this->createAdminUser(['email' => 'boss@signature.test']);
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main() + $this->notifications())->assertOk();
        $this->useInMemoryMailboxes();

        $this->postJson('/api/settings/notifications/test-email', ['mailbox' => 'notifications'])->assertOk();
        $this->assertSame(1, $this->sent('notifications@signature.test'));
        $this->assertSame(0, $this->sent('accounts@signature.test'), 'Testing the notifications mailbox must not quietly use the main one.');

        $this->postJson('/api/settings/notifications/test-email', ['mailbox' => 'main'])->assertOk();
        $this->assertSame(1, $this->sent('accounts@signature.test'));
    }

    public function test_the_notifications_test_refuses_when_that_mailbox_is_not_set_up(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main())->assertOk();                 // main only
        $this->useInMemoryMailboxes();

        $this->postJson('/api/settings/notifications/test-email', ['mailbox' => 'notifications'])
            ->assertStatus(422)->assertJsonPath('error_code', 'MAIL_NOT_SET_UP');
        $this->assertSame(0, $this->sent('accounts@signature.test'), 'It must not have tested the main mailbox instead.');
    }

    public function test_another_company_never_sees_or_uses_these_mailboxes(): void
    {
        $a = $this->createAdminUser();
        $b = $this->createAdminUser();
        NotificationSetting::forCompany($a->company_id)->update($this->main() + $this->notifications());

        $this->assertNotNull(CompanyMail::activeFor($a->company_id, CompanyMailbox::NOTIFICATIONS));
        $this->assertNull(CompanyMail::activeFor($b->company_id, CompanyMailbox::NOTIFICATIONS));
        $this->assertSame('notifications', CompanyMail::kindFor($b->company_id, 'payment_reminder'), 'B has the defaults, not A\'s choices.');
    }

    public function test_the_test_e_mail_goes_to_the_address_typed_not_to_the_tenant(): void
    {
        $admin = $this->createAdminUser(['email' => 'tenant.owner@signature.test']);
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main() + $this->notifications())->assertOk();
        $this->useInMemoryMailboxes();

        $res = $this->postJson('/api/settings/notifications/test-email', ['mailbox' => 'notifications', 'to' => 'krish@checker.test'])->assertOk();

        $this->assertStringContainsString('krish@checker.test', $res->json('message'));
        $msg = $this->boxes['notifications@signature.test']->messages()[0]->getOriginalMessage();
        $this->assertSame('krish@checker.test', $msg->getTo()[0]->getAddress());
        $this->assertCount(1, $msg->getTo(), 'Only the typed address, not the tenant owner as well.');

        // Blank means the admin's own login address, as before.
        $this->postJson('/api/settings/notifications/test-email', ['mailbox' => 'main', 'to' => ''])->assertOk();
        $this->assertSame('tenant.owner@signature.test', $this->boxes['accounts@signature.test']->messages()[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_another_recipient_needs_the_companys_own_mailbox_so_the_server_is_not_a_relay(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');            // no mailbox of its own: the test would go through the PanelOS server
        $this->useInMemoryMailboxes();

        $this->postJson('/api/settings/notifications/test-email', ['to' => 'someone@elsewhere.test'])
            ->assertStatus(422)->assertJsonPath('error_code', 'MAIL_TEST_TARGET_NOT_ALLOWED');
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages(), 'Nothing may leave through the server mailer.');
    }

    public function test_the_test_address_must_be_an_email_and_tests_are_rate_limited(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->save($this->main())->assertOk();
        $this->useInMemoryMailboxes();

        $this->postJson('/api/settings/notifications/test-email', ['to' => 'not-an-email'])->assertStatus(422);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/settings/notifications/test-email', ['to' => 'a@b.test']);
        }
        $this->postJson('/api/settings/notifications/test-email', ['to' => 'a@b.test'])->assertStatus(429);
    }
}
