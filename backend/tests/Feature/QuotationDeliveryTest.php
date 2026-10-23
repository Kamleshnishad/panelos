<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelType;
use App\Models\Quotation;
use Tests\TestCase;

/**
 * Step 0.4 of QUOTATION_TEMPLATES_PLAN.md — a delivery address and a site contact on the quotation. The
 * buyer's registered address and the site the goods go to are often different (the client's own proforma
 * carries both) and the quotation only had a one-line project location.
 */
class QuotationDeliveryTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function rows(PanelType $pt): array
    {
        return [[
            'panel_type_id' => $pt->id, 'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
            'top_skin_material' => 'PPGI', 'top_skin_thickness' => '0.40', 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => '0.40',
            'sizes' => [['length_mm' => 3000, 'nos' => 4, 'rate_per_sqm' => 900]],
        ]];
    }

    /** @return array{0:\App\Models\User,1:Quotation,2:array} */
    private function quotation(array $extra = []): array
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $rows = $this->rows(PanelType::factory()->create(['company_id' => $admin->company_id]));
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', array_merge([
            'customer_id' => $customer->id, 'panel_rows' => $rows,
            'delivery_address' => 'Survey No. 1116, B/h. Pioneer Polymers, Kadi - Nandasan Road, Nandasan, Dist. Mehsana - 382705',
            'site_contact_name' => 'Site supervisor', 'site_contact_phone' => '63545 66678',
        ], $extra))->assertStatus(201);

        return [$admin, Quotation::find($res->json('data.id')), $rows];
    }

    public function test_the_fields_are_saved_and_returned(): void
    {
        [$admin, $q] = $this->quotation();

        $show = $this->actingAs($admin, 'sanctum')->getJson("/api/quotations/{$q->id}")->assertOk();

        $this->assertStringContainsString('Pioneer Polymers', $show->json('data.delivery_address'));
        $this->assertSame('Site supervisor', $show->json('data.site_contact_name'));
        $this->assertSame('63545 66678', $show->json('data.site_contact_phone'));
    }

    public function test_an_update_can_change_clear_or_leave_them_alone(): void
    {
        [$admin, $q, $rows] = $this->quotation();
        $put = fn (array $body) => $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$q->id}",
            array_merge(['customer_id' => $q->customer_id, 'panel_rows' => $rows], $body));

        $put(['site_contact_name' => 'New person'])->assertOk();
        $this->assertSame('New person', $q->fresh()->site_contact_name);
        $this->assertStringContainsString('Pioneer Polymers', $q->fresh()->delivery_address, 'An untouched field keeps its value.');

        // The form sends an emptied field as an empty string — that must really clear it.
        $put(['delivery_address' => '', 'site_contact_phone' => null])->assertOk();
        $this->assertNull($q->fresh()->delivery_address);
        $this->assertNull($q->fresh()->site_contact_phone);
        $this->assertSame('New person', $q->fresh()->site_contact_name);
    }

    public function test_a_nonsense_phone_is_rejected_but_ordinary_formats_are_not(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $rows = $this->rows(PanelType::factory()->create(['company_id' => $admin->company_id]));
        $post = fn ($phone) => $this->actingAs($admin, 'sanctum')->postJson('/api/quotations',
            ['customer_id' => $customer->id, 'panel_rows' => $rows, 'site_contact_phone' => $phone]);

        $post('call me maybe')->assertStatus(422)->assertJsonValidationErrors(['site_contact_phone']);
        foreach (['9876543210', '+91 98765 43210', '0265-2345678', '(022) 2345 6789'] as $ok) {
            $post($ok)->assertStatus(201);
        }
    }

    public function test_revise_and_duplicate_carry_them(): void
    {
        [$admin, $q] = $this->quotation();

        $rev = $this->actingAs($admin, 'sanctum')->postJson("/api/quotations/{$q->id}/revise")->assertStatus(201)->json('data.id');
        $dup = $this->actingAs($admin, 'sanctum')->postJson("/api/quotations/{$q->id}/duplicate")->assertStatus(201)->json('data.id');

        foreach ([$rev, $dup] as $id) {
            $copy = Quotation::find($id);
            $this->assertSame('63545 66678', $copy->site_contact_phone);
            $this->assertStringContainsString('Pioneer Polymers', $copy->delivery_address);
        }
    }

    public function test_the_ledger_prints_them_only_when_present(): void
    {
        [, $q] = $this->quotation();
        $html = view('quotations.pdf_ledger', ['quotation' => $q->fresh()])->render();

        $this->assertStringContainsString('Deliver to: <b>Survey No. 1116', $html);
        $this->assertStringContainsString('Site contact: <b>Site supervisor · 63545 66678</b>', $html);

        $q->update(['delivery_address' => null, 'site_contact_name' => null, 'site_contact_phone' => null]);
        $html = view('quotations.pdf_ledger', ['quotation' => $q->fresh()])->render();
        $this->assertStringNotContainsString('Deliver to:', $html);
        $this->assertStringNotContainsString('Site contact:', $html);
    }
}
