<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadRequirementLine;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadRequirementService;
use Tests\TestCase;

/**
 * LEADS_PLAN 5.4: what a lead wants, as lines, and "Quote this lead". The quotation and BOQ modules are only called: these tests also prove the quotation
 * that comes out behaves like any other one (the real create endpoint accepts the same data, rates are pending, a BOQ converts, numbers keep counting).
 */
class LeadRequirementTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private PanelType $wall;
    private PanelType $roof;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true, 'name' => 'Admin']);
        $this->actingAs($this->admin, 'sanctum');
        $this->wall = PanelType::factory()->create(['company_id' => $this->company->id, 'name' => 'Wall Panel', 'category' => 'wall', 'is_active' => true, 'hsn_code' => '39259010']);
        $this->roof = PanelType::factory()->create(['company_id' => $this->company->id, 'name' => 'Roof Panel', 'category' => 'roof', 'is_active' => true]);
    }

    private function lead(array $o = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create($o + ['company_id' => $this->company->id, 'lead_no' => "LD-{$n}", 'contact_name' => 'Ravi Patel', 'company_name' => 'Zephyr Cold Storage', 'phone' => '98765432' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'city' => 'Rajkot', 'source' => 'Phone', 'status' => 'new']);
    }

    private function accessory(array $o = []): Accessory
    {
        return Accessory::factory()->create($o + ['company_id' => $this->company->id, 'name' => 'Cam-lock set', 'unit' => 'SET', 'unit_price' => 450, 'rate' => 450, 'is_active' => true]);
    }

    private function panel(array $o = []): array
    {
        return $o + ['kind' => 'panel', 'panel_type_id' => $this->wall->id, 'application' => 'Cold Room', 'thickness' => 100, 'area_sqm' => 300];
    }

    private function setReq(Lead $l, array $lines)
    {
        return $this->putJson("/api/leads/{$l->id}/requirement", ['lines' => $lines]);
    }

    private function quoteIt(Lead $l, array $body = [])
    {
        return $this->postJson("/api/leads/{$l->id}/quote", $body + ['state_code' => '24']);
    }

    // ── the requirement ──────────────────────────────────────────────────────

    public function test_a_requirement_is_saved_in_lines_with_names_and_adds_up_into_the_estimated_area(): void
    {
        $l = $this->lead(['est_qty_sqm' => 10]);
        $acc = $this->accessory();

        $res = $this->setReq($l, [$this->panel(), $this->panel(['panel_type_id' => $this->roof->id, 'application' => 'Roof', 'thickness' => 50, 'area_sqm' => 120.5]), ['kind' => 'accessory', 'accessory_id' => $acc->id, 'qty' => 12], ['kind' => 'accessory', 'description' => 'Site-made flashing', 'qty' => 4]])->assertOk();

        $lines = $res->json('data.lines');
        $this->assertSame(['Wall Panel', 'Roof Panel', null, null], array_column($lines, 'panel_type_name'));
        $this->assertSame([null, null, 'Cam-lock set', null], array_column($lines, 'accessory_name'));
        $this->assertSame('Site-made flashing', $lines[3]['description']);
        $this->assertEquals(420.5, $l->fresh()->est_qty_sqm, 'the lead estimate follows the panel lines');
        $this->assertStringContainsString('Requirement updated: 2 panel lines (420.5 sqm), 2 accessories', LeadActivity::where('lead_id', $l->id)->latest('id')->value('description'));
        $this->assertSame($lines, $this->getJson("/api/leads/{$l->id}/requirement")->assertOk()->json('data.lines'));
    }

    public function test_saving_again_replaces_the_lines_and_an_empty_list_clears_them_without_touching_the_estimate(): void
    {
        $l = $this->lead();
        $this->setReq($l, [$this->panel(), $this->panel(['area_sqm' => 50])])->assertOk();
        $this->setReq($l, [$this->panel(['area_sqm' => 80])])->assertOk();

        $this->assertSame(1, LeadRequirementLine::where('lead_id', $l->id)->count());
        $this->assertEquals(80, $l->fresh()->est_qty_sqm);

        $this->setReq($l, [])->assertOk();
        $this->assertSame(0, LeadRequirementLine::where('lead_id', $l->id)->count());
        $this->assertEquals(80, $l->fresh()->est_qty_sqm);
    }

    public function test_bad_lines_are_refused_in_words_and_nothing_is_saved(): void
    {
        $l = $this->lead();
        $other = PanelType::factory()->create(['company_id' => Company::factory()->create()->id, 'is_active' => true]);
        $off = PanelType::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);
        $foreign = Accessory::factory()->create(['company_id' => Company::factory()->create()->id, 'is_active' => true]);

        foreach ([
            [$this->panel(['panel_type_id' => $other->id]), 'panel types'],
            [$this->panel(['panel_type_id' => $off->id]), 'panel types'],
            [$this->panel(['thickness' => 77]), 'thickness'],
            [$this->panel(['application' => 'Moon base']), 'applications'],
            [$this->panel(['area_sqm' => 0]), 'area'],
            [$this->panel(['area_sqm' => 'lots']), 'area'],
            [['kind' => 'accessory', 'accessory_id' => $foreign->id, 'qty' => 1], 'accessories'],
            [['kind' => 'accessory', 'qty' => 1], 'describe'],
            [['kind' => 'accessory', 'description' => 'x', 'qty' => 0], 'quantity'],
            [['kind' => 'nonsense'], 'panel or an accessory'],
        ] as [$line, $words]) {
            $res = $this->setReq($l, [$line])->assertStatus(422);
            $this->assertStringContainsString($words, $res->json('message'), json_encode($line));
        }
        $this->assertSame(0, LeadRequirementLine::where('lead_id', $l->id)->count());
        $this->setReq($l, array_fill(0, LeadRequirementService::MAX_LINES + 1, $this->panel()))->assertStatus(422);
    }

    // ── quote this lead ──────────────────────────────────────────────────────

    public function test_quoting_makes_a_draft_from_the_lines_with_rates_still_pending_and_links_the_lead(): void
    {
        $l = $this->lead();
        $acc = $this->accessory();
        $this->setReq($l, [$this->panel(['area_sqm' => 310]), $this->panel(['panel_type_id' => $this->roof->id, 'application' => 'Roof', 'thickness' => 50, 'area_sqm' => 90]), ['kind' => 'accessory', 'accessory_id' => $acc->id, 'qty' => 12], ['kind' => 'accessory', 'description' => 'Site-made flashing', 'qty' => 4]])->assertOk();

        $res = $this->quoteIt($l)->assertStatus(201);

        $q = Quotation::with('items.sizes', 'accessories', 'lines')->findOrFail($res->json('data.quotation_id'));
        $this->assertSame(['draft', $l->id], [$q->status, $l->fresh()->quotation_id ? $l->id : null]);
        $this->assertSame($q->quotation_no, $res->json('data.quotation_no'));
        $this->assertCount(2, $q->items);
        $this->assertSame([100, 50], $q->items->pluck('thickness')->all());
        $this->assertEquals(['RIBBED' => 1, 'PLAIN' => 1], collect($q->items)->countBy('top_surface')->all(), 'a roof panel starts ribbed, as the form does');
        $this->assertGreaterThanOrEqual(310, (float) $q->items[0]->sizes->sum('sqm'), 'the area is covered, never under-quoted');
        $this->assertLessThan(313.1, (float) $q->items[0]->sizes->sum('sqm'));
        $this->assertSame([3000, 104], [(int) $q->items[0]->sizes[0]->length_mm, (int) $q->items[0]->sizes[0]->nos]);   // 310 / 3 = 103.33 -> 104 lengths of 3 m
        $this->assertSame([0.0, 0.0], [(float) $q->items[0]->sizes[0]->rate_per_sqm, (float) $q->items[1]->sizes[0]->rate_per_sqm], 'nothing is priced silently');
        $this->assertSame([450.0, 12.0], [(float) $q->accessories[0]->pivot->unit_price, (float) $q->accessories[0]->pivot->quantity], 'a catalogue accessory comes with its catalogue price');
        $this->assertSame(['custom', 'Site-made flashing', 0.0], [$q->lines[0]->type, $q->lines[0]->description, (float) $q->lines[0]->unit_price]);
        $this->assertSame('quoted', $l->fresh()->status);
        $this->assertSame('Zephyr Cold Storage, Rajkot', $q->project_name);
        $note = LeadActivity::where('lead_id', $l->id)->where('description', 'like', 'Quotation draft%')->value('description');
        $this->assertStringContainsString('sizes are a starting point', $note);
        $this->assertStringContainsString('Rates are still to be entered', $note);
    }

    public function test_the_quotation_it_makes_behaves_like_any_other_the_send_is_blocked_until_it_is_priced(): void
    {
        $l = $this->lead();
        $this->setReq($l, [$this->panel()]);
        $id = $this->quoteIt($l)->json('data.quotation_id');

        $this->assertTrue($this->getJson("/api/quotations/{$id}")->assertOk()->json('data.rates_pending'));
        $this->postJson("/api/quotations/{$id}/send")->assertStatus(400);

        $size = Quotation::find($id)->items()->first()->sizes()->first();
        $this->postJson("/api/quotations/{$id}/rates", ['rates' => [['id' => $size->id, 'rate_per_sqm' => 1450]]])->assertOk();
        $this->postJson("/api/quotations/{$id}/send")->assertOk();
        $this->assertSame('sent', Quotation::find($id)->status);
    }

    public function test_the_data_it_builds_is_accepted_by_the_real_quotation_endpoint(): void
    {
        // The drift guard: if the quotation rules change, this fails and says the lead's builder must follow.
        $l = $this->lead();
        $acc = $this->accessory();
        $this->setReq($l, [$this->panel(['application' => null]), $this->panel(['panel_type_id' => $this->roof->id, 'thickness' => 50, 'area_sqm' => 7.5]), ['kind' => 'accessory', 'accessory_id' => $acc->id, 'qty' => 2], ['kind' => 'accessory', 'description' => 'Flashing', 'qty' => 1]]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        foreach ([false, true] as $asBoq) {
            $payload = app(LeadRequirementService::class)->payload($l->fresh(), $customer->id, $asBoq);
            unset($payload['company_id']);
            $res = $this->postJson('/api/quotations', $payload);
            $this->assertSame(201, $res->status(), $res->getContent());
            $this->assertSame($asBoq ? 'boq' : 'draft', Quotation::find($res->json('data.id'))->status);
        }
    }

    public function test_as_boq_makes_a_boq_that_stays_out_of_the_quotation_list_and_converts_like_one(): void
    {
        $l = $this->lead();
        $this->setReq($l, [$this->panel()]);

        $res = $this->quoteIt($l, ['as_boq' => true])->assertStatus(201);
        $id = $res->json('data.quotation_id');

        $this->assertTrue($res->json('data.as_boq'));
        $this->assertSame('boq', Quotation::find($id)->status);
        $this->assertNotContains($id, collect($this->getJson('/api/quotations')->json('data'))->pluck('id')->all(), 'the Quotations screen never shows raw BOQs');
        $this->assertContains($id, collect($this->getJson('/api/quotations?status=boq')->json('data'))->pluck('id')->all());
        $this->postJson("/api/quotations/{$id}/convert")->assertOk();
        $this->assertSame('draft', Quotation::find($id)->status);
        $this->assertStringContainsString('BOQ', LeadActivity::where('lead_id', $l->id)->where('description', 'like', 'BOQ%')->value('description'));
    }

    public function test_numbers_keep_counting_for_quotations_made_this_way_and_the_old_way(): void
    {
        $l = $this->lead();
        $this->setReq($l, [$this->panel()]);
        $first = $this->quoteIt($l)->json('data.quotation_no');
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $l2 = $this->lead();
        $this->setReq($l2, [$this->panel()]);
        $second = $this->quoteIt($l2, ['customer_id' => $customer->id])->json('data.quotation_no');

        preg_match('/(\d+)$/', $first, $a);
        preg_match('/(\d+)$/', $second, $b);
        $this->assertSame((int) $a[1] + 1, (int) $b[1]);
    }

    public function test_quoting_makes_the_customer_the_way_convert_does_and_asks_the_same_questions(): void
    {
        $l = $this->lead(['phone' => '9811111111']);
        Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Zephyr Cold Storage Pvt', 'phone' => '9811111111']);
        $this->setReq($l, [$this->panel()]);

        $res = $this->quoteIt($l)->assertStatus(409);
        $this->assertSame('CUSTOMER_EXISTS', $res->json('error_code'));
        $this->assertCount(1, $res->json('errors.customers'));

        $this->postJson("/api/leads/{$l->id}/quote", ['state_code' => '24', 'create_new' => true])->assertStatus(201);
        $this->assertNotNull($l->fresh()->customer_id);
    }

    public function test_a_new_customer_needs_a_state_and_a_linked_one_does_not(): void
    {
        $l = $this->lead();
        $this->setReq($l, [$this->panel()]);

        $this->postJson("/api/leads/{$l->id}/quote", [])->assertStatus(422)->assertJsonValidationErrors('state_code');
        $this->assertSame(0, Quotation::count());

        $this->quoteIt($l)->assertStatus(201);
        $this->setReq($l, [$this->panel(['area_sqm' => 20])]);
        $this->postJson("/api/leads/{$l->id}/quote", [])->assertStatus(201);        // the customer is already linked
    }

    public function test_it_refuses_without_a_panel_line_for_a_closed_lead_and_when_nothing_could_be_quoted(): void
    {
        $empty = $this->lead();
        $this->quoteIt($empty)->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->setReq($empty, [['kind' => 'accessory', 'description' => 'Only a fitting', 'qty' => 1]]);
        $this->quoteIt($empty)->assertStatus(422);

        $won = $this->lead(['status' => 'won']);
        LeadRequirementLine::create(['company_id' => $this->company->id, 'lead_id' => $won->id, 'kind' => 'panel', 'panel_type_id' => $this->wall->id, 'thickness' => 50, 'area_sqm' => 10]);
        $this->quoteIt($won)->assertStatus(422)->assertJsonValidationErrors('lead');

        $gone = $this->lead();
        $this->setReq($gone, [$this->panel()]);
        $this->wall->delete();
        PanelType::withTrashed()->whereKey($this->wall->id)->first()?->forceDelete();
        $this->quoteIt($gone)->assertStatus(422);
        $this->assertSame(0, Quotation::count());
    }

    // ── who may ──────────────────────────────────────────────────────────────

    private function userWith(array $perms): User
    {
        return User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => false, 'role_id' => Role::factory()->create(['company_id' => $this->company->id, 'permissions' => $perms])->id]);
    }

    public function test_making_a_quotation_needs_the_quotation_permission_as_well(): void
    {
        $l = $this->lead();
        $this->setReq($l, [$this->panel()]);

        $this->actingAs($this->userWith(['leads.view', 'leads.manage']), 'sanctum');
        $this->setReq($l, [$this->panel(['area_sqm' => 5])])->assertOk();                                  // the requirement is a lead matter
        $this->quoteIt($l)->assertStatus(403);
        $this->assertSame(0, Quotation::count());

        $this->actingAs($this->userWith(['leads.view', 'leads.manage', 'quotations.manage']), 'sanctum');
        $this->quoteIt($l)->assertStatus(201);
    }

    public function test_viewing_needs_only_leads_view_and_changing_needs_leads_manage(): void
    {
        $l = $this->lead();
        $this->setReq($l, [$this->panel()]);
        $this->actingAs($this->userWith(['leads.view', 'quotations.manage']), 'sanctum');

        $this->getJson("/api/leads/{$l->id}/requirement")->assertOk();
        $this->setReq($l, [])->assertStatus(403);
        $this->quoteIt($l)->assertStatus(403);
    }

    public function test_other_companies_and_other_peoples_leads_are_not_found(): void
    {
        $l = $this->lead(['assigned_to_user_id' => $this->admin->id]);
        $this->setReq($l, [$this->panel()]);

        $stranger = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);
        $this->actingAs($stranger, 'sanctum');
        $this->getJson("/api/leads/{$l->id}/requirement")->assertStatus(404);
        $this->setReq($l, [])->assertStatus(404);
        $this->quoteIt($l)->assertStatus(404);

        $mine = $this->userWith(['leads.view', 'leads.manage', 'leads.own_only', 'quotations.manage']);
        $this->actingAs($mine, 'sanctum');
        $this->getJson("/api/leads/{$l->id}/requirement")->assertStatus(404);
        $this->quoteIt($l)->assertStatus(404);
        $this->assertSame(0, Quotation::count());
    }
}
