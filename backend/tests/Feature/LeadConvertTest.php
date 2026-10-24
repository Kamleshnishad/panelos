<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PanelType;
use App\Models\User;
use App\Services\LeadService;
use Tests\TestCase;

/** LEADS_PLAN 1.5: converting a lead never creates a bad or duplicate customer. */
class LeadConvertTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private const GSTIN_GJ = '24AAACC1206D1ZM';    // state code 24
    private const GSTIN_MH = '27AAPFU0939F1ZV';    // state code 27

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
        return app(LeadService::class)->create($this->company->id, $over + ['contact_name' => 'Ravi Patel', 'company_name' => 'Zephyr Cold Storage', 'phone' => '9876543210', 'city' => 'Rajkot', 'source' => 'Phone']);
    }

    private function convert(Lead $lead, array $body = [])
    {
        return $this->postJson("/api/leads/{$lead->id}/convert", $body);
    }

    private function customer(array $over = []): Customer
    {
        return Customer::factory()->create($over + ['company_id' => $this->company->id]);
    }

    // ── the state ────────────────────────────────────────────────────────────

    public function test_a_new_customer_needs_a_state_and_nothing_is_created_without_one(): void
    {
        $lead = $this->lead();

        $this->convert($lead)->assertStatus(422)->assertJsonValidationErrors('state_code');

        $this->assertSame(0, Customer::where('company_id', $this->company->id)->count());
        $this->assertNull($lead->fresh()->customer_id);
    }

    public function test_the_chosen_state_is_stored_as_the_gst_code_and_name(): void
    {
        $lead = $this->lead();

        $id = $this->convert($lead, ['state_code' => 'Gujarat'])->assertOk()->json('data.customer_id');

        $c = Customer::find($id);
        $this->assertSame('24', $c->getRawOriginal('state_code'));
        $this->assertSame('Gujarat', $c->state);
    }

    public function test_the_state_can_come_from_the_gstin(): void
    {
        $lead = $this->lead();

        $id = $this->convert($lead, ['gstin' => self::GSTIN_MH])->assertOk()->json('data.customer_id');

        $c = Customer::find($id);
        $this->assertSame('27', $c->getRawOriginal('state_code'));
        $this->assertSame(self::GSTIN_MH, $c->gstin);
    }

    public function test_a_state_that_disagrees_with_the_gstin_is_refused(): void
    {
        $this->convert($this->lead(), ['gstin' => self::GSTIN_MH, 'state_code' => '24'])->assertStatus(422)->assertJsonValidationErrors('state_code');
    }

    public function test_a_malformed_gstin_is_refused(): void
    {
        $this->convert($this->lead(), ['gstin' => '24AAAAAAAAAAAAA', 'state_code' => '24'])->assertStatus(422)->assertJsonValidationErrors('gstin');
    }

    public function test_the_address_and_city_are_carried_to_the_customer(): void
    {
        $lead = $this->lead(['contact_name' => 'Zoya Khan', 'email' => 'zoya@zephyr.test']);

        $id = $this->convert($lead, ['state_code' => '24', 'address_line1' => 'Plot 12, GIDC', 'pincode' => '360003'])->assertOk()->json('data.customer_id');

        $c = Customer::find($id);
        $this->assertSame('Plot 12, GIDC', $c->address_line1);
        $this->assertSame('360003', $c->pincode);
        $this->assertSame('Rajkot', $c->city);
        $this->assertSame('Zoya Khan', $c->contact_person);
        $this->assertSame('zoya@zephyr.test', $c->email);
        $this->assertSame('Zephyr Cold Storage', $c->name);
    }

    public function test_the_new_customer_gets_the_right_gst_split_on_a_quotation(): void
    {
        // The reason for asking: a blank state meant the split was guessed. A Gujarat customer on a Gujarat company is CGST+SGST.
        $this->company->forceFill(['state_code' => '24'])->save();
        $lead = $this->lead();
        $customer = $this->convert($lead, ['state_code' => '27'])->json('data.customer_id');
        $pt = PanelType::factory()->create(['company_id' => $this->company->id]);

        $q = $this->postJson('/api/quotations', ['customer_id' => $customer, 'lead_id' => $lead->id, 'panel_rows' => $this->panelRows($pt->id, 10, 900)])->assertStatus(201)->json('data');

        $this->assertTrue((bool) $q['is_inter_state'], 'Maharashtra customer, Gujarat supplier: IGST.');
    }

    // ── customer codes ───────────────────────────────────────────────────────

    public function test_customer_codes_count_up_and_never_repeat(): void
    {
        $codes = [];
        foreach (range(1, 5) as $i) {
            $lead = $this->lead(['company_name' => 'Zephyr Traders ' . $i, 'phone' => '98765432' . (10 + $i)]);
            $codes[] = Customer::find($this->convert($lead, ['state_code' => '24'])->json('data.customer_id'))->code;
        }

        $this->assertSame(['ZEPH101', 'ZEPH102', 'ZEPH103', 'ZEPH104', 'ZEPH105'], $codes);
    }

    public function test_the_next_code_goes_past_an_old_random_one_and_ignores_other_companies(): void
    {
        $this->customer(['name' => 'Zephyr Old', 'code' => 'ZEPH734']);
        $other = Company::factory()->create();
        Customer::factory()->create(['company_id' => $other->id, 'code' => 'ZEPH900']);

        $this->assertSame('ZEPH735', Customer::nextCode($this->company->id, 'Zephyr New'));
        $this->assertSame('CUST101', Customer::nextCode($this->company->id, '123 456'), 'A name with no letters falls back to CUST.');
    }

    public function test_the_customer_screen_uses_the_same_codes(): void
    {
        $this->customer(['code' => 'ACME250', 'name' => 'Acme']);

        $res = $this->postJson('/api/customers', ['name' => 'Acme Two', 'phone' => '9811122233'])->assertStatus(201);

        $this->assertSame('ACME251', $res->json('data.code'));
    }

    // ── existing customers ───────────────────────────────────────────────────

    public function test_an_existing_customer_with_the_same_phone_is_offered_not_duplicated(): void
    {
        $existing = $this->customer(['name' => 'Ravi Patel Traders', 'phone' => '+91 98765-43210']);
        $lead = $this->lead(['company_name' => 'Zephyr Cold Storage']);

        $res = $this->convert($lead, ['state_code' => '24'])->assertStatus(409);

        $res->assertJsonPath('error_code', 'CUSTOMER_EXISTS');
        $m = $res->json('errors.customers.0');
        $this->assertSame($existing->id, $m['id']);
        $this->assertSame(['phone'], $m['matched_on']);
        $this->assertSame(1, Customer::where('company_id', $this->company->id)->count(), 'Nothing was created.');
        $this->assertNull($lead->fresh()->customer_id);
    }

    public function test_matching_on_email_gstin_and_name(): void
    {
        $byEmail = $this->customer(['name' => 'A', 'phone' => '9000000001', 'email' => 'Ravi@Zephyr.test', 'gstin' => null]);
        $byGstin = $this->customer(['name' => 'B', 'phone' => '9000000002', 'email' => null, 'gstin' => self::GSTIN_GJ]);
        $byName = $this->customer(['name' => 'zephyr cold storage ', 'phone' => '9000000003', 'email' => null, 'gstin' => null]);
        $lead = $this->lead(['email' => 'ravi@zephyr.test', 'phone' => '9811111111']);

        $m = collect($this->convert($lead, ['gstin' => self::GSTIN_GJ])->assertStatus(409)->json('errors.customers'))->keyBy('id');

        $this->assertSame(['email'], $m[$byEmail->id]['matched_on']);
        $this->assertSame(['gstin'], $m[$byGstin->id]['matched_on']);
        $this->assertSame(['name'], $m[$byName->id]['matched_on']);
    }

    public function test_choosing_the_existing_customer_links_it_and_writes_the_timeline(): void
    {
        $existing = $this->customer(['name' => 'Ravi Patel Traders', 'phone' => '9876543210']);
        $lead = $this->lead();

        $res = $this->convert($lead, ['customer_id' => $existing->id])->assertOk();

        $this->assertSame($existing->id, $res->json('data.customer_id'));
        $this->assertSame($existing->id, $lead->fresh()->customer_id);
        $this->assertSame(1, Customer::where('company_id', $this->company->id)->count());
        $this->assertTrue(LeadActivity::where('lead_id', $lead->id)->where('description', 'Customer linked: Ravi Patel Traders')->exists());
    }

    public function test_create_new_goes_ahead_after_the_matches_were_seen(): void
    {
        $existing = $this->customer(['name' => 'Ravi Patel Traders', 'phone' => '9876543210']);
        $lead = $this->lead();

        $id = $this->convert($lead, ['create_new' => true, 'state_code' => '24'])->assertOk()->json('data.customer_id');

        $this->assertNotSame($existing->id, $id);
        $this->assertSame(2, Customer::where('company_id', $this->company->id)->count());
    }

    public function test_create_new_cannot_reuse_a_gstin_that_belongs_to_a_customer(): void
    {
        $this->customer(['gstin' => self::GSTIN_GJ, 'phone' => '9000000009']);

        $this->convert($this->lead(), ['create_new' => true, 'gstin' => self::GSTIN_GJ])->assertStatus(422)->assertJsonValidationErrors('gstin');
    }

    public function test_another_companys_customer_is_never_offered_or_linkable(): void
    {
        $other = Company::factory()->create();
        $stranger = Customer::factory()->create(['company_id' => $other->id, 'phone' => '9876543210', 'name' => 'Ravi Patel']);
        $lead = $this->lead();

        $this->convert($lead, ['state_code' => '24'])->assertOk();            // no look-alike in this company: created
        $lead2 = $this->lead(['phone' => '9811111111', 'contact_name' => 'B', 'company_name' => 'B Co']);
        $this->convert($lead2, ['customer_id' => $stranger->id])->assertStatus(422)->assertJsonValidationErrors('customer_id');
    }

    public function test_a_lead_that_already_has_a_customer_returns_it_without_asking_anything(): void
    {
        $lead = $this->lead();
        $first = $this->convert($lead, ['state_code' => '24'])->json('data.customer_id');

        $this->assertSame($first, $this->convert($lead->fresh())->assertOk()->json('data.customer_id'));
        $this->assertSame(1, Customer::where('company_id', $this->company->id)->count());
    }
}
