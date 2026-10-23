<?php

namespace Tests\Feature;

use App\Models\Customer;
use Tests\TestCase;

/**
 * Regression: every class in app/Rules implemented the deprecated
 * Illuminate\Contracts\Validation\Rule interface (which requires passes())
 * while only defining validate(). PHP refused to compile them — a FATAL error,
 * not an exception — so any endpoint whose validator instantiated one returned
 * a bare 500 with an empty body: customer create/update, company update,
 * supplier create/update and user create/update were all dead.
 */
class CustomerWriteRegressionTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    public function test_validation_rule_classes_are_usable(): void
    {
        $rules = [
            \App\Rules\IndianPhone::class   => ['9876543210', '12345'],
            \App\Rules\IndianGstin::class   => ['27AAAAA0000A1Z5', '27AAAAA0000A1QQ'],
            \App\Rules\IndianPan::class     => ['ABCDE1234F', 'ABCDE12345'],
            \App\Rules\IndianPincode::class => ['411001', '4110011'],
            \App\Rules\IndianIfsc::class    => ['HDFC0001234', 'HDFC1001234'],
        ];

        foreach ($rules as $class => [$good, $bad]) {
            $rule = new $class();

            $failures = [];
            $rule->validate('field', $good, function ($m) use (&$failures) { $failures[] = $m; });
            $this->assertSame([], $failures, "{$class} rejected a valid value");

            $rule->validate('field', $bad, function ($m) use (&$failures) { $failures[] = $m; });
            $this->assertNotEmpty($failures, "{$class} accepted an invalid value");
        }
    }

    public function test_customer_can_be_created_with_credit_terms(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/customers', [
            'name'               => 'TEST Cold Store',
            'type'               => 'wholesale',
            'phone'              => '9876543210',
            'state'              => 'Gujarat',
            'state_code'         => '24',
            'credit_limit'       => 500000,
            'payment_terms_days' => 45,
            'notes'              => 'Priority account',
        ]);

        $response->assertStatus(201);

        $customer = Customer::where('company_id', $admin->company_id)->firstOrFail();
        // Credit limit and payment terms used to be hard-coded to 0 / 30 in the
        // controller, so credit control could never engage for a new customer.
        $this->assertEquals(500000, (float) $customer->credit_limit);
        $this->assertEquals(45, (int) $customer->payment_terms_days);
        $this->assertSame('Priority account', $customer->notes);
    }

    public function test_customer_can_be_updated(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/customers/{$customer->id}", [
                'name'         => 'TEST Renamed',
                'credit_limit' => 250000,
            ])
            ->assertStatus(200);

        $this->assertSame('TEST Renamed', $customer->fresh()->name);
        $this->assertEquals(250000, (float) $customer->fresh()->credit_limit);
    }

    public function test_invalid_phone_is_a_422_not_a_500(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/customers', ['name' => 'TEST Bad Phone', 'phone' => '12345'])
            ->assertStatus(422);
    }
}
