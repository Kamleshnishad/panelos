<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessageTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadMessageService;
use App\Support\CompanyMail;
use App\Support\CompanyMailbox;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** LEADS_PLAN 5.3: contacting a lead from its screen: templates, the WhatsApp link, e-mail through the company mailbox, all on the timeline. */
class LeadMessageTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private ArrayTransport $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['name' => 'Signature PUF Panel']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'name' => 'Asha Rao', 'email' => 'asha@signature.test']);
        $this->actingAs($this->admin, 'sanctum');
        $this->box = new ArrayTransport();
        CompanyMail::useFactory(fn (CompanyMailbox $b) => new Mailer('company', app('view'), $this->box, app('events')));
    }

    protected function tearDown(): void
    {
        CompanyMail::useFactory(null);
        parent::tearDown();
    }

    private function mailbox(): void
    {
        $this->putJson('/api/settings/notifications', [
            'smtp_enabled' => true, 'smtp_host' => 'smtppro.zoho.in', 'smtp_port' => 465, 'smtp_encryption' => 'ssl', 'smtp_username' => 'sales@signature.test',
            'smtp_password' => 'Main-Pass-1!', 'smtp_from_address' => 'sales@signature.test', 'smtp_from_name' => 'Signature PUF Panel',
        ])->assertOk();
    }

    private function lead(array $o = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "LD-{$n}", 'contact_name' => 'Ravi Patel', 'company_name' => 'Zephyr Cold Storage', 'phone' => '+91 98765-43210',
            'email' => 'ravi@zephyr.test', 'city' => 'Rajkot', 'requirement' => 'Need 300 sqm PUF wall panel for a cold room.', 'source' => 'Phone', 'status' => 'new']);
    }

    private function svc(): LeadMessageService
    {
        return app(LeadMessageService::class);
    }

    private function lastLine(Lead $l): LeadActivity
    {
        return LeadActivity::where('lead_id', $l->id)->latest('id')->firstOrFail();
    }

    // ── templates ────────────────────────────────────────────────────────────

    public function test_a_company_gets_starter_templates_once_and_never_again_after_deleting_them(): void
    {
        $this->svc()->ensureDefaults($this->company->id);
        $this->svc()->ensureDefaults($this->company->id);

        $this->assertSame(['email' => 2, 'whatsapp' => 4], LeadMessageTemplate::where('company_id', $this->company->id)->get()->countBy('channel')->sortKeys()->all());

        LeadMessageTemplate::where('company_id', $this->company->id)->get()->each->delete();
        $this->assertCount(0, $this->svc()->templates($this->company->id));
    }

    public function test_placeholders_are_filled_in_and_gaps_read_naturally(): void
    {
        $lead = $this->lead();
        $text = '{name} / {company} / {lead_no} / {requirement} / {city} / {my_name} / {my_company} / {unknown}';

        $out = $this->svc()->render($text, $lead, $this->admin);

        $this->assertSame("Ravi Patel / Zephyr Cold Storage / {$lead->lead_no} / Need 300 sqm PUF wall panel for a cold room / Rajkot / Asha Rao / Signature PUF Panel / {unknown}", $out);

        $bare = $this->lead(['contact_name' => '', 'requirement' => null, 'company_name' => null, 'city' => null]);
        $this->assertSame('Hello there, about your enquiry.', $this->svc()->render('Hello {name}, about {requirement}.', $bare, $this->admin));
    }

    public function test_a_long_requirement_is_shortened_on_a_word_and_a_message_with_markup_is_left_as_text(): void
    {
        $lead = $this->lead(['requirement' => str_repeat('panel ', 40), 'contact_name' => '<b>Ravi</b>']);

        $out = $this->svc()->render('{requirement}|{name}', $lead, $this->admin);

        $this->assertLessThanOrEqual(81 + 11, mb_strlen($out));
        $this->assertStringContainsString('<b>Ravi</b>', $out);                // plain text here; the e-mail escapes it
    }

    public function test_the_lead_screen_gets_active_templates_filled_in_for_that_lead_and_that_person(): void
    {
        $lead = $this->lead();
        LeadMessageTemplate::where('company_id', $this->company->id)->count() || $this->svc()->ensureDefaults($this->company->id);
        LeadMessageTemplate::where('company_id', $this->company->id)->where('name', 'Site visit')->update(['is_active' => false]);

        $res = $this->getJson("/api/leads/{$lead->id}/message-templates?channel=whatsapp")->assertOk();

        $names = collect($res->json('data.templates'))->pluck('name')->all();
        $this->assertSame(['First reply', 'No answer, call me back', 'Quotation follow-up'], $names);
        $this->assertStringContainsString('Hello Ravi Patel, thank you for your enquiry about Need 300 sqm PUF wall panel for a cold room. This is Asha Rao from Signature PUF Panel', $res->json('data.templates.0.body'));
        $this->assertTrue($res->json('data.whatsapp_ready'));
        $this->assertFalse($res->json('data.email_ready'), 'no mailbox yet');
        $this->getJson("/api/leads/{$lead->id}/message-templates")->assertStatus(422);
    }

    // ── managing templates ───────────────────────────────────────────────────

    public function test_templates_are_added_changed_and_deleted_in_settings(): void
    {
        $id = $this->postJson('/api/settings/lead-message-templates', ['channel' => 'whatsapp', 'name' => 'Diwali', 'body' => 'Happy Diwali {name}!', 'subject' => 'ignored'])->assertStatus(201)->json('data.id');
        $this->assertNull(LeadMessageTemplate::find($id)->subject, 'a WhatsApp text has no subject');

        $this->putJson("/api/settings/lead-message-templates/{$id}", ['body' => 'Season greetings {name}!', 'is_active' => false])->assertOk();
        $this->assertSame([false, 'Season greetings {name}!'], [LeadMessageTemplate::find($id)->is_active, LeadMessageTemplate::find($id)->body]);

        $list = $this->getJson('/api/settings/lead-message-templates')->assertOk();
        $this->assertContains('Diwali', collect($list->json('data.templates'))->pluck('name')->all());
        $this->assertArrayHasKey('my_name', $list->json('data.placeholders'));

        $this->deleteJson("/api/settings/lead-message-templates/{$id}")->assertNoContent();
        $this->postJson('/api/settings/lead-message-templates', ['channel' => 'sms', 'name' => 'x', 'body' => 'y'])->assertStatus(422);
        $this->postJson('/api/settings/lead-message-templates', ['channel' => 'email', 'name' => '', 'body' => 'y'])->assertStatus(422);
    }

    public function test_one_company_cannot_see_or_change_anothers_templates(): void
    {
        $this->svc()->ensureDefaults($this->company->id);
        $id = LeadMessageTemplate::where('company_id', $this->company->id)->value('id');
        $other = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);
        $this->actingAs($other, 'sanctum');

        $names = collect($this->getJson('/api/settings/lead-message-templates')->json('data.templates'))->pluck('company_id')->unique()->all();
        $this->assertSame([$other->company_id], $names);
        $this->putJson("/api/settings/lead-message-templates/{$id}", ['name' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson("/api/settings/lead-message-templates/{$id}")->assertStatus(404);
    }

    // ── WhatsApp ─────────────────────────────────────────────────────────────

    public function test_whatsapp_numbers_are_normalised_with_the_country_code(): void
    {
        foreach ([['9876543210', '919876543210'], ['+91 98765-43210', '919876543210'], ['098765 43210', '919876543210'], ['91 9876543210', '919876543210'], ['+1 415 555 2671', '14155552671']] as [$in, $out]) {
            $this->assertSame($out, LeadMessageService::waNumber($in), $in);
        }
        foreach ([null, '', '12345', 'call me', '98765'] as $bad) {
            $this->assertNull(LeadMessageService::waNumber($bad), (string) $bad);
        }
    }

    public function test_the_whatsapp_link_carries_the_text_and_the_timeline_says_the_chat_was_opened(): void
    {
        $lead = $this->lead();

        $res = $this->postJson("/api/leads/{$lead->id}/whatsapp", ['message' => "Hello Ravi & team,\nneed a quote? 100% yes"])->assertOk();

        $this->assertSame('919876543210', $res->json('data.number'));
        $this->assertSame('https://wa.me/919876543210?text=' . rawurlencode("Hello Ravi & team,\nneed a quote? 100% yes"), $res->json('data.url'));
        $line = $this->lastLine($lead);
        $this->assertSame(['whatsapp', $this->admin->id], [$line->type, $line->user_id]);
        $this->assertStringStartsWith('WhatsApp opened with: Hello Ravi', $line->description);
    }

    public function test_whatsapp_needs_a_usable_number_and_some_text(): void
    {
        $noPhone = $this->lead(['phone' => null]);
        $this->postJson("/api/leads/{$noPhone->id}/whatsapp", ['message' => 'Hi'])->assertStatus(422)->assertJsonValidationErrors('phone');

        $lead = $this->lead();
        $this->postJson("/api/leads/{$lead->id}/whatsapp", ['message' => '   '])->assertStatus(422);
        $this->postJson("/api/leads/{$lead->id}/whatsapp", ['message' => str_repeat('a', 1501)])->assertStatus(422);
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'whatsapp')->count());
    }

    // ── e-mail ───────────────────────────────────────────────────────────────

    public function test_an_email_goes_out_through_the_company_mailbox_with_replies_to_the_sender_and_is_logged(): void
    {
        $this->mailbox();
        $lead = $this->lead();

        $this->postJson("/api/leads/{$lead->id}/email", ['subject' => 'Your enquiry with Signature', 'body' => "Dear Ravi,\nThank you <b>very</b> much."])->assertOk();

        $this->assertCount(1, $this->box->messages());
        $mail = $this->box->messages()[0]->getOriginalMessage();
        $this->assertSame('ravi@zephyr.test', $mail->getTo()[0]->getAddress());
        $this->assertSame('sales@signature.test', $mail->getFrom()[0]->getAddress());
        $this->assertSame('asha@signature.test', $mail->getReplyTo()[0]->getAddress());
        $this->assertSame('Your enquiry with Signature', $mail->getSubject());
        $this->assertStringContainsString('Dear Ravi,<br />', $mail->getHtmlBody());
        $this->assertStringContainsString('&lt;b&gt;very&lt;/b&gt;', $mail->getHtmlBody(), 'what a person types is shown as text, never as markup');
        $line = $this->lastLine($lead);
        $this->assertSame('email', $line->type);
        $this->assertStringStartsWith("E-mail sent to ravi@zephyr.test: Your enquiry with Signature\nDear Ravi,", $line->description);
    }

    public function test_without_a_company_mailbox_nothing_is_sent_and_the_person_is_told_what_to_do(): void
    {
        Mail::fake();
        $lead = $this->lead();

        $res = $this->postJson("/api/leads/{$lead->id}/email", ['subject' => 'Hello', 'body' => 'Text'])->assertStatus(422);

        $this->assertStringContainsString('Customer Messaging', $res->json('message'));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertCount(0, $this->box->messages());
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'email')->count());
    }

    public function test_an_email_needs_a_valid_address_a_subject_and_text(): void
    {
        $this->mailbox();
        $noMail = $this->lead(['email' => null]);
        $bad = $this->lead(['email' => 'not-an-address']);
        $ok = $this->lead();

        $this->postJson("/api/leads/{$noMail->id}/email", ['subject' => 'S', 'body' => 'B'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson("/api/leads/{$bad->id}/email", ['subject' => 'S', 'body' => 'B'])->assertStatus(422);
        $this->postJson("/api/leads/{$ok->id}/email", ['subject' => '', 'body' => 'B'])->assertStatus(422);
        $this->postJson("/api/leads/{$ok->id}/email", ['subject' => 'S', 'body' => ''])->assertStatus(422);
        $this->assertCount(0, $this->box->messages());
    }

    public function test_it_only_ever_writes_to_the_leads_own_address_and_a_subject_cannot_inject_headers(): void
    {
        $this->mailbox();
        $lead = $this->lead();

        $this->postJson("/api/leads/{$lead->id}/email", ['to' => 'someone@else.test', 'cc' => 'x@y.test', 'subject' => "Hi\r\nBcc: spy@evil.test", 'body' => 'Text'])->assertOk();

        $mail = $this->box->messages()[0]->getOriginalMessage();
        $this->assertSame(['ravi@zephyr.test'], array_map(fn ($a) => $a->getAddress(), $mail->getTo()));
        $this->assertSame([], $mail->getCc());
        $this->assertSame([], $mail->getBcc());
        $this->assertSame('Hi Bcc: spy@evil.test', $mail->getSubject());
    }

    public function test_a_mailbox_that_refuses_gives_a_plain_message_with_no_credentials_in_it(): void
    {
        $this->mailbox();
        CompanyMail::useFactory(function () {
            throw new \RuntimeException('535 Authentication failed for sales@signature.test with Main-Pass-1! at smtppro.zoho.in');
        });
        $lead = $this->lead();

        $res = $this->postJson("/api/leads/{$lead->id}/email", ['subject' => 'Hello', 'body' => 'Text'])->assertStatus(422);

        $this->assertStringNotContainsString('Main-Pass-1', $res->getContent());
        $this->assertStringNotContainsString('zoho', $res->getContent());
        $this->assertStringContainsString('did not accept the message', $res->json('message'));
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'email')->count());
    }

    public function test_the_mail_type_shows_in_the_mailbox_routing_screen(): void
    {
        $types = collect($this->getJson('/api/settings/notifications')->assertOk()->json('data.mail_types'))->pluck('type')->all();

        $this->assertContains('lead_message', $types);
    }

    // ── who may ──────────────────────────────────────────────────────────────

    public function test_sending_needs_leads_manage_and_another_company_never_reaches_the_lead(): void
    {
        $this->mailbox();
        $lead = $this->lead();

        $viewer = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view']])->id]);
        $this->actingAs($viewer, 'sanctum');
        $this->getJson("/api/leads/{$lead->id}/message-templates?channel=email")->assertOk();
        $this->postJson("/api/leads/{$lead->id}/whatsapp", ['message' => 'Hi'])->assertStatus(403);
        $this->postJson("/api/leads/{$lead->id}/email", ['subject' => 'S', 'body' => 'B'])->assertStatus(403);

        $other = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);
        $this->actingAs($other, 'sanctum');
        $this->getJson("/api/leads/{$lead->id}/message-templates?channel=email")->assertStatus(404);
        $this->postJson("/api/leads/{$lead->id}/whatsapp", ['message' => 'Hi'])->assertStatus(404);
        $this->postJson("/api/leads/{$lead->id}/email", ['subject' => 'S', 'body' => 'B'])->assertStatus(404);
        $this->assertCount(0, $this->box->messages());
    }

    public function test_a_person_restricted_to_their_own_leads_cannot_contact_anyone_elses(): void
    {
        $this->mailbox();
        $theirs = $this->lead(['assigned_to_user_id' => $this->admin->id]);
        $me = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => ['leads.view', 'leads.manage', 'leads.own_only']])->id]);
        $this->actingAs($me, 'sanctum');

        $this->postJson("/api/leads/{$theirs->id}/email", ['subject' => 'S', 'body' => 'B'])->assertStatus(404);
        $this->postJson("/api/leads/{$theirs->id}/whatsapp", ['message' => 'Hi'])->assertStatus(404);
        $this->assertCount(0, $this->box->messages());
    }
}
