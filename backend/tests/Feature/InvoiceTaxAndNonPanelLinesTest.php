<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\TaxConfiguration;
use App\Services\InvoiceService;
use Tests\TestCase;

/**
 * Regressions on the money that leaves the factory:
 *
 *  1. Nothing in the app ever created a TaxConfiguration (/gst/register writes a
 *     *GstConfiguration*, a different model), so TaxService bailed out early and
 *     every invoice ever issued carried ₹0 GST, silently.
 *  2. Accessories, doors and installation never reached an invoice — only panel
 *     rows did — so that revenue was simply never billed.
 */
class InvoiceTaxAndNonPanelLinesTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    /** Order with one 78 m² panel row (₹1,14,000), a ₹4,000 accessory and ₹5,000 installation. */
    private function orderWithAccessories(int $companyId, string $customerState = '24'): Order
    {
        $customer = Customer::factory()->create([
            'company_id' => $companyId,
            'state_code' => $customerState,
        ]);
        $panelType = PanelType::factory()->create(['company_id' => $companyId]);

        $quotation = Quotation::create([
            'company_id'   => $companyId,
            'customer_id'  => $customer->id,
            'quotation_no' => 'Q-TEST-' . uniqid(),
            'status'       => 'accepted',
            'installation_amount' => 5000,
        ]);

        $accessory = Accessory::factory()->create(['company_id' => $companyId]);
        $quotation->accessories()->attach($accessory->id, [
            'quantity' => 10, 'unit_price' => 400, 'amount' => 4000, 'type' => 'standard',
            'description' => 'Cam-lock set', 'unit' => 'NOS',
        ]);

        $order = Order::create([
            'company_id'   => $companyId,
            'quotation_id' => $quotation->id,
            'customer_id'  => $customer->id,
            'order_no'     => 'ORD-TEST-' . uniqid(),
            'order_date'   => now(),
            'status'       => 'pending',
        ]);

        $order->items()->create([
            'panel_type_id' => $panelType->id,
            'total_sqm'     => 78,
            'rate_per_sqm'  => 1461.54,     // weighted average of 60@1450 + 18@1500
            'amount'        => 114000.00,   // the authoritative figure
            'quantity'      => 78,
            'unit_price'    => 1461.54,
            'sort_order'    => 0,
        ]);

        return $order->fresh('items');
    }

    public function test_invoice_is_taxed_at_18_percent_without_any_configuration(): void
    {
        $admin = $this->createAdminUser();
        $admin->company->forceFill(['state_code' => '24'])->save();
        $this->actingAs($admin, 'sanctum');

        $this->assertSame(0, TaxConfiguration::where('company_id', $admin->company_id)->count());

        $order = $this->orderWithAccessories($admin->company_id);
        $invoice = app(InvoiceService::class)->createFromOrder($order->id, [], $admin->company_id);

        // 114000 panels + 4000 accessory + 5000 installation
        $this->assertEquals(123000.00, round((float) $invoice->subtotal, 2));
        $this->assertEquals(22140.00, round((float) $invoice->tax_amount, 2));  // 18%
        $this->assertEquals(145140.00, round((float) $invoice->total_amount, 2));
        $this->assertEquals(
            round((float) $invoice->subtotal + (float) $invoice->tax_amount, 2),
            round((float) $invoice->total_amount, 2)
        );
    }

    public function test_same_state_splits_cgst_sgst_and_other_state_uses_igst(): void
    {
        $admin = $this->createAdminUser();
        $admin->company->forceFill(['state_code' => '24'])->save();
        $this->actingAs($admin, 'sanctum');

        $intra = app(InvoiceService::class)
            ->createFromOrder($this->orderWithAccessories($admin->company_id, '24')->id, [], $admin->company_id);
        $intra->load('taxCalculation');
        $this->assertEquals(11070.00, round((float) $intra->taxCalculation->cgst_amount, 2));
        $this->assertEquals(11070.00, round((float) $intra->taxCalculation->sgst_amount, 2));
        $this->assertEquals(0.0, (float) $intra->taxCalculation->igst_amount);

        $inter = app(InvoiceService::class)
            ->createFromOrder($this->orderWithAccessories($admin->company_id, '27')->id, [], $admin->company_id);
        $inter->load('taxCalculation');
        $this->assertEquals(22140.00, round((float) $inter->taxCalculation->igst_amount, 2));
        $this->assertEquals(0.0, (float) $inter->taxCalculation->cgst_amount);
    }

    public function test_deactivated_tax_configuration_is_still_honoured(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        TaxConfiguration::create([
            'company_id' => $admin->company_id, 'tax_type' => 'exclusive',
            'default_tax_rate' => 18, 'is_active' => false,
        ]);

        $order = $this->orderWithAccessories($admin->company_id);
        $invoice = app(InvoiceService::class)->createFromOrder($order->id, [], $admin->company_id);

        // Switching tax off is a deliberate choice; the fallback must not override it.
        $this->assertEquals(0.0, round((float) $invoice->tax_amount, 2));
    }

    public function test_accessories_and_installation_are_billed_exactly_once(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $order = $this->orderWithAccessories($admin->company_id);
        $invoice = app(InvoiceService::class)->createFromOrder($order->id, [], $admin->company_id);

        $nonPanel = InvoiceItem::where('invoice_id', $invoice->id)->whereNull('panel_type_id')->get();
        $this->assertCount(2, $nonPanel);
        $this->assertEqualsCanonicalizing(
            ['Cam-lock set', 'Installation / site work'],
            $nonPanel->pluck('description')->all()
        );

        // Panel line must carry the order item's stored amount, not a
        // recomputed quantity × averaged rate (which drifts by paise).
        $panelLine = InvoiceItem::where('invoice_id', $invoice->id)->whereNotNull('panel_type_id')->firstOrFail();
        $this->assertEquals(114000.00, round((float) $panelLine->amount, 2));

        // A second invoice for the same order (the multi-dispatch case) must not
        // re-bill the accessories that the first invoice already carried.
        $invoice->update(['status' => 'sent']);
        $second = app(InvoiceService::class)
            ->createFromDispatch($this->dispatchFor($order)->id, [], $admin->company_id);

        $this->assertSame(0, InvoiceItem::where('invoice_id', $second->id)->whereNull('panel_type_id')->count());
    }

    /** Minimal dispatch for an order, so a second invoice can be raised against it. */
    private function dispatchFor(Order $order): \App\Models\Dispatch
    {
        $batch = \App\Models\ProductionBatch::create([
            'company_id' => $order->company_id,
            'order_id'   => $order->id,
            'batch_no'   => 'PB-TEST-' . uniqid(),
            'status'     => 'qc_passed',
            'planned_quantity'   => 78,
            'completed_quantity' => 78,
        ]);

        $dispatch = \App\Models\Dispatch::create([
            'company_id'    => $order->company_id,
            'batch_id'      => $batch->id,
            'status'        => 'pending',
            'dispatch_date' => now(),
        ]);

        \App\Models\DispatchItem::create([
            'dispatch_id'   => $dispatch->id,
            'panel_type_id' => $order->items->first()->panel_type_id,
            'quantity'      => 78,
            'unit_price'    => 1461.54,
            'amount'        => 114000.00,
            'created_at'    => now(),
        ]);

        return $dispatch;
    }

    public function test_fractional_quantities_survive_the_invoice_line(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $order = $this->orderWithAccessories($admin->company_id);
        $order->items()->first()->update(['total_sqm' => 312.93, 'quantity' => 312.93, 'amount' => 328576.50]);

        $invoice = app(InvoiceService::class)->createFromOrder($order->id, [], $admin->company_id);
        $panelLine = InvoiceItem::where('invoice_id', $invoice->id)->whereNotNull('panel_type_id')->firstOrFail();

        // quantity was an INTEGER column, so 312.93 m² billed as 313 m².
        $this->assertEquals(312.93, round((float) $panelLine->quantity, 2));
        $this->assertEquals(328576.50, round((float) $panelLine->amount, 2));
    }

    /**
     * PB-021. Transport is settled at dispatch, not quotation — the real cost
     * depends on the vehicle available that day and is renegotiated repeatedly.
     * Before this, freight was never billed at all, from either source.
     */
    public function test_actual_transport_entered_at_dispatch_is_billed(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $order = $this->orderWithAccessories($admin->company_id);
        $dispatch = $this->dispatchFor($order);
        $dispatch->update([
            'transport_amount' => 7500,
            'transporter_name' => 'Gujarat Roadlines',
            'vehicle_no'       => 'GJ01AB1234',
        ]);

        $invoice = app(InvoiceService::class)->createFromDispatch($dispatch->id, [], $admin->company_id);

        $line = InvoiceItem::where('invoice_id', $invoice->id)
            ->where('description', 'like', 'Transport%')->first();

        $this->assertNotNull($line, 'Freight entered at dispatch was not billed.');
        $this->assertEquals(7500, (float) $line->amount);
        $this->assertStringContainsString('Gujarat Roadlines', $line->description);
        $this->assertStringContainsString('GJ01AB1234', $line->description);

        // 114000 panels + 4000 accessory + 5000 installation + 7500 freight
        $this->assertEquals(130500.00, round((float) $invoice->subtotal, 2));
        // Freight rides in the subtotal, so it carries the same GST as the goods.
        $this->assertEquals(round(130500 * 0.18, 2), round((float) $invoice->tax_amount, 2));
    }

    /** With no actual entered, a transport figure agreed up front is still billed. */
    public function test_quoted_transport_is_billed_when_no_actual_was_entered(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $order = $this->orderWithAccessories($admin->company_id);
        $order->update(['transport_fixed' => true, 'transport_amount' => 3000]);

        $invoice = app(InvoiceService::class)->createFromOrder($order->id, [], $admin->company_id);

        $line = InvoiceItem::where('invoice_id', $invoice->id)
            ->where('description', 'like', 'Transport%')->first();
        $this->assertNotNull($line);
        $this->assertEquals(3000, (float) $line->amount);
    }

    /** The day's actual beats the estimate — it must not bill both. */
    public function test_actual_transport_replaces_the_quoted_estimate(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $order = $this->orderWithAccessories($admin->company_id);
        $order->update(['transport_fixed' => true, 'transport_amount' => 3000]);

        $dispatch = $this->dispatchFor($order);
        $dispatch->update(['transport_amount' => 9200]);

        $invoice = app(InvoiceService::class)->createFromDispatch($dispatch->id, [], $admin->company_id);

        $lines = InvoiceItem::where('invoice_id', $invoice->id)
            ->where('description', 'like', 'Transport%')->get();

        $this->assertCount(1, $lines, 'Freight was billed twice - estimate and actual.');
        $this->assertEquals(9200, (float) $lines->first()->amount);
    }

    /** No freight agreed and none incurred: no phantom zero-value line. */
    public function test_no_transport_line_when_there_is_no_freight(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin, 'sanctum');

        $order = $this->orderWithAccessories($admin->company_id);
        $invoice = app(InvoiceService::class)->createFromOrder($order->id, [], $admin->company_id);

        $this->assertSame(0, InvoiceItem::where('invoice_id', $invoice->id)
            ->where('description', 'like', 'Transport%')->count());
    }
}
