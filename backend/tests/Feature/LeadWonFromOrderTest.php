<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\User;
use App\Services\LeadService;
use App\Services\OrderService;
use App\Services\QuotationService;
use Tests\TestCase;

/** LEADS_PLAN 1.3: a lead is won when an order is created from its quotation. */
class LeadWonFromOrderTest extends TestCase
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
        return app(LeadService::class)->create($this->company->id, $over + ['contact_name' => 'Ravi Patel', 'phone' => '9876543210', 'source' => 'Phone']);
    }

    private function quote(Lead $lead): Quotation
    {
        $customer = $this->postJson("/api/leads/{$lead->id}/convert")->json('data.customer_id');
        $pt = PanelType::factory()->create(['company_id' => $this->company->id]);
        $id = $this->postJson('/api/quotations', ['customer_id' => $customer, 'lead_id' => $lead->id, 'panel_rows' => $this->panelRows($pt->id, 10, 900)])->assertStatus(201)->json('data.id');

        return Quotation::find($id);
    }

    private function order(Quotation $q)
    {
        $q->update(['status' => 'accepted']);

        return app(OrderService::class)->createFromQuotation($q->fresh(), true);
    }

    private function log(Lead $lead): array
    {
        return LeadActivity::where('lead_id', $lead->id)->orderBy('id')->pluck('description')->all();
    }

    public function test_creating_the_order_marks_the_lead_won_and_the_timeline_names_the_order(): void
    {
        $lead = $this->lead();
        $order = $this->order($this->quote($lead));

        $this->assertSame('won', $lead->fresh()->status);
        $this->assertContains("Status: quoted → won (order {$order->order_no} created)", $this->log($lead));
    }

    public function test_an_order_from_a_revised_quotation_still_wins_the_lead(): void
    {
        $lead = $this->lead();
        $v1 = $this->quote($lead);
        $v2 = app(QuotationService::class)->revise($v1);

        $this->order($v2);

        $this->assertSame('won', $lead->fresh()->status);
    }

    public function test_an_on_hold_lead_is_won_by_its_order(): void
    {
        $lead = $this->lead();
        $q = $this->quote($lead);
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'on_hold', 'hold_until' => now()->addDays(3)->toDateString()])->assertOk();

        $this->order($q);

        $this->assertSame('won', $lead->fresh()->status);
    }

    public function test_a_lost_lead_is_not_reopened_by_an_order_but_the_timeline_says_so(): void
    {
        $lead = $this->lead();
        $q = $this->quote($lead);
        $this->postJson("/api/leads/{$lead->id}/status", ['status' => 'lost', 'lost_reason' => 'Price'])->assertOk();

        $order = $this->order($q);

        $fresh = $lead->fresh();
        $this->assertSame('lost', $fresh->status);
        $this->assertSame('Price', $fresh->lost_reason);
        $this->assertContains("Order {$order->order_no} was created from this lead's quotation. It stays lost; reopen it to mark it won.", $this->log($lead));
    }

    public function test_marking_won_twice_does_nothing_the_second_time(): void
    {
        $lead = $this->lead();
        $order = $this->order($this->quote($lead));
        $before = count($this->log($lead));

        app(LeadService::class)->markWonFromOrder($order);

        $this->assertSame($before, count($this->log($lead)));
    }

    public function test_an_order_for_a_quotation_with_no_lead_just_works(): void
    {
        $customer = \App\Models\Customer::factory()->create(['company_id' => $this->company->id]);
        $pt = PanelType::factory()->create(['company_id' => $this->company->id]);
        $id = $this->postJson('/api/quotations', ['customer_id' => $customer->id, 'panel_rows' => $this->panelRows($pt->id, 10, 900)])->assertStatus(201)->json('data.id');

        $order = $this->order(Quotation::find($id));

        $this->assertNotNull($order->id);
    }

    public function test_another_companys_lead_is_never_touched(): void
    {
        $other = Company::factory()->create();
        $stranger = Lead::withoutGlobalScope('tenant')->create(['company_id' => $other->id, 'lead_no' => 'LEAD-X-0001', 'contact_name' => 'X', 'source' => 'Other', 'status' => 'quoted']);
        $lead = $this->lead();
        $q = $this->quote($lead);
        Lead::withoutGlobalScope('tenant')->whereKey($stranger->id)->update(['quotation_id' => $q->id]);   // same quotation id, other company

        $this->order($q);

        $this->assertSame('quoted', Lead::withoutGlobalScope('tenant')->find($stranger->id)->status);
        $this->assertSame('won', $lead->fresh()->status);
    }

    // ── the catch-up command ─────────────────────────────────────────────────

    private function legacyLead(): array
    {
        // a lead that has an order but was never marked won, as every lead made before this rule
        $lead = $this->lead();
        $order = $this->order($this->quote($lead));
        Lead::whereKey($lead->id)->update(['status' => 'quoted']);
        LeadActivity::where('lead_id', $lead->id)->where('description', 'like', '%→ won%')->delete();

        return [$lead, $order];
    }

    public function test_the_backfill_dry_run_lists_leads_and_changes_nothing(): void
    {
        [$lead] = $this->legacyLead();
        $before = count($this->log($lead));

        $this->artisan('leads:backfill-won', ['--company' => $this->company->id])->expectsOutputToContain($lead->lead_no)->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame('quoted', $lead->fresh()->status);
        $this->assertSame($before, count($this->log($lead)));
    }

    public function test_the_backfill_with_apply_marks_them_won_with_a_timeline_entry_and_a_second_run_finds_nothing(): void
    {
        [$lead, $order] = $this->legacyLead();

        $this->artisan('leads:backfill-won', ['--company' => $this->company->id, '--apply' => true])->assertSuccessful();

        $this->assertSame('won', $lead->fresh()->status);
        $this->assertContains("Status: quoted → won (order {$order->order_no} created (backfill))", $this->log($lead));
        $this->artisan('leads:backfill-won', ['--company' => $this->company->id])->expectsOutputToContain('No lead needs changing')->assertSuccessful();
    }

    public function test_the_backfill_leaves_a_lost_lead_alone(): void
    {
        [$lead] = $this->legacyLead();
        Lead::whereKey($lead->id)->update(['status' => 'lost']);

        $this->artisan('leads:backfill-won', ['--company' => $this->company->id, '--apply' => true])->expectsOutputToContain('left alone')->assertSuccessful();

        $this->assertSame('lost', $lead->fresh()->status);
    }
}
