<?php

namespace Tests\Traits;

use App\Models\Accessory;
use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;

/** The real UMA quotation (SCP-2027-012) as a fixture: 7,72,437.50 taxable, 1,39,038.75 GST, 9,11,476.00 total. */
trait BuildsUmaQuotation
{
    use HasDatabaseTestHelpers;

    protected function panelRow(PanelType $pt, string $application, int $thickness, int $length, int $nos, float $rate): array
    {
        return ['panel_type_id' => $pt->id, 'application' => $application, 'thickness' => $thickness,
            'density_type' => 'PUF', 'density_kgm3' => 40,
            'top_skin_material' => 'PPGL', 'top_skin_thickness' => '0.50', 'top_color' => 'Off White', 'top_color_ral' => '9002', 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGL', 'bottom_skin_thickness' => '0.50', 'bottom_color' => 'Off White', 'bottom_color_ral' => '9002', 'bottom_surface' => 'PLAIN',
            'guard_film' => true, 'cello_tap' => true, 'hsn_code' => '39259010',
            'sizes' => [['length_mm' => $length, 'nos' => $nos, 'rate_per_sqm' => $rate]]];
    }

    protected function acc(int $company, string $name, string $unit, string $hsn): Accessory
    {
        return Accessory::factory()->create(['company_id' => $company, 'name' => $name, 'unit' => $unit, 'hsn_code' => $hsn, 'description' => $name]);
    }

    /** @return array{0:\App\Models\User,1:Quotation} */
    protected function umaQuotation(array $over = [], array $customerOver = []): array
    {
        $admin = $this->createAdminUser();
        $cid = $admin->company_id;
        $admin->company->update(['name' => 'Signature PUF Panel', 'city' => 'Vadodara', 'state' => 'Gujarat', 'state_code' => '24',
            'gstin' => '24AAFFU9050M1ZS', 'pan' => 'AAFFU9050M', 'bank_name' => 'HDFC Bank Ltd', 'bank_branch' => 'Manjusar, Vadodara',
            'bank_account_no' => '59200000008899', 'bank_ifsc' => 'HDFC0003001',
            'authorized_signatory' => 'Pratik Patel', 'signatory_phone' => '9574799722']);

        $customer = Customer::factory()->create(array_merge(['company_id' => $cid, 'name' => 'IDEAL AGRI CARE', 'state' => 'Gujarat', 'state_code' => '24',
            'city' => 'Ahmedabad', 'pincode' => '382470', 'gstin' => null, 'pan' => null, 'contact_person' => null, 'whatsapp_no' => null, 'email' => null], $customerOver));
        $ceiling = PanelType::factory()->create(['company_id' => $cid, 'name' => 'Signature Ceiling Panel']);
        $wall    = PanelType::factory()->create(['company_id' => $cid, 'name' => 'Signature Wall Panel']);

        $acc = fn ($a, $q, $r, $type = 'standard', $extra = []) => array_merge(
            ['type' => $type, 'accessory_id' => $a->id, 'qty' => $q, 'rate' => $r, 'description' => $a->name, 'unit' => $a->unit], $extra);
        $single = $this->acc($cid, 'Clean Room Manual Swing Door - Single', 'NOS', '73083000');
        $double = $this->acc($cid, 'Clean Room Manual Swing Door - Double', 'NOS', '73083000');

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', array_merge([
            'customer_id' => $customer->id, 'project_name' => 'IDEAL AGRI CARE - Nandasan, Kadi',
            'project_location' => 'Nandasan (Navapura), Ta. Kadi, Dist. Mehsana', 'quality_grade' => 'High',
            'advance_pct' => 50, 'validity_days' => 10,
            'panel_rows' => [$this->panelRow($ceiling, 'Ceiling', 30, 4450, 30, 1075), $this->panelRow($wall, 'Wall', 50, 2750, 84, 1275)],
            'accessories' => [
                $acc($this->acc($cid, 'Aluminium T Channel', 'MTR', '76169990'), 15, 950),
                $acc($this->acc($cid, '3D Corner', 'NOS', '76169990'), 25, 320),
                $acc($this->acc($cid, 'Aluminium Coving', 'MTR', '76169990'), 130, 325),
                $acc($this->acc($cid, 'C Channel', 'MTR', '73089090'), 100, 130),
                $acc($this->acc($cid, 'Aluminium Outer Corner', 'MTR', '76169990'), 9, 450),
                $acc($single, 4, 17500, 'door', ['door_type' => 'hinged_door', 'door_width' => 1000, 'door_height' => 2100]),
                $acc($double, 3, 24500, 'door', ['door_type' => 'hinged_door', 'door_width' => 2000, 'door_height' => 2100]),
                ['type' => 'installation', 'description' => 'Installation', 'qty' => 364.5, 'rate' => 300, 'unit' => 'MTR'],
            ],
        ], $over))->assertStatus(201);

        return [$admin, Quotation::find($res->json('data.id'))];
    }
}
