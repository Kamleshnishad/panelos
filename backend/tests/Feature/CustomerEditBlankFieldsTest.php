<?php

namespace Tests\Feature;

use App\Models\Customer;
use Tests\TestCase;

/**
 * Editing a customer through the form sends EVERY field, with an empty string for
 * each one left blank. Laravel turns those into null, and several customer
 * columns are NOT NULL (address_line1, city, state, state_code, pincode, phone,
 * credit_limit, payment_terms_days). Create already coalesced them to ''; update
 * wrote the nulls straight through, so the whole edit failed.
 */
class CustomerEditBlankFieldsTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** Exactly the payload CustomerEditModal sends for a sparsely filled customer. */
    private function modalPayload(array $over = []): array
    {
        return array_merge([
            'name' => 'TEST- E2E Cold Store', 'type' => 'retail',
            'contact_person' => 'TEST- Lead', 'phone' => '9000000001',
            'whatsapp_no' => '', 'email' => '', 'gstin' => '', 'pan' => '',
            'address_line1' => '', 'city' => 'Vadodara', 'state' => '',
            'state_code' => '', 'pincode' => '', 'notes' => '',
            'credit_limit' => 0, 'payment_terms_days' => 30,
        ], $over);
    }

    public function test_editing_a_customer_with_blank_optional_fields_succeeds(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create([
            'company_id' => $admin->company_id, 'phone' => '9000000001', 'city' => 'Vadodara',
            'address_line1' => '', 'state' => '', 'state_code' => '', 'pincode' => '',
        ]);

        $res = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/customers/{$customer->id}", $this->modalPayload(['name' => 'Renamed Co']));

        $res->assertStatus(200);
        $this->assertSame('Renamed Co', $customer->fresh()->name);
    }

    public function test_clearing_a_previously_filled_field_does_not_crash(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create([
            'company_id' => $admin->company_id, 'phone' => '9812345678', 'address_line1' => '12 MG Road',
            'state' => 'Gujarat', 'state_code' => '24', 'pincode' => '390001',
        ]);

        // The user empties address, state and pincode in the form and saves.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/customers/{$customer->id}", $this->modalPayload(['phone' => $customer->phone]))
            ->assertStatus(200);

        $fresh = $customer->fresh();
        $this->assertSame('', $fresh->address_line1);
        $this->assertSame('', $fresh->state_code);
    }

    public function test_clearing_credit_limit_means_no_limit_not_an_error(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create([
            'company_id' => $admin->company_id, 'phone' => '9812345678', 'credit_limit' => 500000,
        ]);

        // An emptied number input arrives as null.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/customers/{$customer->id}", $this->modalPayload([
                'phone' => $customer->phone, 'credit_limit' => null, 'payment_terms_days' => null,
            ]))->assertStatus(200);

        $this->assertEquals(0, (float) $customer->fresh()->credit_limit);
        $this->assertSame(30, (int) $customer->fresh()->payment_terms_days);
    }

    /**
     * The same fault in the accessories edit: unit_price, rate and unit are NOT NULL,
     * and a cleared price field arrives as null. It returned a 500 that also leaked
     * the raw SQL error to the user.
     */
    public function test_blanking_an_accessory_price_does_not_crash(): void
    {
        $admin = $this->createAdminUser();
        $accessory = \App\Models\Accessory::factory()->create(['company_id' => $admin->company_id]);

        foreach ([['unit_price' => null], ['rate' => null], ['unit' => null]] as $blank) {
            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/accessories/{$accessory->id}", array_merge(['name' => 'Renamed'], $blank))
                ->assertStatus(200);
        }

        $this->assertSame('Renamed', $accessory->fresh()->name);
        $this->assertEquals(0, (float) $accessory->fresh()->unit_price);
        $this->assertSame('NOS', $accessory->fresh()->unit);
    }
}
