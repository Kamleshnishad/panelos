<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\TaxCalculation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reports audit, 25 Oct 2026: the three High findings.
 *  PB-071 the GST report counted cancelled and draft invoices and picked invoices by when their tax row was written;
 *  PB-072 the Tally XML broke on & and < in names and used a date format Tally does not read;
 *  PB-073 cash flow lost the last day's payments and subtracted write-offs from cash.
 */
class ReportsHighFixesTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $co;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 15:00:00', 'UTC'));
        $this->co = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->co->id, 'is_company_admin' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inv(array $o = [], float $tax = 0, ?Company $co = null): Invoice
    {
        static $n = 0;
        $n++;
        $co ??= $this->co;
        $sub = $o['subtotal'] ?? 1000;
        $i = Invoice::withoutGlobalScope('tenant')->create($o + ['company_id' => $co->id, 'invoice_no' => "INV-H-{$n}", 'status' => 'sent', 'subtotal' => $sub, 'tax_amount' => $tax, 'total_amount' => $sub + $tax,
            'invoice_date' => '2026-11-05', 'due_date' => '2026-12-05']);
        if ($tax > 0) {
            TaxCalculation::create(['invoice_id' => $i->id, 'tax_rate' => 18, 'taxable_amount' => $sub, 'tax_amount' => $tax, 'sgst_amount' => $tax / 2, 'cgst_amount' => $tax / 2, 'igst_amount' => 0, 'created_at' => now()]);
        }

        return $i;
    }

    private function pay(Invoice $i, float $amt, string $date, string $method = 'bank_transfer'): PaymentTransaction
    {
        return PaymentTransaction::create(['company_id' => $this->co->id, 'invoice_id' => $i->id, 'amount' => $amt, 'payment_method' => $method, 'status' => 'completed', 'transaction_date' => $date]);
    }

    private function invoiceFor(string $customerName, array $o = [], float $tax = 180, string $gstin = '24AAACR5055K1Z5'): Invoice
    {
        $c = Customer::factory()->create(['company_id' => $this->co->id, 'name' => $customerName, 'gstin' => $gstin]);
        $order = Order::factory()->create(['company_id' => $this->co->id, 'customer_id' => $c->id]);

        return $this->inv($o + ['order_id' => $order->id], $tax);
    }

    // ── PB-071: GST report ───────────────────────────────────────────────────

    public function test_the_gst_report_leaves_out_cancelled_and_draft_invoices(): void
    {
        $this->inv(['status' => 'sent'], 180);
        $this->inv(['status' => 'paid'], 90);
        $this->inv(['status' => 'cancelled'], 900);
        $this->inv(['status' => 'draft'], 450);

        $r = $this->getJson('/api/reports/tax')->assertOk()->json('data');

        $this->assertEquals([270, 2], [$r['total_tax'], $r['calculation_count']]);
        $this->assertEquals([135, 135], [$r['total_cgst'], $r['total_sgst']]);
    }

    public function test_the_gst_report_period_is_the_invoice_date_not_when_the_tax_row_was_written(): void
    {
        $this->inv(['invoice_date' => '2026-11-05'], 180);
        $this->inv(['invoice_date' => '2025-05-05'], 90);          // an old invoice whose tax row was written today
        $tax = fn (string $q) => $this->getJson("/api/reports/tax?{$q}")->assertOk()->json('data.total_tax');

        $this->assertEquals(180, $tax('from_date=2026-11-01&to_date=2026-11-30'));
        $this->assertEquals(90, $tax('from_date=2025-05-01&to_date=2025-05-31'));
        $this->assertEquals(270, $tax(''));
        $this->assertEquals(180, $tax('from_date=2026-11-05&to_date=2026-11-05'), 'both ends of the range are inclusive');
        $this->assertEquals(0, $tax('from_date=2026-11-06&to_date=2026-11-30'));
    }

    public function test_each_gst_row_names_the_invoice_the_customer_and_the_gstin(): void
    {
        $this->invoiceFor('Patel Cold Chain Pvt Ltd', ['invoice_date' => '2026-11-04'], 180, '24AAACR5055K1Z5');
        $this->invoiceFor('Shah Agro', ['invoice_date' => '2026-11-02'], 90, '27AABCS1429B1Z1');

        $rows = $this->getJson('/api/reports/tax')->assertOk()->json('data.details');

        $this->assertSame(['2026-11-02', '2026-11-04'], array_column($rows, 'invoice_date'), 'oldest invoice first');
        $this->assertSame(['Shah Agro', '27AABCS1429B1Z1'], [$rows[0]['customer_name'], $rows[0]['customer_gstin']]);
        $this->assertSame('Patel Cold Chain Pvt Ltd', $rows[1]['customer_name']);
        $this->assertEquals([1000, 18, 180, 90, 90], [$rows[1]['taxable_amount'], $rows[1]['tax_rate'], $rows[1]['tax_amount'], $rows[1]['cgst_amount'], $rows[1]['sgst_amount']]);
        $this->assertStringStartsWith('INV-H-', $rows[0]['invoice_no']);
    }

    public function test_the_gst_report_is_only_this_companys(): void
    {
        $other = Company::factory()->create();
        $this->inv([], 180);
        $this->inv([], 5000, $other);

        $this->assertEquals(180, $this->getJson('/api/reports/tax')->json('data.total_tax'));
    }

    // ── PB-072: Tally ────────────────────────────────────────────────────────

    private function parseXml(string $xml): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $this->assertNotFalse($doc, 'the file is not valid XML: ' . implode('; ', $errors));

        return $doc;
    }

    public function test_the_tally_xml_is_valid_whatever_is_in_the_names(): void
    {
        $name = "Sharma & Sons <Traders> \"Pvt\" 'Ltd'";
        $this->invoiceFor($name . "\x07", ['invoice_no' => 'INV/2026/A&B-1']);

        $xml = $this->get('/api/reports/tally/xml?from=2026-11-01&to=2026-11-30')->assertOk()->getContent();
        $v = $this->parseXml($xml)->BODY->IMPORTDATA->REQUESTDATA->VOUCHER;

        $this->assertSame($name, (string) $v->PARTYLEDGERNAME, 'the name survives, the control character does not');
        $this->assertSame('INV/2026/A&B-1', (string) $v->VOUCHERNUMBER);
        $this->assertSame($name, (string) $v->{'ALLLEDGERENTRIES.LIST'}[0]->LEDGERNAME, 'the debtor ledger carries the same escaped name');
    }

    public function test_the_tally_xml_uses_tallys_date_format_and_two_decimal_amounts(): void
    {
        $this->invoiceFor('Shah Agro', ['invoice_date' => '2026-11-05', 'subtotal' => 1000.5], 180);

        $xml = $this->get('/api/reports/tally/xml?from=2026-11-01&to=2026-11-30')->assertOk()->getContent();
        $doc = $this->parseXml($xml);

        $this->assertSame('20261105', (string) $doc->BODY->IMPORTDATA->REQUESTDATA->VOUCHER->DATE);
        $this->assertSame(['20261101', '20261130'], [(string) $doc->BODY->IMPORTDATA->REQUESTDESC->STATICVARIABLES->SVFROMDATE, (string) $doc->BODY->IMPORTDATA->REQUESTDESC->STATICVARIABLES->SVTODATE]);
        $this->assertStringContainsString('<AMOUNT>-1180.50</AMOUNT>', $xml);
        $this->assertStringContainsString('<AMOUNT>1000.50</AMOUNT>', $xml);
        $this->assertStringContainsString('<AMOUNT>90.00</AMOUNT>', $xml);
    }

    public function test_the_tally_xml_lists_only_this_companys_live_invoices(): void
    {
        $other = Company::factory()->create();
        $this->invoiceFor('Shah Agro');
        $this->inv(['status' => 'cancelled', 'invoice_no' => 'INV-CANCELLED'], 180);
        $this->inv(['status' => 'draft', 'invoice_no' => 'INV-DRAFT'], 180);
        $this->inv(['invoice_no' => 'INV-OTHER-CO'], 180, $other);

        $xml = $this->get('/api/reports/tally/xml?from=2026-11-01&to=2026-11-30')->getContent();

        $this->assertSame(1, $this->parseXml($xml)->BODY->IMPORTDATA->REQUESTDATA->VOUCHER->count());
        $this->assertStringNotContainsString('INV-CANCELLED', $xml);
        $this->assertStringNotContainsString('INV-DRAFT', $xml);
        $this->assertStringNotContainsString('INV-OTHER-CO', $xml);
    }

    public function test_the_tally_csv_opens_correctly_in_excel_and_cannot_run_a_formula(): void
    {
        $this->invoiceFor('=HYPERLINK("http://evil.test","click")');
        $this->invoiceFor('गुजरात पैनल्स');

        $res = $this->get('/api/reports/tally/csv?from=2026-11-01&to=2026-11-30')->assertOk();
        $csv = $res->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'byte-order mark so Excel reads UTF-8');
        $this->assertStringContainsString('गुजरात पैनल्स', $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv, 'a leading = is neutralised');
        $this->assertStringNotContainsString('"=HYPERLINK(', $csv);
        $this->assertStringContainsString('charset=UTF-8', $res->headers->get('Content-Type'));
        $this->assertCount(3, array_filter(explode("\n", $csv)), 'header + two vouchers');
    }

    // ── PB-073: cash flow and balance sheet ──────────────────────────────────

    public function test_cash_flow_counts_payments_made_on_the_first_and_the_last_day_of_the_period(): void
    {
        $i = $this->inv([], 180);
        $this->pay($i, 100, '2026-10-01 00:00:00');
        $this->pay($i, 300, '2026-10-30 09:00:00');
        $this->pay($i, 500, '2026-10-31 15:30:00');
        $this->pay($i, 50, '2026-10-31 23:59:59');
        $this->pay($i, 7000, '2026-11-01 00:00:00');           // next period
        $this->pay($i, 9000, '2026-09-30 23:59:59');           // previous period

        $cf = $this->getJson('/api/reports/cash-flow?from_date=2026-10-01&to_date=2026-10-31')->assertOk()->json('data.operating_activities');

        $this->assertEquals(950, $cf['cash_collected']);
    }

    public function test_cash_flow_does_not_subtract_write_offs_from_cash(): void
    {
        $i = $this->inv([], 180);
        $this->pay($i, 1000, '2026-11-02 10:00:00', 'bank_transfer');
        $this->pay($i, 200, '2026-11-03 10:00:00', 'write_off');

        $cf = $this->getJson('/api/reports/cash-flow?from_date=2026-11-01&to_date=2026-11-30')->assertOk()->json('data.operating_activities');

        $this->assertEquals([1000, 200, 1000], [$cf['cash_collected'], $cf['write_offs'], $cf['net_operating_cash_flow']]);
    }

    public function test_cash_flow_without_dates_still_covers_the_last_year_up_to_now(): void
    {
        $i = $this->inv([], 180);
        $this->pay($i, 400, '2026-11-10 14:59:00');
        $this->pay($i, 600, '2025-12-01 10:00:00');
        $this->pay($i, 800, '2025-10-01 10:00:00');             // older than a year

        $this->assertEquals(1000, $this->getJson('/api/reports/cash-flow')->assertOk()->json('data.operating_activities.cash_collected'));
    }

    public function test_the_balance_sheet_counts_payments_by_the_day_they_were_received(): void
    {
        $i = $this->inv(['invoice_date' => '2026-11-01'], 180);
        $this->pay($i, 400, '2026-11-08 10:00:00');             // received on 8 Nov (the row is typed in later: created_at is "now")
        $paid = fn (string $asOf) => (float) $this->getJson("/api/reports/balance-sheet?as_of={$asOf}")->assertOk()->json('data.assets.cash_collected');

        $this->assertEquals(400, $paid('2026-11-08'), 'the same day counts');
        $this->assertEquals(0, $paid('2026-11-07'));
        $this->assertEquals(400, $paid('2026-11-30'));
    }
}
