<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\PanelType;
use App\Models\PaymentTransaction;
use App\Models\ProductionBatch;
use App\Models\ProductionRun;
use App\Models\TaxCalculation;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reports audit, 25 Oct 2026: the Medium findings PB-074 (MIS), PB-076 (revenue leak), PB-077 (panel mix and sales by panel),
 * PB-078 (receivables as of a date), PB-079 (receivables bucket filter) and PB-080 (one set of definitions).
 * The fixed date is Tuesday 10 Nov 2026, 15:00; the financial year is 1 Apr 2026 to 31 Mar 2027.
 */
class ReportsMediumFixesTest extends TestCase
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

    private function inv(array $o = [], float $tax = 0): Invoice
    {
        static $n = 0;
        $n++;
        $sub = $o['subtotal'] ?? 1000;
        $total = $o['total_amount'] ?? ($sub + $tax);
        $i = Invoice::create($o + ['company_id' => $this->co->id, 'invoice_no' => "INV-M-{$n}", 'status' => 'sent', 'subtotal' => $sub, 'tax_amount' => $tax, 'total_amount' => $total,
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

    private function makeRun(string $completedAt, float $sqm = 100): ProductionRun
    {
        static $n = 0;
        $n++;

        return ProductionRun::create(['company_id' => $this->co->id, 'run_no' => "RUN-M-{$n}", 'status' => 'completed', 'planned_sqm' => $sqm, 'completed_at' => $completedAt]);
    }

    private function orderWithBatches(array $order, array $batchStatuses): Order
    {
        $o = Order::factory()->create(['company_id' => $this->co->id] + $order);
        foreach ($batchStatuses as $s) {
            ProductionBatch::factory()->create(['company_id' => $this->co->id, 'order_id' => $o->id, 'status' => $s]);
        }

        return $o;
    }

    private function mis(string $from = '2026-11-01', string $to = '2026-11-30'): array
    {
        return $this->getJson("/api/reports/mis?from={$from}&to={$to}")->assertOk()->json('data');
    }

    // ── PB-074: MIS ─────────────────────────────────────────────────────────

    public function test_mis_counts_orders_and_runs_through_the_whole_last_day_of_the_range(): void
    {
        Order::factory()->create(['company_id' => $this->co->id, 'order_date' => '2026-11-10']);                 // today
        Order::factory()->create(['company_id' => $this->co->id, 'order_date' => '2026-11-01']);                 // first day
        Order::factory()->create(['company_id' => $this->co->id, 'order_date' => '2026-10-31']);                 // before
        Order::factory()->create(['company_id' => $this->co->id, 'order_date' => '2026-11-11']);                 // after
        $this->makeRun('2026-11-10 14:00:00', 100);
        $this->makeRun('2026-11-10 23:59:59', 50);
        $this->makeRun('2026-11-11 00:00:00', 999);
        $this->makeRun('2026-10-31 23:59:59', 999);

        $m = $this->mis('2026-11-01', '2026-11-10');

        $this->assertSame(2, $m['orders']['count']);
        $this->assertEquals([2, 150], [$m['production']['runs'], $m['production']['sqm_produced']]);
        $this->assertSame('planned', $m['production']['basis'], 'no actual output is recorded: the figure is the runs\' planned sqm and says so');
    }

    // ── PB-080 a/b/c: MIS means one thing and adds up ───────────────────────

    public function test_mis_says_what_was_invoiced_collected_written_off_and_is_still_unpaid(): void
    {
        $a = $this->inv(['invoice_date' => '2026-11-02', 'due_date' => '2026-12-20'], 180);      // 1180, not due, 400 paid, 100 written off
        $b = $this->inv(['invoice_date' => '2026-11-03', 'due_date' => '2026-11-01'], 0);        // 1000, 9 days overdue, nothing paid
        $c = $this->inv(['invoice_date' => '2026-11-04', 'due_date' => '2026-10-01', 'status' => 'paid'], 0);   // 1000 marked paid but only 300 received: 40 days overdue
        $this->pay($a, 400, '2026-11-06 10:00:00');
        $this->pay($a, 100, '2026-11-07 10:00:00', 'write_off');
        $this->pay($c, 300, '2026-11-08 10:00:00');

        $m = $this->mis();

        $this->assertEquals([3180, 700, 100, 2380], [$m['revenue']['invoiced'], $m['revenue']['collected'], $m['revenue']['written_off'], $m['revenue']['outstanding']]);
        $this->assertEquals(22, $m['revenue']['collection_pct']);
        $this->assertEquals(['not_due' => 680, '0_30' => 1000, '31_60' => 700, '61_90' => 0, 'over_90' => 0], $m['aging'], 'a legacy "paid" invoice with a balance is included');
        $this->assertEquals($m['revenue']['outstanding'], array_sum($m['aging']), 'the ageing adds up to the outstanding figure');
        $this->assertEquals(3180 - 700 - 100, $m['revenue']['outstanding'], 'invoiced - collected - written off');
        $this->assertEquals([['month' => 'Nov 2026', 'invoiced' => 3180, 'collected' => 700, 'count' => 3]], $m['monthly']);
    }

    public function test_mis_uses_the_stored_invoice_total_like_every_other_report(): void
    {
        $this->inv(['subtotal' => 1000, 'total_amount' => 1180], 0);          // a total with no tax row behind it

        $this->assertEquals(1180, $this->mis()['revenue']['invoiced']);
    }

    // ── PB-076: revenue leak ────────────────────────────────────────────────

    private function recon(): array
    {
        $r = $this->getJson('/api/reports/reconciliation?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');

        return ['summary' => $r['summary'], 'flags' => collect($r['rows'])->pluck('flag', 'order_no')->all()];
    }

    public function test_an_order_that_has_not_been_delivered_is_not_revenue_leakage(): void
    {
        $this->orderWithBatches(['order_no' => 'ORD-NEW', 'status' => 'pending', 'total_amount' => 118000, 'order_date' => '2026-11-09'], []);
        $this->orderWithBatches(['order_no' => 'ORD-WIP', 'status' => 'in_production', 'total_amount' => 59000, 'order_date' => '2026-11-08'], ['in_progress', 'draft']);

        $r = $this->recon();

        $this->assertEquals(['ORD-NEW' => 'not_delivered', 'ORD-WIP' => 'not_delivered'], $r['flags']);
        $this->assertEquals([0, 0, 0, 2], [$r['summary']['revenue_leak'], $r['summary']['not_invoiced'], $r['summary']['under_invoiced'], $r['summary']['not_delivered']]);
    }

    public function test_a_fully_delivered_order_that_was_not_invoiced_or_under_invoiced_is_leakage(): void
    {
        $this->orderWithBatches(['order_no' => 'ORD-DONE', 'status' => 'completed', 'total_amount' => 118000, 'order_date' => '2026-11-02'], ['dispatched']);          // completed, never invoiced
        $under = $this->orderWithBatches(['order_no' => 'ORD-UNDER', 'status' => 'in_production', 'total_amount' => 50000, 'order_date' => '2026-11-03'], ['dispatched', 'dispatched']);   // every batch out, status not set yet
        $this->inv(['order_id' => $under->id, 'subtotal' => 40000, 'total_amount' => 40000]);
        $ok = $this->orderWithBatches(['order_no' => 'ORD-OK', 'status' => 'completed', 'total_amount' => 20000, 'order_date' => '2026-11-04'], ['dispatched']);
        $this->inv(['order_id' => $ok->id, 'subtotal' => 20000, 'total_amount' => 20000]);

        $r = $this->recon();

        $this->assertEquals(['ORD-DONE' => 'not_invoiced', 'ORD-UNDER' => 'under_invoiced', 'ORD-OK' => 'ok'], $r['flags']);
        $this->assertEquals([128000, 1, 1], [$r['summary']['revenue_leak'], $r['summary']['not_invoiced'], $r['summary']['under_invoiced']]);
    }

    public function test_an_order_delivered_in_part_is_listed_but_its_value_is_not_counted_as_leakage(): void
    {
        $this->orderWithBatches(['order_no' => 'ORD-PART-NOINV', 'status' => 'in_production', 'total_amount' => 100000, 'order_date' => '2026-11-05'], ['dispatched', 'in_progress']);
        $p = $this->orderWithBatches(['order_no' => 'ORD-PART-INV', 'status' => 'in_production', 'total_amount' => 100000, 'order_date' => '2026-11-06'], ['dispatched', 'in_progress']);
        $this->inv(['order_id' => $p->id, 'subtotal' => 40000, 'total_amount' => 40000]);

        $r = $this->recon();

        $this->assertEquals(['ORD-PART-NOINV' => 'awaiting_invoice', 'ORD-PART-INV' => 'part_delivered'], $r['flags']);
        $this->assertEquals([0, 1], [$r['summary']['revenue_leak'], $r['summary']['awaiting_invoice']]);
    }

    // ── PB-077: accessories ─────────────────────────────────────────────────

    private function lines(Invoice $i): void
    {
        $puf = PanelType::factory()->create(['company_id' => $this->co->id, 'name' => 'PUF 50mm']);
        $pir = PanelType::factory()->create(['company_id' => $this->co->id, 'name' => 'PIR 80mm']);
        $row = fn ($type, $desc, $qty, $price) => InvoiceItem::create(['invoice_id' => $i->id, 'panel_type_id' => $type?->id, 'description' => $desc, 'quantity' => $qty, 'unit_price' => $price, 'amount' => $qty * $price,
            'tax_rate' => 18, 'tax_amount' => 0, 'total_with_tax' => $qty * $price, 'created_at' => now()]);
        $row($puf, 'PUF roof', 120.5, 800);        // 96,400
        $row($puf, 'PUF wall', 30, 800);           // 24,000
        $row($pir, 'PIR wall', 50, 1000);          // 50,000
        $row(null, 'Screws', 500, 10);             // 5,000 (pieces)
        $row(null, 'Ridge cap', 20, 400);          // 8,000 (pieces)
    }

    public function test_accessory_lines_are_one_row_with_a_value_and_no_sqm(): void
    {
        $this->lines($this->inv(['subtotal' => 183400]));

        $mix = $this->getJson('/api/reports/panel-type-mix?from_date=2026-11-01&to_date=2026-11-30')->assertOk()->json('data');
        $rows = collect($mix['rows'])->keyBy('panel_type');

        $this->assertEquals(200.5, $mix['total_sqm'], 'only panel area: 120.5 + 30 + 50, never the 520 pieces');
        $this->assertEquals(183400, $mix['total_value']);
        $this->assertEquals([150.5, 120400], [$rows['PUF 50mm']['sqm'], $rows['PUF 50mm']['value']]);
        $this->assertEquals([50, 50000], [$rows['PIR 80mm']['sqm'], $rows['PIR 80mm']['value']]);
        $this->assertNull($rows['Accessories & other']['sqm']);
        $this->assertEquals(13000, $rows['Accessories & other']['value']);
        $this->assertEquals(7.1, $rows['Accessories & other']['value_pct']);
        $this->assertArrayNotHasKey('Unknown', $rows->all());
    }

    public function test_the_sales_report_names_each_panel_type_and_keeps_accessories_apart(): void
    {
        $this->lines($this->inv(['subtotal' => 183400]));

        $by = $this->getJson('/api/reports/sales?from_date=2026-11-01&to_date=2026-11-30')->assertOk()->json('data.by_panel_type');

        $this->assertEqualsCanonicalizing(['PUF 50mm', 'PIR 80mm', 'Accessories & other'], array_keys($by), 'it used to call every panel Unknown');
        $this->assertEquals([150.5, 120400, 2], [$by['PUF 50mm']['quantity'], $by['PUF 50mm']['value'], $by['PUF 50mm']['count']]);
        $this->assertNull($by['Accessories & other']['quantity']);
        $this->assertEquals(13000, $by['Accessories & other']['value']);
    }

    // ── PB-078 / PB-079: receivables ────────────────────────────────────────

    private function ar(string $q = ''): array
    {
        return $this->getJson('/api/reports/accounts-receivable' . ($q ? "?{$q}" : ''))->assertOk()->json('data');
    }

    public function test_receivables_as_of_a_past_date_only_count_what_had_been_paid_by_then_and_age_to_that_date(): void
    {
        $i = $this->inv(['invoice_date' => '2026-07-01', 'due_date' => '2026-07-31'], 0);
        $this->pay($i, 1000, '2026-10-20 10:00:00');
        $this->inv(['invoice_date' => '2026-09-15', 'due_date' => '2026-10-15'], 0);        // raised after 1 Sep

        $sep = $this->ar('as_of=2026-09-01')['summary'];
        $this->assertEquals([1000, 1, 1000, 0], [$sep['total_ar'], $sep['invoice_count'], $sep['60_days'], $sep['over_90_days']], '1 Sep: unpaid, 32 days past its 31 Jul due date, and the September invoice does not exist yet');

        $day19 = $this->ar('as_of=2026-10-19')['summary'];
        $this->assertEquals([2000, 1000, 1000], [$day19['total_ar'], $day19['90_days'], $day19['30_days']], '19 Oct: the July invoice is still unpaid (80 days over) and the September one is 4 days over');

        $day20 = $this->ar('as_of=2026-10-20');
        $this->assertEquals(1000, $day20['summary']['total_ar'], '20 Oct: the July invoice was paid that morning, so only the September one is left');
        $this->assertEquals(5, $day20['details'][0]['days_overdue'], '5 days past 15 Oct, counted to 20 Oct, not to today');

        $now = $this->ar();
        $this->assertEquals(1000, $now['summary']['total_ar'], 'today: only the September invoice is left');
        $this->assertEquals(26, $now['details'][0]['days_overdue'], '26 days past 15 Oct, counted to today');
    }

    public function test_the_receivables_bucket_filter_works_on_the_server_so_it_agrees_with_paging(): void
    {
        $this->inv(['invoice_no' => 'INV-CUR-1', 'due_date' => '2026-12-01', 'subtotal' => 500], 0);
        $this->inv(['invoice_no' => 'INV-CUR-2', 'due_date' => '2026-11-10', 'subtotal' => 700], 0);      // due today = not overdue
        $this->inv(['invoice_no' => 'INV-30', 'due_date' => '2026-10-20', 'subtotal' => 100], 0);          // 21 days
        $this->inv(['invoice_no' => 'INV-60', 'due_date' => '2026-09-20', 'subtotal' => 200], 0);          // 51 days
        $this->inv(['invoice_no' => 'INV-90', 'due_date' => '2026-08-20', 'subtotal' => 300], 0);          // 82 days
        $this->inv(['invoice_no' => 'INV-90P', 'due_date' => '2026-06-01', 'subtotal' => 400], 0);         // 162 days
        $nos = fn (array $d) => collect($d['details'])->pluck('invoice_no')->sort()->values()->all();

        $all = $this->ar();
        $this->assertSame(6, $all['pagination']['total']);
        $this->assertEquals(2200, $all['filtered_total']);

        $cur = $this->ar('bucket=current');
        $this->assertSame(['INV-CUR-1', 'INV-CUR-2'], $nos($cur));
        $this->assertEquals([2, 1200], [$cur['pagination']['total'], $cur['filtered_total']]);
        $this->assertEquals(2200, $cur['summary']['total_ar'], 'the cards always cover everything');

        $this->assertSame(['INV-30'], $nos($this->ar('bucket=30')));
        $this->assertSame(['INV-60'], $nos($this->ar('bucket=60')));
        $this->assertSame(['INV-90'], $nos($this->ar('bucket=90')));
        $this->assertSame(['INV-90P'], $nos($this->ar('bucket=90plus')));

        $p1 = $this->ar('bucket=current&per_page=1&page=1');
        $p2 = $this->ar('bucket=current&per_page=1&page=2');
        $this->assertSame([2, 2, 2], [$p1['pagination']['total'], $p1['pagination']['last_page'], $p2['pagination']['current_page']]);
        $this->assertNotSame($nos($p1), $nos($p2));

        $this->assertSame(6, $this->ar('bucket=nonsense')['pagination']['total'], 'an unknown bucket is ignored');
    }

    // ── PB-080 c/d/e: one set of definitions ────────────────────────────────

    public function test_receivables_use_the_stored_invoice_total(): void
    {
        $this->inv(['subtotal' => 1000, 'total_amount' => 1180, 'due_date' => '2026-12-31'], 0);       // total with no tax row

        $this->assertEquals(1180, $this->ar()['summary']['total_ar']);
    }

    public function test_the_financial_year_starts_on_1_april(): void
    {
        $fy = fn (string $d) => ReportingService::financialYearStart(Carbon::parse($d))->toDateString();

        $this->assertSame(['2026-04-01', '2026-04-01', '2025-04-01', '2026-04-01'], [$fy('2026-04-01'), $fy('2027-03-31'), $fy('2026-03-31'), $fy('2026-11-10')]);
    }

    public function test_the_accounting_dashboard_separates_cash_received_from_invoiced_and_runs_from_april(): void
    {
        $this->inv(['invoice_date' => '2026-03-15', 'due_date' => '2026-04-15'], 0);                      // last financial year
        $this->inv(['invoice_date' => '2026-04-10', 'due_date' => '2026-05-10', 'subtotal' => 2000], 0);   // this FY, 184 days overdue
        $b = $this->inv(['invoice_date' => '2026-11-03', 'due_date' => '2026-11-01'], 180);                // this month, 1180, 9 days overdue
        $this->pay($b, 300, '2026-11-04 10:00:00');
        $this->pay($b, 50, '2026-10-30 10:00:00');                                                         // received last month

        $s = $this->getJson('/api/reports/accounting-dashboard')->assertOk()->json('data.summary');

        $this->assertEquals(3180, $s['total_revenue_ytd'], 'the March invoice is last year');
        $this->assertEquals(300, $s['cash_collected_mtd'], 'money received this month, not what was invoiced');
        $this->assertEquals(1180, $s['invoiced_mtd']);
        $this->assertEquals(3000 + 830, $s['total_overdue'], 'every overdue bucket, including 1-30 days: 1000 + 2000 over 90 days and 830 still unpaid on the 9-day-old invoice');
    }

    public function test_the_balance_sheet_balances_in_every_situation(): void
    {
        $assertBalanced = function (string $why) {
            $b = $this->getJson('/api/reports/balance-sheet?as_of=2026-11-30')->assertOk()->json('data');
            $this->assertEquals($b['total_assets'], $b['total_liabilities_and_equity'], $why);

            return $b;
        };

        $i = $this->inv(['subtotal' => 1000], 180);                                   // billed 1180
        $b = $assertBalanced('billed, nothing received');
        $this->assertEquals([1180, 0, 180, 1000], [$b['assets']['accounts_receivable'], $b['assets']['cash_collected'], $b['liabilities']['tax_payable'], $b['equity']['retained_earnings']], 'equity = net sales (the GST is owed, not earned)');

        $this->pay($i, 400, '2026-11-08 10:00:00');
        $b = $assertBalanced('part paid');
        $this->assertEquals([780, 400], [$b['assets']['accounts_receivable'], $b['assets']['cash_collected']]);

        $this->pay($i, 100, '2026-11-09 10:00:00', 'write_off');
        $b = $assertBalanced('with a write-off');
        $this->assertEquals([680, 400, 900], [$b['assets']['accounts_receivable'], $b['assets']['cash_collected'], $b['equity']['retained_earnings']], 'equity = net sales 1000 less the 100 written off');

        $this->pay($i, 900, '2026-11-10 10:00:00');                                   // 1300 received + 100 written off against 1180 billed: 220 received in advance
        $b = $assertBalanced('paid more than billed');
        $this->assertEquals([0, 1300, 220, 900], [$b['assets']['accounts_receivable'], $b['assets']['cash_collected'], $b['liabilities']['customer_advances'], $b['equity']['retained_earnings']]);
    }
}
