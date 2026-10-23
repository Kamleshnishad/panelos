<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Support\GstState;
use Tests\TestCase;

/**
 * Regression: quotations mapped the supplier's GSTIN prefix to an ALPHA code
 * ("27" → "MH") and compared it with customers.state_code, while invoices
 * compared companies.state_code to customers.state_code as stored. A customer
 * entered as "GJ" against a company on "24" was intra-state on the quotation and
 * inter-state on the invoice — CGST+SGST on one document, IGST on the other.
 */
class GstStateNormalisationTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_every_accepted_format_normalises_to_the_gst_code(): void
    {
        $this->assertSame('24', GstState::normalize('24'));
        $this->assertSame('24', GstState::normalize('GJ'));
        $this->assertSame('24', GstState::normalize('gj'));
        $this->assertSame('24', GstState::normalize('Gujarat'));
        $this->assertSame('24', GstState::normalize('24AAFFU9050M1ZS'));
        $this->assertSame('07', GstState::normalize('7'));
        $this->assertSame('07', GstState::normalize('DL'));
        $this->assertNull(GstState::normalize(''));
        $this->assertNull(GstState::normalize('ZZ'));
    }

    public function test_state_code_is_normalised_on_save(): void
    {
        $company = Company::factory()->create(['state_code' => 'GJ']);
        $this->assertSame('24', $company->fresh()->state_code);

        $customer = Customer::factory()->create(['company_id' => $company->id, 'state_code' => 'Gujarat']);
        $this->assertSame('24', $customer->fresh()->state_code);
    }

    public function test_quotation_and_invoice_agree_on_the_same_customer(): void
    {
        $company = Company::factory()->create(['state_code' => '24', 'gstin' => '24AAFFU9050M1ZS']);

        // Same state, written two different ways
        $gj = Customer::factory()->create(['company_id' => $company->id, 'state_code' => 'GJ']);
        $this->assertFalse(GstState::isInterState($company, $gj));

        // Different state
        $mh = Customer::factory()->create(['company_id' => $company->id, 'state_code' => '27']);
        $this->assertTrue(GstState::isInterState($company, $mh));

        // Unknown state falls back to intra-state (the conservative default the
        // quotation engine already used), so the two documents still agree.
        $unknown = Customer::factory()->create([
            'company_id' => $company->id, 'state_code' => '', 'state' => '', 'gstin' => null,
        ]);
        $this->assertFalse(GstState::isInterState($company, $unknown));
    }

    public function test_state_is_derived_from_a_gstin_when_no_code_is_set(): void
    {
        $company  = Company::factory()->create(['state_code' => '24', 'gstin' => '24AAFFU9050M1ZS']);
        $customer = Customer::factory()->create([
            'company_id' => $company->id,
            'state_code' => '',
            'state'      => '',
            'gstin'      => '27AAAAA0000A1Z5',
        ]);

        $this->assertSame('27', GstState::forParty($customer));
        $this->assertTrue(GstState::isInterState($company, $customer));
    }
}
