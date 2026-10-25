<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\TaxCalculation;
use App\Models\User;
use App\Services\ReportingService;
use App\Support\CompanyMail;
use App\Support\CompanyMailbox;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Reports audit, FR-010 (25 Oct 2026): the GST sales register, the payments register, customer statements and the receivables ageing as PDF,
 * the monthly MIS e-mail, the company's own Tally ledger names, financial-year handling, and numeric tests for the trend and top-customers reports.
 * The fixed date is Tuesday 10 Nov 2026, 15:00.
 */
class ReportsEnhancementsTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $co;
    private User $admin;
    /** @var array<string,\Symfony\Component\Mailer\Transport\TransportInterface> */
    private array $boxes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 15:00:00', 'UTC'));
        $this->co = Company::factory()->create();
        $this->admin = User::factory()->create(['company_id' => $this->co->id, 'is_company_admin' => true, 'email' => 'owner@signature.test']);
        $this->actingAs($this->admin, 'sanctum');
    }

    protected function tearDown(): void
    {
        CompanyMail::useFactory(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function customer(string $name, ?string $gstin = null, ?string $stateCode = null, ?Company $co = null): Customer
    {
        return Customer::factory()->create(['company_id' => ($co ?? $this->co)->id, 'name' => $name, 'gstin' => $gstin, 'state_code' => $stateCode ?? '', 'state' => $stateCode ? \App\Support\GstState::name($stateCode) : '']);
    }

    /** An invoice against a new order of $customer. Totals: subtotal + tax; the tax row splits CGST/SGST or is all IGST. */
    private function invoice(?Customer $customer, array $o = [], float $tax = 0, bool $inter = false, ?Company $co = null): Invoice
    {
        static $n = 0;
        $n++;
        $co ??= $this->co;
        $order = $customer ? Order::factory()->create(['company_id' => $co->id, 'customer_id' => $customer->id]) : null;
        $sub = $o['subtotal'] ?? 1000;
        $i = Invoice::withoutGlobalScope('tenant')->create($o + ['company_id' => $co->id, 'order_id' => $order?->id, 'invoice_no' => "INV-E-{$n}", 'status' => 'sent', 'subtotal' => $sub, 'tax_amount' => $tax, 'total_amount' => $sub + $tax,
            'invoice_date' => '2026-11-05', 'due_date' => '2026-12-05']);
        if ($tax > 0) {
            TaxCalculation::create(['invoice_id' => $i->id, 'tax_rate' => 18, 'taxable_amount' => $sub, 'tax_amount' => $tax, 'cgst_amount' => $inter ? 0 : $tax / 2, 'sgst_amount' => $inter ? 0 : $tax / 2, 'igst_amount' => $inter ? $tax : 0, 'created_at' => now()]);
        }

        return $i;
    }

    private function line(Invoice $i, ?PanelType $type, float $qty, float $amount, float $tax, string $desc = 'Line'): void
    {
        InvoiceItem::create(['invoice_id' => $i->id, 'panel_type_id' => $type?->id, 'description' => $desc, 'quantity' => $qty, 'unit_price' => $qty > 0 ? $amount / $qty : 0, 'amount' => $amount, 'tax_rate' => 18, 'tax_amount' => $tax, 'total_with_tax' => $amount + $tax, 'created_at' => now()]);
    }

    private function pay(Invoice $i, float $amt, string $date, string $method = 'bank_transfer', ?string $ref = null): PaymentTransaction
    {
        return PaymentTransaction::create(['company_id' => $i->company_id, 'invoice_id' => $i->id, 'amount' => $amt, 'payment_method' => $method, 'status' => 'completed', 'transaction_date' => $date, 'reference_no' => $ref]);
    }

    private function panel(string $name, ?string $hsn): PanelType
    {
        return PanelType::factory()->create(['company_id' => $this->co->id, 'name' => $name, 'hsn_code' => $hsn ?? '']);      // the column cannot be null: no code is an empty string
    }

    /** A CSV file as rows of cells (without the byte-order mark), the way Excel reads it. */
    private function rows(string $csv): array
    {
        return array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_values(array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF")), fn ($l) => trim($l) !== '')));
    }

    // ── GST sales register ───────────────────────────────────────────────────

    /** Four invoices in November 2026 plus the ones that must not be there. */
    private function gstScenario(): void
    {
        $puf = $this->panel('PUF 50mm', '3921');
        $pir = $this->panel('PIR 80mm', '6815');
        $bare = $this->panel('Unnamed panel', null);

        $a = $this->invoice($this->customer('Patel Agro', '24AAACR5055K1Z5', '24'), ['invoice_date' => '2026-11-02', 'subtotal' => 10000], 1800);
        $this->line($a, $puf, 100, 10000, 1800);
        $b = $this->invoice($this->customer('Mumbai Cold', '27AABCS1429B1Z1', '27'), ['invoice_date' => '2026-11-05', 'subtotal' => 20000], 3600, true);
        $this->line($b, $pir, 200, 20000, 3600);
        $c = $this->invoice($this->customer('Retail Rajesh'), ['invoice_date' => '2026-11-07', 'subtotal' => 5000], 900);
        $this->line($c, $puf, 50, 4000, 720);
        $this->line($c, null, 100, 1000, 180, 'Screws');
        $d = $this->invoice($this->customer('Wrong Gstin Co', '24AAA', '24'), ['invoice_date' => '2026-11-30', 'subtotal' => 1000], 180);
        $this->line($d, $bare, 10, 1000, 180);

        $this->invoice($this->customer('Cancelled Ltd', '24AAACR5055K1Z5'), ['status' => 'cancelled', 'subtotal' => 7000], 1260);
        $this->invoice($this->customer('Draft Ltd', '24AAACR5055K1Z5'), ['status' => 'draft', 'subtotal' => 7000], 1260);
        $this->invoice($this->customer('Last Month Ltd', '24AAACR5055K1Z5'), ['invoice_date' => '2026-10-31', 'subtotal' => 7000], 1260);
        $this->invoice($this->customer('Next Month Ltd', '24AAACR5055K1Z5'), ['invoice_date' => '2026-12-01', 'subtotal' => 7000], 1260);
    }

    private function register(string $q = 'from=2026-11-01&to=2026-11-30'): array
    {
        return $this->getJson("/api/reports/gst-register?{$q}")->assertOk()->json('data');
    }

    public function test_the_gst_register_lists_live_invoices_of_the_period_with_b2b_and_b2c(): void
    {
        $this->gstScenario();

        $r = $this->register();
        $rows = collect($r['invoices'])->keyBy('customer_name');

        $this->assertSame(['Patel Agro', 'Mumbai Cold', 'Retail Rajesh', 'Wrong Gstin Co'], collect($r['invoices'])->pluck('customer_name')->all(), 'oldest first; cancelled, draft and out-of-period invoices left out; both ends included');
        $this->assertSame(['B2B', 'B2B', 'B2C', 'B2C'], collect($r['invoices'])->pluck('type')->all());
        $this->assertSame(['24AAACR5055K1Z5', '27AABCS1429B1Z1', '', ''], collect($r['invoices'])->pluck('customer_gstin')->all(), 'an invalid GSTIN is not shown as one');
        $this->assertSame(['24-Gujarat', '27-Maharashtra'], [$rows['Patel Agro']['place_of_supply'], $rows['Mumbai Cold']['place_of_supply']]);
        $this->assertSame(['Intra-State', 'Inter-State'], [$rows['Patel Agro']['supply'], $rows['Mumbai Cold']['supply']]);
        $this->assertEquals([11800, 10000, 18, 900, 900, 0, 1800], [$rows['Patel Agro']['invoice_value'], $rows['Patel Agro']['taxable_value'], $rows['Patel Agro']['tax_rate'], $rows['Patel Agro']['cgst'], $rows['Patel Agro']['sgst'], $rows['Patel Agro']['igst'], $rows['Patel Agro']['total_tax']]);
        $this->assertEquals([23600, 20000, 0, 0, 3600], [$rows['Mumbai Cold']['invoice_value'], $rows['Mumbai Cold']['taxable_value'], $rows['Mumbai Cold']['cgst'], $rows['Mumbai Cold']['sgst'], $rows['Mumbai Cold']['igst']]);
    }

    public function test_the_gst_register_totals_and_the_totals_by_rate(): void
    {
        $this->gstScenario();

        $r = $this->register();

        $this->assertEquals(['invoices' => 4, 'b2b_invoices' => 2, 'b2c_invoices' => 2, 'invoice_value' => 42480, 'taxable_value' => 36000, 'cgst' => 1440, 'sgst' => 1440, 'igst' => 3600, 'total_tax' => 6480, 'b2b_taxable' => 30000, 'b2c_taxable' => 6000], $r['totals']);
        $this->assertCount(1, $r['by_rate']);
        $this->assertEquals([18, 4, 36000, 1440, 1440, 3600], [$r['by_rate'][0]['tax_rate'], $r['by_rate'][0]['invoices'], $r['by_rate'][0]['taxable_value'], $r['by_rate'][0]['cgst'], $r['by_rate'][0]['sgst'], $r['by_rate'][0]['igst']]);
    }

    public function test_the_gst_register_has_an_hsn_summary_that_joins_lines_of_the_same_code_and_splits_the_tax_by_supply(): void
    {
        $this->gstScenario();

        $hsn = collect($this->register()['hsn'])->keyBy(fn ($r) => $r['hsn'] . '|' . $r['uqc']);

        $this->assertEqualsCanonicalizing(['3921|SQM', '6815|SQM', 'Not set|SQM', 'Not set|OTH'], $hsn->keys()->all());
        $this->assertEquals([150, 14000, 1260, 1260, 0, 2520], [$hsn['3921|SQM']['quantity'], $hsn['3921|SQM']['taxable_value'], $hsn['3921|SQM']['cgst'], $hsn['3921|SQM']['sgst'], $hsn['3921|SQM']['igst'], $hsn['3921|SQM']['total_tax']], 'two invoices, one code');
        $this->assertEquals([200, 20000, 0, 0, 3600], [$hsn['6815|SQM']['quantity'], $hsn['6815|SQM']['taxable_value'], $hsn['6815|SQM']['cgst'], $hsn['6815|SQM']['sgst'], $hsn['6815|SQM']['igst']], 'an inter-state sale is all IGST');
        $this->assertEquals([100, 1000], [$hsn['Not set|OTH']['quantity'], $hsn['Not set|OTH']['taxable_value']]);
        $this->assertSame('Accessories & other', $hsn['Not set|OTH']['description']);
    }

    public function test_the_gst_register_warns_about_what_to_fix_before_filing(): void
    {
        $this->gstScenario();

        $w = implode(' | ', $this->register()['warnings']);

        $this->assertStringContainsString('GSTIN(s) are not valid', $w);
        $this->assertStringContainsString('Wrong Gstin Co (24AAA)', $w);
        $this->assertStringContainsString('no HSN code', $w);
        $this->assertStringContainsString('Unnamed panel', $w);
        $this->assertSame([], $this->register('from=2025-01-01&to=2025-01-31')['warnings']);
    }

    public function test_the_gst_register_is_only_this_companys_and_defaults_to_this_month(): void
    {
        $other = Company::factory()->create();
        $this->invoice($this->customer('Other Co Customer', '24AAACR5055K1Z5', '24', $other), ['subtotal' => 99999], 17999, false, $other);
        $this->invoice($this->customer('Mine', '24AAACR5055K1Z5', '24'), ['invoice_date' => '2026-11-03', 'subtotal' => 1000], 180);
        $this->invoice($this->customer('Old', '24AAACR5055K1Z5', '24'), ['invoice_date' => '2026-10-03', 'subtotal' => 1000], 180);

        $r = $this->register('');

        $this->assertSame(['Mine'], collect($r['invoices'])->pluck('customer_name')->all());
        $this->assertSame(['2026-11-01', '2026-11-10'], [$r['period']['from'], $r['period']['to']]);
    }

    public function test_the_gst_register_csv_opens_in_excel_and_cannot_run_a_formula(): void
    {
        $this->gstScenario();
        $this->invoice($this->customer('=HYPERLINK("http://evil.test","x")', '24AAACR5055K1Z5', '24'), ['invoice_date' => '2026-11-08', 'subtotal' => 100], 18);

        $res = $this->get('/api/reports/gst-register/csv?from=2026-11-01&to=2026-11-30')->assertOk();
        $csv = $res->getContent();
        $rows = $this->rows($csv);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(['Invoice No', 'Invoice Date', 'Type', 'Customer', 'GSTIN', 'Place of Supply', 'Supply', 'Invoice Value', 'Rate %', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total Tax'], $rows[0]);
        $this->assertCount(1 + 5 + 1, $rows, 'header, five invoices, a total line');
        $this->assertSame(['02-11-2026', 'B2B', 'Patel Agro', '24AAACR5055K1Z5', '24-Gujarat'], [$rows[1][1], $rows[1][2], $rows[1][3], $rows[1][4], $rows[1][5]]);
        $this->assertSame("'=HYPERLINK(\"http://evil.test\",\"x\")", $rows[4][3], 'a formula is defused');
        $this->assertSame(['TOTAL', '42598.00', '', '36100.00', '1449.00', '1449.00', '3600.00', '6498.00'], [$rows[6][0], $rows[6][7], $rows[6][8], $rows[6][9], $rows[6][10], $rows[6][11], $rows[6][12], $rows[6][13]]);
        $this->assertStringContainsString('attachment; filename="gst_register_invoices_20261101_20261130.csv"', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('charset=UTF-8', $res->headers->get('Content-Type'));
    }

    public function test_the_gst_register_hsn_csv_and_its_input_checks(): void
    {
        $this->gstScenario();

        $csv = $this->get('/api/reports/gst-register/csv?from=2026-11-01&to=2026-11-30&part=hsn')->assertOk()->getContent();

        $rows = $this->rows($csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(['HSN', 'Description', 'UQC', 'Quantity', 'Rate %', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total Tax'], $rows[0]);
        $this->assertSame(['3921', 'PUF 50mm', 'SQM', '150.00', '18.00', '14000.00', '1260.00', '1260.00', '0.00', '2520.00'], $rows[1]);
        $this->assertSame(['TOTAL', '36000.00', '1440.00', '1440.00', '3600.00', '6480.00'], [$rows[5][0], $rows[5][5], $rows[5][6], $rows[5][7], $rows[5][8], $rows[5][9]]);
        $this->getJson('/api/reports/gst-register/csv?part=nonsense')->assertStatus(422);
        $this->getJson('/api/reports/gst-register?from=abc')->assertStatus(422);
        $this->getJson('/api/reports/gst-register?from=2026-12-01&to=2026-01-01')->assertStatus(422);
    }

    public function test_only_people_who_may_view_reports_can_open_the_new_reports(): void
    {
        $role = Role::factory()->create(['company_id' => $this->co->id, 'permissions' => ['quotations.view']]);
        $this->actingAs(User::factory()->create(['company_id' => $this->co->id, 'is_company_admin' => false, 'role_id' => $role->id]), 'sanctum');

        foreach (['gst-register', 'gst-register/csv', 'payments', 'payments/csv', 'customer-statement/1', 'customer-statement/1/pdf', 'accounts-receivable/pdf'] as $r) {
            $this->getJson("/api/reports/{$r}")->assertStatus(403);
        }
    }

    // ── payments register ────────────────────────────────────────────────────

    public function test_the_payments_register_counts_the_first_and_last_day_and_keeps_write_offs_apart(): void
    {
        $inv = $this->invoice($this->customer('Patel Agro'), ['subtotal' => 100000]);
        $this->pay($inv, 5000, '2026-10-31 23:59:59');                         // the day before
        $this->pay($inv, 3000, '2026-11-01 00:00:00', 'upi', 'UPI-777');
        $this->pay($inv, 2000, '2026-11-30 23:59:59', 'cash');
        $this->pay($inv, 500, '2026-11-15 12:00:00', 'write_off');
        $this->pay($inv, 700, '2026-12-01 00:00:00');                          // the day after

        $r = $this->getJson('/api/reports/payments?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');

        $this->assertEquals([2, 5000, 500], [$r['totals']['payments'], $r['totals']['received'], $r['totals']['written_off']]);
        $this->assertSame(['2026-11-30', '2026-11-15', '2026-11-01'], collect($r['payments'])->pluck('date')->all(), 'newest first');
        $this->assertEquals([['method' => 'upi', 'payments' => 1, 'amount' => 3000], ['method' => 'cash', 'payments' => 1, 'amount' => 2000]], $r['by_method']);
        $this->assertSame(['Patel Agro', 'UPI-777', true], [$r['payments'][2]['customer_name'], $r['payments'][2]['reference'], $r['payments'][1]['is_write_off']]);
        $this->assertSame($inv->invoice_no, $r['payments'][0]['invoice_no']);
    }

    public function test_the_payments_register_csv_and_other_companies_payments_stay_out(): void
    {
        $other = Company::factory()->create();
        $theirs = $this->invoice($this->customer('Theirs', null, null, $other), ['subtotal' => 100], 0, false, $other);
        PaymentTransaction::create(['company_id' => $other->id, 'invoice_id' => $theirs->id, 'amount' => 9999, 'payment_method' => 'cash', 'status' => 'completed', 'transaction_date' => '2026-11-05 10:00:00']);
        $mine = $this->invoice($this->customer('=cmd|calc'), ['subtotal' => 5000]);
        $this->pay($mine, 1234.5, '2026-11-05 10:00:00', 'cheque', 'CHQ-1');

        $csv = $this->get('/api/reports/payments/csv?from=2026-11-01&to=2026-11-30')->assertOk()->getContent();

        $rows = $this->rows($csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(['Date', 'Invoice No', 'Customer', 'Method', 'Reference', 'Amount', 'Write-off', 'Entered By'], $rows[0]);
        $this->assertSame(['05-11-2026', "'=cmd|calc", 'cheque', 'CHQ-1', '1234.50'], [$rows[1][0], $rows[1][2], $rows[1][3], $rows[1][4], $rows[1][5]]);
        $this->assertStringNotContainsString('9999', $csv);
        $this->assertSame(['TOTAL RECEIVED', '1234.50'], [$rows[2][0], $rows[2][5]]);
        $this->assertSame(['TOTAL WRITTEN OFF', '0.00'], [$rows[3][0], $rows[3][5]]);
    }

    // ── customer statement and PDFs ──────────────────────────────────────────

    private function statementScenario(): Customer
    {
        $patel = $this->customer('Patel Agro', '24AAACR5055K1Z5', '24');
        $a = $this->invoice($patel, ['invoice_date' => '2026-09-10', 'due_date' => '2026-10-10', 'subtotal' => 10000], 1800);     // 11,800
        $b = $this->invoice($patel, ['invoice_date' => '2026-11-03', 'due_date' => '2026-12-03', 'subtotal' => 5000], 900);        // 5,900
        $this->pay($a, 4000, '2026-09-20 10:00:00');                                    // before the period
        $this->pay($a, 3000, '2026-11-03 09:00:00', 'upi', 'UPI-1');                    // the day invoice B is raised
        $this->pay($a, 800, '2026-11-20 10:00:00', 'write_off');
        $this->pay($b, 1000, '2026-12-05 10:00:00');                                    // after the period
        $this->invoice($this->customer('Someone Else'), ['invoice_date' => '2026-11-04', 'subtotal' => 77777]);                     // another customer
        $this->invoice($patel, ['status' => 'cancelled', 'invoice_date' => '2026-11-05', 'subtotal' => 55555]);
        $this->invoice($patel, ['status' => 'draft', 'invoice_date' => '2026-11-06', 'subtotal' => 44444]);

        return $patel;
    }

    private function statement(Customer $c, string $q = 'from=2026-11-01&to=2026-11-30'): array
    {
        return $this->getJson("/api/reports/customer-statement/{$c->id}?{$q}")->assertOk()->json('data');
    }

    public function test_a_statement_adds_up_from_the_opening_balance_to_the_closing_balance(): void
    {
        $s = $this->statement($this->statementScenario());

        $this->assertEquals(7800, $s['opening_balance'], '11,800 billed in September less 4,000 paid in September');
        $this->assertSame(['invoice', 'payment', 'write_off'], collect($s['lines'])->pluck('type')->all(), 'an invoice comes before a payment on the same day');
        $this->assertEquals([13700, 10700, 9900], collect($s['lines'])->pluck('balance')->all());
        $this->assertEquals([5900, 3800, 9900], [$s['total_debit'], $s['total_credit'], $s['closing_balance']]);
        $this->assertEquals($s['opening_balance'] + $s['total_debit'] - $s['total_credit'], $s['closing_balance']);
        $this->assertStringStartsWith('Invoice INV-E-', $s['lines'][0]['description']);
        $this->assertStringStartsWith('Payment received (upi) against INV-E-', $s['lines'][1]['description']);
        $this->assertStringStartsWith('Written off against INV-E-', $s['lines'][2]['description']);
        $this->assertSame('UPI-1', $s['lines'][1]['reference']);
    }

    public function test_a_statement_lists_what_is_still_unpaid_at_the_end_of_the_period_oldest_first(): void
    {
        $s = $this->statement($this->statementScenario());

        $this->assertCount(2, $s['outstanding']);
        $this->assertEquals([4000, 51], [$s['outstanding'][0]['balance'], $s['outstanding'][0]['days_overdue']], '11,800 less 7,800 paid by 30 Nov, due 10 Oct');
        $this->assertEquals([5900, 0], [$s['outstanding'][1]['balance'], $s['outstanding'][1]['days_overdue']], 'the December payment does not count on 30 Nov; not due yet');
        $this->assertSame(['Patel Agro', '24AAACR5055K1Z5'], [$s['customer']['name'], $s['customer']['gstin']]);
    }

    public function test_a_statement_for_an_earlier_period_and_a_default_period(): void
    {
        $c = $this->statementScenario();

        $sep = $this->statement($c, 'from=2026-09-01&to=2026-09-30');
        $this->assertEquals([0, 11800, 4000, 7800], [$sep['opening_balance'], $sep['total_debit'], $sep['total_credit'], $sep['closing_balance']]);

        $default = $this->getJson("/api/reports/customer-statement/{$c->id}")->assertOk()->json('data');
        $this->assertSame(['2026-04-01', '2026-11-10'], [$default['period']['from'], $default['period']['to']], 'financial year to date');
        $this->assertEquals(0, $default['opening_balance']);
        $this->assertEquals(11800 + 5900 - 4000 - 3000, $default['closing_balance'], 'the 20 Nov write-off and the 5 Dec payment are after today (10 Nov)');
    }

    public function test_a_statement_is_refused_for_another_companys_customer_and_for_bad_dates(): void
    {
        $other = Company::factory()->create();
        $theirs = $this->customer('Theirs', null, null, $other);
        $mine = $this->customer('Mine');

        $this->getJson("/api/reports/customer-statement/{$theirs->id}")->assertStatus(404);
        $this->getJson('/api/reports/customer-statement/999999')->assertStatus(404);
        $this->getJson("/api/reports/customer-statement/{$mine->id}?from=abc")->assertStatus(422);
        $this->getJson("/api/reports/customer-statement/{$mine->id}?from=2026-12-01&to=2026-01-01")->assertStatus(422);
        $this->assertSame([], $this->statement($mine)['lines']);
    }

    public function test_the_statement_and_the_receivables_ageing_come_as_real_pdf_files(): void
    {
        $c = $this->statementScenario();

        $st = $this->get("/api/reports/customer-statement/{$c->id}/pdf?from=2026-11-01&to=2026-11-30")->assertOk();
        $ar = $this->get('/api/reports/accounts-receivable/pdf?as_of=2026-11-30')->assertOk();

        foreach ([$st, $ar] as $res) {
            $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $res->getContent());
        }
        $this->assertStringContainsString('statement_patel-agro_20261130.pdf', $st->headers->get('Content-Disposition'));
        $this->assertStringContainsString('receivables_ageing_20261130.pdf', $ar->headers->get('Content-Disposition'));
        $this->get("/api/reports/customer-statement/999999/pdf")->assertStatus(404);
        $this->getJson('/api/reports/accounts-receivable/pdf?as_of=garbage')->assertStatus(422);
    }

    // ── monthly MIS e-mail ───────────────────────────────────────────────────

    private function mailbox(): array
    {
        return ['smtp_enabled' => true, 'smtp_host' => 'smtp.signature.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => 'accounts@signature.test', 'smtp_password' => 'Pass-1!',
            'smtp_from_address' => 'accounts@signature.test', 'smtp_from_name' => 'Signature PUF Panel'];
    }

    private function inMemoryMailbox(bool $fail = false): void
    {
        CompanyMail::useFactory(function (CompanyMailbox $b) use ($fail) {
            if ($fail) {
                throw new \RuntimeException('Mailbox refused the login');
            }
            $this->boxes[$b->fromAddress] ??= new \Illuminate\Mail\Transport\ArrayTransport();

            return new Mailer('company', app('view'), $this->boxes[$b->fromAddress], app('events'));
        });
    }

    private function sent(): array
    {
        return collect($this->boxes)->flatMap(fn ($t) => $t->messages()->all())->all();
    }

    private function misOn(array $over = []): NotificationSetting
    {
        $s = NotificationSetting::forCompany($this->co->id);
        $s->forceFill(['email_monthly_mis' => true] + $this->mailbox() + $over)->save();

        return $s;
    }

    private function octoberBusiness(): void
    {
        $inv = $this->invoice($this->customer('Patel Agro'), ['invoice_date' => '2026-10-12', 'due_date' => '2026-10-25', 'subtotal' => 10000], 1800);
        $this->pay($inv, 4000, '2026-10-20 10:00:00');
        $this->pay($inv, 1000, '2026-11-02 10:00:00');
    }

    public function test_the_monthly_mis_goes_to_the_admins_of_a_company_that_switched_it_on_and_only_once_a_month(): void
    {
        $this->octoberBusiness();
        $this->misOn();
        User::factory()->create(['company_id' => $this->co->id, 'is_company_admin' => true, 'email' => 'partner@signature.test']);
        User::factory()->create(['company_id' => $this->co->id, 'is_company_admin' => true, 'is_active' => false, 'email' => 'left@signature.test']);
        User::factory()->create(['company_id' => $this->co->id, 'is_company_admin' => false, 'email' => 'clerk@signature.test']);
        $this->inMemoryMailbox();

        $this->assertSame(0, Artisan::call('reports:send-monthly-mis'));

        $to = collect($this->sent())->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())->sort()->values()->all();
        $this->assertSame(['owner@signature.test', 'partner@signature.test'], $to, 'active admins only');
        $msg = $this->sent()[0]->getOriginalMessage();
        $this->assertStringContainsString('Monthly MIS: October 2026', $msg->getSubject());
        $this->assertSame('2026-10', NotificationSetting::forCompany($this->co->id)->mis_last_sent_month);

        Artisan::call('reports:send-monthly-mis');
        $this->assertCount(2, $this->sent(), 'a second run the same month sends nothing');
        $this->assertStringContainsString('already sent', strtolower(Artisan::output()));

        Artisan::call('reports:send-monthly-mis', ['--force' => true]);
        $this->assertCount(4, $this->sent());
    }

    public function test_the_monthly_mis_says_what_happened_last_month(): void
    {
        $this->octoberBusiness();
        $this->misOn();
        $this->inMemoryMailbox();

        Artisan::call('reports:send-monthly-mis');
        $body = $this->sent()[0]->getOriginalMessage()->getHtmlBody();

        $this->assertStringContainsString('Invoiced this month', $body);
        $this->assertStringContainsString('₹ 11,800.00', $body);                       // invoiced in October
        $this->assertStringContainsString('₹ 4,000.00', $body);                        // money received in October (the 1,000 came in November)
        $this->assertStringContainsString('₹ 5,000.00', $body);                        // paid so far against October's invoice (4,000 + the 1,000 in November)
        $this->assertStringContainsString('₹ 6,800.00', $body);                        // still unpaid on it today
        $this->assertStringContainsString('₹ 1,800.00', $body);                        // GST on it
        $this->assertStringContainsString('₹ 7,800.00', $body);                        // receivable at 31 Oct (11,800 - 4,000), all of it 6 days overdue
    }

    public function test_the_monthly_mis_can_be_sent_for_a_chosen_month_and_refuses_a_bad_one(): void
    {
        $this->octoberBusiness();
        $this->misOn();
        $this->inMemoryMailbox();

        $this->assertSame(1, Artisan::call('reports:send-monthly-mis', ['--month' => '2026-13']));
        $this->assertSame(1, Artisan::call('reports:send-monthly-mis', ['--month' => 'October']));
        $this->assertCount(0, $this->sent());

        $this->assertSame(0, Artisan::call('reports:send-monthly-mis', ['--month' => '2026-09']));
        $this->assertStringContainsString('September 2026', $this->sent()[0]->getOriginalMessage()->getSubject());
        $this->assertSame('2026-09', NotificationSetting::forCompany($this->co->id)->mis_last_sent_month);
    }

    public function test_nothing_is_sent_unless_it_is_switched_on_and_a_mailbox_exists(): void
    {
        $this->octoberBusiness();
        $this->inMemoryMailbox();

        NotificationSetting::forCompany($this->co->id)->forceFill($this->mailbox())->save();       // a mailbox but not switched on
        Artisan::call('reports:send-monthly-mis');
        $this->assertCount(0, $this->sent());
        $this->assertFalse((bool) NotificationSetting::forCompany($this->co->id)->email_monthly_mis, 'off until someone chooses it');

        NotificationSetting::forCompany($this->co->id)->forceFill(['email_monthly_mis' => true, 'smtp_enabled' => false])->save();   // switched on, no mailbox
        Artisan::call('reports:send-monthly-mis');
        $this->assertCount(0, $this->sent());
        $this->assertStringContainsString('No mailbox', Artisan::output());
        $this->assertNull(NotificationSetting::forCompany($this->co->id)->mis_last_sent_month, 'not marked as sent, so it goes once a mailbox is set up');
    }

    public function test_a_mailbox_that_fails_is_reported_and_tried_again_next_time(): void
    {
        $this->octoberBusiness();
        $this->misOn();
        $this->inMemoryMailbox(true);

        $this->assertSame(1, Artisan::call('reports:send-monthly-mis'));

        $this->assertStringContainsString('failed', Artisan::output());
        $this->assertNull(NotificationSetting::forCompany($this->co->id)->mis_last_sent_month);
    }

    public function test_one_companys_problem_does_not_stop_the_next_company(): void
    {
        $this->octoberBusiness();
        $this->misOn();
        $other = Company::factory()->create();
        NotificationSetting::withoutGlobalScope('tenant')->updateOrCreate(['company_id' => $other->id], ['email_monthly_mis' => true]);       // switched on, but this company has no mailbox
        User::factory()->create(['company_id' => $other->id, 'is_company_admin' => true, 'email' => 'boss@other.test']);
        $this->inMemoryMailbox();

        Artisan::call('reports:send-monthly-mis');

        $this->assertCount(1, $this->sent());
        $this->assertSame('2026-10', NotificationSetting::forCompany($this->co->id)->mis_last_sent_month);
        $this->assertNull(NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $other->id)->value('mis_last_sent_month'));
    }

    public function test_the_monthly_mis_setting_is_in_the_notification_settings_and_the_mail_type_list_and_the_schedule(): void
    {
        $this->assertFalse((bool) $this->getJson('/api/settings/notifications')->assertOk()->json('data.email_monthly_mis'));

        $this->putJson('/api/settings/notifications', ['email_monthly_mis' => true])->assertOk();

        $this->assertTrue((bool) $this->getJson('/api/settings/notifications')->json('data.email_monthly_mis'));
        $this->assertContains('monthly_mis', array_column($this->getJson('/api/settings/notifications')->json('data.mail_types'), 'type'));
        Artisan::call('schedule:list');
        $this->assertStringContainsString('reports:send-monthly-mis', Artisan::output());
    }

    public function test_last_month_is_the_calendar_month_before_in_indian_time(): void
    {
        $m = fn (string $now) => \App\Services\MonthlyMisService::lastMonth(Carbon::parse($now, 'Asia/Kolkata'))->format('Y-m');

        $this->assertSame(['2026-10', '2026-12', '2026-02', '2026-10'], [$m('2026-11-01 08:00'), $m('2027-01-01 08:00'), $m('2026-03-01 08:00'), $m('2026-11-30 23:59')]);
    }

    // ── Tally ledger names ───────────────────────────────────────────────────

    private function tallyInvoice(): void
    {
        $this->invoice($this->customer('Patel Agro', '24AAACR5055K1Z5', '24'), ['invoice_date' => '2026-11-05', 'subtotal' => 10000], 1800);
    }

    public function test_the_tally_export_uses_the_default_ledger_names_until_the_company_sets_its_own(): void
    {
        $this->tallyInvoice();

        $xml = $this->get('/api/reports/tally/xml?from=2026-11-01&to=2026-11-30')->assertOk()->getContent();

        foreach (['Sales @ 18%', '>CGST<', '>SGST<'] as $name) {
            $this->assertStringContainsString($name, $xml);
        }
        $this->assertStringNotContainsString('>IGST<', $xml, 'an intra-state sale has no IGST');
        $this->assertSame(['sales' => 'Sales @ {rate}%', 'cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST', 'hsn' => '39259010'], $this->getJson('/api/settings/tally-ledgers')->assertOk()->json('data.ledgers'));
    }

    public function test_a_company_can_name_its_own_tally_ledgers_and_the_export_uses_them(): void
    {
        $this->tallyInvoice();
        $this->invoice($this->customer('Mumbai Cold', '27AABCS1429B1Z1', '27'), ['invoice_date' => '2026-11-06', 'subtotal' => 1000], 180, true);

        $this->putJson('/api/settings/tally-ledgers', ['sales' => 'Sales - Panels {rate}% & Fittings', 'cgst' => 'Output CGST 9%', 'sgst' => 'Output SGST 9%', 'igst' => 'Output IGST 18%', 'hsn' => '681599'])->assertOk()
            ->assertJsonPath('data.ledgers.sales', 'Sales - Panels {rate}% & Fittings');
        $xml = $this->get('/api/reports/tally/xml?from=2026-11-01&to=2026-11-30')->assertOk()->getContent();
        $csv = $this->get('/api/reports/tally/csv?from=2026-11-01&to=2026-11-30')->getContent();

        $doc = new \SimpleXMLElement($xml);
        $names = collect($doc->xpath('//LEDGERNAME'))->map(fn ($n) => (string) $n)->unique()->sort()->values()->all();
        $this->assertSame(['Mumbai Cold', 'Output CGST 9%', 'Output IGST 18%', 'Output SGST 9%', 'Patel Agro', 'Sales - Panels 18% & Fittings'], $names);
        $this->assertStringContainsString('Sales - Panels 18% & Fittings', $csv);
        $this->assertStringNotContainsString('>CGST<', $xml);
    }

    public function test_the_tally_sales_ledger_can_name_one_ledger_per_rate_and_leaving_a_field_empty_restores_its_default(): void
    {
        $this->assertSame(['Sales 18%', 'Sales 12.5%', 'Sales 5%', 'Sales 0%'], array_map(fn ($r) => \App\Support\TallyLedgers::salesLedger(['sales' => 'Sales {rate}%'], $r), [18.0, 12.5, 5.0, 0.0]));
        $this->putJson('/api/settings/tally-ledgers', ['sales' => 'Sales', 'cgst' => 'My CGST'])->assertOk();
        $this->putJson('/api/settings/tally-ledgers', ['sales' => '', 'cgst' => '  ', 'sgst' => null])->assertOk();

        $l = $this->getJson('/api/settings/tally-ledgers')->json('data.ledgers');
        $this->assertSame(['Sales @ {rate}%', 'CGST', 'SGST'], [$l['sales'], $l['cgst'], $l['sgst']]);
    }

    public function test_tally_ledger_settings_are_checked_per_company_and_by_permission(): void
    {
        $this->getJson('/api/settings/tally-ledgers')->assertOk()->assertJsonStructure(['data' => ['ledgers', 'defaults', 'panel_types_without_hsn']]);
        $this->putJson('/api/settings/tally-ledgers', ['sales' => 'Sales <18>'])->assertStatus(422);
        $this->putJson('/api/settings/tally-ledgers', ['cgst' => str_repeat('x', 81)])->assertStatus(422);
        $this->putJson('/api/settings/tally-ledgers', ['hsn' => '39'])->assertStatus(422);
        $this->putJson('/api/settings/tally-ledgers', ['hsn' => '39259AB'])->assertStatus(422);
        $this->putJson('/api/settings/tally-ledgers', ['sales' => 'Mine'])->assertOk();

        $other = Company::factory()->create();
        $this->assertSame('Sales @ {rate}%', \App\Support\TallyLedgers::for($other->id)['sales'], 'another company is unaffected');

        $role = Role::factory()->create(['company_id' => $this->co->id, 'permissions' => ['reports.view']]);
        $this->actingAs(User::factory()->create(['company_id' => $this->co->id, 'is_company_admin' => false, 'role_id' => $role->id]), 'sanctum');
        $this->getJson('/api/settings/tally-ledgers')->assertOk();
        $this->putJson('/api/settings/tally-ledgers', ['sales' => 'Sneaky'])->assertStatus(403);
    }

    public function test_the_tally_settings_name_the_panel_types_with_no_hsn_code(): void
    {
        $this->panel('With code', '3921');
        $this->panel('No code', null);
        $this->panel('Blank code', '');

        $this->assertEqualsCanonicalizing(['No code', 'Blank code'], $this->getJson('/api/settings/tally-ledgers')->json('data.panel_types_without_hsn'));
    }

    public function test_the_fallback_hsn_is_used_in_the_export_data_for_a_panel_with_no_code(): void
    {
        $bare = $this->panel('Bare', null);
        $coded = $this->panel('Coded', '3921');
        $i = $this->invoice($this->customer('Patel Agro'), ['invoice_date' => '2026-11-05'], 180);
        $this->line($i, $bare, 10, 1000, 180);
        $this->line($i, $coded, 10, 1000, 180);
        $this->putJson('/api/settings/tally-ledgers', ['hsn' => '681599'])->assertOk();

        $data = app(ReportingService::class)->getTallyExportData($this->co->id, '2026-11-01', '2026-11-30');

        $this->assertEqualsCanonicalizing(['681599', '3921'], array_column($data['vouchers'][0]['hsn_summary'], 'hsn'));
    }

    // ── financial year ───────────────────────────────────────────────────────

    public function test_the_financial_year_follows_the_companys_start_month(): void
    {
        $fy = fn (string $d, int $m) => ReportingService::financialYearStart(Carbon::parse($d), $m)->toDateString();

        $this->assertSame(['2026-04-01', '2026-04-01', '2025-04-01'], [$fy('2026-11-10', 4), $fy('2027-03-31', 4), $fy('2026-03-31', 4)]);
        $this->assertSame(['2026-01-01', '2026-01-01', '2026-01-01'], [$fy('2026-11-10', 1), $fy('2026-01-01', 1), $fy('2026-12-31', 1)]);
        $this->assertSame(['2026-07-01', '2025-07-01'], [$fy('2026-11-10', 7), $fy('2026-03-10', 7)]);
        $this->assertSame('2025-12-01', $fy('2026-11-10', 99), 'a silly month is held to December');
    }

    public function test_the_accounting_dashboards_year_to_date_uses_the_companys_financial_year(): void
    {
        $this->invoice(null, ['invoice_date' => '2026-02-10', 'due_date' => '2026-03-10', 'subtotal' => 1000]);     // Feb: last year for an April company, this year for a calendar-year company
        $this->invoice(null, ['invoice_date' => '2026-05-10', 'due_date' => '2026-06-10', 'subtotal' => 2000]);
        $ytd = fn () => $this->getJson('/api/reports/accounting-dashboard')->assertOk()->json('data.summary.total_revenue_ytd');

        $this->assertEquals(2000, $ytd(), 'default: from 1 April');

        Company::whereKey($this->co->id)->update(['financial_year_start' => 1]);
        $this->assertEquals(3000, $ytd(), 'a calendar-year company');
    }

    // ── revenue trend and top customers ──────────────────────────────────────

    public function test_the_revenue_trend_adds_each_month_and_counts_only_money_received(): void
    {
        $sep = $this->invoice(null, ['invoice_date' => '2026-09-15', 'subtotal' => 1000], 180);                                   // 1,180
        $this->invoice(null, ['invoice_date' => '2026-09-20', 'subtotal' => 2000, 'total_amount' => 0, 'tax_amount' => 360]);       // stored total missing: 2,000 + 360
        $oct = $this->invoice(null, ['invoice_date' => '2026-10-05', 'subtotal' => 5000]);
        $this->invoice(null, ['invoice_date' => '2026-10-06', 'status' => 'draft', 'subtotal' => 99999]);
        $this->invoice(null, ['invoice_date' => '2026-11-01', 'status' => 'cancelled', 'subtotal' => 88888]);
        $this->invoice(null, ['invoice_date' => '2026-08-31', 'subtotal' => 7777]);                                               // before the three months
        $this->pay($sep, 500, '2026-09-30 23:59:59');
        $this->pay($sep, 100, '2026-10-01 00:00:00');
        $this->pay($oct, 300, '2026-10-10 10:00:00', 'write_off');
        $this->pay($oct, 2500, '2026-11-02 10:00:00', 'upi');

        $t = $this->getJson('/api/reports/revenue-trend?months=3')->assertOk()->json('data');

        $this->assertSame(['2026-09', '2026-10', '2026-11'], array_column($t['series'], 'key'));
        $this->assertEquals([3540, 5000, 0], array_column($t['series'], 'invoiced'));
        $this->assertEquals([500, 100, 2500], array_column($t['series'], 'collected'), 'a write-off is not money received');
        $this->assertEquals([8540, 3100], [$t['totals']['invoiced'], $t['totals']['collected']]);
    }

    public function test_top_customers_are_ranked_by_what_they_were_invoiced_with_what_they_still_owe(): void
    {
        $big = $this->invoice($this->customer('Big Buyer'), ['invoice_date' => '2026-06-01', 'subtotal' => 50000], 9000);          // 59,000
        $this->invoice($this->customer('Mid Buyer'), ['invoice_date' => '2026-07-01', 'subtotal' => 20000]);
        $this->invoice($this->customer('Small Buyer'), ['invoice_date' => '2026-08-01', 'subtotal' => 1000]);
        $this->invoice(null, ['invoice_date' => '2026-08-02', 'subtotal' => 3000]);                                               // no customer on record
        $this->invoice($this->customer('Cancelled Buyer'), ['invoice_date' => '2026-08-03', 'status' => 'cancelled', 'subtotal' => 99999]);
        $this->pay($big, 40000, '2026-06-20 10:00:00');
        $this->pay($big, 500, '2026-06-21 10:00:00', 'write_off');

        $top = $this->getJson('/api/reports/top-customers?from_date=2026-01-01&to_date=2026-12-31&limit=3')->assertOk()->json('data');

        $this->assertSame(['Big Buyer', 'Mid Buyer', 'Unknown'], array_column($top, 'customer_name'));
        $this->assertEquals([59000, 40500, 18500], [$top[0]['invoiced'], $top[0]['paid'], $top[0]['outstanding']], 'paid includes the written-off amount: it is no longer owed');
        $this->assertEquals([20000, 0, 20000, 1], [$top[1]['invoiced'], $top[1]['paid'], $top[1]['outstanding'], $top[1]['invoice_count']]);
        $this->assertSame(0, $top[2]['customer_id']);
    }
}
