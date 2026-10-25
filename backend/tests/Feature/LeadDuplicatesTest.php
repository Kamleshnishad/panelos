<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadMatcher;
use App\Services\LeadService;
use Tests\TestCase;

/** LEADS_PLAN 1.4: the same buyer is one lead, not three. */
class LeadDuplicatesTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function lead(array $over = []): Lead
    {
        return app(LeadService::class)->create($this->company->id, $over + ['contact_name' => 'Ravi Patel', 'source' => 'Phone']);
    }

    private function create(array $payload)
    {
        return $this->postJson('/api/leads', $payload + ['contact_name' => 'New Person']);
    }

    // ── the keys ─────────────────────────────────────────────────────────────

    public function test_phone_formats_reduce_to_one_key(): void
    {
        foreach (['9876543210', '+91 98765 43210', '+91-98765-43210', '098765 43210', '(91) 9876543210', '0091 9876543210', '98765.43210'] as $format) {
            $this->assertSame('9876543210', LeadMatcher::phoneKey($format), $format);
        }
    }

    public function test_a_number_too_short_to_identify_anyone_has_no_key(): void
    {
        $this->assertNull(LeadMatcher::phoneKey('12345'));
        $this->assertNull(LeadMatcher::phoneKey(''));
        $this->assertNull(LeadMatcher::phoneKey(null));
        $this->assertNull(LeadMatcher::phoneKey('n/a'));
    }

    public function test_email_keys_ignore_case_and_spaces_and_junk_has_none(): void
    {
        $this->assertSame('ravi@zephyr.test', LeadMatcher::emailKey('  Ravi@Zephyr.TEST '));
        $this->assertNull(LeadMatcher::emailKey('not an email'));
        $this->assertNull(LeadMatcher::emailKey(null));
    }

    public function test_the_keys_are_stored_and_follow_edits(): void
    {
        $lead = $this->lead(['phone' => '+91 98765 43210', 'email' => 'Ravi@Zephyr.test']);
        $this->assertSame('9876543210', $lead->fresh()->phone_key);
        $this->assertSame('ravi@zephyr.test', $lead->fresh()->email_key);

        $lead->update(['phone' => null, 'email' => null]);

        $this->assertNull($lead->fresh()->phone_key);
        $this->assertNull($lead->fresh()->email_key);
    }

    // ── warning on create ────────────────────────────────────────────────────

    public function test_a_different_format_of_the_same_phone_is_a_duplicate_and_the_answer_names_the_lead(): void
    {
        $other = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Sales Sam']);
        $existing = $this->lead(['phone' => '9876543210', 'company_name' => 'Zephyr', 'assigned_to_user_id' => $other->id]);

        $res = $this->create(['phone' => '+91 98765 43210'])->assertStatus(409);

        $res->assertJsonPath('error_code', 'DUPLICATE_LEAD');
        $d = $res->json('errors.duplicates.0');
        $this->assertSame($existing->id, $d['id']);
        $this->assertSame($existing->lead_no, $d['lead_no']);
        $this->assertSame('Sales Sam', $d['assigned_to']);
        $this->assertSame(['phone'], $d['matched_on']);
        $this->assertStringContainsString($existing->lead_no, $res->json('message'));
        $this->assertSame(1, Lead::count(), 'Nothing was created.');
    }

    public function test_the_same_email_in_another_case_is_a_duplicate(): void
    {
        $this->lead(['email' => 'ravi@zephyr.test']);

        $this->create(['email' => 'RAVI@Zephyr.Test'])->assertStatus(409)->assertJsonPath('errors.duplicates.0.matched_on', ['email']);
    }

    public function test_phone_and_email_matching_two_leads_returns_both_and_says_what_matched(): void
    {
        $a = $this->lead(['phone' => '9876543210']);
        $b = $this->lead(['contact_name' => 'B', 'email' => 'b@x.test']);

        $d = $this->create(['phone' => '9876543210', 'email' => 'b@x.test'])->assertStatus(409)->json('errors.duplicates');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($d, 'id'));
    }

    public function test_send_allow_duplicate_to_create_it_anyway(): void
    {
        $this->lead(['phone' => '9876543210']);

        $this->create(['phone' => '9876543210', 'allow_duplicate' => true])->assertStatus(201);

        $this->assertSame(2, Lead::where('phone_key', '9876543210')->count());
    }

    public function test_won_lost_and_invalid_leads_still_count_as_the_same_buyer(): void
    {
        foreach (['won', 'lost', 'invalid'] as $i => $status) {
            $l = $this->lead(['contact_name' => $status, 'phone' => '98000000' . $i . '0']);
            Lead::whereKey($l->id)->update(['status' => $status]);

            $this->create(['phone' => '98000000' . $i . '0'])->assertStatus(409)->assertJsonPath('errors.duplicates.0.status', $status);
        }
    }

    public function test_a_deleted_lead_does_not_match(): void
    {
        $gone = $this->lead(['phone' => '9876543210']);
        $this->deleteJson("/api/leads/{$gone->id}")->assertStatus(204);

        $this->create(['phone' => '9876543210'])->assertStatus(201);
    }

    public function test_another_companys_lead_never_matches(): void
    {
        $other = Company::factory()->create();
        app(LeadService::class)->create($other->id, ['contact_name' => 'Elsewhere', 'phone' => '9876543210', 'source' => 'Phone']);

        $this->create(['phone' => '9876543210'])->assertStatus(201);
    }

    public function test_leads_with_no_phone_and_no_email_never_match_each_other(): void
    {
        $this->lead(['contact_name' => 'Walk-in one']);

        $this->create(['contact_name' => 'Walk-in two'])->assertStatus(201);
        $this->create(['contact_name' => 'Short', 'phone' => '12345'])->assertStatus(201);
        $this->create(['contact_name' => 'Short again', 'phone' => '12345'])->assertStatus(201);
    }

    // ── warning on edit ──────────────────────────────────────────────────────

    public function test_editing_other_fields_never_warns_even_when_the_lead_already_has_a_twin(): void
    {
        $this->lead(['phone' => '9876543210']);
        $twin = $this->lead(['contact_name' => 'Twin', 'phone' => '9876543210']);

        $this->putJson("/api/leads/{$twin->id}", ['contact_name' => 'Twin', 'city' => 'Surat', 'phone' => '9876543210'])->assertOk();
        $this->putJson("/api/leads/{$twin->id}", ['city' => 'Vapi'])->assertOk();

        $this->assertSame('Vapi', $twin->fresh()->city);
    }

    public function test_changing_the_phone_to_another_leads_number_warns_and_changes_nothing(): void
    {
        $a = $this->lead(['phone' => '9876543210']);
        $b = $this->lead(['contact_name' => 'B', 'phone' => '9811111111']);

        $this->putJson("/api/leads/{$b->id}", ['phone' => '+91 98765 43210'])->assertStatus(409)->assertJsonPath('errors.duplicates.0.id', $a->id);
        $this->assertSame('9811111111', $b->fresh()->phone);

        $this->putJson("/api/leads/{$b->id}", ['phone' => '+91 98765 43210', 'allow_duplicate' => true])->assertOk();
        $this->assertSame('9876543210', $b->fresh()->phone_key);
    }

    public function test_a_lead_never_matches_itself_when_only_the_format_of_its_number_changes(): void
    {
        $a = $this->lead(['phone' => '9876543210']);

        $this->putJson("/api/leads/{$a->id}", ['phone' => '+91 98765 43210'])->assertOk();
    }

    // ── the migration's catch-up ─────────────────────────────────────────────

    public function test_leads_saved_before_the_keys_existed_are_found_once_keyed(): void
    {
        $old = $this->lead(['phone' => '98765 43210']);
        Lead::whereKey($old->id)->update(['phone_key' => null]);                       // as a lead from before this release
        $this->create(['phone' => '9876543210'])->assertStatus(201);                    // not found until it is keyed: this is why the migration fills them

        $migration = require base_path('database/migrations/2026_10_25_000002_lead_match_keys.php');
        $migration->up();

        $this->assertSame('9876543210', $old->fresh()->phone_key);
    }
}
