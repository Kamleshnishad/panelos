<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Services\InvoiceService;
use Tests\TestCase;

/**
 * PB-045 / FR-002. Installation, custom items and doors typed into the form were added into
 * quotations.installation_amount and the row itself was thrown away. The edit form therefore
 * could not show them, and saving any quotation reset the amount to 0 (reproduced: ₹109,350
 * installation → ₹0 after opening the form and saving with nothing changed).
 * They are now stored as rows; installation_amount stays as their sum.
 */
class QuotationLinesTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private function panelRows(PanelType $pt): array
    {
        return [[
            'panel_type_id' => $pt->id, 'thickness' => 50, 'density_type' => 'PUF', 'density_kgm3' => 40,
            'top_skin_material' => 'PPGI', 'top_skin_thickness' => '0.40', 'top_surface' => 'PLAIN',
            'bottom_skin_material' => 'PPGI', 'bottom_skin_thickness' => '0.40',
            'sizes' => [['length_mm' => 3000, 'nos' => 10, 'rate_per_sqm' => 1000]],
        ]];
    }

    /** @return array{0:\App\Models\User,1:Quotation,2:array} the user, the quotation, and the panel rows */
    private function quotation(array $accessories): array
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);
        $rows = $this->panelRows($pt);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id, 'panel_rows' => $rows, 'accessories' => $accessories,
        ])->assertStatus(201);

        return [$admin, Quotation::find($res->json('data.id')), $rows];
    }

    private function installation(float $qty = 364.5, float $rate = 300, string $unit = 'MTR'): array
    {
        return ['type' => 'installation', 'description' => 'Installation incl. sealant', 'qty' => $qty, 'rate' => $rate, 'unit' => $unit];
    }

    /** Exactly what the edit form sends back: each loaded row, mapped to the payload shape. */
    private function asFormPayload(array $lines): array
    {
        return array_map(fn ($l) => [
            'type' => $l['type'] === 'custom' ? 'standard' : $l['type'], 'accessory_id' => null,
            'description' => $l['description'], 'unit' => $l['unit'], 'qty' => (float) $l['quantity'], 'rate' => (float) $l['unit_price'],
            'door_type' => $l['door_type'], 'door_width' => $l['door_width'], 'door_height' => $l['door_height'],
        ], $lines);
    }

    public function test_installation_is_stored_as_a_row_with_its_quantity_unit_and_rate(): void
    {
        [$admin, $q] = $this->quotation([$this->installation()]);

        $show = $this->actingAs($admin, 'sanctum')->getJson("/api/quotations/{$q->id}")->assertOk();

        $this->assertEquals(109350.00, $show->json('data.installation_amount'));
        $this->assertCount(1, $show->json('data.lines'));
        $line = $show->json('data.lines.0');
        $this->assertSame('installation', $line['type']);
        $this->assertEquals(364.5, $line['quantity']);
        $this->assertSame('MTR', $line['unit']);
        $this->assertEquals(300, $line['unit_price']);
        $this->assertEquals(109350.00, $line['amount']);
    }

    public function test_opening_the_form_and_saving_unchanged_keeps_installation(): void
    {
        // The PB-045 regression: the form reloads every row it is shown and sends them all back.
        [$admin, $q, $rows] = $this->quotation([$this->installation()]);
        $before = (float) $q->fresh()->total_amount;

        $loaded = $this->actingAs($admin, 'sanctum')->getJson("/api/quotations/{$q->id}")->json('data.lines');
        $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$q->id}", [
            'customer_id' => $q->customer_id, 'panel_rows' => $rows, 'accessories' => $this->asFormPayload($loaded),
        ])->assertStatus(200);

        $this->assertEquals(109350.00, (float) $q->fresh()->installation_amount);
        $this->assertEquals($before, (float) $q->fresh()->total_amount);
        $this->assertSame(1, QuotationLine::where('quotation_id', $q->id)->count());
    }

    public function test_an_update_that_does_not_mention_accessories_leaves_the_rows_alone(): void
    {
        [$admin, $q, $rows] = $this->quotation([$this->installation()]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$q->id}", [
            'customer_id' => $q->customer_id, 'panel_rows' => $rows, 'project_name' => 'Renamed',
        ])->assertStatus(200);

        $this->assertEquals(109350.00, (float) $q->fresh()->installation_amount);
        $this->assertSame(1, QuotationLine::where('quotation_id', $q->id)->count());
    }

    public function test_removing_the_row_in_the_form_really_removes_it(): void
    {
        [$admin, $q, $rows] = $this->quotation([$this->installation()]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/quotations/{$q->id}", [
            'customer_id' => $q->customer_id, 'panel_rows' => $rows, 'accessories' => [],
        ])->assertStatus(200);

        $this->assertEquals(0, (float) $q->fresh()->installation_amount);
        $this->assertSame(0, QuotationLine::where('quotation_id', $q->id)->count());
    }

    public function test_doors_and_custom_items_with_no_master_are_kept_and_counted(): void
    {
        [$admin, $q] = $this->quotation([
            $this->installation(1, 300, 'LS'),
            ['type' => 'door', 'accessory_id' => null, 'description' => 'Fire rated', 'qty' => 2, 'rate' => 8000, 'unit' => 'NOS',
             'door_type' => 'hinged_door', 'door_width' => 900, 'door_height' => 2100],
            // the form's "— Custom —" accessory: type standard, no master id (silently dropped before)
            ['type' => 'standard', 'accessory_id' => null, 'description' => 'Custom bracket', 'qty' => 3, 'rate' => 100, 'unit' => 'NOS'],
        ]);

        $lines = QuotationLine::where('quotation_id', $q->id)->orderBy('sort_order')->get();
        $this->assertSame(['installation', 'door', 'custom'], $lines->pluck('type')->all());
        $door = $lines[1];
        $this->assertSame('hinged_door', $door->door_type);
        $this->assertSame(900, (int) $door->door_width);
        $this->assertEquals(16000.00, (float) $door->amount);

        // Amounts are counted: 300 + 16,000 + 300 = 16,600 beyond the panel subtotal.
        $this->assertEquals(16600.00, (float) $q->fresh()->installation_amount);
        $this->assertEquals(30000.00 + 16600.00, (float) $q->fresh()->subtotal);
    }

    public function test_a_row_with_an_unknown_door_type_is_still_rejected_cleanly(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $pt = PanelType::factory()->create(['company_id' => $admin->company_id]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/quotations', [
            'customer_id' => $customer->id, 'panel_rows' => $this->panelRows($pt),
            'accessories' => [['type' => 'door', 'qty' => 1, 'rate' => 10, 'door_type' => 'Single']],
        ])->assertStatus(422);
    }

    public function test_the_backfill_gives_existing_installation_a_lump_row_once(): void
    {
        $admin = $this->createAdminUser();
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $mk = fn (float $install) => Quotation::create([
            'company_id' => $admin->company_id, 'customer_id' => $customer->id,
            'quotation_no' => 'Q-BF-' . uniqid(), 'status' => 'draft', 'installation_amount' => $install,
        ]);
        $withInstall = $mk(5000);
        $without = $mk(0);
        $alreadyHasRows = $mk(700);
        QuotationLine::create(['quotation_id' => $alreadyHasRows->id, 'type' => 'installation', 'quantity' => 7, 'unit_price' => 100, 'amount' => 700, 'unit' => 'MTR']);

        $migration = require base_path('database/migrations/2026_10_24_000002_create_quotation_lines_table.php');
        $migration->backfill();
        $migration->backfill();   // running it again changes nothing

        $line = QuotationLine::where('quotation_id', $withInstall->id)->get();
        $this->assertCount(1, $line);
        $this->assertSame('LS', $line[0]->unit);
        $this->assertEquals(5000.00, (float) $line[0]->amount);
        $this->assertSame(0, QuotationLine::where('quotation_id', $without->id)->count());
        $this->assertSame(1, QuotationLine::where('quotation_id', $alreadyHasRows->id)->count());
    }

    public function test_revise_and_duplicate_carry_the_rows(): void
    {
        [$admin, $q] = $this->quotation([$this->installation()]);

        $rev = $this->actingAs($admin, 'sanctum')->postJson("/api/quotations/{$q->id}/revise")->assertStatus(201)->json('data.id');
        $dup = $this->actingAs($admin, 'sanctum')->postJson("/api/quotations/{$q->id}/duplicate")->assertStatus(201)->json('data.id');

        foreach ([$rev, $dup] as $id) {
            $this->assertSame(1, QuotationLine::where('quotation_id', $id)->count(), "Quotation {$id} lost its installation row.");
            $this->assertEquals(109350.00, (float) Quotation::find($id)->installation_amount);
        }
    }

    public function test_the_invoice_itemises_the_rows_and_totals_the_same(): void
    {
        [$admin, $q] = $this->quotation([
            $this->installation(),
            ['type' => 'door', 'accessory_id' => null, 'qty' => 2, 'rate' => 8000, 'unit' => 'NOS', 'door_type' => 'hinged_door', 'door_width' => 900, 'door_height' => 2100],
        ]);
        $q->update(['status' => 'accepted']);
        $order = Order::create([
            'company_id' => $admin->company_id, 'quotation_id' => $q->id, 'customer_id' => $q->customer_id,
            'order_no' => 'ORD-L-' . uniqid(), 'order_date' => now(), 'status' => 'pending',
        ]);
        $order->items()->create(['panel_type_id' => PanelType::first()->id, 'total_sqm' => 30, 'rate_per_sqm' => 1000,
            'amount' => 30000, 'quantity' => 30, 'unit_price' => 1000, 'sort_order' => 0]);

        $invoice = app(InvoiceService::class)->createFromOrder($order->id, [], $admin->company_id);

        $lines = InvoiceItem::where('invoice_id', $invoice->id)->whereNull('panel_type_id')->get();
        $this->assertCount(2, $lines, 'One invoice line per row, not one lump.');
        $install = $lines->firstWhere('description', 'Installation incl. sealant');
        $this->assertEquals(364.5, (float) $install->quantity);
        $this->assertEquals(300, (float) $install->unit_price);
        $this->assertEquals(109350.00, (float) $install->amount);
        $this->assertEqualsWithDelta(109350.00 + 16000.00, $lines->sum('amount'), 0.001);
        $this->assertStringContainsString('900×2100mm', $lines->firstWhere('amount', 16000)->description);
    }

    public function test_the_ledger_pdf_prints_the_row_with_its_real_quantity_unit_and_rate(): void
    {
        [$admin, $q] = $this->quotation([$this->installation()]);

        $doc = app(\App\Services\QuotationDocumentService::class)->build($q->fresh());
        $last = collect($doc['lines'])->last();

        $this->assertSame('installation', $last['kind']);
        $this->assertEquals(364.5, $last['qty']);
        $this->assertSame('MTR', $last['uom']);
        $this->assertEquals(300, $last['rate']);
        $this->assertSame('994568', $last['hsn']);
    }
}
