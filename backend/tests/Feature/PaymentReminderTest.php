<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\User;
use App\Services\PaymentReminderService;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $dispatch;
    protected $invoice;
    protected $reminderService;

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

        $this->dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $this->dispatch->id
        ]);

        $this->invoice = $this->company->invoices()->first();
        $this->invoice->update(['due_date' => now()->subDays(5)]);

        $this->reminderService = new PaymentReminderService(app(EmailService::class));
    }

    public function test_schedule_reminder_for_overdue_invoice()
    {
        $response = $this->postJson("/api/invoices/{$this->invoice->id}/schedule-reminder");

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('payment_reminders', [
            'invoice_id' => $this->invoice->id,
            'reminder_type' => 'first',
            'is_paid' => false,
        ]);
    }

    public function test_cannot_schedule_multiple_reminders_for_same_invoice()
    {
        $this->postJson("/api/invoices/{$this->invoice->id}/schedule-reminder");
        $response = $this->postJson("/api/invoices/{$this->invoice->id}/schedule-reminder");

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_get_reminder_status()
    {
        $this->reminderService->scheduleRemindersForInvoice($this->invoice, $this->company->id);

        $response = $this->getJson("/api/invoices/{$this->invoice->id}/reminder-status");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'reminder_id',
                    'reminder_type',
                    'reminder_count',
                    'days_overdue',
                    'is_paid',
                ]
            ]);
    }

    public function test_send_manual_reminder()
    {
        $response = $this->postJson("/api/invoices/{$this->invoice->id}/send-reminder");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_get_reminder_stats()
    {
        $this->reminderService->scheduleRemindersForInvoice($this->invoice, $this->company->id);

        $response = $this->getJson('/reminders/stats');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_reminders',
                    'pending_reminders',
                    'paid_reminders',
                    'upcoming_in_7_days',
                ]
            ]);
    }

    public function test_mark_reminder_as_paid()
    {
        $this->reminderService->scheduleRemindersForInvoice($this->invoice, $this->company->id);

        $paid = $this->reminderService->markReminderAsPaid($this->invoice->id);

        $this->assertTrue($paid);
        $this->assertDatabaseHas('payment_reminders', [
            'invoice_id' => $this->invoice->id,
            'is_paid' => true,
        ]);
    }

    public function test_send_due_reminders_command()
    {
        $this->reminderService->scheduleRemindersForInvoice($this->invoice, $this->company->id);

        $reminder = PaymentReminder::where('invoice_id', $this->invoice->id)->first();
        $reminder->update(['next_reminder_at' => now()]);

        $result = $this->reminderService->sendDueReminders($this->company->id);

        $this->assertGreaterThan(0, $result['sent']);
    }

    public function test_reminder_type_escalation()
    {
        $this->invoice->update(['due_date' => now()->subDays(10)]);
        $this->reminderService->scheduleRemindersForInvoice($this->invoice, $this->company->id);

        $reminder = PaymentReminder::where('invoice_id', $this->invoice->id)->first();
        $this->assertEquals('first', $reminder->reminder_type);

        $reminder->update(['next_reminder_at' => now()]);
        $this->reminderService->sendDueReminders($this->company->id);

        $updatedReminder = PaymentReminder::where('invoice_id', $this->invoice->id)->first();
        $this->assertIn($updatedReminder->reminder_type, ['first', 'second', 'final']);
    }

    public function test_reminder_increments_count()
    {
        $this->reminderService->scheduleRemindersForInvoice($this->invoice, $this->company->id);

        $reminder = PaymentReminder::where('invoice_id', $this->invoice->id)->first();
        $this->assertEquals(0, $reminder->reminder_count);

        $reminder->update(['next_reminder_at' => now()]);
        $this->reminderService->sendDueReminders($this->company->id);

        $updatedReminder = PaymentReminder::where('invoice_id', $this->invoice->id)->first();
        $this->assertGreaterThan(0, $updatedReminder->reminder_count);
    }

    public function test_no_reminder_for_recent_invoice()
    {
        $recentInvoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'due_date' => now()->addDays(5),
        ]);

        $result = $this->reminderService->scheduleRemindersForInvoice($recentInvoice, $this->company->id);

        $this->assertFalse($result['scheduled']);
        $this->assertStringContainsString('not yet due', $result['message']);
    }

    public function test_paid_invoice_excluded_from_reminders()
    {
        $this->reminderService->scheduleRemindersForInvoice($this->invoice, $this->company->id);

        // Mark invoice as paid
        $this->invoice->update(['status' => 'paid']);

        $reminder = PaymentReminder::where('invoice_id', $this->invoice->id)->first();
        $reminder->update(['next_reminder_at' => now()]);

        $result = $this->reminderService->sendDueReminders($this->company->id);

        // Should mark as paid and not send reminder
        $updatedReminder = PaymentReminder::where('invoice_id', $this->invoice->id)->first();
        $this->assertTrue($updatedReminder->is_paid);
    }
}
