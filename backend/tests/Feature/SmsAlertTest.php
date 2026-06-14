<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsAlertTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $dispatch;
    protected $invoice;
    protected $smsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Test Company']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+919876543210',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id
        ]);

        $this->actingAs($this->user);

        $this->dispatch = Dispatch::factory()->for($this->company)->create();
        $this->postJson('/api/invoices/from-dispatch', [
            'dispatch_id' => $this->dispatch->id
        ]);

        $this->invoice = $this->company->invoices()->first();
        $this->smsService = new SmsService();
    }

    public function test_sms_service_disabled_by_default()
    {
        $this->assertFalse($this->smsService->isEnabled());
    }

    public function test_validate_phone_number_valid()
    {
        $result = $this->smsService->validatePhoneNumber('+919876543210');

        $this->assertTrue($result['valid']);
        $this->assertEquals('+919876543210', $result['normalized']);
    }

    public function test_validate_phone_number_with_spaces()
    {
        $result = $this->smsService->validatePhoneNumber('+91 98765 43210');

        $this->assertTrue($result['valid']);
    }

    public function test_validate_phone_number_too_short()
    {
        $result = $this->smsService->validatePhoneNumber('123');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('10-15 digits', $result['message']);
    }

    public function test_validate_phone_number_invalid_format()
    {
        $result = $this->smsService->validatePhoneNumber('abc123def');

        $this->assertFalse($result['valid']);
    }

    public function test_sms_disabled_returns_error()
    {
        $response = $this->smsService->sendPaymentReminder($this->invoice, $this->company->id, '+919876543210');

        $this->assertFalse($response['success']);
        $this->assertStringContainsString('not enabled', $response['message']);
    }

    public function test_send_payment_reminder_sms_endpoint()
    {
        $response = $this->postJson("/api/invoices/{$this->invoice->id}/send-sms-reminder");

        $response->assertStatus(400);
        $this->assertFalse($response['success']);
    }

    public function test_send_custom_sms_endpoint_validation()
    {
        $response = $this->postJson('/api/sms/send', [
            'phone_number' => 'invalid',
            'message' => 'Test message'
        ]);

        $response->assertStatus(400);
    }

    public function test_send_custom_sms_valid_phone()
    {
        $response = $this->postJson('/api/sms/send', [
            'phone_number' => '+919876543210',
            'message' => 'Test payment reminder'
        ]);

        $response->assertStatus(400); // Service disabled
    }

    public function test_validate_phone_endpoint()
    {
        $response = $this->postJson('/api/sms/validate', [
            'phone_number' => '+919876543210'
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_validate_phone_endpoint_invalid()
    {
        $response = $this->postJson('/api/sms/validate', [
            'phone_number' => 'invalid'
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_get_sms_logs()
    {
        $response = $this->getJson('/api/sms/logs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data'
            ]);
    }

    public function test_get_sms_status()
    {
        $response = $this->getJson('/api/sms/status');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'enabled',
                'provider'
            ]);

        $this->assertFalse($response['enabled']);
    }

    public function test_sms_message_formatting_overdue()
    {
        $reflection = new \ReflectionMethod($this->smsService, 'formatPaymentReminderMessage');
        $reflection->setAccessible(true);

        $message = $reflection->invoke($this->smsService, $this->invoice, 5000, 20);

        $this->assertStringContainsString('URGENT', $message);
        $this->assertStringContainsString('20 days', $message);
        $this->assertStringContainsString('₹5000', $message);
    }

    public function test_sms_message_formatting_slightly_overdue()
    {
        $reflection = new \ReflectionMethod($this->smsService, 'formatPaymentReminderMessage');
        $reflection->setAccessible(true);

        $message = $reflection->invoke($this->smsService, $this->invoice, 1000, 5);

        $this->assertStringContainsString('Payment Reminder', $message);
        $this->assertStringContainsString('₹1000', $message);
    }

    public function test_phone_number_masking()
    {
        $reflection = new \ReflectionMethod($this->smsService, 'maskPhoneNumber');
        $reflection->setAccessible(true);

        $masked = $reflection->invoke($this->smsService, '+919876543210');

        $this->assertStringContainsString('****', $masked);
        $this->assertStringStartsWith('+91', $masked);
    }

    public function test_low_stock_message_formatting()
    {
        $reflection = new \ReflectionMethod($this->smsService, 'formatLowStockMessage');
        $reflection->setAccessible(true);

        $message = $reflection->invoke($this->smsService, 'Copper Coils', 10);

        $this->assertStringContainsString('Low Stock', $message);
        $this->assertStringContainsString('Copper Coils', $message);
        $this->assertStringContainsString('10 units', $message);
    }

    public function test_production_alert_formatting()
    {
        $reflection = new \ReflectionMethod($this->smsService, 'formatProductionAlertMessage');
        $reflection->setAccessible(true);

        $message = $reflection->invoke($this->smsService, 123, 'Coating');

        $this->assertStringContainsString('Batch #123', $message);
        $this->assertStringContainsString('Coating', $message);
    }
}
