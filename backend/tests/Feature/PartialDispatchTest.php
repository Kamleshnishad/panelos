<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Dispatch;
use App\Models\DispatchItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSize;
use App\Models\PanelType;
use App\Models\Quotation;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\DispatchPdfService;
use App\Services\InvoiceService;
use Tests\TestCase;

/**
 * FT-03. A batch can be dispatched in several loads: each dispatch takes chosen pieces of chosen size rows, the rest stays
 * to send, money follows the pieces and always adds back up to the order line, and the batch only counts as dispatched
 * once everything has been delivered.
 */
class PartialDispatchTest extends TestCase
{
    use \Tests\Traits\HasDatabaseTestHelpers;

    private Company $company;
    private User $admin;
    private Order $order;
    private Quotation $quotation;
    private ProductionBatch $batch;
    /** @var array<string,OrderItemSize> */
    private array $size = [];
    private OrderItem $ceiling;
    private OrderItem $wall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        // A real company admin, not the platform super admin the helper makes (that one bypasses the tenant scope).
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'is_company_admin' => true]);
        $this->actingAs($this->admin, 'sanctum');

        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $this->quotation = Quotation::create(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'quotation_no' => 'Q-PD-1', 'status' => 'accepted']);
        $this->order = Order::create(['company_id' => $this->company->id, 'quotation_id' => $this->quotation->id, 'customer_id' => $customer->id, 'order_no' => 'ORD-PD-1', 'order_date' => now(), 'status' => 'in_production']);
        $pt = PanelType::factory()->create(['company_id' => $this->company->id]);

        // Ceiling: 30 x 4450 (143,512.50) and 10 x 3000 (32,250.00) -> 163.5 sqm, 175,762.50
        $this->ceiling = $this->item(['order_id' => $this->order->id, 'panel_type_id' => $pt->id, 'application' => 'Ceiling', 'thickness' => 30,
            'total_sqm' => 163.5, 'rate_per_sqm' => 1075, 'amount' => 175762.50, 'sort_order' => 1]);
        $this->size['a'] = $this->addSize($this->ceiling, 4450, 30, 133.5, 1075, 143512.50, 1);
        $this->size['b'] = $this->addSize($this->ceiling, 3000, 10, 30.0, 1075, 32250.00, 2);
        // Wall: 84 x 2750 (294,525.00)
        $this->wall = $this->item(['order_id' => $this->order->id, 'panel_type_id' => $pt->id, 'application' => 'Wall', 'thickness' => 50,
            'total_sqm' => 231.0, 'rate_per_sqm' => 1275, 'amount' => 294525.00, 'sort_order' => 2]);
        $this->size['c'] = $this->addSize($this->wall, 2750, 84, 231.0, 1275, 294525.00, 1);

        $this->batch = ProductionBatch::factory()->for($this->company)->qcPassed()->create(['order_id' => $this->order->id]);
    }

    /** Order lines also carry the legacy NOT NULL quantity / unit_price columns. */
    private function item(array $a): OrderItem
    {
        return OrderItem::create($a + ['quantity' => 1, 'unit_price' => 1]);
    }

    private function addSize(OrderItem $item, int $length, int $nos, float $sqm, float $rate, float $amount, int $sort): OrderItemSize
    {
        return OrderItemSize::create(['order_item_id' => $item->id, 'length_mm' => $length, 'width_mm' => 1000, 'nos' => $nos, 'sqm' => $sqm,
            'rate_per_sqm' => $rate, 'amount' => $amount, 'sort_order' => $sort]);
    }

    private function dispatchApi(array $items = null, array $extra = [])
    {
        $r = $this->postJson("/api/batches/{$this->batch->id}/dispatch", $extra + ($items === null ? [] : ['items' => $items]));
        return $r;
    }

    private function deliver(int $dispatchId)
    {
        return $this->postJson("/api/dispatches/{$dispatchId}/complete")->assertOk();
    }

    public function test_the_plan_shows_what_is_ordered_sent_and_left(): void
    {
        $plan = $this->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->assertOk()->json('data');

        $this->assertEquals(394.5, $plan['ordered_sqm']);
        $this->assertEquals(394.5, $plan['remaining_sqm']);
        $this->assertFalse($plan['fully_dispatched']);
        $this->assertCount(2, $plan['items']);
        $sizeA = $plan['items'][0]['sizes'][0];
        $this->assertSame(30, $sizeA['ordered_nos']);
        $this->assertSame(0, $sizeA['dispatched_nos']);
        $this->assertSame(30, $sizeA['remaining_nos']);
        $this->assertEquals(4.45, $sizeA['sqm_each']);
    }

    public function test_a_dispatch_with_no_choice_sends_everything_and_matches_the_order_to_the_paisa(): void
    {
        $d = $this->dispatchApi()->assertStatus(201)->json('data');

        $this->assertCount(2, $d['items']);
        $byItem = collect($d['items'])->keyBy('order_item_id');
        $this->assertEquals(175762.50, $byItem[$this->ceiling->id]['amount']);
        $this->assertEquals(163.5, $byItem[$this->ceiling->id]['quantity']);
        $this->assertEquals(294525.00, $byItem[$this->wall->id]['amount']);
        $this->assertCount(2, $byItem[$this->ceiling->id]['sizes'], 'The pieces of each size row are recorded.');

        $this->dispatchApi()->assertStatus(400)->assertJsonPath('message', 'Batch has already been dispatched');
    }

    public function test_a_partial_dispatch_takes_chosen_pieces_and_money_follows_them(): void
    {
        $d = $this->dispatchApi([
            ['order_item_size_id' => $this->size['a']->id, 'nos' => 10],
            ['order_item_size_id' => $this->size['b']->id, 'nos' => 10],
        ])->assertStatus(201)->json('data');

        $ceiling = collect($d['items'])->firstWhere('order_item_id', $this->ceiling->id);
        $this->assertEquals(44.5 + 30.0, $ceiling['quantity']);                   // 10 of 30 x 4450 + all of the 3000s
        $this->assertEquals(47837.50 + 32250.00, $ceiling['amount']);
        $this->assertNull(collect($d['items'])->firstWhere('order_item_id', $this->wall->id), 'The wall was not chosen.');

        $plan = $this->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->json('data');
        $this->assertSame(20, $plan['items'][0]['sizes'][0]['remaining_nos']);
        $this->assertSame(0, $plan['items'][0]['sizes'][1]['remaining_nos']);
        $this->assertSame(84, $plan['items'][1]['sizes'][0]['remaining_nos']);
        $this->assertEquals(394.5 - 74.5, $plan['remaining_sqm']);
    }

    public function test_the_batch_is_dispatched_only_when_every_piece_has_been_delivered(): void
    {
        $first = $this->dispatchApi([['order_item_size_id' => $this->size['a']->id, 'nos' => 30], ['order_item_size_id' => $this->size['b']->id, 'nos' => 10]])->json('data');
        $this->deliver($first['id']);
        $this->assertSame('qc_passed', $this->batch->fresh()->status, 'Half the order delivered is not a dispatched batch.');

        $second = $this->dispatchApi()->assertStatus(201)->json('data');
        $this->assertSame('qc_passed', $this->batch->fresh()->status, 'The rest is on a truck, not delivered.');
        $this->deliver($second['id']);
        $this->assertSame('dispatched', $this->batch->fresh()->status);

        $this->assertTrue($this->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->json('data.fully_dispatched'));
    }

    public function test_the_dispatches_of_an_order_line_add_up_to_the_order_line_exactly(): void
    {
        // 3 pieces for 100.00: 33.33 + 33.33 + 33.34, never 99.99
        $odd = $this->item(['order_id' => $this->order->id, 'panel_type_id' => $this->ceiling->panel_type_id, 'application' => 'Odd', 'thickness' => 30,
            'total_sqm' => 3.0, 'rate_per_sqm' => 33.3333, 'amount' => 100.00, 'sort_order' => 3]);
        $size = $this->addSize($odd, 1000, 3, 3.0, 33.3333, 100.00, 1);

        foreach ([1, 1, 1] as $_) {
            $this->dispatchApi([['order_item_size_id' => $size->id, 'nos' => 1]])->assertStatus(201);
        }

        $rows = DispatchItem::where('order_item_id', $odd->id)->orderBy('id')->get();
        $this->assertSame(['33.33', '33.33', '33.34'], $rows->pluck('amount')->all());
        $this->assertEquals(100.00, round($rows->sum('amount'), 2));
        $this->assertEquals(3.0, round($rows->sum('quantity'), 4));
    }

    public function test_it_refuses_pieces_that_are_not_left_or_not_in_this_order(): void
    {
        $this->dispatchApi([['order_item_size_id' => $this->size['a']->id, 'nos' => 31]])->assertStatus(400)
            ->assertJsonPath('message', 'Only 30 of 30 pieces of 4450 x 1000 mm are left to dispatch; you asked for 31.');

        $this->dispatchApi([['order_item_size_id' => $this->size['a']->id, 'nos' => 25]])->assertStatus(201);
        $this->dispatchApi([['order_item_size_id' => $this->size['a']->id, 'nos' => 6]])->assertStatus(400)
            ->assertJsonPath('message', 'Only 5 of 30 pieces of 4450 x 1000 mm are left to dispatch; you asked for 6.');

        $foreign = $this->addSize($this->item(['order_id' => Order::create(['company_id' => $this->company->id, 'quotation_id' => Quotation::create(['company_id' => $this->company->id, 'customer_id' => $this->order->customer_id, 'quotation_no' => 'Q-X', 'status' => 'accepted'])->id, 'customer_id' => $this->order->customer_id, 'order_no' => 'ORD-X', 'order_date' => now(), 'status' => 'pending'])->id,
            'panel_type_id' => $this->ceiling->panel_type_id, 'total_sqm' => 1, 'rate_per_sqm' => 1, 'amount' => 1]), 1000, 1, 1, 1, 1, 1);
        $this->dispatchApi([['order_item_size_id' => $foreign->id, 'nos' => 1]])->assertStatus(400);

        $this->dispatchApi([['order_item_size_id' => $this->size['b']->id]])->assertStatus(400);                 // no pieces
        $this->dispatchApi([['order_item_size_id' => $this->size['b']->id, 'nos' => 0]])->assertStatus(400);     // validation (this controller reports it as a 400)
    }

    public function test_cancelling_a_dispatch_gives_its_pieces_back_and_reopens_a_delivered_batch(): void
    {
        $d = $this->dispatchApi([['order_item_size_id' => $this->size['a']->id, 'nos' => 12]])->json('data');
        $this->assertSame(18, $this->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->json('data.items.0.sizes.0.remaining_nos'));

        $this->deleteJson("/api/dispatches/{$d['id']}")->assertOk();
        $this->assertSame(30, $this->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->json('data.items.0.sizes.0.remaining_nos'));

        $all = $this->dispatchApi()->json('data');
        $this->deliver($all['id']);
        $this->assertSame('dispatched', $this->batch->fresh()->status);
        $this->deleteJson("/api/dispatches/{$all['id']}")->assertOk();
        $this->assertSame('qc_passed', $this->batch->fresh()->status);
    }

    public function test_a_dispatch_made_before_partial_dispatch_counts_as_everything_sent(): void
    {
        $legacy = Dispatch::create(['company_id' => $this->company->id, 'batch_id' => $this->batch->id, 'status' => 'delivered', 'dispatch_date' => now()]);
        DispatchItem::create(['dispatch_id' => $legacy->id, 'panel_type_id' => $this->ceiling->panel_type_id, 'quantity' => 163.5, 'unit_price' => 1075, 'amount' => 175762.50, 'created_at' => now()]);

        $plan = $this->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->json('data');
        $this->assertTrue($plan['legacy']);
        $this->assertTrue($plan['fully_dispatched']);
        $this->assertEquals(0, $plan['remaining_sqm']);
        $this->dispatchApi()->assertStatus(400);
    }

    public function test_each_load_is_invoiced_on_its_own_and_the_invoices_add_up_to_the_order(): void
    {
        $one = $this->dispatchApi([['order_item_size_id' => $this->size['a']->id, 'nos' => 30], ['order_item_size_id' => $this->size['b']->id, 'nos' => 4]])->json('data');
        $two = $this->dispatchApi()->json('data');

        $svc = app(InvoiceService::class);
        $i1 = $svc->createFromDispatch($one['id'], [], $this->company->id);
        $i2 = $svc->createFromDispatch($two['id'], [], $this->company->id);

        $this->assertNotSame($i1->id, $i2->id);
        $panelTotal = fn ($i) => round((float) $i->items->whereNotNull('panel_type_id')->sum('amount'), 2);
        $this->assertEquals(143512.50 + 12900.00, $panelTotal($i1->load('items')));
        $this->assertEquals(175762.50 + 294525.00, $panelTotal($i1) + $panelTotal($i2->load('items')), 'Together the two invoices bill the order, not more.');
    }

    public function test_the_plan_needs_dispatch_view_and_stays_inside_the_company(): void
    {
        $production = Role::factory()->for($this->company)->create(['permissions' => ['production.view']]);
        $viewer = User::factory()->create(['company_id' => $this->company->id, 'role_id' => $production->id, 'is_company_admin' => false]);
        $this->actingAs($viewer, 'sanctum')->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->assertStatus(403);

        $dispatcher = Role::factory()->for($this->company)->create(['permissions' => ['dispatch.view']]);
        $clerk = User::factory()->create(['company_id' => $this->company->id, 'role_id' => $dispatcher->id, 'is_company_admin' => false]);
        $this->actingAs($clerk, 'sanctum')->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->assertOk();

        $stranger = User::factory()->create(['company_id' => Company::factory()->create()->id, 'is_company_admin' => true]);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/batches/{$this->batch->id}/dispatch-plan")->assertStatus(404);
    }

    public function test_the_challan_lists_the_pieces_and_flags_a_part_load(): void
    {
        $d = $this->dispatchApi([['order_item_size_id' => $this->size['a']->id, 'nos' => 10]])->json('data');

        $challan = $this->getJson("/api/dispatches/{$d['id']}/challan")->assertOk()->json('data');
        $this->assertSame(10, $challan['items'][0]['sizes'][0]['nos']);
        $this->assertSame(4450, $challan['items'][0]['sizes'][0]['length_mm']);

        $dispatch = Dispatch::with('batch.order.customer')->find($d['id']);
        $m = new \ReflectionMethod(DispatchPdfService::class, 'loadForChallan');
        $m->setAccessible(true);
        $html = view('dispatches.challan', ['dispatch' => $m->invoke(app(DispatchPdfService::class), $dispatch)])->render();
        $text = html_entity_decode(strip_tags(preg_replace('#<style.*?</style>#s', '', $html)));

        $this->assertStringContainsString('4450 × 1000 mm × 10 nos', $text);
        $this->assertStringContainsString('Part load', $text);
        $this->assertStringContainsString('Balance still to be dispatched on this order: 350.00 SQM', $text);

        $this->getJson("/api/dispatches/{$d['id']}/challan/pdf")->assertOk();
    }

    public function test_a_whole_batch_dispatch_has_no_part_load_note(): void
    {
        $d = $this->dispatchApi()->json('data');
        $dispatch = Dispatch::with('batch.order.customer')->find($d['id']);
        $m = new \ReflectionMethod(DispatchPdfService::class, 'loadForChallan');
        $m->setAccessible(true);
        $html = view('dispatches.challan', ['dispatch' => $m->invoke(app(DispatchPdfService::class), $dispatch)])->render();

        $this->assertStringNotContainsString('Part load', $html);
    }
}
