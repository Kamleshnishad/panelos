<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadImportLog;
use App\Models\LeadInboundToken;
use App\Models\LeadPayload;
use App\Models\User;
use App\Services\LeadInboundService;
use Tests\TestCase;

/** LEADS_PLAN 4.1: the website enquiry form posts to the inbound address from a browser, and the address can be limited to the company's own websites. */
class LeadWebsiteFormTest extends TestCase
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

    /** @return array{0:LeadInboundToken,1:string} */
    private function token(?array $origins = null): array
    {
        [$row, $plain] = app(LeadInboundService::class)->createToken($this->company->id, 'Website form', 'Website');
        if ($origins !== null) {
            $row->update(['allowed_origins' => $origins]);
        }

        return [$row->fresh(), $plain];
    }

    /** What the form on the website sends (see frontend/public/lead-form.js). */
    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Ravi Patel', 'phone' => '9876543210', 'email' => 'ravi@zephyr.test', 'city' => 'Rajkot', 'message' => 'Need 300 sqm PUF wall panel',
            '_hp' => '', '_t' => 14500, 'external_id' => 'web-k3j2h1g0f9', 'landing_page' => 'https://www.zephyr.test/cold-rooms',
            'referrer' => 'https://www.google.com/', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'cold-room-summer', 'campaign' => 'cold-room-summer',
        ];
    }

    private function send(string $plain, array $body, array $headers = [])
    {
        return $this->postJson("/api/inbound/leads/{$plain}", $body, $headers);
    }

    // ── what the form sends becomes a lead ───────────────────────────────────

    public function test_the_form_creates_a_lead_with_its_page_and_campaign(): void
    {
        [, $plain] = $this->token();

        $this->send($plain, $this->form())->assertStatus(201)->assertJsonPath('data.outcome', 'created');

        $lead = Lead::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame(['Ravi Patel', '9876543210', 'ravi@zephyr.test', 'Rajkot', 'Website'], [$lead->contact_name, $lead->phone, $lead->email, $lead->city, $lead->source]);
        $this->assertSame('Need 300 sqm PUF wall panel', $lead->requirement);
        $this->assertSame('https://www.zephyr.test/cold-rooms', $lead->landing_page);
        $this->assertSame(['cold-room-summer', 'google', 'cpc'], [$lead->campaign, $lead->utm_source, $lead->utm_medium]);
        $this->assertSame('web-k3j2h1g0f9', $lead->external_id);
    }

    public function test_the_referrer_and_the_whole_message_are_kept_in_the_original_message(): void
    {
        [, $plain] = $this->token();
        $this->send($plain, $this->form())->assertStatus(201);

        $payload = LeadPayload::withoutGlobalScope('tenant')->firstOrFail()->payload;

        $this->assertSame('https://www.google.com/', $payload['referrer']);
        $this->assertSame(14500, $payload['_t']);
    }

    public function test_a_double_click_makes_one_lead(): void
    {
        [, $plain] = $this->token();

        $this->send($plain, $this->form())->assertStatus(201);
        $this->send($plain, $this->form())->assertOk()->assertJsonPath('data.outcome', 'already_received');

        $this->assertSame(1, Lead::where('company_id', $this->company->id)->count());
    }

    public function test_a_form_with_neither_phone_nor_email_is_refused_with_words_the_form_can_show(): void
    {
        [, $plain] = $this->token();

        $res = $this->send($plain, $this->form(['phone' => '', 'email' => '']))->assertStatus(422);

        $this->assertNotSame('', $res->json('message'));
        $this->assertSame(0, Lead::where('company_id', $this->company->id)->count());
    }

    public function test_the_landing_page_is_cut_to_the_column_and_blank_is_nothing(): void
    {
        [, $plain] = $this->token();

        $this->send($plain, $this->form(['landing_page' => 'https://x.test/' . str_repeat('a', 800)]))->assertStatus(201);
        $this->send($plain, $this->form(['external_id' => 'web-2', 'phone' => '9123456780', 'email' => '', 'landing_page' => '']))->assertStatus(201);

        $this->assertSame([500, null], Lead::orderBy('id')->get()->map(fn ($l) => $l->landing_page === null ? null : mb_strlen($l->landing_page))->all());
    }

    // ── from a browser on another website ────────────────────────────────────

    public function test_a_browser_on_another_website_is_allowed_to_post(): void
    {
        [, $plain] = $this->token();

        $pre = $this->call('OPTIONS', "/api/inbound/leads/{$plain}", [], [], [], [
            'HTTP_ORIGIN' => 'https://www.zephyr.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
        $this->assertContains($pre->getStatusCode(), [200, 204]);
        $this->assertNotNull($pre->headers->get('Access-Control-Allow-Origin'));

        $res = $this->send($plain, $this->form(), ['Origin' => 'https://www.zephyr.test'])->assertStatus(201);
        $this->assertNotNull($res->headers->get('Access-Control-Allow-Origin'));
    }

    // ── limiting the address to the company's websites ───────────────────────

    public function test_with_no_list_any_website_may_use_the_address(): void
    {
        [, $plain] = $this->token();

        $this->send($plain, $this->form(), ['Origin' => 'https://anything.example'])->assertStatus(201);
    }

    public function test_with_a_list_only_those_websites_may_post(): void
    {
        [, $plain] = $this->token(['https://www.zephyr.test', 'https://zephyr.test:8443']);

        $this->send($plain, $this->form(), ['Origin' => 'https://www.zephyr.test'])->assertStatus(201);
        $this->send($plain, $this->form(['external_id' => 'a', 'phone' => '9111111111', 'email' => '']), ['Origin' => 'https://ZEPHYR.test:8443'])->assertStatus(201);
    }

    public function test_another_website_is_refused_and_nothing_is_created_and_it_is_logged(): void
    {
        [$row, $plain] = $this->token(['https://www.zephyr.test']);

        $this->send($plain, $this->form(), ['Origin' => 'https://copycat.example'])->assertStatus(403)->assertJsonPath('error_code', 'ORIGIN_NOT_ALLOWED');

        $this->assertSame(0, Lead::where('company_id', $this->company->id)->count());
        $log = LeadImportLog::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame(['blocked', $row->id], [$log->outcome, $log->token_id]);
        $this->assertStringContainsString('copycat.example', $log->reason);
    }

    public function test_the_http_version_and_a_different_port_are_different_websites(): void
    {
        [, $plain] = $this->token(['https://www.zephyr.test']);

        foreach (['http://www.zephyr.test', 'https://www.zephyr.test:8443', 'https://zephyr.test', 'null'] as $i => $origin) {
            $this->send($plain, $this->form(['external_id' => "x{$i}"]), ['Origin' => $origin])->assertStatus(403);
        }
    }

    public function test_a_server_that_sends_without_an_origin_is_not_stopped_by_the_list(): void
    {
        [, $plain] = $this->token(['https://www.zephyr.test']);

        $this->send($plain, $this->form())->assertStatus(201);       // curl, Zapier, a partner's server: no browser, no Origin header
    }

    public function test_the_list_does_not_hide_a_wrong_address(): void
    {
        $this->send('lt_' . str_repeat('x', 48), $this->form(), ['Origin' => 'https://www.zephyr.test'])->assertStatus(401);
    }

    // ── spam ─────────────────────────────────────────────────────────────────

    public function test_a_form_posted_in_under_a_second_looks_like_success_and_creates_nothing(): void
    {
        [, $plain] = $this->token();

        $this->send($plain, $this->form(['_t' => 200]))->assertOk()->assertJsonPath('data.outcome', 'received');

        $this->assertSame(0, Lead::where('company_id', $this->company->id)->count());
        $this->assertSame('spam', LeadImportLog::where('company_id', $this->company->id)->value('outcome'));
    }

    public function test_a_post_without_the_timer_is_not_treated_as_spam(): void
    {
        [, $plain] = $this->token();
        $body = $this->form();
        unset($body['_t']);

        $this->send($plain, $body)->assertStatus(201);
    }

    public function test_the_hidden_field_still_catches_bots(): void
    {
        [, $plain] = $this->token();

        $this->send($plain, $this->form(['_hp' => 'http://spam.example']))->assertOk()->assertJsonPath('data.outcome', 'received');

        $this->assertSame(0, Lead::where('company_id', $this->company->id)->count());
    }

    // ── managing the list ────────────────────────────────────────────────────

    public function test_the_screen_saves_the_websites_as_tidy_origins(): void
    {
        [$row] = $this->token();
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/settings/lead-inbound-tokens/{$row->id}", ['allowed_origins' => ['https://WWW.Zephyr.test/some/page?x=1', 'https://www.zephyr.test', 'http://localhost:5173']])
            ->assertOk()->assertJsonPath('data.allowed_origins', ['https://www.zephyr.test', 'http://localhost:5173']);

        $this->getJson('/api/settings/lead-inbound-tokens')->assertOk()->assertJsonPath('data.0.allowed_origins.0', 'https://www.zephyr.test');
    }

    public function test_an_empty_list_means_any_website_again(): void
    {
        [$row] = $this->token(['https://www.zephyr.test']);
        $this->actingAs($this->admin, 'sanctum');

        $this->putJson("/api/settings/lead-inbound-tokens/{$row->id}", ['allowed_origins' => []])->assertOk()->assertJsonPath('data.allowed_origins', null);
    }

    public function test_something_that_is_not_a_website_is_refused_with_an_example(): void
    {
        [$row] = $this->token();
        $this->actingAs($this->admin, 'sanctum');

        $res = $this->putJson("/api/settings/lead-inbound-tokens/{$row->id}", ['allowed_origins' => ['www.zephyr.test']])->assertStatus(422)->assertJsonValidationErrors('allowed_origins');

        $this->assertStringContainsString('https://www.example.com', $res->json('message'));
        $this->assertNull($row->fresh()->allowed_origins);
    }

    public function test_only_ten_websites_and_only_for_your_own_address(): void
    {
        [$row] = $this->token();
        $this->actingAs($this->admin, 'sanctum');
        $eleven = array_map(fn ($i) => "https://site{$i}.test", range(1, 11));

        $this->putJson("/api/settings/lead-inbound-tokens/{$row->id}", ['allowed_origins' => $eleven])->assertStatus(422);

        $other = Company::factory()->create();
        $theirs = app(LeadInboundService::class)->createToken($other->id, 'Theirs', 'Website')[0];
        $this->putJson("/api/settings/lead-inbound-tokens/{$theirs->id}", ['allowed_origins' => ['https://a.test']])->assertStatus(404);
    }

    public function test_changing_the_websites_needs_the_settings_permission(): void
    {
        [$row] = $this->token();
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false]), 'sanctum');

        $this->putJson("/api/settings/lead-inbound-tokens/{$row->id}", ['allowed_origins' => ['https://a.test']])->assertStatus(403);
    }

    public function test_a_new_address_keeps_its_website_list(): void
    {
        [$row, $old] = $this->token(['https://www.zephyr.test']);
        $this->actingAs($this->admin, 'sanctum');

        $new = $this->postJson("/api/settings/lead-inbound-tokens/{$row->id}/regenerate")->assertOk()->json('data.token');

        $this->assertSame(['https://www.zephyr.test'], $row->fresh()->allowed_origins);
        $this->send($new, $this->form(), ['Origin' => 'https://copycat.example'])->assertStatus(403);
        $this->send($old, $this->form())->assertStatus(401);
    }

    // ── the script itself ────────────────────────────────────────────────────

    public function test_the_script_the_screen_hands_out_exists_and_sends_what_the_service_expects(): void
    {
        $js = file_get_contents(base_path('../frontend/public/lead-form.js'));

        foreach (['data-token', '/api/inbound/leads/', '_hp', '_t', 'external_id', 'landing_page', 'utm_campaign', 'panelos:lead'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        $this->assertSame(1000, LeadInboundService::MIN_FILL_MS);
    }
}
