<?php

namespace Tests\Feature;

use App\Models\PanelType;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsUmaQuotation;

/** PB-085: a sent quotation could be rewritten in place (total 9,11,476 -> 3,96,362, still 'sent', no revision). */
class SentQuotationLockedTest extends TestCase
{
    use RefreshDatabase, BuildsUmaQuotation;

    public function test_a_sent_quotation_cannot_be_edited_and_nothing_changes(): void
    {
        [$admin, $q] = $this->umaQuotation();
        $this->actingAs($admin, 'sanctum')->postJson("/api/quotations/{$q->id}/send")->assertOk();
        $pt = PanelType::withoutGlobalScope('tenant')->where('company_id', $admin->company_id)->first();

        $res = $this->putJson("/api/quotations/{$q->id}", ['panel_rows' => $this->panelRows($pt->id, 3, 500), 'advance_pct' => 10]);

        $res->assertStatus(400);
        $this->assertStringContainsString('Use Revise', $res->json('message'));
        $fresh = Quotation::find($q->id);
        $this->assertSame('911476.00', $fresh->total_amount);
        $this->assertEquals(50, $fresh->advance_pct);
        $this->assertSame('sent', $fresh->status);
    }

    public function test_the_way_to_change_a_sent_quotation_is_revise_and_the_revision_can_be_edited(): void
    {
        [$admin, $q] = $this->umaQuotation();
        $this->actingAs($admin, 'sanctum')->postJson("/api/quotations/{$q->id}/send")->assertOk();
        $pt = PanelType::withoutGlobalScope('tenant')->where('company_id', $admin->company_id)->first();

        $rev = $this->postJson("/api/quotations/{$q->id}/revise")->assertStatus(201);
        $this->putJson('/api/quotations/' . $rev->json('data.id'), ['panel_rows' => $this->panelRows($pt->id, 3, 500)])->assertOk();

        $this->assertSame('911476.00', Quotation::find($q->id)->total_amount);   // what the customer holds is untouched
        $this->assertSame('revised', Quotation::find($q->id)->status);
    }

    public function test_draft_and_boq_quotations_are_still_editable_and_an_accepted_one_is_not(): void
    {
        [$admin, $q] = $this->umaQuotation();
        $this->actingAs($admin, 'sanctum');
        $this->putJson("/api/quotations/{$q->id}", ['advance_pct' => 30])->assertOk();
        $this->assertEquals(30, Quotation::find($q->id)->advance_pct);

        $boq = $this->postJson('/api/quotations', ['customer_id' => $q->customer_id, 'as_boq' => true,
            'panel_rows' => $this->panelRows(PanelType::withoutGlobalScope('tenant')->where('company_id', $admin->company_id)->first()->id, 5, 0)])->assertStatus(201);
        $this->putJson('/api/quotations/' . $boq->json('data.id'), ['project_name' => 'Renamed BOQ'])->assertOk();

        $this->postJson("/api/quotations/{$q->id}/send")->assertOk();
        $this->postJson("/api/quotations/{$q->id}/accept")->assertOk();
        $this->putJson("/api/quotations/{$q->id}", ['advance_pct' => 10])->assertStatus(400);
    }
}
