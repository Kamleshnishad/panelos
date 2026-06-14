<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Dispatch;
use App\Models\Order;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $invoiceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Company']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id
        ]);

        $this->actingAs($this->user);
        $this->invoiceService = app(InvoiceService::class);
    }

    public function test_create_invoice_from_dispatch()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();

        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $this->assertDatabaseHas('invoices', [
            'dispatch_id' => $dispatch->id,
            'company_id' => $this->company->id,
            'status' => 'draft'
        ]);

        $this->assertEquals('draft', $invoice->status);
        $this->assertStringStartsWith('INV-', $invoice->invoice_no);
    }

    public function test_create_invoice_from_order()
    {
        $order = Order::factory()->for($this->company)->create();

        $invoice = $this->invoiceService->createFromOrder($order->id);

        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'company_id' => $this->company->id,
            'status' => 'draft'
        ]);

        $this->assertEquals('draft', $invoice->status);
    }

    public function test_invoice_auto_numbering()
    {
        $dispatch1 = Dispatch::factory()->for($this->company)->create();
        $dispatch2 = Dispatch::factory()->for($this->company)->create();

        $invoice1 = $this->invoiceService->createFromDispatch($dispatch1->id);
        $invoice2 = $this->invoiceService->createFromDispatch($dispatch2->id);

        $this->assertTrue($invoice1->invoice_no < $invoice2->invoice_no);
    }

    public function test_add_item_to_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $item = $this->invoiceService->addItem(
            $invoice->id,
            1,
            10,
            100.00
        );

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'quantity' => 10,
            'unit_price' => 100.00,
            'amount' => 1000.00
        ]);

        $this->assertEquals(1000.00, $item->amount);
    }

    public function test_cannot_add_item_to_non_draft_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->sendInvoice($invoice->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Can only add items to draft invoices');

        $this->invoiceService->addItem($invoice->id, 1, 10, 100.00);
    }

    public function test_calculate_totals()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $this->invoiceService->addItem($invoice->id, 1, 10, 100.00);
        $this->invoiceService->addItem($invoice->id, 2, 5, 200.00);

        $updated = $this->invoiceService->calculateTotals($invoice->id);

        $this->assertEquals(2000.00, $updated->subtotal);
    }

    public function test_send_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $sent = $this->invoiceService->sendInvoice($invoice->id);

        $this->assertEquals('sent', $sent->status);
    }

    public function test_accept_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->sendInvoice($invoice->id);

        $accepted = $this->invoiceService->acceptInvoice($invoice->id);

        $this->assertEquals('accepted', $accepted->status);
    }

    public function test_mark_invoice_paid()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->sendInvoice($invoice->id);

        $paid = $this->invoiceService->markPaid($invoice->id);

        $this->assertEquals('paid', $paid->status);
        $this->assertNotNull($paid->paid_date);
    }

    public function test_cancel_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $cancelled = $this->invoiceService->cancelInvoice($invoice->id);

        $this->assertEquals('cancelled', $cancelled->status);
    }

    public function test_get_invoice_details()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $invoice = $this->invoiceService->createFromDispatch($dispatch->id);

        $details = $this->invoiceService->getInvoiceDetails($invoice->id);

        $this->assertEquals($invoice->id, $details->id);
        $this->assertTrue($details->relationLoaded('items'));
    }

    public function test_duplicate_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $original = $this->invoiceService->createFromDispatch($dispatch->id);
        $this->invoiceService->addItem($original->id, 1, 10, 100.00);

        $duplicate = $this->invoiceService->duplicateInvoice($original->id);

        $this->assertNotEquals($original->id, $duplicate->id);
        $this->assertEquals('draft', $duplicate->status);
        $this->assertEquals($original->items->count(), $duplicate->items->count());
    }
}
