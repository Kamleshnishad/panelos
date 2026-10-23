<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Quotation;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_buckets_orders_by_month_and_ranks_customers()
    {
        $company = Company::factory()->create();
        $big   = Customer::factory()->create(['company_id' => $company->id, 'state' => 'Gujarat']);
        $small = Customer::factory()->create(['company_id' => $company->id, 'state' => 'Kerala']);

        $mk = fn ($c, $amt, $date, $status = 'pending') => Order::create([
            'company_id' => $company->id, 'customer_id' => $c->id, 'order_no' => uniqid('SO'),
            'quotation_id' => Quotation::create(['company_id' => $company->id, 'customer_id' => $c->id, 'quotation_no' => uniqid('Q'), 'status' => 'accepted'])->id,
            'status' => $status, 'total_amount' => $amt, 'order_date' => $date,
        ]);
        $mk($big, 1000, now());
        $mk($big, 500, now());
        $mk($small, 300, now());
        $mk($small, 9999, now(), 'cancelled');            // excluded everywhere
        $mk($small, 700, now()->subMonthsNoOverflow(13)); // outside the 12-month window

        $a = app(DashboardService::class)->getDashboard($company->id)['analytics'];

        $this->assertCount(12, $a['trend']['months']);
        $this->assertSame(now()->format('Y-m'), end($a['trend']['months']));
        $this->assertEquals(1800, end($a['trend']['booked']));
        $this->assertEquals(1800, array_sum($a['trend']['booked']));

        $this->assertSame($big->name, $a['top_customers'][0]['name']);
        $this->assertEquals(1500, $a['top_customers'][0]['total']);
        $this->assertSame('Gujarat', $a['revenue_by_state'][0]['label']);
        $this->assertEquals(600, $a['headline']['avg_order_value']);
        $this->assertCount(6, $a['lead_funnel']);
    }
}
