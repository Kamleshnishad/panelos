<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

class AccessoryTenantGuardTest extends TestCase
{
    public function test_cannot_attach_another_companys_accessory_to_quotation(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $customer = Customer::factory()->create(['company_id' => $ownCompany->id]);
        $quotation = Quotation::create([
            'company_id' => $ownCompany->id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-ACC-' . uniqid(), 'status' => 'draft',
        ]);
        $foreignAccessory = Accessory::factory()->create(['company_id' => $otherCompany->id]);

        $method = new \ReflectionMethod(QuotationService::class, 'saveAccessories');
        $method->setAccessible(true);

        $this->expectException(ModelNotFoundException::class);
        $method->invoke(app(QuotationService::class), $quotation, [
            ['type' => 'standard', 'accessory_id' => $foreignAccessory->id, 'qty' => 1, 'rate' => 10],
        ]);
    }

    public function test_own_companys_accessory_attaches(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id]);
        $quotation = Quotation::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-ACC2-' . uniqid(), 'status' => 'draft',
        ]);
        $own = Accessory::factory()->create(['company_id' => $company->id]);

        $method = new \ReflectionMethod(QuotationService::class, 'saveAccessories');
        $method->setAccessible(true);
        $method->invoke(app(QuotationService::class), $quotation, [
            ['type' => 'standard', 'accessory_id' => $own->id, 'qty' => 1, 'rate' => 10],
        ]);

        $this->assertTrue($quotation->accessories()->where('accessories.id', $own->id)->exists());
    }
}
