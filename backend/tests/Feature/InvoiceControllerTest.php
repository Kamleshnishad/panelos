<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;

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
    }

    public function test_create_invoice_from_dispatch()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();

        $response = $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $dispatch->id
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_list_invoices()
    {
        $response = $this->getJson('/api/invoices');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data',
                'pagination'
            ]);
    }

    public function test_get_invoice_details()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id]);
        $invoice = $this->company->invoices()->first();

        $response = $this->getJson("/api/invoices/{$invoice->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $invoice->id);
    }

    public function test_send_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id]);
        $invoice = $this->company->invoices()->first();

        $response = $this->postJson("/api/invoices/{$invoice->id}/send");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'sent');
    }

    public function test_add_item_to_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id]);
        $invoice = $this->company->invoices()->first();

        $response = $this->postJson("/api/invoices/{$invoice->id}/items", [
            'panel_type_id' => 1,
            'quantity' => 10,
            'unit_price' => 100.00
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_mark_invoice_paid()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id]);
        $invoice = $this->company->invoices()->first();
        $this->postJson("/api/invoices/{$invoice->id}/send");

        $response = $this->postJson("/api/invoices/{$invoice->id}/mark-paid");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_cancel_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id]);
        $invoice = $this->company->invoices()->first();

        $response = $this->postJson("/api/invoices/{$invoice->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_duplicate_invoice()
    {
        $dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', ['dispatch_id' => $dispatch->id]);
        $invoice = $this->company->invoices()->first();

        $response = $this->postJson("/api/invoices/{$invoice->id}/duplicate");

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }
}
