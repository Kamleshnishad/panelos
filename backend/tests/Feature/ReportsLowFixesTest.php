<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ExportController;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reports audit, 25 Oct 2026: the Low findings.
 *  PB-081 the CSV exports filtered on when a record was typed in, not on its own date, and Excel garbled Gujarati / Hindi text;
 *  PB-082 the report endpoints accepted any input and answered it with a result (or with a raw exception message).
 */
class ReportsLowFixesTest extends TestCase
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

    private function csv(string $url): string
    {
        return $this->get($url)->assertOk()->streamedContent();
    }

    private function order(string $no, string $date): Order
    {
        return Order::factory()->create(['company_id' => $this->co->id, 'order_no' => $no, 'order_date' => $date]);
    }

    // ── PB-081 ───────────────────────────────────────────────────────────────

    public function test_the_order_export_filters_on_the_order_date_not_on_when_it_was_typed_in(): void
    {
        $this->order('ORD-JAN', '2026-01-05');                 // typed in today (created_at is 10 Nov)
        $this->order('ORD-DEC', '2026-12-01');

        $jan = $this->csv('/api/export/orders?from=2026-01-01&to=2026-01-31');
        $nov = $this->csv('/api/export/orders?from=2026-11-01&to=2026-11-30');

        $this->assertStringContainsString('ORD-JAN', $jan);
        $this->assertStringNotContainsString('ORD-DEC', $jan);
        $this->assertStringNotContainsString('ORD-JAN', $nov, 'it is not a November order just because it was entered in November');
    }

    public function test_the_order_export_includes_both_ends_of_the_range(): void
    {
        $this->order('ORD-A', '2026-03-01');
        $this->order('ORD-B', '2026-03-15');
        $this->order('ORD-C', '2026-03-31');
        $this->order('ORD-BEFORE', '2026-02-28');
        $this->order('ORD-AFTER', '2026-04-01');

        $csv = $this->csv('/api/export/orders?from=2026-03-01&to=2026-03-31');

        foreach (['ORD-A', 'ORD-B', 'ORD-C'] as $in) {
            $this->assertStringContainsString($in, $csv);
        }
        $this->assertStringNotContainsString('ORD-BEFORE', $csv);
        $this->assertStringNotContainsString('ORD-AFTER', $csv);
    }

    public function test_the_quotation_export_filters_on_the_quotation_date(): void
    {
        Quotation::factory()->create(['company_id' => $this->co->id, 'quotation_no' => 'Q-JAN', 'quoted_on' => '2026-01-05']);
        Quotation::factory()->create(['company_id' => $this->co->id, 'quotation_no' => 'Q-OCT', 'quoted_on' => '2026-10-20']);

        $jan = $this->csv('/api/export/quotations?from=2026-01-01&to=2026-01-31');

        $this->assertStringContainsString('Q-JAN', $jan);
        $this->assertStringNotContainsString('Q-OCT', $jan);
        $this->assertStringNotContainsString('Q-JAN', $this->csv('/api/export/quotations?from=2026-11-01&to=2026-11-30'));
    }

    public function test_the_invoice_export_filters_on_the_invoice_date(): void
    {
        foreach (['INV-S' => '2026-04-01', 'INV-E' => '2026-04-30', 'INV-OUT' => '2026-05-01'] as $no => $date) {
            Invoice::create(['company_id' => $this->co->id, 'invoice_no' => $no, 'status' => 'sent', 'subtotal' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'invoice_date' => $date, 'due_date' => '2026-06-01']);
        }

        $csv = $this->csv('/api/export/invoices?from=2026-04-01&to=2026-04-30');

        $this->assertStringContainsString('INV-S', $csv);
        $this->assertStringContainsString('INV-E', $csv);
        $this->assertStringNotContainsString('INV-OUT', $csv);
    }

    public function test_every_export_starts_with_a_byte_order_mark_and_keeps_gujarati_and_hindi_names(): void
    {
        $c = Customer::factory()->create(['company_id' => $this->co->id, 'name' => 'શાહ એગ્રો', 'city' => 'अहमदाबाद']);
        Order::factory()->create(['company_id' => $this->co->id, 'customer_id' => $c->id, 'order_date' => '2026-11-05']);
        Quotation::factory()->create(['company_id' => $this->co->id, 'customer_id' => $c->id, 'quoted_on' => '2026-11-05']);
        Invoice::create(['company_id' => $this->co->id, 'invoice_no' => 'INV-G', 'status' => 'sent', 'subtotal' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'invoice_date' => '2026-11-05', 'due_date' => '2026-12-01']);

        foreach (['customers', 'quotations', 'orders', 'invoices'] as $what) {
            $csv = $this->csv("/api/export/{$what}");
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, $what);
        }
        $customers = $this->csv('/api/export/customers');
        $this->assertStringContainsString('શાહ એગ્રો', $customers);
        $this->assertStringContainsString('अहमदाबाद', $customers);
        $this->assertTrue(mb_check_encoding($customers, 'UTF-8'));
    }

    public function test_a_number_or_a_phone_number_is_left_alone_but_anything_that_could_run_is_defused(): void
    {
        $same = ['+91 98765 43210', '+919876543210', '-500', '-1250.75', '(079) 2630 1234', '0261-2345678', '12345', 'Shah & Sons', 'Rajkot', ''];
        foreach ($same as $v) {
            $this->assertSame($v, ExportController::csvSafe($v), "'{$v}' is plain data");
        }
        $defused = ['=SUM(A1:A9)', '=HYPERLINK("http://evil.test","x")', '@SUM(1)', '+cmd|calc', '-2+3+cmd', '+1+1', "\tx", "\rx", '=1+1', '-cmd'];
        foreach ($defused as $v) {
            $this->assertSame("'" . $v, ExportController::csvSafe($v), "'{$v}' could run");
        }
    }

    public function test_a_bad_export_range_is_refused_with_the_reason(): void
    {
        $this->getJson('/api/export/orders?from=abc')->assertStatus(422)->assertJsonPath('message', 'Use the date format YYYY-MM-DD for the start of the period.');
        $this->getJson('/api/export/orders?to=31-03-2026')->assertStatus(422);
        $this->getJson('/api/export/invoices?from=2026-04-30&to=2026-04-01')->assertStatus(422)->assertJsonPath('message', 'The end of the period is before its start.');
        $this->getJson('/api/export/quotations?from=2026-02-30')->assertStatus(422);
    }

    // ── PB-082 ───────────────────────────────────────────────────────────────

    public function test_a_report_that_is_asked_for_a_date_that_is_not_a_date_says_so(): void
    {
        foreach (['profit-loss', 'cash-flow', 'sales', 'tax', 'top-customers', 'panel-type-mix'] as $r) {
            $this->getJson("/api/reports/{$r}?from_date=abc")->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR')->assertJsonPath('message', 'Use the date format YYYY-MM-DD for the start of the period.');
            $this->getJson("/api/reports/{$r}?to_date=10/11/2026")->assertStatus(422);
            $this->getJson("/api/reports/{$r}?from_date=2026-02-30")->assertStatus(422);
        }
        foreach (['reconciliation', 'mis', 'tally/xml', 'tally/csv'] as $r) {
            $this->getJson("/api/reports/{$r}?from=abc")->assertStatus(422)->assertJsonPath('message', 'Use the date format YYYY-MM-DD for the start of the period.');
            $this->getJson("/api/reports/{$r}?to=tomorrow")->assertStatus(422);
        }
        $this->getJson('/api/reports/balance-sheet?as_of=garbage')->assertStatus(422)->assertJsonPath('message', 'Use the date format YYYY-MM-DD for the date.');
        $this->getJson('/api/reports/accounts-receivable?as_of=garbage')->assertStatus(422);
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        foreach (['profit-loss', 'cash-flow', 'sales', 'tax', 'top-customers', 'panel-type-mix'] as $r) {
            $this->getJson("/api/reports/{$r}?from_date=2026-12-01&to_date=2026-01-01")->assertStatus(422)->assertJsonPath('message', 'The end of the period is before its start.');
        }
        foreach (['reconciliation', 'mis', 'tally/xml', 'tally/csv'] as $r) {
            $this->getJson("/api/reports/{$r}?from=2026-12-01&to=2026-01-01")->assertStatus(422)->assertJsonPath('message', 'The end of the period is before its start.');
        }
    }

    public function test_a_valid_period_still_works_on_every_report_including_a_single_day_and_no_dates_at_all(): void
    {
        foreach (['profit-loss', 'cash-flow', 'sales', 'tax', 'top-customers', 'panel-type-mix'] as $r) {
            $this->getJson("/api/reports/{$r}")->assertOk();
            $this->getJson("/api/reports/{$r}?from_date=2026-11-10&to_date=2026-11-10")->assertOk();
            $this->getJson("/api/reports/{$r}?from_date=2026-04-01")->assertOk();
        }
        foreach (['reconciliation', 'mis'] as $r) {
            $this->getJson("/api/reports/{$r}")->assertOk();
            $this->getJson("/api/reports/{$r}?from=2026-11-10&to=2026-11-10")->assertOk();
        }
        $this->get('/api/reports/tally/xml?from=2026-11-10&to=2026-11-10')->assertOk();
        $this->get('/api/reports/tally/csv')->assertOk();
        $this->getJson('/api/reports/balance-sheet?as_of=2026-11-10')->assertOk();
        $this->getJson('/api/reports/accounting-dashboard')->assertOk();
        $this->getJson('/api/reports/reconcile')->assertOk();
    }

    public function test_the_revenue_trend_only_accepts_between_1_and_60_months(): void
    {
        foreach (['0', '61', '20000', '-3', 'abc', '2.5'] as $m) {
            $this->getJson("/api/reports/revenue-trend?months={$m}")->assertStatus(422);
        }
        $this->assertCount(60, $this->getJson('/api/reports/revenue-trend?months=60')->assertOk()->json('data.series'));
        $this->assertCount(1, $this->getJson('/api/reports/revenue-trend?months=1')->assertOk()->json('data.series'));
        $this->assertCount(12, $this->getJson('/api/reports/revenue-trend')->assertOk()->json('data.series'));
    }

    public function test_the_top_customers_limit_and_the_receivables_paging_and_bucket_are_checked(): void
    {
        foreach (['0', '101', '-3', 'abc'] as $l) {
            $this->getJson("/api/reports/top-customers?limit={$l}")->assertStatus(422);
        }
        $this->getJson('/api/reports/top-customers?limit=100')->assertOk();

        foreach (['page=0', 'page=-1', 'page=x', 'per_page=0', 'per_page=501', 'per_page=abc'] as $q) {
            $this->getJson("/api/reports/accounts-receivable?{$q}")->assertStatus(422);
        }
        $this->getJson('/api/reports/accounts-receivable?bucket=nonsense')->assertStatus(422)->assertJsonPath('message', 'That is not an ageing bucket (use current, 30, 60, 90 or 90plus).');
        foreach (array_keys(ReportingService::AR_BUCKETS) as $b) {
            $this->getJson("/api/reports/accounts-receivable?bucket={$b}&page=1&per_page=500")->assertOk();
        }
    }

    public function test_an_unexpected_failure_reaches_the_browser_as_a_reference_not_as_the_raw_message(): void
    {
        config(['app.debug' => false]);
        $this->mock(ReportingService::class, fn ($m) => $m->shouldReceive('getProfitLossStatement')->andThrow(new \RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'panelos.secret_table' doesn't exist")));

        $res = $this->getJson('/api/reports/profit-loss')->assertStatus(500)->assertJsonPath('error_code', 'SERVER_ERROR');

        $this->assertStringNotContainsString('secret_table', $res->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertNotEmpty($res->json('reference'));
    }
}
